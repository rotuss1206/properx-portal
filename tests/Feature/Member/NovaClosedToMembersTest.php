<?php

namespace Tests\Feature\Member;

use App\Enums\PersonType;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * With the member area on, Nova is closed to members, principals and agency people: its pages lead
 * to /member, its API answers 403, signing out still works. Staff keep Nova as before.
 */
class NovaClosedToMembersTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        config(['portal.member_area.enabled' => true]);
    }

    private function person(string $kind): User
    {
        if ($kind === 'member') {
            return $this->member();
        }

        $agency = $this->agency(3);

        if ($kind === 'principal') {
            return $agency->principal;
        }

        $profile = $agency->members()->where('user_id', '!=', $agency->principal_user_id)->firstOrFail();

        if ($kind === 'employee') {
            $profile->forceFill(['person_type' => PersonType::AgencyEmployee, 'npn' => null])->saveQuietly();
        }

        return $profile->user;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function people(): array
    {
        return ['individual member' => ['member'], 'agency principal' => ['principal'], 'agency producer' => ['producer'], 'agency employee' => ['employee']];
    }

    #[DataProvider('people')]
    public function test_nova_pages_lead_to_the_member_area(string $kind): void
    {
        $user = $this->person($kind);
        $profile = $user->memberProfile;
        $this->actingAs($user);

        $this->get('/nova')->assertRedirect(route('member.dashboard'));
        $this->get('/nova/dashboards/main')->assertRedirect(route('member.dashboard'));
        $this->get('/nova/resources/member-profiles/'.$profile->id)->assertRedirect(route('member.profile'));
        $this->get('/nova/user-security')->assertRedirect(route('member.profile', ['edit' => 'security']));
        $this->get('/nova/resources/agencies')->assertRedirect(route('member.dashboard'));

        // An Inertia visit leaves Nova for a full page load.
        $this->get('/nova/resources/library-documents', ['X-Inertia' => 'true'])->assertStatus(409);
    }

    #[DataProvider('people')]
    public function test_novas_api_is_closed(string $kind): void
    {
        $user = $this->person($kind);
        $this->actingAs($user);

        $this->getJson('/nova-api/member-profiles')->assertForbidden();
        $this->getJson('/nova-api/member-profiles/'.$user->memberProfile->id)->assertForbidden();
        $this->getJson('/nova-api/nova-notifications')->assertForbidden();
        $this->putJson('/nova-api/member-profiles/'.$user->memberProfile->id, ['first_name' => 'Changed'])->assertForbidden();
        $this->postJson('/nova-api/member-profiles/action?action=generate-certificate', ['resources' => (string) $user->memberProfile->id])->assertForbidden();

        $this->assertNotSame('Changed', $user->memberProfile->fresh()->first_name);
    }

    public function test_members_still_sign_out_through_nova(): void
    {
        $this->actingAs($this->member())->post(route('nova.logout'))->assertRedirect();

        $this->assertGuest();
    }

    /**
     * @return array<string, array{0: Role}>
     */
    public static function staffRoles(): array
    {
        return ['Executive Director' => [Role::ExecutiveDirector], 'Membership Coordinator' => [Role::MembershipCoordinator], 'Compliance Specialist' => [Role::ComplianceSpecialist]];
    }

    #[DataProvider('staffRoles')]
    public function test_staff_keep_nova(Role $role): void
    {
        $this->actingAs($this->staff($role));

        $this->get('/nova/dashboards/main')->assertOk();
        $this->getJson('/nova-api/member-profiles')->assertOk();
        $this->get('/nova/user-security')->assertOk();
    }

    public function test_with_the_member_area_off_members_still_use_nova(): void
    {
        config(['portal.member_area.enabled' => false]);
        $member = $this->member();

        $this->actingAs($member)->getJson('/nova-api/member-profiles/'.$member->memberProfile->id)->assertOk();
        $this->get('/nova/user-security')->assertOk();
    }
}
