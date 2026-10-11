<?php

namespace Tests\Feature;

use App\Models\LibraryDocument;
use App\Models\LicenseDocument;
use App\Models\TaxDocument;
use App\Models\User;
use Database\Seeders\DemoFilesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoFilesSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_documents_get_real_placeholder_files(): void
    {
        $disk = Storage::fake(config('portal.documents_disk'));
        $user = User::factory()->create();

        $pdf = LicenseDocument::factory()->for($user)->create(['mime_type' => 'application/pdf']);
        $png = LicenseDocument::factory()->for($user)->create(['mime_type' => 'image/png', 'stored_filename' => 'license-documents/a.png']);
        $jpeg = LicenseDocument::factory()->for($user)->create(['mime_type' => 'image/jpeg', 'stored_filename' => 'license-documents/a.jpg']);
        $tax = TaxDocument::factory()->for($user)->create();
        $library = LibraryDocument::factory()->create();

        $this->seed(DemoFilesSeeder::class);

        $this->assertStringStartsWith('%PDF-', $disk->get($pdf->stored_filename));
        $this->assertStringStartsWith("\x89PNG", $disk->get($png->stored_filename));
        $this->assertStringStartsWith("\xFF\xD8", $disk->get($jpeg->stored_filename));
        $this->assertStringStartsWith('%PDF-', $disk->get($tax->stored_filename));
        $this->assertStringStartsWith('%PDF-', $disk->get($library->file_path));

        $this->assertSame(strlen($disk->get($pdf->stored_filename)), $pdf->fresh()->size);
    }
}
