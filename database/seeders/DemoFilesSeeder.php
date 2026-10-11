<?php

namespace Database\Seeders;

use App\Models\LibraryDocument;
use App\Models\LicenseDocument;
use App\Models\TaxDocument;
use App\Services\Documents\DocumentVault;
use Database\Seeders\Support\DemoFile;
use Illuminate\Database\Seeder;

/**
 * Writes placeholder files for seeded documents (through the vault, so license and
 * tax documents are encrypted exactly like real uploads) so downloads work in the demo.
 */
class DemoFilesSeeder extends Seeder
{
    public function __construct(private DocumentVault $vault) {}

    public function run(): void
    {
        LicenseDocument::query()->each(function (LicenseDocument $document) {
            $contents = DemoFile::forMimeType($document->mime_type, "License document: {$document->original_filename}");
            $extension = config('portal.uploads.mime_types')[$document->mime_type] ?? 'pdf';

            $document->forceFill([
                'stored_filename' => $this->vault->put(LicenseDocument::DIRECTORY, $extension, $contents, encrypt: true),
                'size' => strlen($contents),
            ])->saveQuietly();
        });

        TaxDocument::query()->each(function (TaxDocument $document) {
            $contents = DemoFile::pdf("{$document->document_type} - tax year {$document->tax_year}");

            $document->forceFill([
                'stored_filename' => $this->vault->put(TaxDocument::DIRECTORY, 'pdf', $contents, encrypt: true),
            ])->saveQuietly();
        });

        LibraryDocument::query()->each(function (LibraryDocument $document) {
            $document->forceFill([
                'file_path' => $this->vault->put(LibraryDocument::DIRECTORY, 'pdf', DemoFile::pdf($document->title), encrypt: false),
            ])->saveQuietly();
        });
    }
}
