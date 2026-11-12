<?php

namespace Tests\Feature\Agencies;

use App\Enums\InviteStatus;
use App\Enums\LicenseVerification;
use App\Enums\Role;
use App\Member\NotificationFeed;
use App\Member\Overview;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\MemberProfile;
use App\Models\User;
use App\Notifications\Member\LicenseNeedsAttention;
use App\Nova\Cards\PendingVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * The license verification queue (Agency spec 8.3, F1-F2): who sees it, Verified and Needs
 * attention, who is told and how, and a corrected NPN or state going back to Pending review.
 */
class LicenseVerificationQueueTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    private const REASON = 'The NPN does not match the resident state in NIPR.';

    private Agency $agency;

    private User $principal;

    private MemberProfile $producer;

    private MemberProfile $employee;

    private MemberProfile $individual;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        config(['portal.member_area.enabled' => true]);

        $this->agency = Agency::factory()->create(['legal_name' => 'Lone Star Benefits LLC']);
        $this->principal = $this->person(['first_name' => 'Dana', 'last_name' => 'Whitfield']);
        $this->agency->update(['principal_user_id' => $this->principal->id]);
        $this->producer = $this->person(['first_name' => 'Marcus', 'last_name' => 'Reyes', 'npn' => '1234567', 'license_state' => 'TX'])->memberProfile;
        $this->employee = $this->person(['first_name' => 'Priya', 'last_name' => 'Nair'], employee: true)->memberProfile;
        $this->individual = $this->member()->memberProfile;
        $this->agency->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function person(array $attributes, bool $employee = false): User
    {
        $user = User::factory()->withTwoFactor()->create()->assignRole(Role::Member->value);
        $factory = MemberProfile::factory()->inAgency($this->agency, InviteStatus::AccountActive);
        ($employee ? $factory->employee() : $factory)->for($user)->create($attributes);

        return $user->load('memberProfile');
    }

    private function filter(LicenseVerification $status): string
    {
        return base64_encode((string) json_encode([['license-verification-status' => $status->value]]));
    }

    /**
     * @return list<int>
     */
    private function queueIds(User $user, LicenseVerification $status = LicenseVerification::PendingReview): array
    {
        $ids = collect($this->actingAs($user)
            ->getJson('/nova-api/license-verification?perPage=100&filters='.$this->filter($status))
            ->assertOk()
            ->json('resources'))
            ->map(fn (array $resource) => $resource['id']['value'])
            ->sort()
            ->values()
            ->all();

        return $ids;
    }

    private function needsAttention(User $staff, MemberProfile $profile, string $reason = self::REASON): TestResponse
    {
        return $this->actingAs($staff)->postJson('/nova-api/license-verification/action?action=mark-license-needs-attention', [
            'resources' => (string) $profile->id,
            'reason' => $reason,
        ]);
    }

    // The queue

    public function test_the_queue_lists_licensed_people_pending_review_from_agencies_and_individuals(): void
    {
        $this->individual->forceFill(['license_verification' => LicenseVerification::PendingReview])->save();
        $verified = $this->member()->memberProfile;
        $verified->forceFill(['license_verification' => LicenseVerification::Verified])->save();

        $pending = [$this->principal->memberProfile->id, $this->producer->id, $this->individual->id];
        sort($pending);

        $coordinator = $this->staff(Role::MembershipCoordinator);
        $this->assertSame($pending, $this->queueIds($coordinator));
        $this->assertSame([$verified->id], $this->queueIds($coordinator, LicenseVerification::Verified));

        // Employees hold no license and never appear, whatever the filter.
        $this->assertNull($this->employee->fresh()->license_verification);
        $this->actingAs($coordinator)->getJson("/nova-api/license-verification/{$this->employee->id}")->assertNotFound();

        // Agency, NPN, state and the date are on the list.
        $row = collect($this->actingAs($coordinator)->getJson('/nova-api/license-verification?filters='.$this->filter(LicenseVerification::PendingReview))->json('resources'))
            ->firstWhere('id.value', $this->producer->id);
        $fields = collect($row['fields'])->keyBy('attribute');
        $this->assertSame('1234567', $fields['npn']['value']);
        $this->assertSame('TX', $fields['license_state']['value']);
        $this->assertStringContainsString('>Lone Star Benefits LLC</a>', collect($row['fields'])->firstWhere('name', 'Agency')['value']);
        $this->assertNotNull($fields['license_verification_changed_at']['value']);
    }

    public function test_principals_are_marked_individuals_named_and_the_type_and_agency_filters_work(): void
    {
        $coordinator = $this->staff(Role::MembershipCoordinator);
        $rows = collect($this->actingAs($coordinator)->getJson('/nova-api/license-verification?perPage=100&filters='.$this->filter(LicenseVerification::PendingReview))->json('resources'))
            ->mapWithKeys(fn (array $row) => [$row['id']['value'] => collect($row['fields'])->keyBy('name')]);

        $this->assertStringContainsString('Dana Whitfield <span', $rows[$this->principal->memberProfile->id]['Name']['value']);
        $this->assertStringContainsString('>Principal</span>', $rows[$this->principal->memberProfile->id]['Name']['value']);
        $this->assertStringNotContainsString('Principal', $rows[$this->producer->id]['Name']['value']);
        $this->assertSame('Individual', $rows[$this->individual->id]['Agency']['value']);

        $ids = fn (array $filters) => collect($this->actingAs($coordinator)
            ->getJson('/nova-api/license-verification?perPage=100&filters='.base64_encode((string) json_encode($filters)))
            ->json('resources'))->pluck('id.value')->sort()->values()->all();

        $this->assertSame([$this->principal->memberProfile->id], $ids([['license-verification-type' => 'principal']]));
        $this->assertSame([$this->producer->id], $ids([['license-verification-type' => 'agency_person']]));
        $this->assertSame([$this->individual->id], $ids([['license-verification-type' => 'individual']]));
        $this->assertSame([$this->individual->id], $ids([['license-verification-agency' => 'individual']]));
        $this->assertEqualsCanonicalizing([$this->principal->memberProfile->id, $this->producer->id], $ids([['license-verification-agency' => (string) $this->agency->id]]));
    }

    public function test_only_the_membership_coordinator_and_executive_director_work_the_queue(): void
    {
        foreach ([Role::MembershipCoordinator, Role::ExecutiveDirector] as $role) {
            $this->actingAs($this->staff($role))->getJson('/nova-api/license-verification')->assertOk();
        }

        $compliance = $this->staff(Role::ComplianceSpecialist);
        $this->actingAs($compliance)->getJson('/nova-api/license-verification')->assertForbidden();
        $this->actingAs($compliance)
            ->postJson('/nova-api/license-verification/action?action=mark-license-verified', ['resources' => (string) $this->producer->id])
            ->assertForbidden();
        $this->needsAttention($compliance, $this->producer)->assertForbidden();
        $this->assertSame(LicenseVerification::PendingReview, $this->producer->fresh()->license_verification);

        $this->actingAs($this->principal)->getJson('/nova-api/license-verification')->assertForbidden();
        $this->actingAs($this->principal)
            ->postJson('/nova-api/license-verification/action?action=mark-license-verified', ['resources' => (string) $this->producer->id])
            ->assertForbidden();

        // Nothing to create, edit or delete here.
        $coordinator = $this->staff(Role::MembershipCoordinator);
        $this->actingAs($coordinator)->putJson("/nova-api/license-verification/{$this->producer->id}", ['npn' => '7654321'])->assertForbidden();
        $this->actingAs($coordinator)->postJson('/nova-api/license-verification', ['npn' => '7654321'])->assertForbidden();
        $this->actingAs($coordinator)->deleteJson('/nova-api/license-verification?resources[]='.$this->producer->id);
        $this->assertSame('1234567', $this->producer->fresh()?->npn);
    }

    public function test_verified_runs_on_a_selection_and_is_audited(): void
    {
        Notification::fake();
        $coordinator = $this->staff(Role::MembershipCoordinator);

        $this->actingAs($coordinator)
            ->postJson('/nova-api/license-verification/action?action=mark-license-verified', [
                'resources' => [$this->producer->id, $this->principal->memberProfile->id],
            ])
            ->assertOk()
            ->assertJson(['message' => '2 licenses marked as verified.']);

        $this->assertSame(LicenseVerification::Verified, $this->producer->fresh()->license_verification);
        $this->assertSame(LicenseVerification::Verified, $this->principal->memberProfile->fresh()->license_verification);
        $this->assertSame([$this->individual->id], $this->queueIds($coordinator));

        $entry = AuditLog::query()->where('action', 'license_verification_changed')->where('resource_id', $this->producer->id)->sole();
        $this->assertSame($coordinator->id, $entry->user_id);
        $this->assertSame(['from' => 'pending_review', 'to' => 'verified'], $entry->details);
        $this->assertFalse(AuditLog::query()->where('action', 'profile_updated')->where('resource_id', $this->producer->id)->exists());
        Notification::assertNothingSent();
    }

    // Needs attention

    public function test_needs_attention_requires_a_reason_and_is_for_one_person_at_a_time(): void
    {
        $coordinator = $this->staff(Role::MembershipCoordinator);

        $this->needsAttention($coordinator, $this->producer, '  ')->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame(LicenseVerification::PendingReview, $this->producer->fresh()->license_verification);

        Notification::fake();
        $this->actingAs($coordinator)->postJson('/nova-api/license-verification/action?action=mark-license-needs-attention', [
            'resources' => [$this->producer->id, $this->individual->id],
            'reason' => self::REASON,
        ])->assertOk()->assertJson(['danger' => 'Choose one person at a time: the reason is written for them.']);

        $this->assertSame(LicenseVerification::PendingReview, $this->producer->fresh()->license_verification);
        $this->assertSame(LicenseVerification::PendingReview, $this->individual->fresh()->license_verification);
        Notification::assertNothingSent();
    }

    public function test_an_agency_person_needing_attention_is_told_in_the_portal_and_the_principal_by_email(): void
    {
        Notification::fake();
        $coordinator = $this->staff(Role::MembershipCoordinator);

        $this->needsAttention($coordinator, $this->producer)->assertOk();

        $producer = $this->producer->fresh();
        $this->assertSame(LicenseVerification::NeedsAttention, $producer->license_verification);
        $this->assertSame(self::REASON, $producer->license_verification_note);
        // Encrypted at rest.
        $this->assertStringNotContainsString('NIPR', (string) $producer->getRawOriginal('license_verification_note'));

        $entry = AuditLog::query()->where('action', 'license_verification_changed')->where('resource_id', $producer->id)->sole();
        $this->assertSame(['from' => 'pending_review', 'to' => 'needs_attention', 'reason_given' => true], $entry->details);
        $this->assertStringNotContainsString('NIPR', json_encode($entry->details));

        // The person: in the bell only. The principal: bell and email, without names in the email.
        Notification::assertSentTo($producer->user, LicenseNeedsAttention::class, fn (LicenseNeedsAttention $notification, array $channels) => $notification->kind === LicenseNeedsAttention::OWN && $channels === ['database']);
        Notification::assertSentTo($this->principal, LicenseNeedsAttention::class, function (LicenseNeedsAttention $notification, array $channels) {
            $mail = $notification->toMail($this->principal);
            $text = $this->mailText($mail);

            return $notification->kind === LicenseNeedsAttention::AGENCY
                && $channels === ['database', 'mail']
                && $mail->subject === 'Action needed: a license on your agency\'s list'
                && str_contains($text, 'What to fix: '.self::REASON)
                && ! str_contains($text, 'Marcus') && ! str_contains($text, 'Reyes') && ! str_contains($text, '1234567');
        });
    }

    public function test_an_individual_member_needing_attention_gets_the_email(): void
    {
        Notification::fake();
        $this->individual->forceFill(['license_verification' => LicenseVerification::PendingReview])->save();

        $this->needsAttention($this->staff(Role::ExecutiveDirector), $this->individual)->assertOk();

        $user = $this->individual->user;
        Notification::assertSentTo($user, LicenseNeedsAttention::class, function (LicenseNeedsAttention $notification, array $channels) use ($user) {
            $mail = $notification->toMail($user);

            return $channels === ['database', 'mail']
                && $mail->subject === 'Action needed: your license could not be verified'
                && str_contains($this->mailText($mail), 'What to fix: '.self::REASON)
                && $mail->actionUrl === route('member.profile', ['edit' => 'business']);
        });
        Notification::assertNotSentTo($this->principal, LicenseNeedsAttention::class);
    }

    public function test_the_principals_own_license_is_emailed_to_them_once(): void
    {
        Notification::fake();

        $this->needsAttention($this->staff(Role::MembershipCoordinator), $this->principal->memberProfile)->assertOk();

        Notification::assertSentToTimes($this->principal, LicenseNeedsAttention::class, 1);
        Notification::assertSentTo($this->principal, LicenseNeedsAttention::class, fn (LicenseNeedsAttention $notification, array $channels) => $notification->kind === LicenseNeedsAttention::OWN && $channels === ['database', 'mail']);
    }

    public function test_the_reason_shows_in_the_member_area_and_the_bell(): void
    {
        $this->needsAttention($this->staff(Role::MembershipCoordinator), $this->producer)->assertOk();

        $person = $this->producer->user->fresh();
        $this->assertSame(self::REASON, Overview::for($person)->license()['reason']);

        $own = NotificationFeed::latest($person)->sole();
        $this->assertSame('Your license needs attention', $own['title']);
        $this->assertSame('Reason: '.self::REASON, $own['detail']);

        $forPrincipal = NotificationFeed::latest($this->principal->fresh())->sole();
        $this->assertSame('Marcus Reyes: license needs attention', $forPrincipal['title']);
        $this->assertSame('Reason: '.self::REASON, $forPrincipal['detail']);

        $this->actingAs($person)->get(route('member.dashboard'))
            ->assertOk()
            ->assertSee('Needs attention')
            ->assertSee(self::REASON)
            ->assertSee('Update your NPN or state');
    }

    public function test_correcting_the_npn_sends_the_license_back_to_pending_review(): void
    {
        $this->needsAttention($this->staff(Role::MembershipCoordinator), $this->producer)->assertOk();
        $person = $this->producer->user->fresh();

        // A too-short NPN is refused for agency people.
        $this->actingAs($person)
            ->patch(route('member.profile.business'), ['firm_name' => '', 'npn' => '765432', 'confirm_review' => '1'])
            ->assertSessionHasErrors(['npn' => 'The NPN must be 7 to 10 digits, numbers only.']);

        $this->actingAs($person)
            ->patch(route('member.profile.business'), ['firm_name' => '', 'npn' => '7654321', 'confirm_review' => '1'])
            ->assertSessionHasNoErrors();

        $profile = $this->producer->fresh();
        $this->assertSame('7654321', $profile->npn);
        $this->assertSame(LicenseVerification::PendingReview, $profile->license_verification);
        $this->assertNull($profile->license_verification_note);
        $this->assertNull(Overview::for($person->fresh())->license()['reason']);
        $this->assertNull(NotificationFeed::latest($person->fresh())->sole()['detail']);
        $this->assertTrue(AuditLog::query()->where('action', 'license_verification_changed')->where('resource_id', $profile->id)
            ->get()->contains(fn (AuditLog $entry) => $entry->details === ['from' => 'needs_attention', 'to' => 'pending_review']));
    }

    public function test_correcting_the_state_sends_the_license_back_to_pending_review(): void
    {
        $this->needsAttention($this->staff(Role::MembershipCoordinator), $this->producer)->assertOk();

        $this->actingAs($this->producer->user->fresh())
            ->patch(route('member.profile.license'), ['license_state' => 'OK', 'confirm_review' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertSame(LicenseVerification::PendingReview, $this->producer->fresh()->license_verification);
    }

    public function test_a_notice_is_not_emailed_once_the_license_is_fixed(): void
    {
        $this->producer->forceFill(['license_verification' => LicenseVerification::NeedsAttention, 'license_verification_note' => self::REASON])->save();
        $notification = new LicenseNeedsAttention($this->producer->id, LicenseNeedsAttention::AGENCY, email: true);
        $this->assertTrue($notification->shouldSend($this->principal, 'mail'));

        $this->producer->forceFill(['license_verification' => LicenseVerification::PendingReview])->save();
        $this->assertFalse($notification->shouldSend($this->principal, 'mail'));
    }

    // Dashboard

    public function test_the_staff_dashboard_counts_pending_licenses_and_links_to_the_queue(): void
    {
        // Principal, producer and the individual member; never the employee.
        $this->assertSame(3, PendingVerification::count());

        $cards = collect($this->actingAs($this->staff(Role::MembershipCoordinator))->getJson('/nova-api/dashboards/main')->assertOk()->json('cards'));
        $card = $cards->firstWhere('component', 'ppx-link-metric');
        $this->assertSame('Pending verification', $card['title']);
        $this->assertSame(3, $card['value']);
        $this->assertSame('/nova/resources/license-verification', parse_url($card['url'], PHP_URL_PATH));

        $compliance = collect($this->actingAs($this->staff(Role::ComplianceSpecialist))->getJson('/nova-api/dashboards/main')->assertOk()->json('cards'));
        $this->assertNull($compliance->firstWhere('component', 'ppx-link-metric'));
    }

    private function mailText(MailMessage $mail): string
    {
        return implode("\n", [...$mail->introLines, ...$mail->outroLines]);
    }
}
