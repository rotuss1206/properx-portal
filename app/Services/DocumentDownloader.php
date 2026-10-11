<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a private document through the app (no direct storage URLs)
 * and records the download in the audit log (ТЗ, розділ 8).
 */
class DocumentDownloader
{
    public function download(Model $document, string $disk, string $path, string $filename): StreamedResponse
    {
        abort_unless(Storage::disk($disk)->exists($path), 404, 'The file is no longer available.');

        AuditLog::record('document_downloaded', $document);

        return Storage::disk($disk)->download($path, $filename);
    }
}
