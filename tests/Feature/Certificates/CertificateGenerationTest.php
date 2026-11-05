<?php

namespace Tests\Feature\Certificates;

use App\Enums\Role;
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

    public function test_the_pdf_is_one_portrait_letter_page_with_our_fonts_embedded(): void
    {
        $certificate = $this->service->generate($this->member()->memberProfile);
        $pdf = Storage::disk($certificate->disk)->get($certificate->path);

        $this->assertSame(1, preg_match_all('#/Type\s*/Page\b#', $pdf));
        $this->assertMatchesRegularExpression('#/MediaBox\s*\[\s*0(\.0+)?\s+0(\.0+)?\s+612(\.0+)?\s+792(\.0+)?\s*\]#', $pdf);

        foreach (['Lora-Italic', 'OpenSans', 'Poppins-SemiBold'] as $font) {
            $this->assertStringContainsString($font, $pdf);
        }

        $this->assertStringNotContainsString('DejaVu', $pdf);
        $this->assertStringContainsString('/FontFile2', $pdf);
    }

    public function test_the_certificate_prints_the_members_data(): void
    {
        $member = $this->member();
        $member->forceFill(['created_at' => '2019-04-10 09:00:00'])->save();
        $profile = $member->memberProfile;
        $profile->forceFill(['first_name' => 'Jordan', 'last_name' => 'Mercer', 'paid_through_at' => '2027-03-14 12:00:00', 'license_expires_at' => '2030-06-01'])->saveQuietly();
        $profile = $profile->fresh();

        $html = view('pdf.certificate-body', $this->service->document($profile))->render();

        $this->assertStringContainsString('Jordan Mercer', $html);
        $this->assertStringContainsString($profile->member_number, $html);
        $this->assertStringContainsString('April 2019', $html);
        $this->assertStringContainsString('Mar 14, 2027', $html);
        $this->assertStringContainsString('2026–2027', $html);
        $this->assertStringContainsString(parse_url(route('certificates.verify.form'), PHP_URL_HOST).'/verify · Certificate ID '.$profile->certificate_code, $html);
        $this->assertStringContainsString('data:image/png;base64,', $html); // the QR code and the logo
        $this->assertStringNotContainsString($profile->npn, $html);
    }

    public function test_the_signatory_comes_from_the_config_and_the_second_is_off(): void
    {
        $this->staff(Role::ExecutiveDirector)->forceFill(['name' => 'Longest Serving'])->save();
        $profile = $this->member()->memberProfile;

        $document = $this->service->document($profile);
        $this->assertSame('Longest Serving', $document['signatoryName']);
        $this->assertSame('Executive Director', $document['signatoryTitle']);
        $this->assertNull($document['secondSignatoryName']);
        $this->assertStringNotContainsString('cert-sign-right', view('pdf.certificate-body', $document)->render());

        config(['portal.member_area.certificate.signatory' => ['name' => 'D. Whitfield', 'title' => 'President']]);
        $old = $this->service->generate($profile);
        $html = view('pdf.certificate-body', $this->service->document($profile))->render();
        $this->assertStringContainsString('D. Whitfield', $html);
        $this->assertStringContainsString('President', $html);

        // What is printed is part of the cache key.
        config(['portal.member_area.certificate.signatory' => ['name' => 'Someone Else', 'title' => 'President']]);
        $this->assertNotSame($old->path, $this->service->generate($profile)->path);
    }

    public function test_a_changed_paid_period_regenerates_the_pdf(): void
    {
        $profile = $this->member()->memberProfile;
        $old = $this->service->generate($profile);

        $profile->update([]);
        $profile->forceFill(['paid_through_at' => now()->addYears(2)])->save();

        Storage::disk($old->disk)->assertMissing($old->path);
        $this->assertFalse($this->service->generate($profile->fresh())->fromCache);
    }

    public function test_long_names_stay_on_one_line_by_a_smaller_size(): void
    {
        $profile = $this->member()->memberProfile;
        $profile->forceFill(['first_name' => str_repeat('Maximiliana', 3), 'last_name' => str_repeat('Featherstonehaugh', 2)])->saveQuietly();

        $this->assertLessThan((float) CertificateGeneratorService::points(38), $this->service->document($profile->fresh())['nameSize']);
    }

    public function test_the_design_values_are_figmas_on_its_636_px_frame(): void
    {
        $this->assertSame('36.538pt', CertificateGeneratorService::points(38));
        $this->assertSame('612pt', CertificateGeneratorService::points(636.48));

        $styles = view('pdf.certificate-styles')->render();

        foreach (['#14508c', '#5f7185', '#0a2d4b', '#2a3340', '#9aa7b4', '#1c2b3a', '#f0c846', '#e6ddc4'] as $color) {
            $this->assertStringContainsString($color, $styles);
        }

        $this->assertStringContainsString('letter-spacing: '.CertificateGeneratorService::points(2.64), $styles);
        $this->assertStringContainsString('line-height: '.CertificateGeneratorService::points(22.1), $styles);
    }

    public function test_only_the_pdf_uses_the_background_image_and_the_preview_uses_real_css(): void
    {
        $profile = $this->member()->memberProfile;

        $this->assertStringNotContainsString('cert-background"', view('pdf.certificate-body', $this->service->document($profile))->render());
        $this->assertFileExists(resource_path('views/pdf/images/certificate-background.png'));
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
