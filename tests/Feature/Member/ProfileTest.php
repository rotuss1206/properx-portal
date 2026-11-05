<?php

namespace Tests\Feature\Member;

use App\Enums\LicenseVerification;
use App\Enums\NotificationCategory;
use App\Enums\PaymentStatus;
use App\Enums\PersonType;
use App\Enums\Role;
use App\Member\ActivityFeed;
use App\Models\AuditLog;
use App\Models\MemberProfile;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Notifications\LicenseExpiryReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * The profile page: each section edited on its own, the same access as Nova, changed license data
 * back to review, audit without values, the unsubscribe link, password and recovery codes.
 */
class ProfileTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    private const CURRENT_PASSWORD = 'correct-horse-battery';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        config(['portal.member_area.enabled' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function licenseInput(MemberProfile $profile, array $changes = []): array
    {
        return [
            'license_state' => $profile->license_state,
            'license_type' => $profile->licenseTypeList(),
            'license_number' => $profile->license_number,
            'license_expires_at' => $profile->license_expires_at->format('Y-m-d'),
            ...$changes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function businessInput(MemberProfile $profile, array $changes = []): array
    {
        return ['firm_name' => $profile->firm_name, 'npn' => $profile->npn, ...$changes];
    }

    private function verified(User $member): MemberProfile
    {
        // A date without a time, as MySQL's date column stores it (the factory's has one on SQLite).
        $profile = $member->memberProfile()->first();
        $profile->forceFill(['license_verification' => LicenseVerification::Verified, 'license_expires_at' => today()->addYear()])->save();

        // The signed-in user object is reused between requests in a test.
        $member->setRelation('memberProfile', $profile->fresh());

        return $member->memberProfile;
    }

    private function agencyPerson(): User
    {
        $agency = $this->agency(2);

        return $agency->members()->where('user_id', '!=', $agency->principal_user_id)->first()->user;
    }

    private function employee(): User
    {
        $person = $this->agencyPerson();
        $person->memberProfile->forceFill(['person_type' => PersonType::AgencyEmployee, 'npn' => null, 'license_state' => null])->save();

        return $person->fresh();
    }

    private function auditValuesLeak(string ...$values): void
    {
        $all = AuditLog::query()->get()->toJson();

        foreach ($values as $value) {
            $this->assertStringNotContainsString($value, $all);
        }
    }

    // The page

    public function test_the_page_shows_every_section_and_no_form_until_edit(): void
    {
        $member = $this->member();
        $profile = $member->memberProfile;

        $this->actingAs($member)->get(route('member.profile'))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee($profile->fullName())
            // The email under Contact, the NPN under Business.
            ->assertSeeInOrder(['Contact information', $member->email, 'Business details', 'National Producer Number', $profile->npn, 'License information', 'Resident state', 'Security', 'Two-factor authentication', 'Notifications'], false)
            ->assertDontSee('Account &amp; security', false)
            ->assertSee('8 available')
            ->assertSee('Enabled')
            ->assertDontSee('name="first_name"', false)
            ->assertDontSee('name="npn"', false);

        $this->get(route('member.profile', ['edit' => 'contact']))->assertOk()
            ->assertSee('name="first_name"', false)
            ->assertSee(route('member.profile').'#contact', false)
            ->assertDontSee('name="npn"', false);

        $this->get(route('member.profile', ['edit' => 'business']))->assertOk()
            ->assertSee('name="firm_name"', false)
            ->assertSee('name="npn"', false)
            ->assertDontSee('name="license_state"', false);

        $this->get(route('member.profile', ['edit' => 'nonsense']))->assertOk()->assertDontSee('name="first_name"', false);
    }

    public function test_the_page_runs_under_the_strict_csp_with_no_inline_scripts_or_handlers(): void
    {
        $response = $this->actingAs($this->member())->get(route('member.profile', ['edit' => 'license']))->assertOk();

        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bnonce=)[^>]*>/', $response->getContent());
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+="/', $response->getContent());
    }

    public function test_guests_and_staff_do_not_reach_it(): void
    {
        $this->get(route('member.profile'))->assertRedirect();
        $this->patch(route('member.profile.contact'), ['first_name' => 'X', 'last_name' => 'Y'])->assertRedirect();

        $staff = $this->staff(Role::MembershipCoordinator);
        $this->actingAs($staff)->get(route('member.profile'))->assertRedirect('/nova');
    }

    // Contact

    public function test_contact_details_are_saved_encrypted_and_audited_without_values(): void
    {
        $member = $this->member();
        $profile = $member->memberProfile;

        $this->actingAs($member)->patch(route('member.profile.contact'), [
            'first_name' => 'Jordan',
            'last_name' => 'Mercer',
            'phone' => '(614) 555-0188',
            'address' => "124 W Main St, Suite 200\nColumbus, OH 43215",
        ])->assertRedirect(route('member.profile').'#contact')->assertSessionHas('status');

        $profile->refresh();
        $this->assertSame('Jordan', $profile->first_name);
        $this->assertSame('(614) 555-0188', $profile->phone);
        $this->assertStringNotContainsString('555-0188', (string) $profile->getRawOriginal('phone'));
        $this->assertStringNotContainsString('Columbus', (string) $profile->getRawOriginal('address'));

        $log = AuditLog::where('action', 'profile_updated')->where('resource_id', $profile->id)->sole();
        $this->assertSame($member->id, $log->user_id);
        $this->assertEqualsCanonicalizing(['first_name', 'last_name', 'phone', 'address'], $log->details['fields']);
        $this->auditValuesLeak('Mercer', '555-0188', 'Columbus');
    }

    public function test_contact_errors_are_written_for_people_and_keep_the_section_open(): void
    {
        $member = $this->member();

        $input = ['first_name' => '', 'last_name' => 'Mercer', 'phone' => '12345'];

        $this->actingAs($member)->followingRedirects()->patch(route('member.profile.contact'), $input)->assertOk()
            ->assertSee('Enter your first name.')
            ->assertSee('aria-invalid=true', false)
            ->assertSee('aria-describedby="ppx-default-first_name-error"', false)
            ->assertSee('value="Mercer"', false)
            ->assertSee('name="first_name"', false);

        $this->assertNotSame('Mercer', $member->memberProfile->fresh()->last_name);

        $this->followingRedirects = false;
        $this->patch(route('member.profile.contact'), $input)
            ->assertRedirect(route('member.profile', ['edit' => 'contact']))
            ->assertSessionHasErrors([
                'first_name' => 'Enter your first name.',
                'phone' => 'Enter the phone number as a 10-digit US number, e.g. (512) 555-0142.',
            ]);
    }

    public function test_an_old_phone_number_does_not_block_other_changes(): void
    {
        $member = $this->member();
        $member->memberProfile->forceFill(['phone' => '555-OLD'])->save();

        $this->actingAs($member)->patch(route('member.profile.contact'), [
            'first_name' => 'Jordan',
            'last_name' => 'Mercer',
            'phone' => '555-OLD',
        ])->assertSessionHasNoErrors();
    }

    public function test_saving_without_changes_writes_no_audit_entry(): void
    {
        $member = $this->member();
        $profile = $member->memberProfile;

        $this->actingAs($member)->patch(route('member.profile.contact'), [
            'first_name' => $profile->first_name,
            'last_name' => $profile->last_name,
            'phone' => $profile->phone,
            'address' => $profile->address,
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, AuditLog::where('action', 'profile_updated')->count());
    }

    public function test_a_forged_request_changes_nothing_outside_its_section(): void
    {
        $member = $this->member();
        $profile = $member->memberProfile;
        $npn = $profile->npn;

        $this->actingAs($member)->patch(route('member.profile.contact'), [
            'first_name' => 'Jordan',
            'last_name' => 'Mercer',
            'npn' => '99999999',
            'status' => 'inactive',
            'payment_status' => 'active',
            'license_verification' => 'verified',
            'user_id' => 1,
        ])->assertSessionHasNoErrors();

        $profile->refresh();
        $this->assertSame($npn, $profile->npn);
        $this->assertSame($member->id, $profile->user_id);
        $this->assertSame(LicenseVerification::PendingReview, $profile->license_verification);
        $this->assertSame('active', $profile->status->value);
    }

    public function test_members_edit_only_their_own_profile(): void
    {
        $member = $this->member();
        $other = $this->member();
        $otherName = $other->memberProfile->first_name;

        $this->actingAs($member)->patch(route('member.profile.contact'), [
            'first_name' => 'Jordan',
            'last_name' => 'Mercer',
            'member_profile_id' => $other->memberProfile->id,
        ]);

        $this->assertSame('Jordan', $member->memberProfile->fresh()->first_name);
        $this->assertSame($otherName, $other->memberProfile->fresh()->first_name);
    }

    // Business

    public function test_the_firm_name_is_saved_and_audited(): void
    {
        $member = $this->member();

        $this->actingAs($member)->patch(route('member.profile.business'), $this->businessInput($member->memberProfile, ['firm_name' => 'Mercer Insurance Group']))
            ->assertRedirect(route('member.profile').'#business');

        $this->assertSame('Mercer Insurance Group', $member->memberProfile->fresh()->firm_name);
        $this->assertSame(['firm_name'], AuditLog::where('action', 'profile_updated')->sole()->details['fields']);
        $this->auditValuesLeak('Mercer Insurance');
    }

    public function test_an_npn_is_one_to_ten_digits_and_unique_as_on_the_site(): void
    {
        $member = $this->member();
        $other = $this->member();
        $profile = $member->memberProfile;

        $this->actingAs($member);

        foreach (['1', '1234567', '1234567890'] as $npn) {
            $this->patch(route('member.profile.business'), $this->businessInput($profile, ['npn' => $npn]))->assertSessionHasNoErrors();
            $this->assertSame($npn, $profile->fresh()->npn);
        }

        foreach (['12345678901', '12ab', '123 456'] as $npn) {
            $this->patch(route('member.profile.business'), $this->businessInput($profile, ['npn' => $npn]))
                ->assertRedirect(route('member.profile', ['edit' => 'business']))
                ->assertSessionHasErrors(['npn' => 'The NPN must be up to 10 digits, numbers only.']);
        }

        $this->patch(route('member.profile.business'), $this->businessInput($profile, ['npn' => $other->memberProfile->npn]))
            ->assertSessionHasErrors(['npn' => 'This NPN is already registered in the portal. Please contact ProPerX.']);
    }

    public function test_an_old_npn_or_none_at_all_does_not_block_the_firm_name(): void
    {
        $member = $this->member();
        $profile = $member->memberProfile;

        $profile->forceFill(['npn' => 'OLD-123'])->save();
        $this->actingAs($member)->patch(route('member.profile.business'), ['firm_name' => 'First', 'npn' => 'OLD-123'])->assertSessionHasNoErrors();
        $this->assertSame('First', $profile->fresh()->firm_name);

        $profile->fresh()->forceFill(['npn' => null])->save();
        $member->setRelation('memberProfile', $profile->fresh());
        $this->patch(route('member.profile.business'), ['firm_name' => 'Second', 'npn' => ''])->assertSessionHasNoErrors();
        $this->assertSame('Second', $profile->fresh()->firm_name);
    }

    // License

    public function test_changed_license_data_asks_first_then_goes_back_to_review(): void
    {
        $member = $this->member();
        $profile = $this->verified($member);
        $oldNpn = $profile->npn;

        foreach ([
            ['business', ['npn' => '12345678']],
            ['license', ['license_state' => $profile->license_state === 'TX' ? 'OH' : 'TX']],
            ['license', ['license_number' => 'NEW-123']],
            ['license', ['license_number' => '']],
            ['license', ['license_expires_at' => today()->addYears(3)->format('Y-m-d')]],
        ] as [$section, $change]) {
            $profile = $this->verified($member);
            $storedNpn = $profile->npn;
            $input = $section === 'business' ? $this->businessInput($profile, $change) : $this->licenseInput($profile, $change);

            $this->actingAs($member)->patch(route('member.profile.'.$section), $input)
                ->assertRedirect(route('member.profile', ['edit' => $section]))
                ->assertSessionHas('license_review_warning');

            // Nothing is stored before the confirmation.
            $this->assertSame(LicenseVerification::Verified, $profile->fresh()->license_verification);
            $this->assertSame($storedNpn, $profile->fresh()->npn);

            $this->get(route('member.profile', ['edit' => $section]))
                ->assertSee('Your license will go back to review.')
                ->assertSee('Save and send for review')
                ->assertSee('name="confirm_review"', false);

            $this->patch(route('member.profile.'.$section), $input + ['confirm_review' => '1'])
                ->assertRedirect(route('member.profile').'#'.$section);

            $this->assertSame(LicenseVerification::PendingReview, $profile->fresh()->license_verification, json_encode(array_keys($change)));
        }

        $this->assertNotSame($oldNpn, $profile->fresh()->npn);
        $this->assertSame('12345678', MemberProfile::whereBlindIndex('npn', '12345678')->sole()->npn);
        $this->assertSame(0, MemberProfile::whereBlindIndex('npn', $oldNpn)->count());
    }

    public function test_changing_only_the_license_type_keeps_the_verification_and_asks_nothing(): void
    {
        $member = $this->member();
        $profile = $this->verified($member);

        $this->actingAs($member)->patch(route('member.profile.license'), $this->licenseInput($profile, ['license_type' => ['Annuity']]))
            ->assertRedirect(route('member.profile').'#license')
            ->assertSessionMissing('license_review_warning');

        $this->assertSame('Annuity', $profile->fresh()->license_type);
        $this->assertSame(LicenseVerification::Verified, $profile->fresh()->license_verification);
    }

    public function test_several_license_types_are_kept_comma_separated_as_the_intake_stores_them(): void
    {
        $member = $this->member();
        $profile = $member->memberProfile;

        $this->actingAs($member)->get(route('member.profile', ['edit' => 'license']))->assertOk()
            ->assertSeeInOrder(['value="Life"', 'value="Health"', 'value="Property &amp; Casualty"', 'value="Annuity"', 'value="Variable"'], false)
            ->assertSee('name="license_type[]"', false);

        $this->patch(route('member.profile.license'), $this->licenseInput($profile, ['license_type' => ['Life', 'Health']]))->assertSessionHasNoErrors();
        $this->assertSame('Life, Health', $profile->fresh()->license_type);

        $this->get(route('member.profile'))->assertSee('Life, Health');

        $form = $this->get(route('member.profile', ['edit' => 'license']))->getContent();
        $this->assertMatchesRegularExpression('/value="Life"\s+checked/', $form);
        $this->assertMatchesRegularExpression('/value="Health"\s+checked/', $form);
        $this->assertDoesNotMatchRegularExpression('/value="Annuity"\s+checked/', $form);
    }

    public function test_an_existing_license_type_is_not_lost(): void
    {
        $member = $this->member();
        $member->memberProfile->forceFill(['license_type' => 'Health, Life & Health'])->save();
        $profile = $this->verified($member);
        AuditLog::query()->delete();

        // Off the site's list, yet still on the form, ticked.
        $form = $this->actingAs($member)->get(route('member.profile', ['edit' => 'license']))->getContent();
        $this->assertMatchesRegularExpression('/value="Life &amp; Health"\s+checked/', $form);

        // Saved as it came, in any order, and that is no change at all.
        $this->patch(route('member.profile.license'), $this->licenseInput($profile, ['license_type' => ['Life & Health', 'Health']]))->assertSessionHasNoErrors();
        $this->assertSame('Health, Life & Health', $profile->fresh()->license_type);
        $this->assertSame(0, AuditLog::where('action', 'profile_updated')->count());

        // Editing something else keeps it.
        $this->patch(route('member.profile.license'), $this->licenseInput($profile, ['license_number' => 'NEW-1', 'confirm_review' => '1']))->assertSessionHasNoErrors();
        $this->assertSame('Health, Life & Health', $profile->fresh()->license_type);

        // Taken off by the member, it no longer validates or shows.
        $this->patch(route('member.profile.license'), $this->licenseInput($profile->fresh(), ['license_type' => ['Health', 'Variable']]))->assertSessionHasNoErrors();
        $this->assertSame('Health, Variable', $profile->fresh()->license_type);
        $this->patch(route('member.profile.license'), $this->licenseInput($profile, ['license_type' => ['Life & Health']]))
            ->assertSessionHasErrors(['license_type.0' => 'Choose your license types from the list.']);
    }

    public function test_the_license_number_is_optional_as_on_the_site(): void
    {
        $member = $this->member();
        $profile = $member->memberProfile;

        $this->actingAs($member)->get(route('member.profile', ['edit' => 'license']))
            ->assertDontSee('id="ppx-default-license_number" required', false);

        $this->patch(route('member.profile.license'), $this->licenseInput($profile, ['license_number' => '']))->assertSessionHasNoErrors();
        $this->assertNull($profile->fresh()->license_number);

        $this->get(route('member.profile'))->assertOk()->assertSee('License number');
    }

    public function test_a_failed_save_keeps_what_was_ticked(): void
    {
        $member = $this->member();
        $profile = $member->memberProfile;

        $form = $this->actingAs($member)->followingRedirects()
            ->patch(route('member.profile.license'), $this->licenseInput($profile, ['license_type' => ['Annuity'], 'license_state' => 'ZZ']))
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/value="Annuity"\s+checked/', $form);

        $form = $this->followingRedirects()->patch(route('member.profile.license'), $this->licenseInput($profile, ['license_type' => []]))
            ->assertOk()->assertSee('Choose at least one license type.')->getContent();

        $this->assertDoesNotMatchRegularExpression('/name="license_type\[\]"[^>]+checked/', $form);
    }

    public function test_a_license_already_in_review_is_saved_without_the_warning(): void
    {
        $member = $this->member();
        $profile = $member->memberProfile;
        $this->assertSame(LicenseVerification::PendingReview, $profile->license_verification);

        $this->actingAs($member)->patch(route('member.profile.license'), $this->licenseInput($profile, ['license_number' => 'NEW-123']))
            ->assertRedirect(route('member.profile').'#license')
            ->assertSessionMissing('license_review_warning');

        $this->assertSame('NEW-123', $profile->fresh()->license_number);
    }

    public function test_a_flagged_license_goes_back_to_review_too(): void
    {
        $member = $this->member();
        $profile = $member->memberProfile;
        $profile->forceFill(['license_verification' => LicenseVerification::NeedsAttention])->save();

        $this->actingAs($member)->patch(route('member.profile.license'), $this->licenseInput($profile->fresh(), ['license_number' => 'FIXED-1']))
            ->assertSessionHas('license_review_warning');
    }

    public function test_changing_license_data_through_nova_goes_back_to_review_as_well_but_not_for_staff(): void
    {
        config(['portal.member_area.enabled' => false]);
        $member = $this->member();
        $profile = $this->verified($member);

        $this->actingAs($member);
        $profile->update(['license_number' => 'BY-MEMBER']);
        $this->assertSame(LicenseVerification::PendingReview, $profile->fresh()->license_verification);

        $profile = $this->verified($member);
        $this->actingAs($this->staff(Role::MembershipCoordinator));
        $profile->update(['license_number' => 'BY-STAFF']);
        $this->assertSame(LicenseVerification::Verified, $profile->fresh()->license_verification);
    }

    public function test_license_errors_are_written_for_people(): void
    {
        $member = $this->member();
        $profile = $member->memberProfile;

        $this->actingAs($member)->patch(route('member.profile.license'), $this->licenseInput($profile, [
            'license_state' => 'ZZ',
            'license_type' => ['Astrology'],
            'license_expires_at' => 'soon',
        ]))->assertSessionHasErrors([
            'license_state' => 'Choose your resident state from the list.',
            'license_type.0' => 'Choose your license types from the list.',
            'license_expires_at' => 'Enter the expiration date as a full date, e.g. 09/30/2027.',
        ]);

        foreach ([[], null] as $none) {
            $this->patch(route('member.profile.license'), $this->licenseInput($profile, ['license_type' => $none]))
                ->assertSessionHasErrors(['license_type' => 'Choose at least one license type.']);
        }

        $this->patch(route('member.profile.license'), $this->licenseInput($profile, ['license_type' => 'Life']))
            ->assertSessionHasErrors(['license_type' => 'Choose your license types from the list.']);
    }

    // Agency people

    public function test_agency_people_edit_npn_and_state_only_and_see_their_agency(): void
    {
        $person = $this->agencyPerson();
        $profile = $person->memberProfile;
        $number = $profile->license_number;

        $this->actingAs($person)->get(route('member.profile'))->assertOk()
            ->assertSee($profile->agency->legal_name)
            ->assertSee('National Producer Number')
            ->assertSee('Resident state')
            ->assertDontSee('License number')
            ->assertDontSee('Expiration date');

        $this->get(route('member.profile', ['edit' => 'license']))->assertOk()
            ->assertSee('name="license_state"', false)
            ->assertDontSee('name="npn"', false)
            ->assertDontSee('license_type[]', false)
            ->assertDontSee('name="license_number"', false)
            ->assertDontSee('name="license_expires_at"', false);

        $this->get(route('member.profile', ['edit' => 'business']))->assertOk()
            ->assertSee('name="npn"', false)
            ->assertSee($profile->agency->legal_name);

        $this->patch(route('member.profile.business'), ['npn' => '23456789', 'confirm_review' => '1'])
            ->assertRedirect(route('member.profile').'#business');

        $this->patch(route('member.profile.license'), [
            'npn' => '34567890',
            'license_state' => 'OH',
            'license_number' => 'FORGED',
            'license_type' => ['Life'],
            'license_expires_at' => '2099-01-01',
            'confirm_review' => '1',
        ])->assertRedirect(route('member.profile').'#license');

        $profile->refresh();
        $this->assertSame('23456789', $profile->npn);
        $this->assertSame('OH', $profile->license_state);
        $this->assertSame($number, $profile->license_number);
    }

    public function test_agency_people_still_edit_their_contact_details_and_firm(): void
    {
        $person = $this->agencyPerson();

        $this->actingAs($person)->patch(route('member.profile.contact'), ['first_name' => 'Jordan', 'last_name' => 'Mercer'])->assertSessionHasNoErrors();
        $this->patch(route('member.profile.business'), $this->businessInput($person->memberProfile, ['firm_name' => 'Own Firm']))->assertSessionHasNoErrors();

        $this->assertSame('Own Firm', $person->memberProfile->fresh()->firm_name);
    }

    public function test_a_principal_cannot_clear_the_npn(): void
    {
        $principal = $this->agency(2)->principal()->first();

        $this->actingAs($principal)->patch(route('member.profile.business'), ['npn' => ''])
            ->assertSessionHasErrors(['npn' => 'Enter your National Producer Number.']);
    }

    public function test_agency_employees_hold_no_license_here(): void
    {
        $employee = $this->employee();

        $this->actingAs($employee)->get(route('member.profile'))->assertOk()
            ->assertSee('Not applicable: agency staff hold no license.')
            ->assertSee('Agency staff')
            ->assertDontSee('National Producer Number');

        $this->get(route('member.profile', ['edit' => 'license']))->assertOk()->assertDontSee('name="npn"', false);
        $this->get(route('member.profile', ['edit' => 'business']))->assertOk()->assertDontSee('name="npn"', false);

        $this->patch(route('member.profile.license'), ['license_state' => 'OH'])->assertForbidden();
        $this->patch(route('member.profile.business'), ['firm_name' => 'Own Firm', 'npn' => '23456789'])->assertSessionHasNoErrors();
        $this->assertNull($employee->memberProfile->fresh()->npn);
        $this->assertSame('Own Firm', $employee->memberProfile->fresh()->firm_name);

        $this->patch(route('member.profile.contact'), ['first_name' => 'Jordan', 'last_name' => 'Mercer'])->assertSessionHasNoErrors();
    }

    // Canceled membership

    public function test_a_canceled_membership_keeps_the_whole_profile_open_as_in_nova(): void
    {
        $member = $this->member();
        $member->memberProfile->forceFill(['payment_status' => PaymentStatus::Canceled])->save();
        $member = $member->fresh();
        $profile = $member->memberProfile;

        $this->actingAs($member)->get(route('member.profile'))->assertOk()->assertSee('Canceled');
        $this->patch(route('member.profile.contact'), ['first_name' => 'Jordan', 'last_name' => 'Mercer'])->assertSessionHasNoErrors();
        $this->patch(route('member.profile.license'), $this->licenseInput($profile, ['license_number' => 'STILL-1']))->assertSessionHasNoErrors();
        $this->patch(route('member.profile.notifications'), [])->assertSessionHasNoErrors();

        $this->assertSame('STILL-1', $profile->fresh()->license_number);
        $this->assertFalse(NotificationSetting::where('user_id', $member->id)->where('category', NotificationCategory::LicenseExpiry)->sole()->enabled);
    }

    // Notifications

    public function test_preferences_show_all_four_categories_on_by_default_and_are_saved(): void
    {
        $member = $this->member();

        $this->actingAs($member)->get(route('member.profile'))
            ->assertSeeInOrder(['License expiry', 'Compliance briefings', 'Tax documents available', 'General announcements'])
            ->assertSee('role="switch"', false);

        $this->patch(route('member.profile.notifications'), ['compliance_briefings' => '1', 'tax_documents' => '1'])
            ->assertRedirect(route('member.profile').'#notifications')
            ->assertSessionHas('status');

        $settings = NotificationSetting::where('user_id', $member->id)->get()->keyBy(fn ($setting) => $setting->category->value);
        $this->assertFalse($settings['license_expiry']->enabled);
        $this->assertFalse($settings['announcements']->enabled);
        $this->assertFalse(isset($settings['tax_documents']));

        $log = AuditLog::where('action', 'notification_settings_updated')->sole();
        $this->assertEqualsCanonicalizing(['license_expiry', 'announcements'], $log->details['disabled']);
        $this->assertSame($member->id, $log->user_id);

        // Turned on again; nothing changed means no entry.
        $all = ['license_expiry' => '1', 'compliance_briefings' => '1', 'tax_documents' => '1', 'announcements' => '1'];
        $this->patch(route('member.profile.notifications'), $all);
        $this->patch(route('member.profile.notifications'), $all);
        $this->assertSame(2, AuditLog::where('action', 'notification_settings_updated')->count());
    }

    public function test_a_reminder_is_not_sent_to_someone_who_turned_it_off_here(): void
    {
        $member = $this->member('expiring');

        $this->actingAs($member)->patch(route('member.profile.notifications'), ['compliance_briefings' => '1', 'tax_documents' => '1', 'announcements' => '1']);

        $this->assertFalse(NotificationSetting::where('user_id', $member->id)->where('category', NotificationCategory::LicenseExpiry)->sole()->enabled);
    }

    // Unsubscribe

    public function test_the_unsubscribe_link_opens_the_section_also_after_signing_in(): void
    {
        $member = $this->member('expiring');
        $url = LicenseExpiryReminder::preferencesUrl($member->memberProfile);

        $this->assertSame(route('member.profile').'?section=notifications', $url);

        $this->get($url)->assertRedirect();
        $this->assertSame($url, session('url.intended'));

        $this->actingAs($member)->get($url)->assertRedirect(route('member.profile').'#notifications');
    }

    public function test_with_the_member_area_off_the_unsubscribe_link_stays_in_nova(): void
    {
        config(['portal.member_area.enabled' => false]);
        $member = $this->member('expiring');
        $url = LicenseExpiryReminder::preferencesUrl($member->memberProfile);

        $this->assertSame(url("/nova/resources/member-profiles/{$member->memberProfile->id}/edit?section=notifications"), $url);
        $this->actingAs($member)->get($url)->assertOk();
    }

    // Password

    public function test_the_password_is_changed_with_the_current_one_and_the_session_stays(): void
    {
        $member = $this->member();
        $member->forceFill(['password' => self::CURRENT_PASSWORD])->save();

        $this->actingAs($member)->from(route('member.profile'))->patch(route('member.profile.password'), [
            'current_password' => 'wrong',
            'password' => 'a-brand-new-passphrase',
            'password_confirmation' => 'a-brand-new-passphrase',
        ])->assertRedirect(route('member.profile', ['edit' => 'security']))
            ->assertSessionHasErrors(['current_password' => "That isn't your current password."]);

        $this->patch(route('member.profile.password'), [
            'current_password' => self::CURRENT_PASSWORD,
            'password' => 'a-brand-new-passphrase',
            'password_confirmation' => 'different',
        ])->assertSessionHasErrors(['password' => "The two new passwords don't match."]);

        $this->patch(route('member.profile.password'), [
            'current_password' => self::CURRENT_PASSWORD,
            'password' => self::CURRENT_PASSWORD,
            'password_confirmation' => self::CURRENT_PASSWORD,
        ])->assertSessionHasErrors(['password' => 'Choose a password different from your current one.']);

        $this->assertTrue(Hash::check(self::CURRENT_PASSWORD, $member->fresh()->password));

        $this->patch(route('member.profile.password'), [
            'current_password' => self::CURRENT_PASSWORD,
            'password' => 'a-brand-new-passphrase',
            'password_confirmation' => 'a-brand-new-passphrase',
        ])->assertRedirect(route('member.profile').'#security')->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('a-brand-new-passphrase', $member->fresh()->password));
        $this->assertSame(1, AuditLog::where('action', 'password_changed')->where('user_id', $member->id)->count());
        $this->auditValuesLeak('a-brand-new-passphrase');

        $this->get(route('member.profile'))->assertOk()->assertSee('last changed '.now()->format('j M Y'));
    }

    public function test_the_email_is_read_only(): void
    {
        $member = $this->member();
        $email = $member->email;

        $this->actingAs($member)->get(route('member.profile', ['edit' => 'contact']))->assertOk()
            ->assertSee($email)
            ->assertSee('This is your sign-in.')
            ->assertDontSee('name="email"', false);

        $this->patch(route('member.profile.contact'), ['first_name' => 'Jordan', 'last_name' => 'Mercer', 'email' => 'forged@example.test']);
        $this->patch(route('member.profile.password'), ['email' => 'forged@example.test']);

        $this->assertSame($email, $member->fresh()->email);
    }

    // Recovery codes

    public function test_recovery_codes_are_replaced_only_with_the_password_and_shown_once(): void
    {
        $member = $this->member();
        $member->forceFill(['password' => self::CURRENT_PASSWORD])->save();
        $old = $member->fresh()->recoveryCodes();

        $this->actingAs($member)->post(route('member.profile.recovery-codes'), ['current_password' => 'wrong'])
            ->assertRedirect(route('member.profile', ['edit' => 'security']))
            ->assertSessionHasErrorsIn('recoveryCodes', ['current_password' => "That isn't your current password."]);

        $this->assertSame($old, $member->fresh()->recoveryCodes());
        $this->assertSame(0, AuditLog::where('action', 'mfa_recovery_codes_regenerated')->count());
        $this->get(route('member.profile.recovery-codes.download'))->assertNotFound();

        $this->post(route('member.profile.recovery-codes'), ['current_password' => self::CURRENT_PASSWORD])
            ->assertRedirect(route('member.profile', ['edit' => 'security']));

        $new = $member->fresh()->recoveryCodes();
        $this->assertNotSame($old, $new);
        $this->assertCount(8, $new);

        $this->get(route('member.profile', ['edit' => 'security']))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee($new[0])
            ->assertDontSee($old[0]);

        $download = $this->get(route('member.profile.recovery-codes.download'))->assertOk();
        $this->assertStringContainsString($new[0], $download->getContent());
        $this->assertStringContainsString('attachment', $download->headers->get('Content-Disposition'));

        // Not shown again.
        $this->get(route('member.profile', ['edit' => 'security']))->assertOk()->assertDontSee($new[0]);

        $this->assertSame(1, AuditLog::where('action', 'mfa_recovery_codes_regenerated')->where('user_id', $member->id)->count());
        $this->auditValuesLeak($new[0], $old[0]);
    }

    public function test_two_factor_cannot_be_turned_off_from_here(): void
    {
        $member = $this->member();

        $this->actingAs($member)->get(route('member.profile', ['edit' => 'security']))->assertOk()
            ->assertSee('cannot be turned off')
            ->assertDontSee('two-factor-authentication', false);

        $this->assertFalse(collect(app('router')->getRoutes())->contains(fn ($route) => str_starts_with((string) $route->uri(), 'member/') && str_contains((string) $route->uri(), 'two-factor')));
        $this->assertTrue($member->fresh()->hasConfirmedTwoFactor());
    }

    public function test_someone_without_two_factor_goes_to_the_setup_first(): void
    {
        $member = $this->member(withTwoFactor: false);

        $this->actingAs($member)->get(route('member.profile'))->assertRedirect(route('security.two-factor'));
        $this->post(route('member.profile.recovery-codes'), ['current_password' => 'x'])->assertRedirect(route('security.two-factor'));
    }

    // Activity

    public function test_saving_shows_in_the_activity_feed_with_section_names_only(): void
    {
        $member = $this->member();

        $this->actingAs($member)->patch(route('member.profile.contact'), ['first_name' => 'Jordan', 'last_name' => 'Mercer']);
        $this->patch(route('member.profile.notifications'), []);

        $feed = ActivityFeed::for($member->fresh());

        $this->assertContains('Notification preferences updated', $feed->pluck('title'));
        $this->assertSame('Contact information', $feed->firstWhere('title', 'Profile updated')['detail']);

        AuditLog::query()->where('action', 'profile_updated')->delete();
        $this->patch(route('member.profile.business'), ['npn' => '4567', 'confirm_review' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('Business details', ActivityFeed::for($member->fresh())->firstWhere('title', 'Profile updated')['detail']);
    }
}
