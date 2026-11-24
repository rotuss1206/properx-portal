<?php

namespace Tests\Feature\Agencies;

use App\Enums\AgencyStatus;
use App\Enums\AgencyTier;
use App\Enums\PersonType;
use App\Enums\VerificationStatus;
use App\Livewire\AgencyRegistration\PeopleCsv;
use App\Livewire\AgencyRegistration\Wizard;
use App\Models\Agency;
use App\Models\AgencyRegistrationDraft;
use App\Models\LicenseDocument;
use App\Models\PendingLicenseFile;
use App\Services\Agencies\AgencyEnrollment;
use App\Support\LicenseCopy;
use App\Support\LicenseTypes;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\DemoFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * The client's rule: every licensed producer of an agency has a license number, resident state,
 * license types and a copy of the license before the agency pays (LicenseCopy). The NPN is optional.
 */
class AgencyLicenseCopyTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    private const AGENCY = [
        'legal_name' => 'Lone Star Benefits Group LLC',
        'agency_type' => 'health_and_life',
        'fein' => '74-1234567',
        'legal_address' => ['line1' => '400 Congress Ave', 'line2' => '', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78701'],
        'phone' => '(512) 555-0142',
        'billing_email' => 'billing@lonestarbenefits.example',
        'referral_source' => 'partner',
    ];

    private const PRINCIPAL = [
        'first_name' => 'Dana',
        'last_name' => 'Whitfield',
        'title' => 'owner',
        'email' => 'dana@lonestarbenefits.example',
        'mobile_phone' => '512-555-0199',
        'npn' => '',
        'license_state' => 'TX',
        'license_number' => 'tx-1001',
        'license_types' => ['Life', 'Other'],
        'license_type_other' => 'Surplus lines',
        'authority_confirmed' => true,
    ];

    private const PRODUCER = ['type' => 'licensed_producer', 'first_name' => 'Marcus', 'last_name' => 'Reyes', 'email' => 'marcus@lonestarbenefits.example', 'npn' => '', 'license_state' => 'TX', 'license_number' => 'TX-2002', 'license_types' => ['Health']];

    private const EMPLOYEE = ['type' => 'agency_employee', 'first_name' => 'Priya', 'last_name' => 'Nair', 'email' => 'priya@lonestarbenefits.example'];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'agency.require_license_copy' => true,
            'portal.registration.enabled' => true,
            'services.stripe.key' => 'pk_test_fake',
            'services.stripe.secret' => 'sk_test_fake',
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'America/New_York'));
    }

    private function pdf(string $name = 'license.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, DemoFile::pdf('License'));
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
     * Screens 1-3 done, the principal's answers typed on screen 4 (no copy yet).
     */
    private function principalScreen(): Testable
    {
        $wizard = Livewire::test(Wizard::class)
            ->set('membership.type', 'agency')->call('next')
            ->set('tier.tier', 'agency_2to9')->call('next');
        $this->fill($wizard, 'agency', self::AGENCY)->call('next');

        return $this->fill($wizard->assertSet('step', 4), 'principal', self::PRINCIPAL);
    }

    private function peopleScreen(): Testable
    {
        return $this->principalScreen()
            ->set('principalLicenseUpload', $this->pdf())
            ->call('next')
            ->assertHasNoErrors()
            ->assertSet('step', 5);
    }

    private function draft(): AgencyRegistrationDraft
    {
        return AgencyRegistrationDraft::query()->sole();
    }

    // The principal (screen 4)

    public function test_the_principal_needs_a_license_copy_and_no_npn(): void
    {
        $wizard = $this->principalScreen()
            ->assertSee('Your license')
            ->call('next')
            ->assertSet('step', 4)
            ->assertHasErrors(['principal.license_file' => 'required'])
            ->assertHasNoErrors(['principal.npn', 'principal.license_number', 'principal.license_types'])
            ->assertSee('Upload a copy of your license (PDF, JPG or PNG, up to 10 MB).');

        $wizard->set('principalLicenseUpload', $this->pdf('Dana license.pdf'))
            ->assertHasNoErrors('principal.license_file')
            ->assertSet('principal.license_file_name', 'Dana license.pdf')
            ->assertSet('principalLicenseUpload', null);

        $file = PendingLicenseFile::query()->sole();
        $this->assertSame([PendingLicenseFile::OWNER_DRAFT, $this->draft()->getKey()], [$file->owner_type, $file->owner_id]);
        $this->assertSame($file->getKey(), $wizard->get('principal.license_file'));
        // Encrypted in the vault under a random name; the form holds the record's id only.
        Storage::disk('azure')->assertExists($file->stored_filename);
        $this->assertStringEndsWith('.enc', $file->stored_filename);
        $this->assertStringNotContainsString('%PDF', Storage::disk('azure')->get($file->stored_filename));

        $wizard->call('next')->assertHasNoErrors()->assertSet('step', 5)
            ->assertSet('principal.license_number', 'TX-1001');
    }

    public function test_a_file_that_is_not_a_pdf_jpg_or_png_is_refused(): void
    {
        $this->principalScreen()
            ->set('principalLicenseUpload', UploadedFile::fake()->createWithContent('license.pdf', 'just text, not a PDF'))
            ->assertHasErrors('principal.license_file')
            ->assertSet('principal.license_file', '');

        $this->assertSame(0, PendingLicenseFile::query()->count());
    }

    public function test_other_needs_its_words_and_an_npn_given_must_be_7_to_10_digits(): void
    {
        $this->principalScreen()
            ->set('principalLicenseUpload', $this->pdf())
            ->set('principal.license_type_other', '')
            ->set('principal.npn', '123')
            ->set('principal.license_number', '#1')
            ->call('next')
            ->assertSet('step', 4)
            ->assertHasErrors(['principal.license_types', 'principal.npn', 'principal.license_number'])
            ->assertSee('Say which other license type.');
    }

    // People (screen 5)

    public function test_a_producer_added_by_hand_needs_a_copy_an_employee_does_not(): void
    {
        $wizard = $this->fill($this->peopleScreen(), 'people.entry', self::PRODUCER)
            ->call('savePerson')
            ->assertHasErrors(['people.entry.license_file' => 'required'])
            ->assertSet('people.rows', []);

        $wizard->set('licenseUpload', $this->pdf('marcus.pdf'))
            ->assertSet('people.entry.license_file_name', 'marcus.pdf')
            ->call('savePerson')
            ->assertHasNoErrors()
            ->assertCount('people.rows', 1)
            ->assertSet('people.rows.0.npn', '');

        $this->fill($wizard, 'people.entry', self::EMPLOYEE + ['license_number' => 'X-1', 'license_types' => ['Life']])
            ->call('savePerson')
            ->assertHasNoErrors()
            ->assertSet('people.rows.1.license_number', '')
            ->assertSet('people.rows.1.license_types', [])
            ->assertSet('missingLicenseCopies', []);
    }

    public function test_csv_rows_wait_for_a_copy_and_a_row_upload_settles_it(): void
    {
        $csv = PeopleCsv::template()
            ."licensed_producer,Marcus,Reyes,marcus@lonestarbenefits.example,,TX,TX-2002,\"Life; Health\"\r\n"
            ."agency_employee,Priya,Nair,priya@lonestarbenefits.example,,,,\r\n";

        $wizard = $this->peopleScreen()
            ->call('importPeople', 'people.csv', $csv)
            ->assertSet('people.problems', [])
            ->assertCount('people.rows', 2)
            ->assertSet('people.rows.0.license_types', ['Life', 'Health'])
            ->assertSee(LicenseCopy::NEEDED)
            ->assertSet('missingLicenseCopies', ['Marcus Reyes'])
            // Continuing is allowed; paying is not.
            ->call('next')
            ->assertSet('step', 6)
            ->assertSee('Marcus Reyes');

        $this->ready($wizard)->call('preparePayment', 'ctoken_debit')
            ->assertSet('checkout', [])
            ->assertSee('Upload a copy of the license for Marcus Reyes before paying');

        $wizard->call('goTo', 5)
            ->set('rowLicenseUploads.0', $this->pdf())
            ->assertHasNoErrors()
            ->assertSet('missingLicenseCopies', [])
            ->assertDontSee(LicenseCopy::NEEDED);
        $this->assertNotSame('', $wizard->get('people.rows.0.license_file'));
    }

    public function test_another_enrollment_s_file_does_not_count(): void
    {
        $wizard = $this->peopleScreen();
        $foreign = PendingLicenseFile::store($this->pdf(), PendingLicenseFile::OWNER_DRAFT, $this->draft()->getKey() + 1);

        $wizard
            ->call('importPeople', 'people.csv', PeopleCsv::template()."licensed_producer,Marcus,Reyes,marcus@lonestarbenefits.example,,TX,TX-2002,Life\r\n")
            ->set('people.rows.0.license_file', $foreign->getKey())
            ->assertSet('missingLicenseCopies', ['Marcus Reyes']);

        $wizard->set('principal.license_file', $foreign->getKey())
            ->assertSet('missingLicenseCopies', ['Dana Whitfield', 'Marcus Reyes']);
    }

    public function test_an_older_csv_without_license_columns_leaves_producers_to_fix(): void
    {
        $this->peopleScreen()
            ->call('importPeople', 'people.csv', "type,first_name,last_name,email,npn,state\r\n"
                ."licensed_producer,Marcus,Reyes,marcus@lonestarbenefits.example,20304050,TX\r\n"
                ."agency_employee,Priya,Nair,priya@lonestarbenefits.example,,\r\n")
            ->assertCount('people.rows', 1)
            ->assertSet('people.problems.0.errors', [
                'license_number' => 'Enter the license number of this licensed producer.',
                'license_types' => 'Choose at least one license type.',
            ]);
    }

    public function test_with_the_rule_off_nobody_needs_a_copy(): void
    {
        config(['agency.require_license_copy' => false]);

        $this->fill($this->principalScreen()->call('next')->assertSet('step', 5), 'people.entry', self::PRODUCER)
            ->call('savePerson')
            ->assertHasNoErrors()
            ->assertSet('missingLicenseCopies', [])
            ->assertDontSee(LicenseCopy::NEEDED);
    }

    private function ready(Testable $wizard): Testable
    {
        foreach (['terms', 'dues_separate', 'annual_no_refund', 'roster_confirmed'] as $consent) {
            $wizard->set("review.{$consent}", true);
        }

        // "Review payment" stops before anything is asked of Stripe (FakeStripe fails on any request).
        return $wizard;
    }

    // The person is created

    public function test_the_copy_becomes_a_license_document_pending_review(): void
    {
        $this->seedRoles();
        $agency = Agency::factory()->create(['tier' => AgencyTier::Agency, 'status' => AgencyStatus::Active]);
        $file = PendingLicenseFile::store($this->pdf('marcus.pdf'), PendingLicenseFile::OWNER_AGENCY, $agency->getKey());

        $user = app(AgencyEnrollment::class)->createPerson($agency, [
            'type' => PersonType::LicensedProducer->value, 'first_name' => 'Marcus', 'last_name' => 'Reyes', 'email' => 'marcus@lonestarbenefits.example',
            'phone' => null, 'npn' => '', 'license_state' => 'TX', 'license_number' => 'TX-2002',
            'license_types' => ['Health', 'Other'], 'license_type_other' => 'Surplus lines', 'license_file' => $file->getKey(), 'principal' => false,
        ], [PendingLicenseFile::OWNER_AGENCY, $agency->getKey()]);

        $profile = $user->memberProfile;
        $this->assertNull($profile->npn);
        $this->assertSame('TX-2002', $profile->license_number);
        $this->assertSame('Health, Other: Surplus lines', $profile->license_type);

        $document = LicenseDocument::query()->sole();
        $this->assertSame($user->getKey(), $document->user_id);
        $this->assertSame(VerificationStatus::Pending, $document->verification_status);
        $this->assertSame(['marcus.pdf', 'TX-2002', 'TX', 'resident'], [$document->original_filename, $document->license_number, $document->license_state, $document->document_type]);
        $this->assertSame($file->stored_filename, $document->stored_filename);
        $this->assertModelMissing($file);
    }

    public function test_a_file_of_another_owner_is_never_attached(): void
    {
        $this->seedRoles();
        $agency = Agency::factory()->create(['tier' => AgencyTier::Agency, 'status' => AgencyStatus::Active]);
        $file = PendingLicenseFile::store($this->pdf(), PendingLicenseFile::OWNER_AGENCY, $agency->getKey() + 1);

        app(AgencyEnrollment::class)->createPerson($agency, self::PRODUCER + [
            'phone' => null, 'license_type_other' => '', 'license_file' => $file->getKey(), 'principal' => false,
        ], [PendingLicenseFile::OWNER_AGENCY, $agency->getKey()]);

        $this->assertSame(0, LicenseDocument::query()->count());
        $this->assertModelExists($file);
    }

    public function test_unused_copies_are_pruned_with_their_file(): void
    {
        $old = PendingLicenseFile::store($this->pdf(), PendingLicenseFile::OWNER_DRAFT, 1);
        $old->forceFill(['created_at' => now()->subDays(PendingLicenseFile::KEEP_DAYS + 1)])->save();
        $recent = PendingLicenseFile::store($this->pdf(), PendingLicenseFile::OWNER_DRAFT, 2);

        $this->artisan('model:prune', ['--model' => [PendingLicenseFile::class]])->assertSuccessful();

        $this->assertModelMissing($old);
        Storage::disk('azure')->assertMissing($old->stored_filename);
        $this->assertModelExists($recent);
        Storage::disk('azure')->assertExists($recent->stored_filename);
    }

    // License types

    public function test_license_types_from_a_spreadsheet_cell(): void
    {
        $this->assertSame(['types' => ['Life', 'Health'], 'other' => ''], LicenseTypes::fromText('health; LIFE'));
        $this->assertSame(['types' => ['Property & Casualty', 'Other'], 'other' => 'Surplus lines, Crop'], LicenseTypes::fromText('P&C, Other: Surplus lines, Crop'));
        $this->assertSame(['types' => [], 'other' => ''], LicenseTypes::fromText(' '));
    }

    public function test_license_types_for_the_profile(): void
    {
        $this->assertSame('Life, Health', LicenseTypes::forProfile(['Health', 'Life', 'Bogus'], 'ignored'));
        $this->assertSame('Life, Other: Surplus lines', LicenseTypes::forProfile(['Other', 'Life'], ' Surplus lines '));
        $this->assertNull(LicenseTypes::forProfile([], ''));
    }
}
