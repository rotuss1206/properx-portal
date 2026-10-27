<?php

namespace Database\Seeders;

use App\Models\LibraryDocument;
use App\Models\LicenseDocument;
use App\Models\TaxDocument;
use App\Services\Documents\DocumentVault;
use Database\Seeders\Support\DemoFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Writes placeholder files for seeded documents (through the vault, so license and tax
 * documents are encrypted exactly like real uploads) so downloads work in the demo.
 * Idempotent: only documents whose file is missing get one.
 */
class DemoFilesSeeder extends Seeder
{
    public function __construct(private DocumentVault $vault) {}

    public function run(): void
    {
        $disk = Storage::disk(DocumentVault::diskName());

        LicenseDocument::query()->each(function (LicenseDocument $document) use ($disk) {
            if ($this->hasFile($disk, $document->stored_filename)) {
                return;
            }

            $contents = DemoFile::forMimeType($document->mime_type, "License document: {$document->original_filename}");
            $extension = config('portal.uploads.mime_types')[$document->mime_type] ?? 'pdf';

            $document->forceFill([
                'stored_filename' => $this->vault->put(LicenseDocument::DIRECTORY, $extension, $contents, encrypt: true),
                'size' => strlen($contents),
            ])->saveQuietly();
        });

        TaxDocument::query()->each(function (TaxDocument $document) use ($disk) {
            if ($this->hasFile($disk, $document->stored_filename)) {
                return;
            }

            $contents = DemoFile::pdf("{$document->document_type} - tax year {$document->tax_year}");

            $document->forceFill([
                'stored_filename' => $this->vault->put(TaxDocument::DIRECTORY, 'pdf', $contents, encrypt: true),
            ])->saveQuietly();
        });

        LibraryDocument::query()->each(function (LibraryDocument $document) use ($disk) {
            if ($this->hasFile($disk, $document->file_path)) {
                return;
            }

            $document->forceFill([
                'file_path' => $this->vault->put(LibraryDocument::DIRECTORY, 'pdf', DemoFile::pdf($document->title), encrypt: false),
            ])->saveQuietly();
        });
    }

    private function hasFile(Filesystem $disk, ?string $path): bool
    {
        return filled($path) && $disk->exists($path);
    }
}
