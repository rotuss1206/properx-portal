<?php

namespace App\Services;

use App\Contracts\DownloadableDocument;
use App\Exceptions\StorageUnavailableException;
use App\Models\AuditLog;
use App\Services\Documents\DocumentVault;
use App\Services\Documents\StorageHealth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Document delivery (Spec 3.2, 8; KEENEY platform): never a public or SAS URL.
 *
 * 1. After Nova's policy check, link() redirects to a short-lived URL::temporarySignedRoute.
 * 2. That route re-checks the session and the policy, then stream() sends the blob through
 *    the app (decrypting it when it was stored encrypted). Every download is audited.
 */
class DocumentDownloader
{
    public function __construct(private DocumentVault $vault, private StorageHealth $health) {}

    public function link(DownloadableDocument&Model $document): RedirectResponse
    {
        return redirect()->to(self::signedUrl($document));
    }

    public static function signedUrl(DownloadableDocument&Model $document): string
    {
        return URL::temporarySignedRoute(
            'documents.download',
            now()->addMinutes(config('portal.temporary_url_minutes')),
            ['type' => $document::downloadType(), 'document' => $document->getKey()],
        );
    }

    /**
     * Fails fast with StorageUnavailableException (a 503 "try again" page) when storage does
     * not answer; the download is audited only once the file has been read.
     */
    public function stream(DownloadableDocument&Model $document): StreamedResponse
    {
        $disk = Storage::disk(DocumentVault::diskName());
        $path = $document->storagePath();
        $kind = $document::downloadType();

        $this->health->ensureAvailable($kind, $document->getKey());

        try {
            abort_unless($disk->exists($path), 404, 'The file is no longer available.');

            $headers = ['Content-Type' => $document->downloadMimeType(), 'Cache-Control' => 'private, no-store'];

            if (DocumentVault::isEncrypted($path)) {
                $contents = $this->vault->read($path);
                AuditLog::record('document_downloaded', $document);

                return response()->streamDownload(fn () => print ($contents), $document->downloadName(), $headers);
            }

            $stream = $disk->readStream($path);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new StorageUnavailableException($kind, $document->getKey(), $e);
        }

        AuditLog::record('document_downloaded', $document);

        return response()->streamDownload(function () use ($stream) {
            fpassthru($stream);
            fclose($stream);
        }, $document->downloadName(), $headers);
    }
}
