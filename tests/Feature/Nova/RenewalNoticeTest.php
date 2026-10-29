<?php

namespace Tests\Feature\Nova;

use App\Enums\Role;
use App\Support\PortalSidebar;
use App\Support\RenewalNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * When the sidebar shows the "Renewal due" card (paid period from Stripe ends within 90 days).
 */
class RenewalNoticeTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        config(['portal.payments.payment_url' => 'https://pay.example.test/portal']);
    }

    /**
     * @return array<string, array{int|null, int|null}>
     */
    public static function periods(): array
    {
        return [
            'renews in 76 days' => [76, 76],
            'renews today' => [0, 0],
            'last day of the window' => [90, 90],
            'beyond 90 days' => [91, null],
            'period already ended' => [-1, null],
            'no Stripe date yet' => [null, null],
        ];
    }

    #[DataProvider('periods')]
    public function test_members_see_the_card_within_90_days(?int $daysLeft, ?int $expected): void
    {
        $member = $this->member();
        $member->memberProfile->forceFill(['paid_through_at' => $daysLeft === null ? null : today()->addDays($daysLeft)->endOfDay()])->save();

        $notice = RenewalNotice::for($member->fresh());

        $this->assertSame($expected, $notice['days'] ?? null);

        if ($expected !== null) {
            $this->assertSame('https://pay.example.test/portal', $notice['paymentUrl']);
        }
    }

    public function test_staff_never_see_it(): void
    {
        $this->assertNull(RenewalNotice::for($this->staff(Role::ExecutiveDirector)));

        // Not even a staff account that also holds the Member role and a profile.
        $member = $this->member();
        $member->assignRole(Role::MembershipCoordinator->value);
        $member->memberProfile->forceFill(['paid_through_at' => today()->addDays(10)])->save();

        $this->assertNull(RenewalNotice::for($member->fresh()));
    }

    public function test_agency_people_follow_the_agency_and_only_the_principal_sees_it(): void
    {
        $agency = $this->agency(2);
        $agency->forceFill(['paid_through_at' => today()->addDays(30)->endOfDay()])->saveQuietly();

        $principal = $agency->principal()->first();
        $person = $agency->members()->where('user_id', '!=', $principal->id)->first()->user;

        $this->assertSame(30, RenewalNotice::for($principal->fresh())['days']);
        $this->assertNull(RenewalNotice::for($person->fresh()));
    }

    public function test_the_button_needs_the_payment_portal_url(): void
    {
        config(['portal.payments.payment_url' => null]);
        $member = $this->member();
        $member->memberProfile->forceFill(['paid_through_at' => today()->addDays(5)])->save();

        $this->assertSame(['days' => 5, 'paymentUrl' => null], RenewalNotice::for($member->fresh()));
    }

    public function test_the_sidebar_gets_the_card_and_the_person(): void
    {
        $member = $this->member();
        $member->memberProfile->forceFill(['paid_through_at' => today()->addDays(76)->endOfDay()])->save();

        $sidebar = $this->actingAs($member)->get('/nova/dashboards/my-membership')->assertOk()
            ->viewData('page')['props']['novaConfig']['portalSidebar'];

        $this->assertSame(76, $sidebar['renewal']['days']);
        $this->assertSame($member->memberProfile->fullName(), $sidebar['user']['name']);
        $this->assertSame('#'.$member->memberProfile->member_number, $sidebar['user']['caption']);
        $this->assertStringEndsWith('portal/img/logo-portal.png', $sidebar['logo']);

        $staff = PortalSidebar::for($this->staff(Role::ComplianceSpecialist));
        $this->assertNull($staff['renewal']);
        $this->assertSame(Role::ComplianceSpecialist->value, $staff['user']['caption']);
    }
}
