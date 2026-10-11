<?php

namespace Database\Seeders;

use App\Models\LibraryDocument;
use App\Models\LicenseDocument;
use App\Models\TaxDocument;
use Database\Seeders\Support\DemoFile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Writes placeholder files for seeded documents so downloads work in the demo.
 */
class DemoFilesSeeder extends Seeder
{
    public function run(): void
    {
        LicenseDocument::query()->each(function (LicenseDocument $document) {
            $contents = DemoFile::forMimeType($document->mime_type, "License document: {$document->original_filename}");

            Storage::disk(LicenseDocument::disk())->put($document->stored_filename, $contents);
            $document->forceFill(['size' => strlen($contents)])->saveQuietly();
        });

        TaxDocument::query()->each(function (TaxDocument $document) {
            Storage::disk(TaxDocument::disk())->put(
                $document->stored_filename,
                DemoFile::pdf("{$document->document_type} - tax year {$document->tax_year}"),
            );
        });

        LibraryDocument::query()->each(function (LibraryDocument $document) {
            Storage::disk(LibraryDocument::disk())->put($document->file_path, DemoFile::pdf($document->title));
        });
    }
}
