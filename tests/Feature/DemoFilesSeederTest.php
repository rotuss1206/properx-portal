<?php

namespace Tests\Feature;

use App\Models\LibraryDocument;
use App\Models\LicenseDocument;
use App\Models\TaxDocument;
use App\Models\User;
use App\Services\Documents\DocumentVault;
use Database\Seeders\DemoFilesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoFilesSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_documents_get_real_placeholder_files(): void
    {
        Storage::fake(config('portal.documents_disk'));
        $vault = app(DocumentVault::class);
        $user = User::factory()->create();

        $pdf = LicenseDocument::factory()->for($user)->create(['mime_type' => 'application/pdf']);
        $png = LicenseDocument::factory()->for($user)->create(['mime_type' => 'image/png']);
        $jpeg = LicenseDocument::factory()->for($user)->create(['mime_type' => 'image/jpeg']);
        $tax = TaxDocument::factory()->for($user)->create();
        $library = LibraryDocument::factory()->create();

        $this->seed(DemoFilesSeeder::class);

        $this->assertStringStartsWith('%PDF-', $vault->read($pdf->fresh()->stored_filename));
        $this->assertStringStartsWith("\x89PNG", $vault->read($png->fresh()->stored_filename));
        $this->assertStringStartsWith("\xFF\xD8", $vault->read($jpeg->fresh()->stored_filename));
        $this->assertStringStartsWith('%PDF-', $vault->read($tax->fresh()->stored_filename));
        $this->assertStringStartsWith('%PDF-', $vault->read($library->fresh()->file_path));

        // License and tax documents are encrypted at rest, library documents are not.
        $this->assertTrue(DocumentVault::isEncrypted($pdf->fresh()->stored_filename));
        $this->assertTrue(DocumentVault::isEncrypted($tax->fresh()->stored_filename));
        $this->assertFalse(DocumentVault::isEncrypted($library->fresh()->file_path));

        $this->assertSame(strlen($vault->read($pdf->fresh()->stored_filename)), $pdf->fresh()->size);
    }
}
