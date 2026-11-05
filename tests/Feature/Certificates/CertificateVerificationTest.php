<?php

namespace Tests\Feature\Certificates;

use App\Enums\PaymentStatus;
use App\Services\Certificates\CertificateGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

class CertificateVerificationTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    public function test_valid_certificate_shows_name_and_dates_only(): void
    {
        $member = $this->member();
        $profile = $member->memberProfile;

        $this->get(route('certificates.verify', $profile->certificate_code))
            ->assertOk()
            ->assertSee('Valid certificate')
            ->assertSee($profile->fullName())
            ->assertSee($profile->license_expires_at->toFormattedDateString())
            ->assertDontSee($member->email)
            ->assertDontSee($profile->phone)
            ->assertDontSee($profile->npn)
            ->assertDontSee($profile->address);
    }

    public function test_it_shows_the_same_dates_as_the_certificate(): void
    {
        $member = $this->member();
        $profile = $member->memberProfile;
        $profile->forceFill(['paid_through_at' => '2027-03-14 12:00:00', 'license_expires_at' => '2030-06-01'])->saveQuietly();

        $this->assertSame('2027-03-14', CertificateGeneratorService::validThrough($profile->fresh())->toDateString());

        $this->get(route('certificates.verify', $profile->certificate_code))
            ->assertOk()
            ->assertSee('Valid certificate')
            ->assertSee('Mar 14, 2027')
            ->assertSee($member->created_at->format('F Y'))
            ->assertSee($profile->certificate_code)
            ->assertDontSee('Jun 1, 2030');
    }

    public function test_it_falls_back_to_the_license_date_without_a_paid_period_or_when_configured(): void
    {
        $profile = $this->member()->memberProfile;
        $profile->forceFill(['paid_through_at' => null, 'license_expires_at' => '2030-06-01'])->saveQuietly();
        $this->assertSame('2030-06-01', CertificateGeneratorService::validThrough($profile->fresh())->toDateString());

        $profile->forceFill(['paid_through_at' => '2027-03-14 12:00:00'])->saveQuietly();
        config(['portal.member_area.certificate.valid_through' => 'license_expiry']);
        $this->assertSame('2030-06-01', CertificateGeneratorService::validThrough($profile->fresh())->toDateString());
    }

    public function test_an_ended_paid_period_shows_as_expired(): void
    {
        $profile = $this->member()->memberProfile;
        $profile->forceFill(['paid_through_at' => now()->subDays(3), 'license_expires_at' => now()->addYear()])->saveQuietly();

        $this->get(route('certificates.verify', $profile->certificate_code))
            ->assertOk()
            ->assertSee('This certificate has expired');
    }

    public function test_an_expired_license_ends_it_even_with_a_paid_period(): void
    {
        $profile = $this->member('expired')->memberProfile;
        $profile->forceFill(['paid_through_at' => now()->addYear()])->saveQuietly();

        $this->get(route('certificates.verify', $profile->certificate_code))
            ->assertOk()
            ->assertSee('no longer valid')
            ->assertDontSee('Valid certificate');
    }

    public function test_canceled_membership_is_not_shown_as_valid(): void
    {
        $profile = $this->member()->memberProfile;
        $profile->forceFill(['payment_status' => PaymentStatus::Canceled, 'payment_status_updated_at' => now()->subMonth()])->saveQuietly();

        $this->get(route('certificates.verify', $profile->certificate_code))
            ->assertOk()
            ->assertSee('Membership is not active')
            ->assertDontSee('Valid certificate');
    }

    public function test_lowercase_code_is_accepted(): void
    {
        $profile = $this->member()->memberProfile;

        $this->get(route('certificates.verify', strtolower($profile->certificate_code)))
            ->assertOk()
            ->assertSee('Valid certificate');
    }

    public function test_expired_certificate(): void
    {
        $profile = $this->member('expired')->memberProfile;

        $this->get(route('certificates.verify', $profile->certificate_code))
            ->assertOk()
            ->assertSee('no longer valid')
            ->assertSee($profile->fullName());
    }

    public function test_unknown_code_gets_a_neutral_answer(): void
    {
        $this->member();

        $this->get(route('certificates.verify', 'PPX-DOESNOTEXIST'))
            ->assertOk()
            ->assertSee('This certificate could not be verified')
            ->assertDontSee('Member</dt>', false);
    }

    public function test_verification_is_rate_limited(): void
    {
        foreach (range(1, config('portal.verification.requests_per_minute')) as $i) {
            $this->get(route('certificates.verify', "PPX-TRY{$i}"))->assertOk();
        }

        $this->get(route('certificates.verify', 'PPX-ONEMORE'))->assertTooManyRequests();
    }
}
