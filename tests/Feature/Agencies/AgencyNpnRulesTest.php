<?php

namespace Tests\Feature\Agencies;

use App\Enums\InviteStatus;
use App\Enums\Role;
use App\Models\Agency;
use App\Models\MemberProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * NPN of agency principals and agency people: 7 to 10 digits (the client's answer); individual
 * members keep properx.org's 1 to 10. An older value that is not changed never blocks a save.
 */
class AgencyNpnRulesTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    private Agency $agency;

    private User $person;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        config(['portal.member_area.enabled' => true]);

        $this->agency = Agency::factory()->create();
        $this->person = User::factory()->withTwoFactor()->create()->assignRole(Role::Member->value);
        MemberProfile::factory()->inAgency($this->agency, InviteStatus::AccountActive)->for($this->person)->create(['npn' => '1234567', 'license_state' => 'TX']);
        $this->person->load('memberProfile');
    }

    private function business(User $user, string $npn): TestResponse
    {
        return $this->actingAs($user)->patch(route('member.profile.business'), ['firm_name' => '', 'npn' => $npn, 'confirm_review' => '1']);
    }

    public function test_agency_people_change_their_npn_to_7_to_10_digits_only(): void
    {
        foreach (['123456', '1', '12345678901', '12ab567'] as $npn) {
            $this->business($this->person, $npn)->assertSessionHasErrors(['npn' => 'The NPN must be 7 to 10 digits, numbers only.']);
        }

        foreach (['7654321', '7654321098'] as $npn) {
            $this->business($this->person, $npn)->assertSessionHasNoErrors();
            $this->assertSame($npn, $this->person->memberProfile->fresh()->npn);
        }

        $this->actingAs($this->person)->get(route('member.profile', ['edit' => 'business']))->assertSee('7 to 10 digits, numbers only.');
    }

    public function test_an_older_short_npn_left_unchanged_still_saves(): void
    {
        $this->person->memberProfile->forceFill(['npn' => '12345'])->save();

        $this->business($this->person, '12345')->assertSessionHasNoErrors();
        $this->assertSame('12345', $this->person->memberProfile->fresh()->npn);
    }

    public function test_individual_members_keep_1_to_10_digits(): void
    {
        $member = $this->member();

        $this->business($member, '123')->assertSessionHasNoErrors();
        $this->assertSame('123', $member->memberProfile->fresh()->npn);
        $this->actingAs($member)->get(route('member.profile', ['edit' => 'business']))->assertSee('Up to 10 digits, numbers only.');
    }

    public function test_staff_in_nova_follow_the_same_rule(): void
    {
        $coordinator = $this->staff(Role::MembershipCoordinator);
        $profile = $this->person->memberProfile;
        $update = fn (string $npn) => $this->actingAs($coordinator)->putJson("/nova-api/member-profiles/{$profile->id}", [
            'first_name' => $profile->first_name,
            'last_name' => $profile->last_name,
            'npn' => $npn,
            'license_state' => $profile->license_state,
            'status' => $profile->status->value,
            'agency' => $this->agency->id,
            'person_type' => $profile->person_type->value,
        ]);

        $update('123456')->assertUnprocessable()->assertJsonValidationErrors(['npn' => 'The NPN must be 7 to 10 digits, numbers only.']);
        $update('2345678')->assertOk();
        $this->assertSame('2345678', $profile->fresh()->npn);

        // Unchanged older value: saves.
        $profile->fresh()->forceFill(['npn' => '12345'])->save();
        $update('12345')->assertOk();
    }
}
