<?php

namespace App\Services;

use App\Contracts\DownloadableDocument;
use App\Models\AuditLog;
use App\Services\Documents\DocumentVault;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Document delivery (Spec 3.2, 8; KEENEY platform): never a public or SAS URL.
 *
 * 1. After Nova's policy check, link() redirects to a short-lived URL::temporarySignedRoute.
 * 2. That route re-checks the session and the policy, then stream() sends the blob through
 *    the app (decrypting it when it was stored encrypted). Every download is audited.
 */
class DocumentDownloader
{
    public function __construct(private DocumentVault $vault) {}

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

    public function stream(DownloadableDocument&Model $document): StreamedResponse
    {
        $disk = Storage::disk(DocumentVault::diskName());
        $path = $document->storagePath();

        abort_unless($disk->exists($path), 404, 'The file is no longer available.');

        AuditLog::record('document_downloaded', $document);

        $headers = ['Content-Type' => $document->downloadMimeType(), 'Cache-Control' => 'private, no-store'];

        if (DocumentVault::isEncrypted($path)) {
            $contents = $this->vault->read($path);

            return response()->streamDownload(fn () => print ($contents), $document->downloadName(), $headers);
        }

        return response()->streamDownload(function () use ($disk, $path) {
            $stream = $disk->readStream($path);
            fpassthru($stream);
            fclose($stream);
        }, $document->downloadName(), $headers);
    }
}
