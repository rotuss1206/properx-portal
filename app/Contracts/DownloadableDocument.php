<?php

namespace App\Contracts;

/**
 * A member document stored in the private Blob container and served through
 * the signed download route (see App\Services\DocumentDownloader).
 */
interface DownloadableDocument
{
    /** Short name used in the signed download URL, e.g. "license". */
    public static function downloadType(): string;

    public function storagePath(): string;

    public function downloadName(): string;

    public function downloadMimeType(): string;
}
