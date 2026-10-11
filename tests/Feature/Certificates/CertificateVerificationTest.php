<?php

namespace Tests\Feature\Certificates;

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
            ->assertSee('This certificate has expired')
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
