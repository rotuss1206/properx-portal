<?php

namespace Tests\Feature\Member;

use App\Enums\PaymentStatus;
use App\Enums\PersonType;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Certificates\CertificateGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertSee('Scan to verify')
            ->assertSee('Active')
            ->assertSee(route('certificates.open', $profile), false)
            ->assertSee(route('certificates.verify', $profile->certificate_code), false)
            ->assertSee(route('member.profile').'#contact', false);
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
            ->assertSee('Your certificate is not available')
            ->assertSee('while the membership is inactive')
            ->assertSee('Canceled')
            ->assertSee('ppx-cert__renew', false)
            ->assertSee('https://pay.example.test/portal', false)
            ->assertSee(route('member.profile'), false)
            ->assertDontSee('CERTIFICATE OF MEMBERSHIP')
            ->assertDontSee('Download PDF')
            ->assertDontSee($profile->certificate_code)
            ->assertDontSee(route('certificates.open', $profile), false);

        $this->get(route('certificates.open', $profile))->assertStatus(422);
    }

    public function test_an_expired_license_or_incomplete_profile_gets_the_reason(): void
    {
        $this->actingAs($this->member('expired'))->get(route('member.certificate'))->assertOk()
            ->assertSee('the license expired on')
            ->assertDontSee('ppx-cert__renew', false)
            ->assertDontSee('Download PDF');

        $incomplete = $this->member();
        $incomplete->memberProfile->forceFill(['license_expires_at' => null])->saveQuietly();

        $this->actingAs($incomplete->fresh())->get(route('member.certificate'))->assertOk()
            ->assertSee('license expiry date')
            ->assertDontSee('Download PDF');
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
            ->assertDontSee('Download PDF')
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

        $this->actingAs($member->fresh())->get(route('member.certificate'))->assertOk()->assertSee('Download PDF');
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
            ->assertSee('Download PDF')
            ->assertDontSee('href="'.route('member.license-documents').'"', false);
    }

    public function test_agency_people_without_a_paid_date_get_no_valid_through(): void
    {
        $agency = $this->agency(2);
        $agency->forceFill(['paid_through_at' => null])->saveQuietly();

        $this->actingAs($agency->principal()->first())->get(route('member.certificate'))->assertOk()
            ->assertSee('CERTIFICATE OF MEMBERSHIP')
            ->assertDontSee('VALID THROUGH')
            ->assertSee('requirements of membership');
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
