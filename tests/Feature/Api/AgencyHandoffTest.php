<?php

namespace Tests\Feature\Api;

use App\Livewire\AgencyRegistration\Wizard;
use App\Models\AgencyRegistrationDraft;
use App\Models\IntakeSubmission;
use App\Models\PartnerCode;
use App\Notifications\AgencyEnrollmentContinueLink;
use App\Services\Intake\AgencyHandoff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * POST /api/intake/agency-start: "My agency" on properx.org/apply becomes a new, prefilled
 * enrollment draft and a one-time link that opens it on screen 2.
 */
class AgencyHandoffTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    private const SECRET = 'test-intake-secret';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        config([
            'portal.intake.secret' => self::SECRET,
            'portal.registration.enabled' => true,
            'portal.registration.handoff.enabled' => true,
            'portal.registration.individual_url' => 'https://properx.example/apply',
        ]);
        Notification::fake();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'request_id' => bin2hex(random_bytes(16)),
            'first_name' => 'Dana',
            'last_name' => 'Whitfield',
            'email' => 'Dana@WhitfieldInsurance.example',
            'phone' => '(330) 555-0188',
            'agency_name' => 'Whitfield Insurance Group LLC',
            'headcount' => '10-49',
            'partner_code' => '',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>|string  $payload
     */
    private function send(array|string $payload, ?int $timestamp = null, string $secret = self::SECRET): TestResponse
    {
        $body = is_string($payload) ? $payload : json_encode($payload);
        $timestamp ??= now()->getTimestamp();

        return $this->call('POST', '/api/intake/agency-start', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, $secret),
        ], $body);
    }

    public function test_it_is_off_unless_both_flags_are_on(): void
    {
        config(['portal.registration.handoff.enabled' => false]);
        $this->send($this->payload())->assertNotFound();

        config(['portal.registration.handoff.enabled' => true, 'portal.registration.enabled' => false]);
        $this->send($this->payload())->assertNotFound();

        $this->assertSame(0, AgencyRegistrationDraft::query()->count());
    }

    public function test_a_bad_signature_or_an_old_timestamp_is_refused(): void
    {
        $this->send($this->payload(), secret: 'wrong')->assertUnauthorized();
        $this->send($this->payload(), timestamp: now()->subMinutes(6)->getTimestamp())->assertUnauthorized();

        $this->assertSame(0, AgencyRegistrationDraft::query()->count());
    }

    public function test_invalid_fields_are_named_without_values(): void
    {
        $this->send($this->payload(['email' => 'not-an-email', 'phone' => '123', 'headcount' => '7']))
            ->assertUnprocessable()
            ->assertJsonPath('fields', ['email', 'phone', 'headcount'])
            ->assertDontSee('not-an-email');

        $this->assertSame(0, AgencyRegistrationDraft::query()->count());
    }

    public function test_it_creates_a_prefilled_draft_and_a_one_time_link_to_screen_2(): void
    {
        $response = $this->send($this->payload())->assertOk()->assertJsonPath('outcome', 'started');

        $url = $response->json('url');
        $token = basename((string) $url);
        $this->assertSame(route('join.agency.start', $token), $url);

        $draft = AgencyRegistrationDraft::query()->sole();
        $this->assertSame(2, $draft->step);
        $this->assertSame(2, $draft->furthest_step);
        $this->assertSame(hash('sha256', $token), $draft->start_token_hash);
        $this->assertNotSame($draft->token_hash, $draft->start_token_hash);
        $this->assertNotNull($draft->principal_email_index);

        // No link and no personal data in the request log.
        $submission = IntakeSubmission::query()->sole();
        $this->assertSame('started', $submission->outcome);
        $this->assertStringNotContainsString($token, json_encode($submission->result));
        $this->assertStringNotContainsString('Whitfield', json_encode($submission->result));

        // Another browser follows the link: the wizard opens on screen 2, prefilled.
        $this->get($url)->assertRedirect(route('join.agency'));
        $this->assertSame($draft->getKey(), session(Wizard::SESSION_KEY));

        Livewire::test(Wizard::class)
            ->assertSet('step', 2)
            ->assertSet('furthestStep', 2)
            ->assertSet('membership.type', 'agency')
            ->assertSet('tier.tier', 'agency_10to49')
            ->assertSet('agency.legal_name', 'Whitfield Insurance Group LLC')
            ->assertSet('principal.first_name', 'Dana')
            ->assertSet('principal.last_name', 'Whitfield')
            ->assertSet('principal.email', 'dana@whitfieldinsurance.example')
            ->assertSet('principal.mobile_phone', '(330) 555-0188');
    }

    public function test_the_link_works_once(): void
    {
        $url = $this->send($this->payload())->json('url');

        $this->get($url)->assertRedirect(route('join.agency'));

        $this->flushSession();
        $this->get($url)->assertStatus(410)
            ->assertSee("This link can't be used any more", false)
            ->assertSee('Start again on properx.org')
            ->assertDontSee('Whitfield');
        $this->assertNull(session(Wizard::SESSION_KEY));
    }

    public function test_the_link_expires(): void
    {
        $url = $this->send($this->payload())->json('url');

        $this->travel(31)->minutes();
        $this->get($url)->assertStatus(410);

        $this->get(route('join.agency.start', 'not-a-token'))->assertStatus(410);
    }

    public function test_a_request_id_is_used_once(): void
    {
        $payload = $this->payload();

        $this->send($payload)->assertOk();
        $this->send($payload)->assertStatus(409);

        $this->assertSame(1, AgencyRegistrationDraft::query()->count());
    }

    public function test_every_request_creates_a_new_draft_and_the_earlier_one_goes_to_its_owner_by_email(): void
    {
        $first = $this->send($this->payload())->json('url');
        $second = $this->send($this->payload())->assertJsonPath('earlier_draft_emailed', true)->json('url');

        $this->assertNotSame($first, $second);
        $this->assertSame(2, AgencyRegistrationDraft::query()->count());

        // The earlier draft is never opened by the new link: its owner gets its own link by email.
        $this->get($second)->assertRedirect(route('join.agency'));
        $this->assertSame(AgencyRegistrationDraft::query()->latest('id')->first()->getKey(), session(Wizard::SESSION_KEY));

        Notification::assertSentOnDemand(AgencyEnrollmentContinueLink::class, function (AgencyEnrollmentContinueLink $notification, array $channels, object $notifiable) {
            return $notifiable->routes['mail'] === 'dana@whitfieldinsurance.example';
        });

        // Not again within the continue link's interval.
        $this->send($this->payload())->assertJsonPath('earlier_draft_emailed', false);
        Notification::assertSentOnDemandTimes(AgencyEnrollmentContinueLink::class, 1);
    }

    public function test_an_email_with_a_portal_account_starts_nothing(): void
    {
        $member = $this->member();

        $this->send($this->payload(['email' => strtoupper($member->email)]))
            ->assertOk()
            ->assertJsonPath('outcome', 'existing_member')
            ->assertJsonPath('message', AgencyHandoff::EMAIL_REFUSED_MESSAGE)
            ->assertJsonMissingPath('url');

        $this->assertSame(0, AgencyRegistrationDraft::query()->count());
    }

    public function test_a_valid_partner_code_is_carried_and_an_invalid_one_opens_screen_1(): void
    {
        PartnerCode::factory()->create(['code' => 'MIDWESTFMO', 'expires_at' => now()->addMonth()]);

        $this->get($this->send($this->payload(['partner_code' => ' midwestfmo ']))->json('url'));
        Livewire::test(Wizard::class)->assertSet('step', 2)->assertSet('membership.partner_code', 'MIDWESTFMO');

        $this->flushSession();
        $this->get($this->send($this->payload(['partner_code' => 'NOPE-1', 'email' => 'other@example.test']))->json('url'));
        Livewire::test(Wizard::class)
            ->assertSet('step', 1)
            ->assertSet('membership.partner_code', 'NOPE-1')
            ->call('next')
            ->assertHasErrors('membership.partner_code');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function headcounts(): array
    {
        return ['2-9' => ['2-9', 'agency_2to9'], '10-49' => ['10-49', 'agency_10to49'], '50+' => ['50+', 'agency_50plus']];
    }

    #[DataProvider('headcounts')]
    public function test_the_headcount_picks_the_starting_tier(string $headcount, string $tier): void
    {
        $this->send($this->payload(['headcount' => $headcount]))->assertOk();

        $this->assertSame($tier, AgencyRegistrationDraft::query()->sole()->data['tier']['tier']);
    }
}
