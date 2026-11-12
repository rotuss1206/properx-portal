<?php

namespace Tests\Feature\Agencies;

use App\Livewire\AgencyRegistration\PeopleCsv;
use App\Livewire\AgencyRegistration\PeopleForm;
use App\Livewire\AgencyRegistration\Wizard;
use App\Models\Agency;
use App\Models\AgencyRegistrationDraft;
use App\Models\MemberProfile;
use App\Models\PartnerCode;
use App\Models\User;
use App\Notifications\AgencyEnrollmentContinueLink;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
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

        config(['portal.registration.enabled' => true]);

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
     *
     * @param  array<string, string>  $query
     */
    private function wizardAt(int $step, array $query = []): Testable
    {
        $wizard = Livewire::withQueryParams($query)->test(Wizard::class);

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
        if ($step > 5) {
            $this->fill($wizard, 'people.entry', self::EMPLOYEE)->call('savePerson')->call('next');
        }

        return $wizard->assertHasNoErrors()->assertSet('step', $step);
    }

    // Page, CSP, rate limits

    public function test_the_page_is_public_and_works_with_the_strict_csp(): void
    {
        // Enabled in setUp (AGENCY_ENROLLMENT_ENABLED=true).
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

    public function test_the_wizard_is_a_404_while_enrollment_is_off(): void
    {
        config(['portal.registration.enabled' => false]);

        $this->get('/join/agency')->assertNotFound()->assertDontSee('Who is joining?');

        Livewire::test(Wizard::class)->assertStatus(404);

        // A page opened before enrollment was switched off: its next round trip is refused too.
        config(['portal.registration.enabled' => true]);
        $wizard = Livewire::test(Wizard::class)->assertOk();
        config(['portal.registration.enabled' => false]);
        $wizard->call('next')->assertStatus(404);
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

    public function test_just_me_is_a_link_to_the_marketing_site_not_a_choice(): void
    {
        config(['portal.registration.individual_url' => 'https://www.properx.example/join']);

        Livewire::test(Wizard::class)
            ->assertSeeHtml('<a href="https://www.properx.example/join" class="external">Apply as an individual on properx.org')
            ->assertDontSee('Just me')
            ->assertSeeHtml('class="choices choices-single"');

        config(['portal.registration.individual_url' => null]);

        Livewire::test(Wizard::class)
            ->assertSee('Individual membership applications are on properx.org.')
            ->assertDontSeeHtml('class="external"');
    }

    public function test_an_invalid_partner_code_says_it_may_be_left_empty(): void
    {
        Livewire::test(Wizard::class)->set('membership.type', 'agency')->set('membership.partner_code', 'NOPE-1')->call('next')
            ->assertHasErrors('membership.partner_code')
            ->assertSee("Leave it empty if you don't have one.");
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

    public function test_screen_3_is_in_titled_blocks_with_hints_under_the_fields(): void
    {
        $html = $this->wizardAt(3)
            ->assertSeeInOrder(['Agency details', 'Legal agency name', 'DBA', 'Agency type', 'Agency NPN', 'FEIN', 'Legal address', 'Street address', 'Mailing address same as legal address', 'Contact &amp; billing', 'Main phone', 'Website', 'Billing email', 'How did you hear about us?'], false)
            ->html();

        // The hint follows its input, so fields side by side start at the same height.
        // (Livewire marks @if blocks with comments in between.)
        $between = '(\s|<!--\[if BLOCK\]><!\[endif\]-->)*';
        $this->assertMatchesRegularExpression('#id="agency-agency_npn"[^>]*>'.$between.'<p class="hint" id="agency-agency_npn-hint">#', $html);
        $this->assertMatchesRegularExpression('#id="agency-legal_address-line1"[^>]*>'.$between.'<p class="hint"[^>]*>A street address\. PO boxes are not accepted\.#', $html);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function feins(): iterable
    {
        yield 'with the hyphen' => ['74-1234567', '74-1234567'];
        yield 'digits only' => ['741234567', '74-1234567'];
        yield 'with a space' => ['74 1234567', '74-1234567'];
    }

    #[DataProvider('feins')]
    public function test_the_fein_is_accepted_with_or_without_the_hyphen_and_kept_with_it(string $typed, string $kept): void
    {
        $this->fill($this->wizardAt(3), 'agency', ['fein' => $typed] + self::AGENCY)
            ->call('next')
            ->assertHasNoErrors()
            ->assertSet('agency.fein', $kept);
    }

    public function test_the_agency_npn_is_numbers_only_up_to_10_digits(): void
    {
        $wizard = $this->wizardAt(3);

        foreach (['12a', '12345678901', '12 34'] as $npn) {
            $this->fill($wizard, 'agency', ['agency_npn' => $npn] + self::AGENCY)->call('next')
                ->assertHasErrors('agency.agency_npn')
                ->assertSee('The agency NPN is numbers only, up to 10 digits.');
        }

        $this->fill($wizard, 'agency', ['agency_npn' => '7'] + self::AGENCY)->call('next')->assertHasNoErrors()->assertSet('step', 4);
    }

    public function test_the_website_must_be_a_real_address_and_gets_https(): void
    {
        $wizard = $this->wizardAt(3);

        foreach (['not a site', 'localhost', 'https://intranet', 'www.example .com'] as $website) {
            $this->fill($wizard, 'agency', ['website' => $website] + self::AGENCY)->call('next')
                ->assertHasErrors('agency.website')
                ->assertSee('Enter a web address such as www.example.com.');
        }

        $this->fill($wizard, 'agency', ['website' => 'lonestarbenefits.example/team?x=1'] + self::AGENCY)->call('next')
            ->assertHasNoErrors()
            ->assertSet('agency.website', 'https://lonestarbenefits.example/team?x=1');
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
            ->assertSee('This FEIN is already registered in the portal. Please contact ProPerX.')
            ->assertSee('This agency NPN is already registered in the portal. Please contact ProPerX.')
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
        return ['letters' => ['12ab5678'], 'too long' => ['12345678901'], 'dashes' => ['1234-5678'], 'too short' => ['123456'], '1 digit' => ['1']];
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
        return ['7 digits' => ['1234567'], '8 digits' => ['12345678'], '10 digits' => ['1234567890']];
    }

    public function test_an_email_already_in_the_portal_is_blocked(): void
    {
        User::factory()->create(['email' => 'dana@lonestarbenefits.example']);

        $this->fill($this->wizardAt(4), 'principal', self::PRINCIPAL)
            ->set('principal.email', ' Dana@LoneStarBenefits.example ')
            ->call('next')
            ->assertHasErrors('principal.email')
            ->assertSee('This email is already registered in the portal. Please contact ProPerX.')
            ->assertSet('step', 4);
    }

    public function test_an_npn_already_in_the_portal_is_blocked_through_the_blind_index(): void
    {
        MemberProfile::factory()->create(['npn' => '18273645']);

        $this->fill($this->wizardAt(4), 'principal', self::PRINCIPAL)
            ->call('next')
            ->assertHasErrors('principal.npn')
            ->assertSee('This NPN is already registered in the portal. Please contact ProPerX.')
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

    // Screen 6 · Review & payment

    public function test_the_review_sums_up_the_enrollment_at_the_founding_rate(): void
    {
        $this->wizardAt(6)
            ->assertSee('Review & payment')
            ->assertSeeInOrder([
                'Membership type', 'Agency membership', 'Tier', 'Agency', 'Price per person', '$159 a year', 'Founding rate, locked while you stay a member',
                'Legal name', 'Lone Star Benefits Group LLC', 'Agency type', 'Health and Life', 'Legal address', '400 Congress Ave, Suite 210, Austin, TX 78701',
                'Main phone', '(512) 555-0142', 'Billing email', 'billing@lonestarbenefits.example',
                'Dana Whitfield', 'Owner', 'dana@lonestarbenefits.example', '18273645', 'Texas',
                '2 people: 1 licensed producer (the principal included), 1 agency employee', 'Priya Nair', 'Agency employee',
                'Total for the year', '$318', 'Credit cards add 2.9%; debit cards and bank transfers (ACH) do not.',
            ])
            ->assertDontSee('Partner code')
            ->assertDontSeeHtml('type="submit"')
            // Without Stripe keys the page says payment is not set up rather than breaking.
            ->assertSee('Online payment is not set up yet.')
            ->assertDontSeeHtml('data-join-payment')
            // "Edit" goes back to the screen.
            ->call('goTo', 3)
            ->assertSet('step', 3);
    }

    public function test_the_review_shows_the_standard_rate_after_the_cutoff(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-02-01 10:00', 'America/New_York'));

        $this->wizardAt(6)
            ->assertSee('$179 a year')
            ->assertSee('Standard rate')
            ->assertDontSee('Founding rate')
            ->assertSee('$358');
    }

    public function test_the_review_shows_the_partner_code_and_rate(): void
    {
        PartnerCode::factory()->create(['code' => 'MGA-7']);

        $this->wizardAt(6, ['partner' => 'MGA-7'])
            ->assertSeeInOrder(['Price per person', '$149 a year', 'Partner rate', 'Partner code', 'MGA-7', 'Partner rate applied', 'Total for the year', '$298']);
    }

    public function test_the_four_consents_are_required_and_their_times_are_kept(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:30:00', 'America/New_York'));
        config(['services.stripe.key' => 'pk_test_fake', 'services.stripe.secret' => 'sk_test_fake']);

        $wizard = $this->wizardAt(6)
            ->assertSeeInOrder([
                'I agree to the',
                'I understand that membership dues are separate from health plan premiums billed by the plan administrator, and that ProPerX does not sell insurance.',
                'I understand that membership is billed annually, dues are non-refundable, and a member whose membership is canceled cannot reapply for 24 months.',
                'I confirm that every person listed is either a licensed agent or an employee of this agency.',
            ])
            ->assertSeeHtml('<a href="https://properx.org/terms-of-service/" target="_blank" rel="noopener">Terms of Service')
            ->assertSeeHtml('<a href="https://properx.org/privacy-policy/" target="_blank" rel="noopener">Privacy Policy')
            ->assertSeeHtml('wire:key="pay-blocked"')
            ->assertSee('Tick all four boxes above to choose how to pay.')
            ->assertDontSeeHtml('data-join-payment')
            // A forged call is checked too, before Stripe is asked anything.
            ->call('preparePayment', 'ctoken_forged')
            ->assertHasErrors(['review.terms' => 'accepted', 'review.dues_separate' => 'accepted', 'review.annual_no_refund' => 'accepted', 'review.roster_confirmed' => 'accepted'])
            ->assertSet('checkout', []);
        $this->assertSame([], $this->stripe->requests);

        $wizard->set('review.terms', true)
            ->assertSet('consentsGivenAt', ['terms' => now()->toIso8601String()]);
        $this->assertSame(['version' => 1, 'given_at' => ['terms' => now()->toIso8601String()]], AgencyRegistrationDraft::query()->sole()->data['consents']);

        $wizard->set('review.terms', false)->assertSet('consentsGivenAt', []);

        foreach (['terms', 'dues_separate', 'annual_no_refund', 'roster_confirmed'] as $key) {
            $wizard->set("review.{$key}", true);
        }

        // The Payment Element appears, with the dues for Stripe.js to show.
        $wizard->assertDontSee('Tick all four boxes above to choose how to pay.')
            ->assertSeeHtml('data-join-payment data-key="pk_test_fake" data-amount="31800" data-currency="usd"')
            ->assertSeeHtml('data-join-pay-review');

        $this->assertCount(4, AgencyRegistrationDraft::query()->sole()->data['consents']['given_at']);
    }

    public function test_the_browser_cannot_write_the_consent_times(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        $this->wizardAt(6)->set('consentsGivenAt', ['terms' => '2020-01-01T00:00:00+00:00']);
    }

    public function test_the_terms_and_privacy_links_come_from_the_settings(): void
    {
        config(['portal.registration.terms_url' => 'https://example.test/terms', 'portal.registration.privacy_url' => 'https://example.test/privacy']);

        $this->wizardAt(6)
            ->assertSeeHtml('href="https://example.test/terms"')
            ->assertSeeHtml('href="https://example.test/privacy"');
    }

    public function test_consents_come_back_with_the_draft_unless_the_wording_changed(): void
    {
        $this->wizardAt(6)->set('review.terms', true)->set('review.roster_confirmed', true);

        Livewire::test(Wizard::class)
            ->assertSet('step', 6)
            ->assertSet('review.terms', true)
            ->assertSet('review.dues_separate', false)
            ->assertSet('review.roster_confirmed', true)
            ->assertCount('consentsGivenAt', 2);

        $draft = AgencyRegistrationDraft::query()->sole();
        $draft->data = ['consents' => ['version' => 0, 'given_at' => $draft->data['consents']['given_at']]] + $draft->data;
        $draft->save();

        Livewire::test(Wizard::class)
            ->assertSet('review.terms', false)
            ->assertSet('consentsGivenAt', []);
    }

    // Save and continue later

    /**
     * @return array{0: Testable, 1: string} the wizard and the link that was emailed
     */
    private function sendLink(Testable $wizard): array
    {
        Notification::fake();
        $wizard->call('openSaveLater')->call('sendContinueLink');

        $url = null;
        Notification::assertSentOnDemand(AgencyEnrollmentContinueLink::class, function (AgencyEnrollmentContinueLink $notification, array $channels, AnonymousNotifiable $notifiable) use (&$url) {
            $url = $notification->url;

            return $notifiable->routes['mail'] === 'dana@lonestarbenefits.example';
        });

        return [$wizard, $url];
    }

    public function test_save_and_continue_later_is_offered_from_screen_3(): void
    {
        $this->wizardAt(2)->assertDontSee('Save and continue later')->call('openSaveLater')->assertSet('saveLaterOpen', false);
        $this->wizardAt(3)->assertSee('Save and continue later');
    }

    public function test_before_screen_4_the_principals_email_is_asked_for(): void
    {
        Notification::fake();

        $wizard = $this->wizardAt(3)->set('agency.legal_name', 'Half done LLC')
            ->call('openSaveLater')
            ->assertSee("Principal's email")
            ->assertDispatched('join-focus', id: 'save-later-title')
            ->call('sendContinueLink')
            ->assertHasErrors(['saveLaterEmail' => 'required'])
            ->set('saveLaterEmail', ' Dana@LoneStarBenefits.example ')
            ->call('sendContinueLink')
            ->assertHasNoErrors()
            ->assertSet('principal.email', 'dana@lonestarbenefits.example')
            ->assertSet('saveLaterSent', true)
            ->assertSee("We've emailed a link to dana@lonestarbenefits.example.");

        Notification::assertSentOnDemandTimes(AgencyEnrollmentContinueLink::class, 1);

        // Saved as it was, unvalidated, on this screen.
        $draft = AgencyRegistrationDraft::query()->sole();
        $this->assertSame('Half done LLC', $draft->data['agency']['legal_name']);
        $this->assertSame(3, $draft->step);
        $wizard->assertSet('step', 3);
    }

    public function test_the_link_opens_the_draft_on_its_screen_once(): void
    {
        [, $url] = $this->sendLink($this->wizardAt(5)->set('principal.first_name', 'Dana'));
        $token = basename($url);

        $this->assertSame(route('join.agency.continue', $token), $url);
        $this->assertSame(AgencyRegistrationDraft::TOKEN_LENGTH, strlen($token));
        $this->assertSame(hash('sha256', $token), AgencyRegistrationDraft::query()->sole()->token_hash);

        // Another browser, without the session.
        $this->flushSession();
        $this->get($url)->assertRedirect(route('join.agency'));
        $this->assertSame(AgencyRegistrationDraft::query()->sole()->getKey(), session(Wizard::SESSION_KEY));

        Livewire::test(Wizard::class)
            ->assertSet('step', 5)
            ->assertSet('furthestStep', 5)
            ->assertSet('agency.legal_name', 'Lone Star Benefits Group LLC')
            ->assertSee('Dana Whitfield');

        // Used: the same link now explains itself.
        $this->flushSession();
        $this->get($url)->assertStatus(410)
            ->assertSee("This link can't be used any more", false)
            ->assertSee('Go to the enrollment')
            ->assertDontSee('Lone Star');
        $this->assertNull(session(Wizard::SESSION_KEY));
    }

    public function test_a_new_link_replaces_the_previous_one(): void
    {
        [$wizard, $first] = $this->sendLink($this->wizardAt(5));

        $this->travel(11)->minutes();
        [, $second] = $this->sendLink($wizard);

        $this->assertNotSame($first, $second);
        $this->get($first)->assertStatus(410);
        $this->get($second)->assertRedirect(route('join.agency'));
    }

    public function test_an_expired_or_unknown_link_explains_itself(): void
    {
        [, $url] = $this->sendLink($this->wizardAt(5));

        $this->travel(31)->days();
        $this->flushSession();
        $this->get($url)->assertStatus(410)->assertSee("This link can't be used any more", false);

        $this->get(route('join.agency.continue', 'not-a-token'))->assertStatus(410);

        config(['portal.registration.enabled' => false]);
        $this->get($url)->assertNotFound();
    }

    public function test_one_email_per_draft_every_10_minutes(): void
    {
        [$wizard] = $this->sendLink($this->wizardAt(5));

        $wizard->call('sendContinueLink')
            ->assertSet('saveLaterSent', false)
            ->assertSee('We emailed a link a short while ago. You can ask for another in 10 minutes.');
        Notification::assertSentOnDemandTimes(AgencyEnrollmentContinueLink::class, 1);

        $this->travel(10)->minutes();
        $this->travel(1)->seconds();
        $wizard->call('sendContinueLink')->assertSet('saveLaterSent', true);
        Notification::assertSentOnDemandTimes(AgencyEnrollmentContinueLink::class, 2);
    }

    public function test_five_emails_an_hour_per_ip(): void
    {
        foreach (range(1, 5) as $i) {
            RateLimiter::hit('agency-continue-link:ip:127.0.0.1', 3600);
        }

        Notification::fake();
        $this->wizardAt(5)->call('openSaveLater')->call('sendContinueLink')
            ->assertSee('Too many links were asked for from this network. Please try again later.');
        Notification::assertNothingSent();
    }

    public function test_the_email_has_only_the_link_and_the_brand(): void
    {
        [, $url] = $this->sendLink($this->wizardAt(6));

        $mail = (new AgencyEnrollmentContinueLink($url))->toMail(new AnonymousNotifiable);
        $html = (string) app(Markdown::class)->render($mail->markdown, $mail->data());

        $this->assertSame('Continue your ProPerX Alliance agency enrollment', $mail->subject);
        $this->assertStringContainsString(e($url), $html);
        $this->assertStringContainsString('src="cid:properx-logo"', $html);
        $this->assertStringContainsString('The link works once.', $html);

        foreach (['Lone Star', 'Dana', 'Whitfield', 'dana@', '18273645', 'Priya', '$318', 'Agency Plus'] as $private) {
            $this->assertStringNotContainsString($private, $html.$mail->subject);
        }
    }

    // Screen 5 · People

    private const PRODUCER = ['type' => 'licensed_producer', 'first_name' => 'Marcus', 'last_name' => 'Reyes', 'email' => 'Marcus@LoneStarBenefits.example', 'npn' => '20304050', 'license_state' => 'TX'];

    private const EMPLOYEE = ['type' => 'agency_employee', 'first_name' => 'Priya', 'last_name' => 'Nair', 'email' => 'priya@lonestarbenefits.example', 'npn' => '', 'license_state' => ''];

    /**
     * @param  array<string, string>  $person
     */
    private function addPerson(Testable $wizard, array $person): Testable
    {
        return $this->fill($wizard, 'people.entry', $person)->call('savePerson');
    }

    /**
     * @return list<array<string, string>>
     */
    private function rows(int $count, int $from = 1): array
    {
        return array_map(fn (int $i) => ['type' => 'agency_employee', 'first_name' => 'Person', 'last_name' => (string) $i, 'email' => "person{$i}@lonestarbenefits.example", 'npn' => '', 'license_state' => ''], range($from, $from + $count - 1));
    }

    public function test_the_principal_is_person_1_and_continue_needs_a_second_person(): void
    {
        config(['portal.registration.individual_url' => 'https://www.properx.example/join']);

        $this->wizardAt(5)
            ->assertSeeInOrder(['Your people', '1 person', 'Dana Whitfield', 'Principal', 'Licensed producer', 'dana@lonestarbenefits.example', 'NPN 18273645', 'Texas', 'Change on step 4'])
            ->assertSee('Agency membership needs at least 2 people.')
            ->assertSeeHtml('href="https://www.properx.example/join">switch to individual membership</a>')
            ->assertSeeHtml('disabled aria-describedby="people-minimum"')
            ->assertSee('Add at least one more person to see your tier and price.')
            ->call('next')
            ->assertHasErrors(['people.rows' => 'min'])
            ->assertSet('step', 5);
    }

    public function test_people_are_added_and_priced_by_the_actual_headcount(): void
    {
        $wizard = $this->wizardAt(5);
        $this->addPerson($wizard, self::PRODUCER)
            ->assertHasNoErrors()
            ->assertSet('peopleStatus', 'Added Marcus Reyes. 2 people.')
            ->assertDispatched('join-focus', id: 'people-entry-first_name')
            // The next person's type is not carried over: it is chosen for each person.
            ->assertSet('people.entry.type', '')
            ->assertDontSee('Individual NPN')
            ->assertSet('people.entry.email', '');

        // An employee's NPN and state are dropped, whatever was typed.
        $this->addPerson($wizard, ['npn' => '99999999', 'license_state' => 'CA'] + self::EMPLOYEE)
            ->assertHasNoErrors()
            ->assertSet('people.rows', [
                ['email' => 'marcus@lonestarbenefits.example'] + self::PRODUCER,
                self::EMPLOYEE,
            ])
            ->assertSee('3 people · Agency · $159 each · $477 a year')
            ->assertSee('For the people on your list')
            ->assertDontSeeHtml('disabled aria-describedby="people-minimum"')
            ->assertSeeInOrder(['Dana Whitfield', 'Marcus Reyes', 'Licensed producer', 'NPN 20304050', 'Priya Nair', 'Agency employee'])
            ->call('next')
            ->assertHasNoErrors()
            ->assertSet('step', 6)
            ->assertSet('tier.tier', 'agency_2to9');
    }

    public function test_a_person_needs_a_type_names_and_email_and_a_producer_an_npn_and_state(): void
    {
        $wizard = $this->wizardAt(5)->call('savePerson')
            ->assertHasErrors(['people.entry.type' => 'required', 'people.entry.first_name' => 'required', 'people.entry.last_name' => 'required', 'people.entry.email' => 'required'])
            ->assertSee('Choose licensed producer or agency employee.');

        $this->addPerson($wizard, ['npn' => '', 'license_state' => '', 'email' => 'not-an-email'] + self::PRODUCER)
            ->assertHasErrors(['people.entry.npn' => 'required', 'people.entry.license_state' => 'required', 'people.entry.email' => 'email'])
            ->assertSee('Enter the NPN of this licensed producer.')
            ->assertSee('Choose the resident license state.')
            ->assertSet('people.rows', []);

        $this->addPerson($wizard, ['npn' => '12ab'] + self::PRODUCER)
            ->assertHasErrors(['people.entry.npn' => 'regex'])
            ->assertSee('The NPN must be 7 to 10 digits, numbers only.');

        // Agency people: at least 7 digits (the client's rule).
        $this->addPerson($wizard, ['npn' => '123456'] + self::PRODUCER)
            ->assertHasErrors(['people.entry.npn'])
            ->assertSee('The NPN must be 7 to 10 digits, numbers only.');

        // An employee needs neither, and the fields are not shown.
        $this->addPerson($wizard, self::EMPLOYEE)->assertHasNoErrors()->assertCount('people.rows', 1);
        $wizard->set('people.entry.type', 'agency_employee')->assertDontSee('Individual NPN')
            ->set('people.entry.type', 'licensed_producer')->assertSee('Individual NPN');
    }

    public function test_email_and_npn_are_unique_within_the_enrollment(): void
    {
        $wizard = $this->addPerson($this->wizardAt(5), self::PRODUCER);

        $this->addPerson($wizard, ['email' => 'DANA@lonestarbenefits.example', 'npn' => '18273645'] + self::PRODUCER)
            ->assertHasErrors(['people.entry.email', 'people.entry.npn'])
            ->assertSee("This is the principal's email. Each person needs their own email.")
            ->assertSee("This is the principal's NPN. Each person needs their own NPN.");

        $this->addPerson($wizard, ['email' => 'marcus@LONESTARBENEFITS.example', 'first_name' => 'Mark'] + self::PRODUCER)
            ->assertSee('This email is already on your list.')
            ->assertSee('This NPN is already on your list.')
            ->assertCount('people.rows', 1);
    }

    public function test_email_and_npn_already_in_the_portal_are_blocked(): void
    {
        User::factory()->create(['email' => 'marcus@lonestarbenefits.example']);
        MemberProfile::factory()->create(['npn' => '20304050']);

        $this->addPerson($this->wizardAt(5), self::PRODUCER)
            ->assertHasErrors(['people.entry.email', 'people.entry.npn'])
            ->assertSee('This email is already registered in the portal. Please contact ProPerX.')
            ->assertSee('This NPN is already registered in the portal. Please contact ProPerX.')
            ->assertSet('people.rows', []);
    }

    public function test_continue_checks_the_whole_list_again(): void
    {
        $wizard = $this->addPerson($this->addPerson($this->wizardAt(5), self::PRODUCER), self::EMPLOYEE);

        // Someone registered in the meantime, and a list forged in the browser.
        User::factory()->create(['email' => 'priya@lonestarbenefits.example']);
        $wizard->set('people.rows.2', ['email' => 'MARCUS@lonestarbenefits.example', 'npn' => '20304050'] + self::PRODUCER)
            ->call('next')
            ->assertSet('step', 5)
            ->assertHasErrors(['people.rows.1.email', 'people.rows.0.email', 'people.rows.2.email', 'people.rows.2.npn'])
            ->assertSee('Email: This email is already registered in the portal. Please contact ProPerX.')
            ->assertSee('Email: This email is already on your list.')
            ->assertSee('NPN: This NPN is already on your list.');

        // Removing the copy fixes the list, apart from the new account.
        $wizard->call('removePerson', 2)
            ->assertSet('peopleStatus', 'Removed Marcus Reyes. 3 people.')
            ->call('removePerson', 1)
            ->call('next')
            ->assertHasNoErrors()
            ->assertSet('step', 6);
    }

    public function test_a_person_is_edited_and_removed(): void
    {
        $wizard = $this->addPerson($this->addPerson($this->wizardAt(5), self::PRODUCER), self::EMPLOYEE)
            ->call('editPerson', 0)
            ->assertSet('people.editing', 0)
            ->assertSet('people.entry.first_name', 'Marcus')
            ->assertSee('Edit person 2')
            ->assertSee('Save changes')
            ->assertDispatched('join-focus', id: 'people-entry-first_name');

        // Its own email and NPN are not duplicates of itself.
        $wizard->set('people.entry.first_name', 'Marc')
            ->call('savePerson')
            ->assertHasNoErrors()
            ->assertSet('people.rows.0.first_name', 'Marc')
            ->assertCount('people.rows', 2)
            ->assertSet('people.editing', -1)
            ->assertSet('peopleStatus', 'Saved changes for Marc Reyes.')
            ->assertDispatched('join-focus', id: 'person-0-edit');

        // Editing the second person while the first is removed keeps pointing at the same person.
        $wizard->call('editPerson', 1)
            ->call('removePerson', 0)
            ->assertSet('people.editing', 0)
            ->assertSet('people.entry.first_name', 'Priya')
            ->assertDispatched('join-focus', id: 'person-0-edit')
            ->call('clearPerson')
            ->assertSet('people.editing', -1)
            ->assertSet('people.entry', PeopleForm::ROW);
    }

    public function test_a_person_left_in_the_form_stops_continue(): void
    {
        $this->addPerson($this->wizardAt(5), self::EMPLOYEE)
            ->set('people.entry.first_name', 'Half')
            ->call('next')
            ->assertSet('step', 5)
            ->assertHasErrors('people.entry')
            ->assertSee('This person is not on your list yet.')
            ->call('clearPerson')
            ->call('next')
            ->assertSet('step', 6);
    }

    public function test_the_tier_follows_the_headcount_across_10_and_50(): void
    {
        $wizard = $this->wizardAt(5)->set('people.rows', $this->rows(8));

        $this->addPerson($wizard, self::EMPLOYEE)
            ->assertSet('tier.tier', 'agency_10to49')
            ->assertSet('tierMovedUp', true)
            ->assertSet('tierNotice', "You've moved up to Agency Plus. Everyone now pays $149.")
            ->assertSeeHtml('class="notice-ok tier-notice"')
            ->assertSee('10 people · Agency Plus · $149 each · $1,490 a year');

        $wizard->call('removePerson', 0)
            ->assertSet('tier.tier', 'agency_2to9')
            ->assertSet('tierMovedUp', false)
            ->assertSet('tierNotice', 'Your agency is now on Agency, for 2 to 9 people. Everyone pays $159.')
            ->assertSee('9 people · Agency · $159 each · $1,431 a year');

        $wizard->set('people.rows', $this->rows(48));
        $this->addPerson($wizard, self::PRODUCER)
            ->assertSet('tier.tier', 'agency_50plus')
            ->assertSet('tierNotice', "You've moved up to Agency Enterprise. Everyone now pays $129.")
            ->assertSee('50 people · Agency Enterprise · $129 each · $6,450 a year');
    }

    public function test_continue_corrects_a_tier_chosen_too_high(): void
    {
        $wizard = $this->wizardAt(5)->set('tier.tier', 'agency_50plus')->set('people.rows', $this->rows(3));

        $wizard->assertSee('4 people · Agency · $159 each · $636 a year')
            ->call('next')
            ->assertSet('step', 6)
            ->assertSet('tier.tier', 'agency_2to9');
    }

    public function test_the_partner_code_applies_to_the_actual_headcount(): void
    {
        PartnerCode::factory()->create(['code' => 'MGA-7']);

        $wizard = $this->addPerson($this->wizardAt(5, ['partner' => 'MGA-7']), self::EMPLOYEE)
            ->assertSee('2 people · Agency · $149 each · $298 a year')
            ->assertSee('Partner rate applied.');

        $wizard->set('people.rows', $this->rows(59))->call('removePerson', 0)
            ->assertSee('59 people · Agency Enterprise · $129 each · $7,611 a year')
            ->assertSee("Your agency's rate is lower than the partner rate, so we applied it.");
    }

    public function test_the_list_has_an_upper_limit(): void
    {
        $this->addPerson($this->wizardAt(5)->set('people.rows', $this->rows(PeopleForm::MAX_HEADCOUNT - 1)), ['email' => 'one-more@lonestarbenefits.example'] + self::EMPLOYEE)
            ->assertHasErrors('people.entry')
            ->assertSee('Please contact ProPerX to enroll a larger agency.')
            ->assertCount('people.rows', PeopleForm::MAX_HEADCOUNT - 1);
    }

    public function test_people_are_saved_encrypted_after_each_change_and_restored(): void
    {
        $this->addPerson($this->wizardAt(5), self::PRODUCER)
            ->set('people.entry.first_name', 'Unfinished')
            ->call('back')
            ->assertHasNoErrors()
            ->assertSet('step', 4);

        $draft = AgencyRegistrationDraft::query()->sole();
        $this->assertSame('marcus@lonestarbenefits.example', $draft->data['people']['rows'][0]['email']);
        $this->assertSame('Unfinished', $draft->data['people']['entry']['first_name']);

        $raw = DB::table('agency_registration_drafts')->value('data');
        foreach (['marcus@', 'Reyes', '20304050', 'Unfinished'] as $plain) {
            $this->assertStringNotContainsString($plain, $raw);
        }

        // Later, in the same session.
        Livewire::test(Wizard::class)
            ->assertSet('step', 4)
            ->assertSet('people.rows.0.npn', '20304050')
            ->assertSet('people.entry.first_name', 'Unfinished')
            ->call('next')
            ->assertSet('step', 5)
            ->assertSee('Marcus Reyes');
    }

    public function test_a_damaged_draft_restores_only_well_formed_people(): void
    {
        $this->wizardAt(5);
        $draft = AgencyRegistrationDraft::query()->sole();
        $draft->data = ['people' => ['rows' => [['email' => ' A@B.example ', 'type' => 'agency_employee', 'npn' => '1', 'extra' => 'x'], 'junk', [['nested']]], 'editing' => 7]] + $draft->data;
        $draft->save();

        Livewire::test(Wizard::class)
            ->assertSet('people.rows', [
                ['type' => 'agency_employee', 'first_name' => '', 'last_name' => '', 'email' => 'a@b.example', 'npn' => '', 'license_state' => ''],
                PeopleForm::ROW,
            ])
            ->assertSet('people.editing', -1);
    }

    // Screen 5 · CSV

    private const CSV_HEADER = "type,first_name,last_name,email,npn,state\r\n";

    private function upload(Testable $wizard, string $csv, string $name = 'people.csv'): Testable
    {
        return $wizard->call('importPeople', $name, $csv);
    }

    public function test_the_template_has_the_columns_and_sample_rows(): void
    {
        $response = $this->get(route('join.agency.people-template'))->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="properx-agency-people.csv"');

        $this->assertSame(
            "\xEF\xBB\xBF".self::CSV_HEADER."licensed_producer,Jordan,Lee,jordan.lee@youragency.com,12345678,TX\r\nagency_employee,Sam,Rivera,sam.rivera@youragency.com,,\r\n",
            $response->getContent(),
        );
        $this->assertContains('throttle:agency-registration', Route::getRoutes()->getByName('join.agency.people-template')->middleware());

        config(['portal.registration.enabled' => false]);
        $this->get(route('join.agency.people-template'))->assertNotFound();
    }

    public function test_a_valid_csv_adds_everyone_with_the_same_rules_as_the_form(): void
    {
        // Byte order mark, friendly spellings, state names, a column order of its own, blank lines.
        $csv = "\xEF\xBB\xBF".'Email,First Name,Last Name,Type,NPN,State'."\r\n"
            ."Marcus@LoneStarBenefits.example,Marcus,Reyes,Licensed producer,20304050,texas\r\n"
            ."\r\n"
            ."priya@lonestarbenefits.example,Priya,Nair,employee,99999999,CA\r\n"
            ."\"owen@lonestarbenefits.example\",\"Owen, Jr.\",Brooks,producer,30405060,OK\r\n";

        $this->upload($this->wizardAt(5), $csv)
            ->assertHasNoErrors()
            ->assertSet('people.rows', [
                ['email' => 'marcus@lonestarbenefits.example'] + self::PRODUCER,
                self::EMPLOYEE,
                ['type' => 'licensed_producer', 'first_name' => 'Owen, Jr.', 'last_name' => 'Brooks', 'email' => 'owen@lonestarbenefits.example', 'npn' => '30405060', 'license_state' => 'OK'],
            ])
            ->assertSet('people.problems', [])
            ->assertSet('importOk', true)
            ->assertSet('importMessage', 'Added 3 people from people.csv.')
            ->assertDispatched('join-focus', id: 'csv-status')
            ->assertSee('4 people · Agency · $159 each · $636 a year')
            ->call('next')
            ->assertSet('step', 6);

        $this->assertCount(3, AgencyRegistrationDraft::query()->sole()->data['people']['rows']);
    }

    public function test_semicolon_separated_files_from_excel_are_read(): void
    {
        $this->upload($this->wizardAt(5), "type;first_name;last_name;email;npn;state\nagency_employee;Priya;Nair;priya@lonestarbenefits.example;;\n")
            ->assertSet('people.rows', [self::EMPLOYEE]);
    }

    public function test_rows_with_problems_wait_with_their_row_number_and_field(): void
    {
        $csv = self::CSV_HEADER
            ."agency_employee,Priya,Nair,priya@lonestarbenefits.example,,\r\n"   // row 2: fine
            ."licensed_producer,Marcus,Reyes,,20304050,TX\r\n"                   // row 3: no email
            ."licensed_producer,Owen,Brooks,owen@lonestarbenefits.example,,\r\n" // row 4: no NPN or state
            ."contractor,Lena,Ortiz,lena@lonestarbenefits.example,,\r\n"         // row 5: unknown type
            ."licensed_producer,Ana,Diaz,ana@lonestarbenefits.example,123,ZZ\r\n"; // row 6: short NPN, unknown state

        $wizard = $this->upload($this->wizardAt(5), $csv)
            ->assertSet('people.rows', [self::EMPLOYEE])
            ->assertCount('people.problems', 4)
            ->assertSet('importOk', false)
            ->assertSet('importMessage', 'Added 1 person from people.csv. 4 rows need fixing below.')
            ->assertDispatched('join-focus', id: 'problems-title')
            ->assertSet('people.problems.0.line', 3)
            ->assertSet('people.problems.0.errors', ['email' => 'The email field is required.'])
            ->assertSet('people.problems.1.errors', ['npn' => 'Enter the NPN of this licensed producer.', 'license_state' => 'Choose the resident license state.'])
            ->assertSet('people.problems.2.errors', ['type' => 'Choose licensed producer or agency employee.'])
            ->assertSet('people.problems.3.errors', ['npn' => 'The NPN must be 7 to 10 digits, numbers only.', 'license_state' => 'Choose the resident license state.'])
            ->assertSeeInOrder(['Rows to fix', 'Row 3', 'Marcus Reyes', 'Email: The email field is required.', 'Row 4', 'NPN: Enter the NPN of this licensed producer.', 'Row 5', 'Person type: Choose licensed producer or agency employee.', 'Row 6']);

        // The rows are fixed or skipped before going on.
        $wizard->call('next')
            ->assertSet('step', 5)
            ->assertHasErrors('people.problems')
            ->assertSee('Fix or skip the rows from your CSV before you continue.');
    }

    public function test_a_row_is_fixed_in_the_form_or_skipped(): void
    {
        $csv = self::CSV_HEADER
            ."licensed_producer,Marcus,Reyes,,20304050,TX\r\n"
            ."agency_employee,Lena,Ortiz,,,\r\n"
            ."agency_employee,Sam,Cole,,,\r\n";

        $wizard = $this->upload($this->wizardAt(5), $csv)->assertCount('people.problems', 3)
            ->call('fixProblem', 0)
            ->assertSet('people.fixing', 0)
            ->assertSet('people.entry.first_name', 'Marcus')
            ->assertHasErrors('people.entry.email')
            ->assertSee('Fix row 2')
            ->assertDispatched('join-focus', id: 'person-form-title');

        // Clearing the form keeps the row to fix.
        $wizard->call('clearPerson')->assertCount('people.problems', 3)->assertDispatched('join-focus', id: 'problem-0-fix');

        $wizard->call('fixProblem', 0)
            ->set('people.entry.email', 'marcus@lonestarbenefits.example')
            ->call('savePerson')
            ->assertHasNoErrors()
            ->assertSet('people.rows.0.email', 'marcus@lonestarbenefits.example')
            ->assertCount('people.problems', 2)
            ->assertSet('people.fixing', -1)
            ->assertSet('peopleStatus', 'Added Marcus Reyes from row 2. 2 people.')
            ->assertDispatched('join-focus', id: 'problem-0-fix');

        $wizard->call('skipProblem', 0)
            ->assertSet('peopleStatus', 'Skipped row 3.')
            ->assertSet('people.problems.0.line', 4)
            ->call('skipAllProblems')
            ->assertSet('people.problems', [])
            ->call('next')
            ->assertSet('step', 6);
    }

    public function test_duplicates_in_the_file_on_the_list_and_in_the_portal_are_rows_to_fix(): void
    {
        User::factory()->create(['email' => 'taken@lonestarbenefits.example']);
        MemberProfile::factory()->create(['npn' => '55555555']);

        $csv = self::CSV_HEADER
            ."agency_employee,Priya,Nair,priya@lonestarbenefits.example,,\r\n"           // row 2: added
            ."agency_employee,Priya,Patel,PRIYA@lonestarbenefits.example,,\r\n"          // row 3: same email as row 2
            ."agency_employee,Dana,Copy,dana@lonestarbenefits.example,,\r\n"             // row 4: the principal's email
            ."licensed_producer,Kim,Ito,kim@lonestarbenefits.example,18273645,TX\r\n"    // row 5: the principal's NPN
            ."agency_employee,Tom,Hall,taken@lonestarbenefits.example,,\r\n"             // row 6: in the portal
            ."licensed_producer,Ann,Lowe,ann@lonestarbenefits.example,55555555,TX\r\n";  // row 7: NPN in the portal

        $this->upload($this->wizardAt(5), $csv)
            ->assertSet('people.rows', [self::EMPLOYEE])
            ->assertSet('people.problems.0.errors', ['email' => 'This email is already on your list.'])
            ->assertSet('people.problems.1.errors', ['email' => "This is the principal's email. Each person needs their own email."])
            ->assertSet('people.problems.2.errors', ['npn' => "This is the principal's NPN. Each person needs their own NPN."])
            ->assertSet('people.problems.3.errors', ['email' => 'This email is already registered in the portal. Please contact ProPerX.'])
            ->assertSet('people.problems.4.errors', ['npn' => 'This NPN is already registered in the portal. Please contact ProPerX.']);
    }

    public function test_uploading_the_same_file_again_adds_no_one_twice(): void
    {
        $csv = self::CSV_HEADER."licensed_producer,Marcus,Reyes,marcus@lonestarbenefits.example,20304050,TX\r\nagency_employee,Priya,Nair,priya@lonestarbenefits.example,,\r\n";

        $wizard = $this->upload($this->wizardAt(5), $csv)->assertCount('people.rows', 2);

        $this->upload($wizard, $csv)
            ->assertCount('people.rows', 2)
            ->assertSet('people.problems', [])
            ->assertSet('importMessage', 'Added 0 people from people.csv. 2 rows were already on your list.');

        // The same email with other details is a row to fix, not a second person.
        $this->upload($wizard, self::CSV_HEADER."agency_employee,Priya,Nair-Shah,priya@lonestarbenefits.example,,\r\n")
            ->assertCount('people.rows', 2)
            ->assertSet('people.problems.0.errors', ['email' => 'This email is already on your list.']);
    }

    public function test_the_template_sample_rows_are_left_out(): void
    {
        $this->upload($this->wizardAt(5), PeopleCsv::template()."agency_employee,Priya,Nair,priya@lonestarbenefits.example,,\r\n")
            ->assertSet('people.rows', [self::EMPLOYEE])
            ->assertSet('importMessage', 'Added 1 person from people.csv. The example rows from the template were left out.');
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function unreadableFiles(): iterable
    {
        yield 'not a CSV' => ['people.xlsx', self::CSV_HEADER, 'Choose a .csv file.'];
        yield 'larger than 1 MB' => ['people.csv', self::CSV_HEADER.str_repeat('x', 1024 * 1024), 'The file is larger than 1 MB.'];
        yield 'not UTF-8' => ['people.csv', self::CSV_HEADER."agency_employee,Jos\xE9,Ruiz,jose@lonestarbenefits.example,,\r\n", 'The file is not in UTF-8.'];
        yield 'missing columns' => ['people.csv', "first_name,last_name,email\r\nPriya,Nair,priya@lonestarbenefits.example\r\n", 'Missing: type, npn, state.'];
        yield 'empty' => ['people.csv', "\xEF\xBB\xBF \r\n", 'The file is empty.'];
        yield 'header only' => ['people.csv', self::CSV_HEADER, 'The file has no people in it.'];
    }

    #[DataProvider('unreadableFiles')]
    public function test_a_file_that_cannot_be_read_changes_nothing(string $name, string $csv, string $message): void
    {
        $this->upload($this->wizardAt(5), $csv, $name)
            ->assertSet('people.rows', [])
            ->assertSet('people.problems', [])
            ->assertSet('importOk', false)
            ->assertSee($message)
            ->assertDispatched('join-focus', id: 'csv-status');
    }

    public function test_a_file_cannot_take_the_list_past_the_limit(): void
    {
        $wizard = $this->wizardAt(5)->set('people.rows', $this->rows(PeopleForm::MAX_HEADCOUNT - 3));
        $csv = self::CSV_HEADER.implode("\r\n", array_map(fn (int $i) => "agency_employee,New,{$i},new{$i}@lonestarbenefits.example,,", range(1, 3)));

        $this->upload($wizard, $csv)
            ->assertSee('The file lists more people than can still be added (2).')
            ->assertCount('people.rows', PeopleForm::MAX_HEADCOUNT - 3);

        $this->upload($wizard, self::CSV_HEADER."agency_employee,New,1,new1@lonestarbenefits.example,,\r\nagency_employee,New,2,new2@lonestarbenefits.example,,\r\n")
            ->assertCount('people.rows', PeopleForm::MAX_HEADCOUNT - 1);
    }

    public function test_rows_to_fix_are_kept_in_the_draft(): void
    {
        $this->upload($this->wizardAt(5), self::CSV_HEADER."licensed_producer,Marcus,Reyes,,20304050,TX\r\n")
            ->call('fixProblem', 0)
            ->call('back');

        $raw = DB::table('agency_registration_drafts')->value('data');
        $this->assertStringNotContainsString('20304050', $raw);

        Livewire::test(Wizard::class)->call('next')
            ->assertSet('people.problems.0.line', 2)
            ->assertSet('people.problems.0.errors', ['email' => 'The email field is required.'])
            ->assertSet('people.fixing', 0)
            ->assertSee('Rows to fix');
    }

    public function test_a_csv_upload_works_only_on_the_people_screen(): void
    {
        $this->wizardAt(4)->call('importPeople', 'people.csv', self::CSV_HEADER."agency_employee,Priya,Nair,priya@lonestarbenefits.example,,\r\n")
            ->assertSet('people.rows', [])
            ->assertSet('importMessage', '');
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
