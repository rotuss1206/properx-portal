<?php

namespace Tests\Feature\Certificates;

use App\Exceptions\CertificateUnavailableException;
use App\Models\AuditLog;
use App\Services\Certificates\CertificateGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

class CertificateGenerationTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    private CertificateGeneratorService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        Storage::fake(config('portal.certificates.disk'));
        $this->service = app(CertificateGeneratorService::class);
    }

    public function test_generates_a_pdf_and_serves_it_from_cache(): void
    {
        $profile = $this->member()->memberProfile;

        $first = $this->service->generate($profile);
        $second = $this->service->generate($profile);

        $this->assertFalse($first->fromCache);
        $this->assertTrue($second->fromCache);
        $this->assertSame($first->path, $second->path);
        $this->assertStringStartsWith('%PDF-', Storage::disk($first->disk)->get($first->path));
        $this->assertSame(1, AuditLog::where('action', 'certificate_generated')->count());
    }

    public function test_creates_the_font_cache_directory_when_missing(): void
    {
        // Container storage starts empty after every deploy.
        $fontCache = storage_path('framework/cache/dompdf-fonts-test');
        File::deleteDirectory($fontCache);
        config(['dompdf.options.font_cache' => $fontCache, 'dompdf.options.font_dir' => $fontCache]);

        $this->service->generate($this->member()->memberProfile);

        $this->assertDirectoryExists($fontCache);
        File::deleteDirectory($fontCache);
    }

    public function test_cache_expires_after_24_hours(): void
    {
        $profile = $this->member()->memberProfile;
        $this->service->generate($profile);

        $this->travel(25)->hours();

        $this->assertFalse($this->service->generate($profile->fresh())->fromCache);
    }

    public function test_changing_printed_data_clears_the_cache(): void
    {
        $profile = $this->member()->memberProfile;
        $old = $this->service->generate($profile);

        $profile->update(['last_name' => 'Renamed']);

        Storage::disk($old->disk)->assertMissing($old->path);

        $new = $this->service->generate($profile->fresh());
        $this->assertFalse($new->fromCache);
        $this->assertNotSame($old->path, $new->path);
    }

    public function test_unrelated_changes_keep_the_cache(): void
    {
        $profile = $this->member()->memberProfile;
        $certificate = $this->service->generate($profile);

        $profile->update(['phone' => '(555) 000-0000']);

        Storage::disk($certificate->disk)->assertExists($certificate->path);
        $this->assertTrue($this->service->generate($profile->fresh())->fromCache);
    }

    public function test_refuses_expired_license(): void
    {
        $profile = $this->member('expired')->memberProfile;

        $this->expectException(CertificateUnavailableException::class);
        $this->expectExceptionMessage('the license expired on');

        $this->service->generate($profile);
    }

    public function test_refuses_incomplete_profile(): void
    {
        $profile = $this->member()->memberProfile;
        $profile->forceFill(['license_expires_at' => null])->saveQuietly();

        try {
            $this->service->generate($profile->fresh());
            $this->fail('Expected CertificateUnavailableException.');
        } catch (CertificateUnavailableException $e) {
            $this->assertStringContainsString('license expiry date', $e->getMessage());
        }

        Storage::disk(config('portal.certificates.disk'))->assertDirectoryEmpty('/');
    }
}
