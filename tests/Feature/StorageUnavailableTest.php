<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\LibraryDocument;
use App\Models\LicenseDocument;
use App\Models\TaxDocument;
use App\Services\Certificates\CertificateGeneratorService;
use App\Services\DocumentDownloader;
use App\Services\Documents\DocumentVault;
use App\Services\Documents\StorageHealth;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * Document storage that does not answer (Azurite stopped, Azure outage): downloads fail fast
 * with a plain "temporarily unavailable" page instead of a gateway timeout, nothing is audited
 * as downloaded, the log holds ids and the error class only, and the health check stays quick.
 */
class StorageUnavailableTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        // An Azure disk; storageDown() makes its endpoint refuse connections.
        config(['filesystems.disks.azure' => ['driver' => 'azure-storage-blob', 'container' => 'portal-documents', 'endpoint' => 'https://storage.test']]);
    }

    private function storageDown(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));
    }

    private function storageUp(): void
    {
        Http::fake(['*' => Http::response('', 403)]); // reachable (authorisation is the client's business)
    }

    public function test_the_probe_reads_the_blob_endpoint(): void
    {
        $this->assertSame('https://storage.test', StorageHealth::endpoint());

        config(['filesystems.disks.azure' => ['driver' => 'azure-storage-blob', 'connection_string' => 'DefaultEndpointsProtocol=http;AccountName=dev;AccountKey=x;BlobEndpoint=http://127.0.0.1:10000/dev;']]);
        $this->assertSame('http://127.0.0.1:10000/dev', StorageHealth::endpoint());

        config(['filesystems.disks.azure' => ['driver' => 'local']]);
        $this->assertNull(StorageHealth::endpoint());
    }

    public function test_certificate_downloads_fail_fast_with_a_plain_message(): void
    {
        $this->storageDown();
        Log::spy();
        $member = $this->member();

        $started = microtime(true);
        $this->actingAs($member)->get(CertificateGeneratorService::downloadUrl($member->memberProfile))
            ->assertStatus(503)
            ->assertSee('The certificate is temporarily unavailable. Please try again in a few minutes.')
            ->assertDontSee('storage.test');

        $this->assertLessThan(5, microtime(true) - $started);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'certificate_downloaded']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'certificate_generated']);

        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $message === 'Document storage unavailable'
            && $context === ['kind' => 'certificate', 'id' => $member->memberProfile->id])->once();
    }

    public function test_license_tax_and_library_downloads_fail_fast_too(): void
    {
        $this->storageDown();
        $member = $this->member();
        $documents = [
            LicenseDocument::factory()->for($member)->create(),
            TaxDocument::factory()->for($member)->create(),
            LibraryDocument::factory()->published()->create(),
        ];

        foreach ($documents as $document) {
            $this->actingAs($member)->get(DocumentDownloader::signedUrl($document))
                ->assertStatus(503)
                ->assertSee('The document is temporarily unavailable. Please try again in a few minutes.');
        }

        $this->assertDatabaseMissing('audit_logs', ['action' => 'document_downloaded']);
    }

    public function test_a_storage_error_after_the_probe_gives_the_same_page(): void
    {
        $this->storageUp();
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')->andThrow(new RuntimeException('Unable to check existence for: license-documents/x.pdf.enc'));
        Storage::set(DocumentVault::diskName(), $disk);

        $member = $this->member();
        $document = LicenseDocument::factory()->for($member)->create();

        $this->actingAs($member)->get(DocumentDownloader::signedUrl($document))
            ->assertStatus(503)
            ->assertDontSee('license-documents/');

        $this->assertDatabaseMissing('audit_logs', ['action' => 'document_downloaded']);
    }

    public function test_the_probe_result_is_cached_briefly(): void
    {
        $this->storageDown();
        $health = app(StorageHealth::class);

        $this->assertFalse($health->available());
        $this->assertFalse($health->available());

        Http::assertSentCount(0); // the faked connection error throws before a request is recorded
        $this->assertFalse(cache()->get('documents-storage:reachable'));
    }

    public function test_the_health_check_reports_storage_quickly(): void
    {
        $this->storageDown();
        $started = microtime(true);

        $this->getJson('/api/health')->assertStatus(503)->assertJsonPath('checks.storage', 'fail');

        $this->assertLessThan(5, microtime(true) - $started);
    }

    public function test_reachable_storage_downloads_as_before(): void
    {
        $this->storageUp();
        Storage::fake(DocumentVault::diskName());
        $member = $this->member();
        $document = LicenseDocument::factory()->for($member)->create([
            'stored_filename' => app(DocumentVault::class)->put(LicenseDocument::DIRECTORY, 'pdf', '%PDF-1.4 license', encrypt: true),
        ]);

        $response = $this->actingAs($member)->get(DocumentDownloader::signedUrl($document))->assertOk();

        $this->assertSame('%PDF-1.4 license', $response->streamedContent());
        $this->assertSame(1, AuditLog::query()->where('action', 'document_downloaded')->count());
    }
}
