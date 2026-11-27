<?php

namespace Tests\Feature\Member;

use App\Enums\PaymentStatus;
use App\Enums\PersonType;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\CertificateIssue;
use App\Models\StripeEvent;
use App\Models\User;
use App\Services\Certificates\CertificateGeneratorService;
use App\Services\Certificates\CertificateOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * /member/certificate: the preview shows what the PDF prints, the same access as the download, an
 * explanation instead of a certificate when there can be none, agency people as before.
 */
class CertificatePageTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        config(['portal.member_area.enabled' => true]);
        Storage::fake(config('portal.certificates.disk'));
    }

    private function canceled(): User
    {
        $member = $this->member();
        $member->memberProfile->forceFill(['payment_status' => PaymentStatus::Canceled, 'payment_status_updated_at' => now()->subMonth()])->save();

        return $member->fresh();
    }

    public function test_the_page_shows_the_certificate_and_the_download(): void
    {
        $member = $this->member();
        $member->memberProfile->forceFill(['first_name' => 'Jordan', 'last_name' => 'Mercer', 'paid_through_at' => '2027-03-14 12:00:00'])->saveQuietly();
        $member = $member->fresh();
        $profile = $member->memberProfile;

        $this->actingAs($member)->get(route('member.certificate'))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Membership certificate')
            ->assertSee('Jordan Mercer')
            ->assertSee($profile->member_number)
            ->assertSee('Mar 14, 2027')
            ->assertSee($profile->certificate_code)
            ->assertSee('CERTIFICATE OF MEMBERSHIP')
            ->assertSee(route('certificates.open', $profile), false)
            ->assertSee('Live preview');
    }

    public function test_the_options_and_format_are_as_in_the_mockup(): void
    {
        $member = $this->member();
        $content = $this->actingAs($member)->get(route('member.certificate'))->assertOk()
            ->assertSee('Certificate options')
            ->assertSee('Membership term')
            ->assertSee('Include member ID')
            ->assertSee('Show '.$member->memberProfile->member_number.' on the certificate')
            ->assertSee('Include join date')
            ->assertSee('Include verification QR')
            ->assertSee('PDF — Letter, portrait')
            ->assertSee('PNG image')
            ->assertDontSee('coming soon')
            ->assertDontSee('Certificate details')
            ->assertDontSee('Something not right?')
            ->getContent();

        $this->assertMatchesRegularExpression('/name="member_id" value="1"[^>]*checked/', $content);
        $this->assertMatchesRegularExpression('/name="join_date" value="1"[^>]*checked/', $content);
        $this->assertDoesNotMatchRegularExpression('/name="qr" value="1"[^>]*checked/', $content);

        // Every part is there for the live preview; the QR starts hidden.
        $this->assertMatchesRegularExpression('/class="cert-qr"\s+data-ppx-cert="qr"\s+hidden/', $content);
    }

    public function test_the_chosen_options_reach_the_signed_download_and_each_combination_has_its_own_file(): void
    {
        $member = $this->member();
        $profile = $member->memberProfile;
        $this->actingAs($member);

        $signed = $this->get(route('certificates.open', [$profile, 'member_id' => 0, 'join_date' => 1, 'qr' => 1, 'format' => 'pdf']))->assertRedirect()->headers->get('Location');

        parse_str((string) parse_url($signed, PHP_URL_QUERY), $query);
        $this->assertSame(['0', '1', '1'], [$query['member_id'], $query['join_date'], $query['qr']]);

        $this->get($signed)->assertOk();

        $log = AuditLog::where('action', 'certificate_generated')->sole();
        $this->assertSame(false, $log->details['member_id']);
        $this->assertSame(true, $log->details['qr']);
        $this->assertSame('pdf', $log->details['format']);
        $this->assertSame(false, AuditLog::where('action', 'certificate_downloaded')->sole()->details['member_id']);

        $service = app(CertificateGeneratorService::class);
        $withQr = $service->generate($profile, new CertificateOptions(qr: true));
        $without = $service->generate($profile, new CertificateOptions(qr: false));
        $defaults = $service->generate($profile);

        $this->assertNotSame($withQr->path, $without->path);
        $this->assertFalse($without->fromCache);
        $this->assertSame($without->path, $defaults->path);
        $this->assertTrue($defaults->fromCache);
        $this->assertTrue($service->generate($profile, new CertificateOptions(qr: true))->fromCache, 'Another combination must not drop this one.');
    }

    public function test_a_tampered_download_link_is_refused(): void
    {
        $member = $this->member();
        $this->actingAs($member);

        $signed = CertificateGeneratorService::downloadUrl($member->memberProfile, new CertificateOptions(qr: false));

        $this->get(str_replace('qr=0', 'qr=1', $signed))->assertForbidden();
    }

    public function test_the_pdf_prints_only_what_was_chosen_and_always_the_number(): void
    {
        $profile = $this->member()->memberProfile;
        $service = app(CertificateGeneratorService::class);

        $html = view('pdf.certificate-body', $service->document($profile, new CertificateOptions(memberId: false, joinDate: false, qr: false)))->render();
        $this->assertStringNotContainsString('MEMBER ID', $html);
        $this->assertStringNotContainsString('MEMBER SINCE', $html);
        $this->assertStringNotContainsString('cert-qr', $html);
        $this->assertStringContainsString('Certificate ID '.$profile->certificate_code, strip_tags($html));

        $html = view('pdf.certificate-body', $service->document($profile, new CertificateOptions(qr: true)))->render();
        $this->assertStringContainsString('MEMBER ID', $html);
        $this->assertStringContainsString('MEMBER SINCE', $html);
        $this->assertStringContainsString('cert-qr-image', $html);
    }

    public function test_only_the_current_term_without_a_payment_history(): void
    {
        $member = $this->member();
        $profile = $member->memberProfile;
        $profile->forceFill(['paid_through_at' => '2027-03-14 12:00:00'])->saveQuietly();

        $this->assertSame(['2026–2027'], array_column(app(CertificateGeneratorService::class)->terms($profile->fresh()), 'label'));
    }

    public function test_earlier_terms_come_from_the_payment_history(): void
    {
        $member = $this->member();
        $profile = $member->memberProfile;
        $profile->forceFill(['paid_through_at' => '2027-03-14 12:00:00'])->saveQuietly();
        $profile = $profile->fresh();

        foreach (['evt_1' => '2024-03-14 12:00:30', 'evt_2' => '2026-03-14 12:00:30'] as $id => $paidAt) {
            StripeEvent::create(['id' => $id, 'type' => 'invoice.paid', 'member_profile_id' => $profile->id, 'outcome' => 'processed', 'event_created_at' => $paidAt]);
        }

        $service = app(CertificateGeneratorService::class);
        $terms = $service->terms($profile);

        // 2025 has no payment, so that term is not offered.
        $this->assertSame(['2027-03-14', '2025-03-14'], array_column($terms, 'value'));

        $document = $service->document($profile, new CertificateOptions(termEnd: '2025-03-14'));
        $this->assertSame('2025-03-14', $document['validThrough']);
        $this->assertSame('2024–2025', $document['term']);

        // A term this member does not have means the current one.
        $forged = $service->document($profile, new CertificateOptions(termEnd: '2020-01-01'));
        $this->assertSame('2027-03-14', $forged['validThrough']);

        $this->actingAs($member->fresh())->get(route('member.certificate'))->assertOk()
            ->assertSee('value="2025-03-14"', false)
            ->assertSee('data-date="Mar 14, 2025"', false);
    }

    // The two designs: the preview (Certificate Generate.jpg) and the PDF (Certificate.jpg)

    private function pdf(array $config = [], ?CertificateOptions $options = null): string
    {
        config($config);

        return view('pdf.certificate-body', app(CertificateGeneratorService::class)->document($this->member()->memberProfile, $options))->render();
    }

    private function preview(array $config = []): string
    {
        config($config);

        return view('member.certificate-preview', app(CertificateGeneratorService::class)->document($this->member()->memberProfile))->render();
    }

    public function test_without_signatories_both_designs_print_only_the_date_issued_on_the_right(): void
    {
        $this->travelTo('2026-11-05 15:00:00');

        foreach ([$this->pdf(), $this->preview()] as $html) {
            $this->assertMatchesRegularExpression('/class="cert-sign cert-sign-right">\s*<div class="cert-sign-name" data-ppx-cert="issued">Nov 5, 2026<\/div>\s*<div class="cert-sign-rule"><\/div>\s*<div class="cert-sign-title">Date issued</', $html);
            $this->assertStringNotContainsString('cert-sign-left', $html);
            $this->assertStringNotContainsString('Issued Nov', $html);
        }
    }

    public function test_one_signatory_stands_right_of_the_seal_with_the_date_issued_left_of_it(): void
    {
        $this->travelTo('2026-11-05 15:00:00');
        $config = ['portal.member_area.certificate.second_signatory' => ['name' => 'R. Alvarez', 'title' => 'Membership Secretary']];

        foreach ([$this->pdf($config), $this->preview($config)] as $html) {
            $this->assertMatchesRegularExpression('/cert-sign-left">.*?Nov 5, 2026.*?cert-sign-rule.*?Date issued/s', $html);
            $this->assertMatchesRegularExpression('/cert-sign-right">\s*<div class="cert-sign-name">R\. Alvarez<\/div>\s*<div class="cert-sign-rule"><\/div>\s*<div class="cert-sign-title">Membership Secretary</', $html);
            // The name set in type is the signature: not printed twice.
            $this->assertSame(1, substr_count($html, 'R. Alvarez'));
            $this->assertStringNotContainsString('Issued Nov', strip_tags($html));
        }
    }

    public function test_two_signatures_move_the_date_to_the_bottom_line_in_both_designs(): void
    {
        $this->travelTo('2026-11-05 15:00:00');
        $config = [
            'portal.member_area.certificate.signatory' => ['name' => 'D. Whitfield', 'title' => 'President'],
            'portal.member_area.certificate.second_signatory' => ['name' => 'R. Alvarez', 'title' => 'Membership Secretary'],
        ];

        $pdf = $this->pdf($config);
        $this->assertMatchesRegularExpression('/cert-sign-left">.*?D\. Whitfield.*?cert-sign-rule.*?President/s', $pdf);
        $this->assertMatchesRegularExpression('/cert-sign-right">.*?R\. Alvarez.*?cert-sign-rule.*?Membership Secretary/s', $pdf);
        $this->assertStringNotContainsString('Date issued', $pdf);
        $this->assertStringContainsString('Issued Nov 5, 2026 · Verify authenticity at', strip_tags($pdf));

        $preview = $this->preview($config);
        $this->assertStringNotContainsString('Date issued', $preview);
        $this->assertStringContainsString('Issued Nov 5, 2026 · Verify at', strip_tags($preview));
        $this->assertStringContainsString('cert-hr', $preview);
    }

    public function test_the_signatures_are_off_by_default(): void
    {
        $this->assertNull(config('portal.member_area.certificate.signatory'));
        $this->assertNull(config('portal.member_area.certificate.second_signatory'));
    }

    public function test_the_first_signatory_comes_from_the_environment_and_stays_off_without_it(): void
    {
        $config = $this->portalConfigWith(['CERTIFICATE_FIRST_SIGNATORY_NAME' => 'D. Whitfield', 'CERTIFICATE_FIRST_SIGNATORY_TITLE' => 'President']);
        $this->assertSame(['name' => 'D. Whitfield', 'title' => 'President'], $config['member_area']['certificate']['signatory']);

        $config = $this->portalConfigWith(['CERTIFICATE_FIRST_SIGNATORY_NAME' => 'D. Whitfield', 'CERTIFICATE_FIRST_SIGNATORY_TITLE' => '']);
        $this->assertSame(['name' => 'D. Whitfield', 'title' => null], $config['member_area']['certificate']['signatory']);

        $config = $this->portalConfigWith(['CERTIFICATE_FIRST_SIGNATORY_NAME' => '', 'CERTIFICATE_FIRST_SIGNATORY_TITLE' => 'President']);
        $this->assertNull($config['member_area']['certificate']['signatory']);
    }

    public function test_the_pdf_has_gold_corners_a_diamond_and_a_round_seal_and_the_preview_does_not(): void
    {
        $pdf = $this->pdf();
        $this->assertStringContainsString('cert-corner-left', $pdf);
        $this->assertStringContainsString('cert-corner-right', $pdf);
        $this->assertStringContainsString('cert-diamond', $pdf);

        $styles = view('pdf.certificate-styles')->render();
        $this->assertMatchesRegularExpression('/\.cert-seal \{[^}]*border-radius: '.preg_quote(CertificateGeneratorService::points(52)).'/', $styles);

        $preview = $this->preview();
        $this->assertStringNotContainsString('corner', $preview);
        $this->assertStringNotContainsString('diamond', $preview);
        $previewStyles = view('member.certificate-preview-styles')->render();
        $this->assertMatchesRegularExpression('/\.cert-rule \{[^}]*width: 52px; height: 0; border-top: 2px solid #f0c846/', $previewStyles);
        $this->assertMatchesRegularExpression('/\.cert-seal \{[^}]*width: 74px; height: 74px; border: 2px solid #f0c846; border-radius: 28\.86px; background: #fffdf6/', $previewStyles);
    }

    public function test_the_pdf_signatures_are_symmetrical_around_the_seal_in_the_middle(): void
    {
        $styles = view('pdf.certificate-styles')->render();
        $points = fn (string $selector, string $property) => preg_match('/'.preg_quote($selector).' \{[^}]*\b'.$property.': ([\d.]+)pt/', $styles, $m) ? (float) $m[1] : null;

        $page = (float) CertificateGeneratorService::points(816);
        $seal = $points('.cert-seal', 'left');
        $sealOuter = (float) CertificateGeneratorService::points(104);
        $block = $points('.cert-sign', 'width');
        $left = $points('.cert-sign-left', 'left');
        $right = $points('.cert-sign-right', 'left');

        $this->assertEqualsWithDelta($page, 2 * $seal + $sealOuter, 0.02, 'The seal is in the middle.');
        $this->assertEqualsWithDelta($seal - ($left + $block), $right - ($seal + $sealOuter), 0.02, 'Same distance from the seal.');
        $this->assertEqualsWithDelta($page, $left + $right + $block, 0.02, 'Mirrored around the middle.');
    }

    public function test_the_issue_date_is_the_first_issue_of_the_term_not_the_day_of_the_download(): void
    {
        $this->travelTo('2026-11-05 09:00:00');
        $member = $this->member();
        $profile = $member->memberProfile;
        $service = app(CertificateGeneratorService::class);

        $first = $service->generate($profile);
        $this->assertFalse($first->fromCache);

        // The next day: the same date, so the same file.
        $this->travelTo('2026-11-06 09:00:00');
        $this->assertTrue($service->generate($profile->fresh())->fromCache);
        $this->assertStringContainsString('Nov 5, 2026', view('pdf.certificate-body', $service->document($profile->fresh()))->render());

        // Changed data: a new file, still dated the first issue.
        $profile->fresh()->update(['last_name' => 'Renamed']);
        $this->assertFalse($service->generate($profile->fresh())->fromCache);
        $this->assertStringContainsString('Nov 5, 2026', view('pdf.certificate-body', $service->document($profile->fresh()))->render());
        $this->assertSame(1, CertificateIssue::query()->count());

        // The public check shows it.
        $this->get(route('certificates.verify', $profile->certificate_code))->assertOk()->assertSee('Issued')->assertSee('Nov 5, 2026');
    }

    public function test_before_any_issue_the_preview_shows_today_and_records_nothing(): void
    {
        $this->travelTo('2026-11-05 09:00:00');

        $this->assertStringContainsString('Nov 5, 2026', $this->preview());
        $this->assertSame(0, CertificateIssue::query()->count());
    }

    public function test_the_qr_sits_small_in_the_bottom_line_of_the_pdf(): void
    {
        $with = $this->pdf([], new CertificateOptions(qr: true));
        $this->assertStringContainsString('cert--qr', $with);
        $this->assertStringContainsString('cert-qr-image', $with);
        $this->assertStringNotContainsString('cert-qr', $this->pdf([], new CertificateOptions(qr: false)));

        $styles = view('pdf.certificate-styles')->render();
        $top = fn (string $selector) => preg_match('/'.preg_quote($selector).' \{[^}]*\btop: ([\d.]+)pt/', $styles, $m) ? (float) $m[1] : null;
        $this->assertGreaterThan($top('.cert-seal') + (float) CertificateGeneratorService::points(104), $top('.cert-qr'), 'Below the signatures, in the bottom line.');
        $this->assertMatchesRegularExpression('/\.cert-qr \{[^}]*width: '.preg_quote(CertificateGeneratorService::points(60)).'/', $styles);
    }

    public function test_the_second_signatory_and_their_signature_come_from_the_environment(): void
    {
        $config = $this->portalConfigWith([
            'CERTIFICATE_SECOND_SIGNATORY_NAME' => 'Anne Glorioso',
            'CERTIFICATE_SECOND_SIGNATORY_TITLE' => 'Founder and CEO',
            'CERTIFICATE_SIGNATURE_IMAGE' => 'signature-anne-glorioso.png',
        ]);

        $this->assertSame(['name' => 'Anne Glorioso', 'title' => 'Founder and CEO'], $config['member_area']['certificate']['second_signatory']);
        $this->assertSame(resource_path('views/pdf/images/signature-anne-glorioso.png'), $config['certificates']['signature_image']);

        $this->travelTo('2026-11-05 15:00:00');
        config([
            'portal.member_area.certificate.second_signatory' => $config['member_area']['certificate']['second_signatory'],
            'portal.certificates.signature_image' => $config['certificates']['signature_image'],
        ]);
        $image = 'data:image/png;base64,'.base64_encode((string) file_get_contents($config['certificates']['signature_image']));

        // Both designs: her signature right of the seal, the line, her name and title under it; the date issued
        // left of the seal; nobody else signs.
        foreach ([$this->pdf(), $this->preview()] as $html) {
            $this->assertMatchesRegularExpression('/cert-sign-right">\s*<div class="cert-sign-name"><img class="cert-sign-image" src="'.preg_quote($image, '/').'" alt="Signature of Anne Glorioso"><\/div>\s*<div class="cert-sign-rule"><\/div>\s*<div class="cert-sign-person">Anne Glorioso<\/div>\s*<div class="cert-sign-title">Founder and CEO<\/div>/', $html);
            $this->assertMatchesRegularExpression('/cert-sign-left">.*?Nov 5, 2026.*?Date issued/s', $html);
            $this->assertSame(1, substr_count($html, 'cert-sign-image'));
            $this->assertStringNotContainsString('Issued Nov', strip_tags($html));
        }
    }

    public function test_without_the_environment_there_is_no_second_signatory_and_no_image(): void
    {
        $config = $this->portalConfigWith([
            'CERTIFICATE_SECOND_SIGNATORY_NAME' => '',
            'CERTIFICATE_SECOND_SIGNATORY_TITLE' => '',
            'CERTIFICATE_SIGNATURE_IMAGE' => '',
        ]);

        $this->assertNull($config['member_area']['certificate']['second_signatory']);
        $this->assertNull($config['certificates']['signature_image']);
    }

    public function test_the_signature_is_used_only_with_a_second_signatory(): void
    {
        config(['portal.certificates.signature_image' => resource_path('views/pdf/images/signature-anne-glorioso.png')]);

        $this->assertStringNotContainsString('cert-sign-image', $this->pdf());
        $this->assertStringNotContainsString('cert-sign-image', $this->preview());
    }

    public function test_a_second_signatory_without_an_image_is_set_in_type(): void
    {
        $html = $this->preview(['portal.member_area.certificate.second_signatory' => ['name' => 'Anne Glorioso', 'title' => 'Founder and CEO']]);

        $this->assertStringNotContainsString('cert-sign-image', $html);
        $this->assertMatchesRegularExpression('/cert-sign-right">\s*<div class="cert-sign-name">Anne Glorioso</', $html);
    }

    public function test_a_replaced_signature_file_is_not_served_from_the_cache(): void
    {
        $file = storage_path('framework/testing/signature-'.uniqid().'.png');
        File::ensureDirectoryExists(dirname($file));
        File::copy(resource_path('views/pdf/images/signature-anne-glorioso.png'), $file);
        config([
            'portal.member_area.certificate.second_signatory' => ['name' => 'Anne Glorioso', 'title' => 'Founder and CEO'],
            'portal.certificates.signature_image' => $file,
        ]);
        $profile = $this->member()->memberProfile;
        $generator = app(CertificateGeneratorService::class);

        try {
            $first = $generator->generate($profile)->path;
            $this->assertSame($first, $generator->generate($profile)->path, 'unchanged: the cached file');

            File::copy(resource_path('views/pdf/images/logo-color.png'), $file);
            $this->assertNotSame($first, $generator->generate($profile)->path);
        } finally {
            File::delete($file);
        }
    }

    public function test_the_shipped_signature_is_a_transparent_png_inside_the_pdf_chroot(): void
    {
        $path = resource_path('views/pdf/images/signature-anne-glorioso.png');
        $this->assertFileExists($path);
        $this->assertStringStartsWith(str_replace('\\', '/', config('dompdf.options.chroot')), str_replace('\\', '/', $path));

        $image = imagecreatefrompng($path);
        $this->assertSame(127, imagecolorsforindex($image, imagecolorat($image, 0, 0))['alpha'], 'the corner is transparent');
    }

    /**
     * config/portal.php read again with these environment values (and restored afterwards).
     *
     * @param  array<string, string>  $values
     * @return array<string, mixed>
     */
    private function portalConfigWith(array $values): array
    {
        $previous = [];
        foreach ($values as $name => $value) {
            $previous[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
            $_ENV[$name] = $_SERVER[$name] = $value;
            putenv("{$name}={$value}");
        }

        try {
            return require config_path('portal.php');
        } finally {
            foreach ($previous as $name => [$env, $server, $process]) {
                $env === null ? $_ENV[$name] = '' : $_ENV[$name] = $env;
                $server === null ? $_SERVER[$name] = '' : $_SERVER[$name] = $server;
                $process === false ? putenv($name) : putenv("{$name}={$process}");
            }
        }
    }

    public function test_the_pdf_is_one_letter_portrait_page(): void
    {
        $certificate = app(CertificateGeneratorService::class)->generate($this->member()->memberProfile);

        $this->assertMatchesRegularExpression('#/MediaBox\s*\[\s*0(\.0+)?\s+0(\.0+)?\s+612(\.0+)?\s+792(\.0+)?\s*\]#', Storage::disk($certificate->disk)->get($certificate->path));
    }

    // PNG

    public function test_the_png_is_checked_issued_and_audited_before_the_browser_draws_it(): void
    {
        $this->travelTo('2026-11-05 09:00:00');
        $member = $this->member();
        $profile = $member->memberProfile;

        $this->actingAs($member)->postJson(route('member.certificate.png'), ['member_id' => '0', 'join_date' => '1', 'qr' => '1'])
            ->assertOk()
            ->assertExactJson(['filename' => 'ProPerX-membership-certificate-'.$profile->certificate_code.'.png', 'issued_on' => 'Nov 5, 2026']);

        $log = AuditLog::where('action', 'certificate_generated')->sole();
        $this->assertSame(['member_id' => false, 'join_date' => true, 'qr' => true, 'term' => $log->details['term'], 'format' => 'png'], $log->details);
        $this->assertSame(1, CertificateIssue::query()->count());

        // The PDF of the same term prints the same date later.
        $this->travelTo('2026-12-01 09:00:00');
        $this->assertStringContainsString('Nov 5, 2026', view('pdf.certificate-body', app(CertificateGeneratorService::class)->document($profile->fresh()))->render());
    }

    public function test_a_member_without_a_certificate_gets_no_png(): void
    {
        $this->actingAs($this->canceled())->postJson(route('member.certificate.png'))->assertStatus(422)->assertJsonStructure(['message']);
        $this->assertSame(0, AuditLog::where('action', 'certificate_generated')->count());
    }

    public function test_the_page_offers_the_png_and_says_the_pdf_is_letter_size(): void
    {
        $content = $this->actingAs($this->member())->get(route('member.certificate'))->assertOk()
            ->assertSee('Downloaded PDF is Letter size, print-ready')
            ->assertSee('data-png-url="'.route('member.certificate.png').'"', false)
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/name="format" value="png"\s+disabled/', $content);
    }

    public function test_looking_at_the_page_generates_and_audits_nothing(): void
    {
        $member = $this->member();

        $this->actingAs($member)->get(route('member.certificate'))->assertOk();

        $this->assertSame(0, AuditLog::where('action', 'certificate_generated')->count());
        Storage::disk(config('portal.certificates.disk'))->assertDirectoryEmpty('/');
    }

    public function test_it_runs_under_the_strict_csp(): void
    {
        $content = $this->actingAs($this->member())->get(route('member.certificate'))->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bnonce=)[^>]*>/', $content);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+="/', $content);
        $this->assertStringNotContainsString('http://cdn', $content);
    }

    public function test_the_download_from_the_page_gives_the_pdf(): void
    {
        $member = $this->member();
        $this->actingAs($member);

        $signed = $this->get(route('certificates.open', $member->memberProfile))->assertRedirect()->headers->get('Location');

        $this->get($signed)->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertSame(1, AuditLog::where('action', 'certificate_downloaded')->where('user_id', $member->id)->count());
    }

    public function test_a_canceled_membership_gets_an_explanation_instead(): void
    {
        config(['portal.payments.payment_url' => 'https://pay.example.test/portal']);
        $member = $this->canceled();
        $profile = $member->memberProfile;

        $this->actingAs($member)->get(route('member.certificate'))->assertOk()
            ->assertSee('Certificate unavailable')
            ->assertSee('while the membership is inactive')
            ->assertSee('Canceled')
            ->assertSee('ppx-cert__renew', false)
            ->assertSee('https://pay.example.test/portal', false)
            ->assertSee(route('member.profile'), false)
            ->assertDontSee('CERTIFICATE OF MEMBERSHIP')
            ->assertDontSee('Generate & download')
            ->assertDontSee($profile->certificate_code)
            ->assertDontSee(route('certificates.open', $profile), false);

        $this->get(route('certificates.open', $profile))->assertStatus(422);
    }

    public function test_an_expired_license_or_incomplete_profile_gets_the_reason(): void
    {
        $this->actingAs($this->member('expired'))->get(route('member.certificate'))->assertOk()
            ->assertSee('the license expired on')
            ->assertDontSee('ppx-cert__renew', false)
            ->assertDontSee('Generate & download');

        $incomplete = $this->member();
        $incomplete->memberProfile->forceFill(['license_expires_at' => null])->saveQuietly();

        $this->actingAs($incomplete->fresh())->get(route('member.certificate'))->assertOk()
            ->assertSee('license expiry date')
            ->assertDontSee('Generate & download');
    }

    public function test_an_ended_period_gets_no_certificate_also_during_the_grace_period(): void
    {
        config(['portal.payments.payment_url' => 'https://pay.example.test/portal']);
        $member = $this->member();
        $member->memberProfile->forceFill([
            'payment_status' => PaymentStatus::PastDue,
            'payment_status_updated_at' => now()->subDay(), // inside the grace period
            'paid_through_at' => now()->subDays(3),
            'license_expires_at' => now()->addYear(),
        ])->saveQuietly();
        $member = $member->fresh();
        $profile = $member->memberProfile;

        $this->assertFalse($profile->hasRestrictedMembership());

        $this->actingAs($member)->get(route('member.certificate'))->assertOk()
            ->assertSee('Your membership period has ended — renew to get your certificate.')
            ->assertSee('ppx-cert__renew', false)
            ->assertSee('https://pay.example.test/portal', false)
            ->assertDontSee('Generate & download')
            ->assertDontSee('CERTIFICATE OF MEMBERSHIP')
            ->assertDontSee($profile->certificate_code);

        // Nor by the direct routes, whatever is cached.
        $this->get(route('certificates.open', $profile))->assertStatus(422);
        $this->get(CertificateGeneratorService::downloadUrl($profile))->assertStatus(422);
        $this->assertSame(0, AuditLog::where('action', 'certificate_downloaded')->count());

        $this->get(route('certificates.verify', $profile->certificate_code))->assertOk()
            ->assertSee('This certificate has expired')
            ->assertDontSee('Valid certificate');

        // The rest stays open during the grace period.
        $this->get(route('member.library'))->assertOk();
    }

    public function test_without_a_payment_page_the_explanation_stays_without_a_button(): void
    {
        config(['portal.payments.payment_url' => null]);
        $member = $this->member();
        $member->memberProfile->forceFill(['paid_through_at' => now()->subDays(3), 'license_expires_at' => now()->addYear()])->saveQuietly();

        $this->actingAs($member->fresh())->get(route('member.certificate'))->assertOk()
            ->assertSee('Your membership period has ended')
            ->assertDontSee('ppx-cert__renew', false);
    }

    public function test_an_ended_agency_period_only_the_principal_can_renew(): void
    {
        config(['portal.payments.payment_url' => 'https://pay.example.test/portal']);
        $agency = $this->agency(2);
        $agency->forceFill(['paid_through_at' => now()->subDays(3)])->saveQuietly();
        $person = $agency->members()->where('user_id', '!=', $agency->principal_user_id)->first()->user;

        $this->actingAs($agency->principal()->first())->get(route('member.certificate'))->assertOk()
            ->assertSee('Your membership period has ended')
            ->assertSee('ppx-cert__renew', false);

        $this->actingAs($person)->get(route('member.certificate'))->assertOk()
            ->assertSee('Your agency principal can renew it.')
            ->assertDontSee('ppx-cert__renew', false);
    }

    public function test_the_last_day_of_the_period_still_counts(): void
    {
        $member = $this->member();
        $member->memberProfile->forceFill(['paid_through_at' => today(), 'license_expires_at' => now()->addYear()])->saveQuietly();

        $this->actingAs($member->fresh())->get(route('member.certificate'))->assertOk()->assertSee('Generate & download');
    }

    public function test_agency_people_get_it_as_before_with_the_agencys_date(): void
    {
        $agency = $this->agency(2);
        $agency->forceFill(['paid_through_at' => '2027-08-31 00:00:00'])->saveQuietly();
        $person = $agency->members()->where('user_id', '!=', $agency->principal_user_id)->first();
        $person->forceFill(['person_type' => PersonType::AgencyEmployee, 'license_expires_at' => null, 'npn' => null])->saveQuietly();

        $this->actingAs($person->user)->get(route('member.certificate'))->assertOk()
            ->assertSee('CERTIFICATE OF MEMBERSHIP')
            ->assertSee('Aug 31, 2027')
            ->assertSee('Generate & download')
            ->assertDontSee('href="'.route('member.license-documents').'"', false);
    }

    public function test_agency_people_without_a_paid_date_get_no_valid_through(): void
    {
        $agency = $this->agency(2);
        $agency->forceFill(['paid_through_at' => null])->saveQuietly();

        $this->actingAs($agency->principal()->first())->get(route('member.certificate'))->assertOk()
            ->assertSee('CERTIFICATE OF MEMBERSHIP')
            ->assertDontSee('VALID THROUGH')
            ->assertSee('privileges of membership.');
    }

    public function test_guests_and_staff_do_not_reach_it_and_nobody_gets_someone_elses(): void
    {
        $this->get(route('member.certificate'))->assertRedirect();

        $this->actingAs($this->staff(Role::MembershipCoordinator))->get(route('member.certificate'))->assertRedirect('/nova');

        $other = $this->member();
        $this->actingAs($this->member())->get(route('certificates.open', $other->memberProfile))->assertForbidden();
    }

    public function test_it_is_closed_while_the_member_area_is_off(): void
    {
        config(['portal.member_area.enabled' => false]);

        $this->actingAs($this->member())->get('/member/certificate')->assertNotFound();
    }
}
