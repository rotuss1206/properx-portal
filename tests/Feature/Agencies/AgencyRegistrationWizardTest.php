<?php

namespace Tests\Feature\Agencies;

use App\Livewire\AgencyRegistration\Wizard;
use App\Models\Agency;
use App\Models\AgencyRegistrationDraft;
use App\Models\MemberProfile;
use App\Models\PartnerCode;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AgencyRegistrationWizardTest extends TestCase
{
    use RefreshDatabase;

    private const AGENCY = [
        'legal_name' => 'Lone Star Benefits Group LLC',
        'dba' => '',
        'agency_type' => 'health_and_life',
        'agency_npn' => '',
        'fein' => '74-1234567',
        'legal_address' => ['line1' => '400 Congress Ave', 'line2' => 'Suite 210', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78701'],
        'phone' => '(512) 555-0142',
        'website' => 'www.lonestarbenefits.example',
        'billing_email' => 'Billing@LoneStarBenefits.example',
        'referral_source' => 'partner',
    ];

    private const PRINCIPAL = [
        'first_name' => 'Dana',
        'last_name' => 'Whitfield',
        'title' => 'owner',
        'email' => 'dana@lonestarbenefits.example',
        'mobile_phone' => '512-555-0199',
        'npn' => '18273645',
        'license_state' => 'TX',
        'authority_confirmed' => true,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Before the founding cutoff (2027-01-01 00:00 Eastern).
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'America/New_York'));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function fill(Testable $wizard, string $form, array $values): Testable
    {
        foreach ($values as $key => $value) {
            $wizard->set("{$form}.{$key}", $value);
        }

        return $wizard;
    }

    /**
     * A wizard on $step with every earlier screen completed.
     */
    private function wizardAt(int $step): Testable
    {
        $wizard = Livewire::test(Wizard::class);

        if ($step > 1) {
            $wizard->set('membership.type', 'agency')->call('next');
        }
        if ($step > 2) {
            $wizard->set('tier.tier', 'agency_2to9')->call('next');
        }
        if ($step > 3) {
            $this->fill($wizard, 'agency', self::AGENCY)->call('next');
        }
        if ($step > 4) {
            $this->fill($wizard, 'principal', self::PRINCIPAL)->call('next');
        }

        return $wizard->assertHasNoErrors()->assertSet('step', $step);
    }

    // Page, CSP, rate limits

    public function test_the_page_is_public_and_works_with_the_strict_csp(): void
    {
        $response = $this->get('/join/agency')->assertOk();

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString('unsafe-eval', $csp);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[^']+'/", $csp);

        // The CSP-safe Livewire build, served by Livewire's route, with the request's nonce.
        preg_match("/'nonce-([^']+)'/", $csp, $nonce);
        $response->assertSee('-csp" nonce="'.$nonce[1].'"', false);
        $response->assertSee('Who is joining?');
        $response->assertSee('1. Membership type');
        $response->assertSee('6. Review &amp; payment', false);
    }

    public function test_page_loads_and_livewire_round_trips_are_rate_limited(): void
    {
        $this->assertContains('throttle:agency-registration', Route::getRoutes()->getByName('join.agency')->middleware());
        $this->assertContains('throttle:livewire', Route::getRoutes()->getByName('portal.livewire.update')->middleware());

        config(['portal.registration.page_requests_per_minute' => 2]);

        $this->get('/join/agency')->assertOk();
        $this->get('/join/agency')->assertOk();
        $this->get('/join/agency')->assertTooManyRequests();
    }

    // Screen 1 · Membership type and partner code

    public function test_just_me_links_to_the_individual_application(): void
    {
        config(['portal.registration.individual_url' => 'https://www.properx.example/join']);

        Livewire::test(Wizard::class)
            ->assertSeeHtml('href="https://www.properx.example/join"')
            ->assertSee('My agency (2 or more)');
    }

    public function test_screen_1_requires_the_agency_choice(): void
    {
        Livewire::test(Wizard::class)
            ->call('next')
            ->assertHasErrors(['membership.type' => 'required'])
            ->assertSee('Choose who is joining.')
            ->assertSet('step', 1)
            ->set('membership.type', 'individual')
            ->call('next')
            ->assertHasErrors(['membership.type' => 'in'])
            ->set('membership.type', 'agency')
            ->call('next')
            ->assertHasNoErrors()
            ->assertSet('step', 2);
    }

    public function test_a_valid_partner_code_passes_and_is_normalized(): void
    {
        PartnerCode::factory()->create(['code' => 'FMO-2026', 'expires_at' => now()->addMonth()]);

        Livewire::test(Wizard::class)
            ->set('membership.type', 'agency')
            ->set('membership.partner_code', '  fmo-2026 ')
            ->call('next')
            ->assertHasNoErrors()
            ->assertSet('membership.partner_code', 'FMO-2026')
            ->assertSet('step', 2);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPartnerCodes(): array
    {
        return ['expired' => ['OLD-1'], 'inactive' => ['OFF-1'], 'unknown' => ['NOPE-1']];
    }

    #[DataProvider('invalidPartnerCodes')]
    public function test_an_invalid_partner_code_blocks_continue(string $code): void
    {
        PartnerCode::factory()->expired()->create(['code' => 'OLD-1']);
        PartnerCode::factory()->inactive()->create(['code' => 'OFF-1']);

        Livewire::test(Wizard::class)
            ->set('membership.type', 'agency')
            ->set('membership.partner_code', $code)
            ->call('next')
            ->assertHasErrors('membership.partner_code')
            ->assertSee('This partner code is not valid or has expired.')
            ->assertSet('step', 1);
    }

    public function test_partner_query_parameter_fills_the_code(): void
    {
        Livewire::withQueryParams(['partner' => 'fmo-2026'])
            ->test(Wizard::class)
            ->assertSet('membership.partner_code', 'FMO-2026')
            ->assertSet('step', 1);
    }

    // Screen 2 · Tier

    public function test_screen_2_requires_a_tier_and_shows_founding_rates_before_the_cutoff(): void
    {
        $this->wizardAt(2)
            ->assertSee(['Agency', 'Agency Plus', 'Agency Enterprise'])
            ->assertSee(['$159', '$149', '$129'])
            ->assertSee('2–9 people')
            ->assertSee('50+ people')
            ->assertSee('Founding rate')
            ->assertSeeHtml("Not sure? Pick the closest one. We'll adjust it to the number of people you add.")
            ->call('next')
            ->assertHasErrors(['tier.tier' => 'required'])
            ->set('tier.tier', 'agency_99')
            ->call('next')
            ->assertHasErrors('tier.tier')
            ->set('tier.tier', 'agency_10to49')
            ->call('next')
            ->assertHasNoErrors()
            ->assertSet('step', 3);
    }

    public function test_standard_rates_after_the_founding_cutoff(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:00', 'America/New_York'));

        $this->wizardAt(2)
            ->assertSee(['$179', '$159', '$139'])
            ->assertSee('Standard rate')
            ->assertDontSee('Founding rate (locked)');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function tierLinks(): array
    {
        return [
            'value' => ['agency_10to49', 'agency_10to49'],
            'label slug' => ['agency-enterprise', 'agency_50plus'],
        ];
    }

    #[DataProvider('tierLinks')]
    public function test_tier_query_parameter_preselects_the_tier_and_opens_screen_2(string $query, string $tier): void
    {
        Livewire::withQueryParams(['tier' => $query])
            ->test(Wizard::class)
            ->assertSet('tier.tier', $tier)
            ->assertSet('membership.type', 'agency')
            ->assertSet('step', 2)
            ->call('next')
            ->assertHasNoErrors()
            ->assertSet('step', 3);
    }

    public function test_an_unknown_tier_query_parameter_is_ignored(): void
    {
        Livewire::withQueryParams(['tier' => 'platinum'])
            ->test(Wizard::class)
            ->assertSet('tier.tier', '')
            ->assertSet('step', 1);
    }

    // Price block

    public function test_price_block_follows_the_tier_with_the_founding_rate_locked(): void
    {
        $wizard = $this->wizardAt(2)
            ->assertSee('2 people · Agency · $159 each · $318 a year')
            ->assertSee('Founding rate (locked)');

        $wizard->set('tier.tier', 'agency_10to49')
            ->assertSee('10 people · Agency Plus · $149 each · $1,490 a year');

        $wizard->set('tier.tier', 'agency_50plus')
            ->assertSee('50 people · Agency Enterprise · $129 each · $6,450 a year');
    }

    public function test_price_block_applies_a_valid_partner_code(): void
    {
        PartnerCode::factory()->create(['code' => 'MGA-7']);

        $wizard = Livewire::withQueryParams(['partner' => 'MGA-7', 'tier' => 'agency_2to9'])
            ->test(Wizard::class)
            ->assertSee('2 people · Agency · $149 each · $298 a year')
            ->assertSee('Partner rate applied.');

        $wizard->set('tier.tier', 'agency_50plus')
            ->assertSee('$129 each')
            ->assertSee("Your agency's rate is lower than the partner rate, so we applied it.");
    }

    // Screen 3 · Agency

    public function test_screen_3_validates_the_required_fields(): void
    {
        $this->wizardAt(3)
            ->call('next')
            ->assertHasErrors([
                'agency.legal_name' => 'required',
                'agency.agency_type' => 'required',
                'agency.legal_address.line1' => 'required',
                'agency.legal_address.city' => 'required',
                'agency.legal_address.state' => 'required',
                'agency.legal_address.zip' => 'required',
                'agency.phone' => 'required',
                'agency.billing_email' => 'required',
            ])
            ->assertHasNoErrors(['agency.dba', 'agency.agency_npn', 'agency.fein', 'agency.website', 'agency.referral_source', 'agency.mailing_address.line1'])
            ->assertSet('step', 3)
            ->assertSeeHtml('aria-invalid="true"')
            ->assertSeeHtml('id="agency-legal_name-error"');
    }

    public function test_screen_3_accepts_a_complete_agency_and_normalizes_it(): void
    {
        $wizard = $this->wizardAt(4);

        $wizard->assertSet('agency.website', 'https://www.lonestarbenefits.example')
            ->assertSet('agency.billing_email', 'billing@lonestarbenefits.example')
            ->assertSet('agency.mailing_same_as_legal', true);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function poBoxes(): array
    {
        return [
            ['PO Box 1234'], ['P.O. Box 77'], ['p o box 9'], ['Post Office Box 5'], ['POB 300'], ['P.O.B. 12'], ['Box 123'],
        ];
    }

    #[DataProvider('poBoxes')]
    public function test_the_legal_address_cannot_be_a_po_box(string $street): void
    {
        $this->fill($this->wizardAt(3), 'agency', self::AGENCY)
            ->set('agency.legal_address.line1', $street)
            ->call('next')
            ->assertHasErrors('agency.legal_address.line1')
            ->assertSee('must be a street address, not a PO box')
            ->assertSet('step', 3);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function streetsThatAreNotBoxes(): array
    {
        return [['12 Boxwood Lane'], ['9 Polo Drive'], ['300 Pobst Road'], ['5 Box Elder Street']];
    }

    #[DataProvider('streetsThatAreNotBoxes')]
    public function test_street_names_that_only_look_like_a_box_are_accepted(string $street): void
    {
        $this->fill($this->wizardAt(3), 'agency', self::AGENCY)
            ->set('agency.legal_address.line1', $street)
            ->call('next')
            ->assertHasNoErrors()
            ->assertSet('step', 4);
    }

    public function test_mailing_address_is_required_only_when_it_differs(): void
    {
        $wizard = $this->fill($this->wizardAt(3), 'agency', self::AGENCY)
            ->assertSeeHtml('wire:model.live="agency.mailing_same_as_legal"')
            ->set('agency.mailing_same_as_legal', false)
            ->assertSee('Mailing address')
            ->call('next')
            ->assertHasErrors(['agency.mailing_address.line1' => 'required', 'agency.mailing_address.zip' => 'required']);

        // A mailing address may be a PO box.
        $this->fill($wizard, 'agency', ['mailing_address' => ['line1' => 'PO Box 88', 'line2' => '', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78767']])
            ->call('next')
            ->assertHasNoErrors()
            ->assertSet('step', 4);
    }

    public function test_screen_3_checks_formats_and_existing_agency_identifiers(): void
    {
        Agency::factory()->create(['fein' => '74-7654321', 'agency_npn' => '9988776']);

        $this->fill($this->wizardAt(3), 'agency', self::AGENCY)
            ->set('agency.fein', '123')
            ->set('agency.agency_npn', '12ab')
            ->set('agency.phone', '555-0142')
            ->set('agency.legal_address.zip', '7870')
            ->set('agency.billing_email', 'not-an-email')
            ->set('agency.agency_type', 'banking')
            ->set('agency.referral_source', 'tv')
            ->call('next')
            ->assertHasErrors(['agency.fein', 'agency.agency_npn', 'agency.phone', 'agency.legal_address.zip', 'agency.billing_email', 'agency.agency_type', 'agency.referral_source'])
            ->set('agency.fein', '747654321')
            ->set('agency.agency_npn', '9988776')
            ->call('next')
            ->assertSee('This FEIN is already registered in the portal. Please contact ProPerx.')
            ->assertSee('This agency NPN is already registered in the portal. Please contact ProPerx.')
            ->assertSet('step', 3);
    }

    // Screen 4 · Principal

    public function test_screen_4_validates_the_required_fields_and_leaves_sms_consent_optional(): void
    {
        $this->wizardAt(4)
            ->assertSet('principal.sms_consent', false)
            ->assertSet('principal.authority_confirmed', false)
            ->assertSee(['Owner', 'President', 'Principal', 'Managing Partner'])
            ->call('next')
            ->assertHasErrors([
                'principal.first_name' => 'required',
                'principal.last_name' => 'required',
                'principal.title' => 'required',
                'principal.email' => 'required',
                'principal.mobile_phone' => 'required',
                'principal.npn' => 'required',
                'principal.license_state' => 'required',
                'principal.authority_confirmed' => 'accepted',
            ])
            ->assertHasNoErrors('principal.sms_consent')
            ->assertSee('Please confirm that you are authorized to enroll this agency.');

        $this->wizardAt(5)->assertSet('principal.sms_consent', false);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidNpns(): array
    {
        return ['letters' => ['12ab5678'], 'too short' => ['123456'], 'too long' => ['12345678901'], 'dashes' => ['1234-5678']];
    }

    #[DataProvider('invalidNpns')]
    public function test_npn_must_be_7_to_10_digits(string $npn): void
    {
        $this->fill($this->wizardAt(4), 'principal', self::PRINCIPAL)
            ->set('principal.npn', $npn)
            ->call('next')
            ->assertHasErrors(['principal.npn' => 'regex'])
            ->assertSee('The NPN must be 7 to 10 digits, numbers only.');
    }

    #[DataProvider('validNpns')]
    public function test_npn_of_7_to_10_digits_is_accepted(string $npn): void
    {
        $this->fill($this->wizardAt(4), 'principal', self::PRINCIPAL)
            ->set('principal.npn', $npn)
            ->call('next')
            ->assertHasNoErrors()
            ->assertSet('step', 5);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validNpns(): array
    {
        return ['7 digits' => ['1234567'], '10 digits' => ['1234567890']];
    }

    public function test_an_email_already_in_the_portal_is_blocked(): void
    {
        User::factory()->create(['email' => 'dana@lonestarbenefits.example']);

        $this->fill($this->wizardAt(4), 'principal', self::PRINCIPAL)
            ->set('principal.email', ' Dana@LoneStarBenefits.example ')
            ->call('next')
            ->assertHasErrors('principal.email')
            ->assertSee('This email is already registered in the portal. Please contact ProPerx.')
            ->assertSet('step', 4);
    }

    public function test_an_npn_already_in_the_portal_is_blocked_through_the_blind_index(): void
    {
        MemberProfile::factory()->create(['npn' => '18273645']);

        $this->fill($this->wizardAt(4), 'principal', self::PRINCIPAL)
            ->call('next')
            ->assertHasErrors('principal.npn')
            ->assertSee('This NPN is already registered in the portal. Please contact ProPerx.')
            ->assertSet('step', 4);
    }

    // Draft, navigation

    public function test_continue_saves_an_encrypted_draft_referenced_from_the_session(): void
    {
        $this->wizardAt(5);

        $draft = AgencyRegistrationDraft::query()->sole();
        $this->assertSame($draft->getKey(), session(Wizard::SESSION_KEY));
        $this->assertSame(5, $draft->step);
        $this->assertSame('Lone Star Benefits Group LLC', $draft->data['agency']['legal_name']);
        $this->assertSame('18273645', $draft->data['principal']['npn']);
        $this->assertTrue(now()->addDays(30)->equalTo($draft->expires_at));

        // At rest nothing is readable.
        $raw = DB::table('agency_registration_drafts')->value('data');
        foreach (['Lone Star', '18273645', 'dana@', '74-1234567', 'Congress'] as $plain) {
            $this->assertStringNotContainsString($plain, $raw);
        }
    }

    public function test_back_keeps_the_answers_without_validating_them(): void
    {
        $wizard = $this->wizardAt(4)
            ->set('principal.first_name', 'Dana')
            ->set('principal.npn', 'not-a-number')
            ->call('back')
            ->assertHasNoErrors()
            ->assertSet('step', 3)
            ->assertSet('agency.legal_name', 'Lone Star Benefits Group LLC')
            ->call('back')
            ->call('back')
            ->assertSet('step', 1)
            ->assertSet('membership.type', 'agency');

        $this->assertSame('not-a-number', AgencyRegistrationDraft::query()->sole()->data['principal']['npn']);

        // Forward again: earlier answers are still there; the bad NPN is caught on screen 4.
        $wizard->call('next')->call('next')->call('next')
            ->assertSet('step', 4)
            ->assertSet('principal.first_name', 'Dana')
            ->call('next')
            ->assertHasErrors('principal.npn');
    }

    public function test_returning_later_in_the_same_session_restores_the_draft(): void
    {
        $this->wizardAt(4)->set('principal.first_name', 'Dana')->call('back');

        Livewire::test(Wizard::class)
            ->assertSet('step', 3)
            ->assertSet('tier.tier', 'agency_2to9')
            ->assertSet('agency.legal_address.city', 'Austin')
            ->assertSet('principal.first_name', 'Dana');

        $this->assertSame(1, AgencyRegistrationDraft::query()->count());
    }

    public function test_an_expired_draft_is_not_restored_and_is_pruned(): void
    {
        $this->wizardAt(3);

        $this->travel(31)->days();

        Livewire::test(Wizard::class)->assertSet('step', 1)->assertSet('membership.type', '');

        $this->artisan('model:prune', ['--model' => [AgencyRegistrationDraft::class]])->assertSuccessful();
        $this->assertSame(0, AgencyRegistrationDraft::query()->count());
    }

    public function test_the_browser_cannot_skip_screens(): void
    {
        $wizard = $this->wizardAt(2);

        $wizard->call('goTo', 4)->assertSet('step', 2);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $wizard->set('step', 4);
    }

    public function test_the_progress_indicator_goes_back_to_completed_screens(): void
    {
        $this->wizardAt(4)
            ->assertSeeHtml('wire:click="goTo(1)"')
            ->assertSeeHtml('aria-current="step"')
            ->assertDontSeeHtml('wire:click="goTo(4)"')
            ->call('goTo', 2)
            ->assertSet('step', 2)
            ->assertSet('furthestStep', 4);
    }

    public function test_screens_5_and_6_are_placeholders_for_now(): void
    {
        $this->wizardAt(5)
            ->assertSee('Add your people')
            ->call('next')
            ->assertSet('step', 6)
            ->assertSee('Review & payment')
            ->assertDontSeeHtml('type="submit"');
    }

    public function test_nothing_is_logged_while_enrolling(): void
    {
        Log::spy();

        $this->fill($this->wizardAt(4), 'principal', self::PRINCIPAL)->set('principal.npn', '12')->call('next');

        Log::shouldNotHaveReceived('log');
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
    }
}
