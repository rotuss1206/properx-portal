<?php

namespace Tests\Feature;

use App\Models\LicenseDocument;
use App\Services\Documents\DocumentVault;
use AzureOss\Storage\BlobLaravel\AzureStorageBlobAdapter;
use Database\Seeders\Support\DemoFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * Upload restrictions, UUID names and application-level encryption (Spec §2, §3.2, §8).
 */
class DocumentStorageTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    /** Azurite's well-known development account; no real Azure account involved. */
    private const AZURITE = 'DefaultEndpointsProtocol=http;AccountName=devstoreaccount1;AccountKey=Eby8vdM02xNOcqFlqUwJPLlmEtlCDXJ1OUzFT50uSRZ6IFsuFq2UVErCz4I6tq/K1SZFPTOtr/KBHBeksoGMGw==;BlobEndpoint=http://127.0.0.1:10000/devstoreaccount1;';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        Storage::fake(config('portal.documents_disk'));
    }

    public function test_encrypted_document_is_unreadable_at_rest_and_named_by_uuid(): void
    {
        $vault = app(DocumentVault::class);
        $file = UploadedFile::fake()->createWithContent('My License.pdf', DemoFile::pdf('License'));

        $stored = $vault->store($file, 'license-documents', encrypt: true);

        $this->assertMatchesRegularExpression('#^license-documents/[0-9a-f-]{36}\.pdf\.enc$#', $stored->path);
        $this->assertSame('My License.pdf', $stored->originalName);
        $this->assertSame('application/pdf', $stored->mimeType);

        $raw = Storage::disk(config('portal.documents_disk'))->get($stored->path);
        $this->assertStringNotContainsString('%PDF', $raw);
        $this->assertStringStartsWith('%PDF-', $vault->read($stored->path));
    }

    public function test_extension_comes_from_content_not_from_client(): void
    {
        $file = UploadedFile::fake()->createWithContent('scan.png', DemoFile::pdf('Actually a PDF'));

        $stored = app(DocumentVault::class)->store($file, 'license-documents', encrypt: false);

        $this->assertStringEndsWith('.pdf', $stored->path);
    }

    public function test_upload_rules(): void
    {
        $validate = fn (UploadedFile $file) => Validator::make(['file' => $file], ['file' => DocumentVault::uploadRules()])->passes();

        $this->assertTrue($validate(UploadedFile::fake()->createWithContent('license.pdf', DemoFile::pdf('ok'))));
        $this->assertTrue($validate(UploadedFile::fake()->image('license.png')));
        $this->assertTrue($validate(UploadedFile::fake()->image('license.jpg')));

        $this->assertFalse($validate(UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')), 'over 10 MB');
        $this->assertFalse($validate($this->realUpload('evil.pdf', '<?php echo "hi";')), 'spoofed PDF');
        $this->assertFalse($validate($this->realUpload('notes.txt', 'plain text')), 'text file');
        $this->assertFalse($validate(UploadedFile::fake()->image('photo.gif')), 'GIF');
    }

    public function test_vault_refuses_content_that_is_not_pdf_jpg_or_png(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(DocumentVault::class)->store($this->realUpload('evil.pdf', '<?php echo "hi";'), 'license-documents', encrypt: true);
    }

    /**
     * A real (non-fake) upload: its MIME type is detected from the contents like in production.
     */
    private function realUpload(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, null, null, true);
    }

    public function test_member_upload_through_nova_is_encrypted(): void
    {
        $member = $this->member();

        $this->actingAs($member)
            ->post('/nova-api/license-documents', [
                'stored_filename' => UploadedFile::fake()->createWithContent('renewal.pdf', DemoFile::pdf('Renewal')),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $document = LicenseDocument::sole();
        $this->assertSame($member->id, $document->user_id);
        $this->assertSame('renewal.pdf', $document->original_filename);
        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertTrue(DocumentVault::isEncrypted($document->stored_filename));
        $this->assertStringStartsWith('%PDF-', app(DocumentVault::class)->read($document->stored_filename));
    }

    public function test_nova_rejects_disallowed_upload(): void
    {
        $this->actingAs($this->member())
            ->post('/nova-api/license-documents', [
                'stored_filename' => $this->realUpload('malware.pdf', 'MZ'.str_repeat("\0", 64)),
            ], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('stored_filename');

        $this->assertSame(0, LicenseDocument::count());
    }

    public function test_azure_disk_is_configured_for_sas_links(): void
    {
        config([
            'filesystems.disks.azure.connection_string' => self::AZURITE,
            'filesystems.disks.azure.container' => 'portal-documents',
        ]);

        $disk = Storage::disk('azure');

        $this->assertInstanceOf(AzureStorageBlobAdapter::class, $disk);
        $this->assertTrue($disk->providesTemporaryUrls());
        $this->assertStringContainsString('sig=', $disk->temporaryUrl('library/x.pdf', now()->addMinutes(5)));
    }
}
