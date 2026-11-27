<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\AgencyRegistrationDraft;
use App\Models\AuditLog;
use App\Models\IntakeSubmission;
use App\Models\LicenseDocument;
use App\Models\MemberProfile;
use App\Models\PartnerCode;
use App\Models\PendingLicenseFile;
use App\Models\StripeEvent;
use App\Models\TaxDocument;
use App\Models\User;
use Database\Seeders\AgencySeeder;
use Database\Seeders\DemoLoginSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\MemberSeeder;
use Database\Seeders\StaffSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * php artisan app:purge-test-data: removes only the listed accounts and what belongs to them.
 */
class PurgeTestDataTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        // The real Executive Director stays in every test.
        $this->staff(Role::ExecutiveDirector)->update(['email' => 'anne@properx.org']);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: int, 1: string}
     */
    private function purge(array $options): array
    {
        $code = Artisan::call('app:purge-test-data', $options);

        return [$code, Artisan::output()];
    }

    private function disk()
    {
        return Storage::disk(config('portal.documents_disk'));
    }

    public function test_a_dry_run_is_the_default_and_removes_nothing(): void
    {
        $member = $this->member();
        $member->update(['email' => 'test.one@example.com']);

        [$code, $output] = $this->purge(['--email' => ['test.one@example.com']]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('test.one@example.com', $output);
        $this->assertStringContainsString('Dry run: nothing was removed', $output);
        $this->assertModelExists($member);
        $this->assertSame(0, AuditLog::query()->where('action', 'test_data_purged')->count());
    }

    public function test_force_removes_the_account_and_everything_that_belongs_to_it(): void
    {
        $member = $this->member();
        $member->update(['email' => 'test.one@example.com']);
        $profile = $member->memberProfile;
        $profile->forceFill(['stripe_customer_id' => 'cus_test1'])->save();

        $license = LicenseDocument::factory()->for($member)->create();
        $tax = TaxDocument::factory()->for($member)->create();
        $this->disk()->put($license->stored_filename, 'x');
        $this->disk()->put($tax->stored_filename, 'x');
        IntakeSubmission::query()->forceCreate(['source' => 'properx.org', 'entry_id' => '41', 'member_profile_id' => $profile->id, 'outcome' => 'created']);
        StripeEvent::query()->forceCreate(['id' => 'evt_one', 'type' => 'invoice.paid', 'outcome' => 'applied', 'member_profile_id' => $profile->id]);
        StripeEvent::query()->forceCreate(['id' => 'evt_pending', 'type' => 'invoice.paid', 'outcome' => 'applied', 'stripe_customer_id' => 'cus_test1']);
        StripeEvent::query()->forceCreate(['id' => 'evt_other', 'type' => 'invoice.paid', 'outcome' => 'applied', 'stripe_customer_id' => 'cus_someone_else']);
        DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => 'x', 'notifiable_type' => $member->getMorphClass(), 'notifiable_id' => $member->id, 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        $kept = $this->member();

        [$code, $output] = $this->purge(['--email' => ['Test.One@example.com'], '--force' => true]);

        $this->assertSame(0, $code, $output);
        $this->assertModelMissing($member);
        $this->assertModelMissing($profile);
        $this->assertModelMissing($license);
        $this->assertModelMissing($tax);
        $this->assertFalse($this->disk()->exists($license->stored_filename));
        $this->assertFalse($this->disk()->exists($tax->stored_filename));
        $this->assertSame(0, IntakeSubmission::query()->count());
        $this->assertSame(['evt_other'], StripeEvent::query()->pluck('id')->all());
        $this->assertSame(0, DB::table('notifications')->count());
        $this->assertSame(0, DB::table('model_has_roles')->where('model_id', $member->id)->where('model_type', $member->getMorphClass())->count());

        // Everything else stays.
        $this->assertModelExists($kept);
        $this->assertTrue(User::query()->where('email', 'anne@properx.org')->exists());
        $this->assertTrue(RoleModel::query()->where('name', Role::Member->value)->exists());

        $audit = AuditLog::query()->where('action', 'test_data_purged')->sole();
        $this->assertSame(1, $audit->details['counts']['accounts']);
        $this->assertSame(1, $audit->details['counts']['license_documents']);
        $this->assertStringNotContainsString('example.com', json_encode($audit->details));
    }

    public function test_a_domain_selects_member_accounts_only_and_never_staff(): void
    {
        $member = $this->member();
        $member->update(['email' => 'agent@test-agency.com']);
        $staff = $this->staff(Role::MembershipCoordinator);
        $staff->update(['email' => 'helper@test-agency.com']);
        $other = $this->member();
        $other->update(['email' => 'agent@test-agency.com.au']);

        [, $output] = $this->purge(['--domain' => ['test-agency.com'], '--force' => true]);

        $this->assertModelMissing($member);
        $this->assertModelExists($staff);
        $this->assertModelExists($other);
        $this->assertStringContainsString('Kept, staff matched by a domain only', $output);

        // Listed by email, staff go too.
        $this->purge(['--email' => ['helper@test-agency.com'], '--force' => true]);
        $this->assertModelMissing($staff);
    }

    public function test_the_file_lists_one_email_per_line(): void
    {
        $one = $this->member();
        $one->update(['email' => 'one@example.com']);
        $two = $this->member();
        $two->update(['email' => 'two@example.com']);
        $file = storage_path('framework/testing/purge-'.uniqid().'.txt');
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, "# test accounts\none@example.com\n\n");

        try {
            [$code] = $this->purge(['--file' => $file, '--force' => true]);
        } finally {
            unlink($file);
        }

        $this->assertSame(0, $code);
        $this->assertModelMissing($one);
        $this->assertModelExists($two);
    }

    public function test_nothing_is_guessed(): void
    {
        $this->member();
        $users = User::query()->count();

        $this->assertSame(2, $this->purge(['--force' => true])[0]);
        $this->assertSame(2, $this->purge(['--domain' => ['*.com'], '--force' => true])[0]);
        $this->assertSame(2, $this->purge(['--email' => ['not-an-email'], '--force' => true])[0]);
        $this->assertSame($users, User::query()->count());

        [$code, $output] = $this->purge(['--email' => ['nobody@example.com'], '--force' => true]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('No account (nothing to remove): nobody@example.com', $output);
        $this->assertStringContainsString('Nothing to remove', $output);
    }

    public function test_the_api_service_account_and_the_last_executive_director_stay(): void
    {
        $service = $this->apiService();

        $this->purge(['--email' => [$service->email], '--force' => true]);
        $this->assertModelExists($service);

        [$code, $output] = $this->purge(['--email' => ['anne@properx.org'], '--force' => true]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('every Executive Director', $output);
        $this->assertTrue(User::query()->where('email', 'anne@properx.org')->exists());
    }

    public function test_an_agency_goes_with_its_principal_only_when_nobody_else_on_it_stays(): void
    {
        $agency = $this->agency(3, ['stripe_customer_id' => 'cus_agency1', 'stripe_subscription_id' => 'sub_agency1']);
        $people = MemberProfile::query()->where('agency_id', $agency->id)->with('user')->get();
        $principal = User::query()->findOrFail($agency->principal_user_id);
        $copy = PendingLicenseFile::query()->forceCreate(['owner_type' => PendingLicenseFile::OWNER_AGENCY, 'owner_id' => $agency->id, 'stored_filename' => 'license-documents/pending.pdf.enc', 'original_filename' => 'a.pdf', 'mime_type' => 'application/pdf', 'size' => 1]);
        $this->disk()->put('license-documents/pending.pdf.enc', 'x');

        // The principal alone: the agency keeps its other people, so it stays.
        [, $output] = $this->purge(['--email' => [$principal->email], '--force' => true]);
        $this->assertModelExists($agency);
        $this->assertNull($agency->fresh()->principal_user_id);
        $this->assertStringContainsString("Agency #{$agency->id} is kept", $output);

        // Everyone on it: the agency goes too, with its pending copies. Stripe is never called
        // (FakeStripe fails on any request).
        $this->purge(['--email' => $people->pluck('user.email')->all(), '--force' => true]);
        $this->assertModelMissing($agency);
        $this->assertModelMissing($copy);
        $this->assertFalse($this->disk()->exists('license-documents/pending.pdf.enc'));
    }

    public function test_an_agency_with_all_its_people_listed_is_removed_together(): void
    {
        $agency = $this->agency(2);
        $emails = User::query()->whereIn('id', MemberProfile::query()->where('agency_id', $agency->id)->pluck('user_id'))->pluck('email')->all();

        [$code, $output] = $this->purge(['--email' => $emails, '--force' => true]);

        $this->assertSame(0, $code, $output);
        $this->assertModelMissing($agency);
        $this->assertSame(0, MemberProfile::query()->count());
    }

    public function test_enrollment_drafts_of_the_listed_emails_and_domains_go(): void
    {
        $mine = AgencyRegistrationDraft::query()->forceCreate(['token_hash' => str_repeat('a', 64), 'data' => ['principal' => ['email' => 'Owner@Test-Agency.com']], 'step' => 4, 'furthest_step' => 4, 'expires_at' => now()->addDays(30)]);
        $byEmail = AgencyRegistrationDraft::query()->forceCreate(['token_hash' => str_repeat('b', 64), 'data' => ['principal' => ['email' => 'solo@example.com']], 'step' => 4, 'furthest_step' => 4, 'expires_at' => now()->addDays(30)]);
        $other = AgencyRegistrationDraft::query()->forceCreate(['token_hash' => str_repeat('c', 64), 'data' => ['principal' => ['email' => 'real@agency.com']], 'step' => 4, 'furthest_step' => 4, 'expires_at' => now()->addDays(30)]);

        [$code, $output] = $this->purge(['--domain' => ['test-agency.com'], '--email' => ['solo@example.com'], '--force' => true]);

        $this->assertSame(0, $code, $output);
        $this->assertModelMissing($mine);
        $this->assertModelMissing($byEmail);
        $this->assertModelExists($other);
    }

    public function test_demo_selects_exactly_the_seeded_accounts(): void
    {
        config(['portal.demo_login_email' => 'kevin@example.com']);

        $expected = [...StaffSeeder::emails(), ...MemberSeeder::emails(), ...AgencySeeder::emails(), ...DemoLoginSeeder::emails('kevin@example.com')];
        $this->assertCount(3 + 30 + (5 + 12 + 55) + 6, $expected);
        $this->assertContains('agency03.person55@properx.test', $expected);
        $this->assertContains('kevin+agency@example.com', $expected);
        $this->assertContains('demo-agency.kevin.person02@properx.test', $expected);
        $this->assertSame([], DemoLoginSeeder::emails(null));

        $demo = $this->member();
        $demo->update(['email' => 'member07@properx.test']);
        $real = $this->member();
        $real->update(['email' => 'member31@properx.test']);
        PartnerCode::factory()->create(['code' => 'SUNBELT-MGA']);

        [$code, $output] = $this->purge(['--demo' => true, '--force' => true]);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('--demo lists 111 accounts', $output);
        $this->assertModelMissing($demo);
        $this->assertModelExists($real);
        $this->assertSame(1, PartnerCode::query()->count());
        $this->assertTrue(AuditLog::query()->where('action', 'test_data_purged')->sole()->details['demo']);
    }

    public function test_the_demo_lists_match_what_the_demo_seeder_creates(): void
    {
        config(['portal.demo_seed_allowed' => true, 'portal.demo_login_email' => 'kevin@example.com']);
        $this->seed(DemoSeeder::class);

        $seeded = User::query()->where('email', '!=', 'anne@properx.org')
            ->whereDoesntHave('roles', fn ($query) => $query->where('name', Role::ApiService->value))
            ->pluck('email')->sort()->values()->all();
        $listed = collect([...StaffSeeder::emails(), ...MemberSeeder::emails(), ...AgencySeeder::emails(), ...DemoLoginSeeder::emails('kevin@example.com')])->sort()->values()->all();

        $this->assertSame($listed, $seeded);
    }
}
