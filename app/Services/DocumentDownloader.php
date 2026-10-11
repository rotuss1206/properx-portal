<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Services\Documents\DocumentVault;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a private document after the caller has authorised the request (Spec §3.2, §8):
 * encrypted documents are decrypted and streamed by the app; others get a short-lived
 * SAS link when the disk supports it, otherwise they are streamed. Every download is audited.
 */
class DocumentDownloader
{
    public function __construct(private DocumentVault $vault) {}

    public function download(Model $document, string $disk, string $path, string $filename, ?string $mimeType = null): Response
    {
        /** @var FilesystemAdapter $storage */
        $storage = Storage::disk($disk);

        abort_unless($storage->exists($path), 404, 'The file is no longer available.');

        AuditLog::record('document_downloaded', $document);

        if (DocumentVault::isEncrypted($path)) {
            $contents = $this->vault->read($path);

            return response()->streamDownload(fn () => print ($contents), $filename, [
                'Content-Type' => $mimeType ?? 'application/octet-stream',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        if ($storage->providesTemporaryUrls()) {
            return redirect()->away($storage->temporaryUrl(
                $path,
                now()->addMinutes(config('portal.temporary_url_minutes')),
                ['httpHeaders' => ['contentDisposition' => HeaderUtils::makeDisposition('attachment', $filename, 'document')]],
            ));
        }

        return $storage->download($path, $filename, ['Cache-Control' => 'private, no-store']);
    }
}
