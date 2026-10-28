<?php

namespace Tests\Feature;

use App\Enums\AgencyRole;
use App\Enums\Role;
use App\Models\Agency;
use App\Models\User;
use App\Services\ReferenceData;
use Database\Seeders\DemoLoginSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class DemoLoginSeederTest extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNTS = [
        'kevin+director@example.com' => Role::ExecutiveDirector,
        'kevin+coordinator@example.com' => Role::MembershipCoordinator,
        'kevin+compliance@example.com' => Role::ComplianceSpecialist,
        'kevin+member@example.com' => Role::Member,
        'kevin+agency@example.com' => Role::Member,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        config(['portal.demo_seed_allowed' => true]);
    }

    /**
     * Users other than the API Service account from the seed_reference_data migration.
     */
    private function fictionalUsers(): int
    {
        return User::query()->where('email', '!=', ReferenceData::SERVICE_ACCOUNT_EMAIL)->count();
    }

    public function test_refuses_to_run_on_its_own_unless_demo_data_is_allowed(): void
    {
        config(['portal.demo_seed_allowed' => false, 'portal.demo_login_email' => 'kevin@example.com']);

        try {
            $this->runSeeder();
            $this->fail('The seeder ran without DEMO_SEED_ALLOWED.');
        } catch (RuntimeException) {
            $this->assertSame(0, $this->fictionalUsers());
        }
    }

    /**
     * Runs like DemoSeeder does (model events muted) and returns the emails it reported.
     *
     * @return list<string>
     */
    private function runSeeder(): array
    {
        $seeder = app(DemoLoginSeeder::class);
        Model::withoutEvents(fn () => $seeder->run());

        return array_column($seeder->accounts, 1);
    }

    public function test_creates_one_login_per_role_on_plus_addresses_with_unknown_passwords(): void
    {
        config(['portal.demo_login_email' => 'kevin@example.com']);

        $this->assertSame(array_keys(self::ACCOUNTS), $this->runSeeder());

        $hashes = [];
        foreach (self::ACCOUNTS as $email => $role) {
            $user = User::query()->where('email', $email)->sole();

            $this->assertTrue($user->hasRole($role->value), "{$email} has {$role->value}");
            $this->assertFalse(Hash::check('password', $user->password));
            $hashes[] = $user->password;
        }

        $this->assertCount(5, array_unique($hashes));
        $this->assertNotNull(User::query()->firstWhere('email', 'kevin+member@example.com')->memberProfile);
    }

    public function test_real_inbox_accounts_never_get_the_local_test_password(): void
    {
        $this->app['env'] = 'local';
        config(['portal.demo_login_email' => 'kevin@example.com']);

        $this->runSeeder();

        foreach (array_keys(self::ACCOUNTS) as $email) {
            $this->assertFalse(Hash::check('password', User::query()->where('email', $email)->sole()->password), $email);
        }
    }

    public function test_agency_login_leads_a_small_agency(): void
    {
        config(['portal.demo_login_email' => 'kevin@example.com']);

        $this->runSeeder();

        $principal = User::query()->where('email', 'kevin+agency@example.com')->sole();
        $agency = $principal->ledAgency;

        $this->assertNotNull($agency);
        $this->assertSame('Demo Agency (kevin)', $agency->legal_name);
        $this->assertTrue($principal->hasRole(Role::AgencyPrincipal->value));
        $this->assertSame(AgencyRole::Principal, $principal->memberProfile->agency_role);
        $this->assertSame(2, $agency->fresh()->headcount);
    }

    public function test_running_again_keeps_passwords_and_creates_no_duplicates(): void
    {
        config(['portal.demo_login_email' => 'kevin@example.com']);

        $this->runSeeder();
        $password = User::query()->where('email', 'kevin+director@example.com')->value('password');
        $this->runSeeder();

        $this->assertSame(5, User::query()->where('email', 'like', 'kevin+%@example.com')->count());
        $this->assertSame($password, User::query()->where('email', 'kevin+director@example.com')->value('password'));

        $principal = User::query()->where('email', 'kevin+agency@example.com')->sole();
        $this->assertTrue($principal->hasRole(Role::AgencyPrincipal->value));
        $this->assertSame(2, $principal->ledAgency->headcount);
    }

    public function test_each_address_gets_its_own_agency_and_leaves_earlier_ones_alone(): void
    {
        config(['portal.demo_login_email' => 'kevin@example.com']);
        $this->runSeeder();
        $firstPasswords = User::query()->where('email', 'like', 'kevin+%')->pluck('password', 'email')->all();

        config(['portal.demo_login_email' => 'kkeeney+inbox@other.example']);
        $this->runSeeder();
        $this->runSeeder(); // the same address again adds nothing

        $agencies = Agency::query()->where('legal_name', 'like', 'Demo Agency (%')->with('members')->orderBy('id')->get();
        $this->assertSame(['Demo Agency (kevin)', 'Demo Agency (kkeeney)'], $agencies->pluck('legal_name')->all());

        foreach (['kevin+agency@example.com', 'kkeeney+agency@other.example'] as $index => $email) {
            $agency = $agencies[$index];
            $principal = User::query()->where('email', $email)->sole();

            $this->assertSame($principal->id, $agency->principal_user_id, $email);
            $this->assertSame(2, $agency->headcount, $agency->legal_name);
            $this->assertCount(2, $agency->members);
            $this->assertSame([$principal->id], $agency->members->where('agency_role', AgencyRole::Principal)->pluck('user_id')->values()->all());
            $this->assertTrue($principal->hasRole(Role::AgencyPrincipal->value));
        }

        // The first address's accounts are untouched; NPNs differ between the two addresses.
        $this->assertSame($firstPasswords, User::query()->where('email', 'like', 'kevin+%')->pluck('password', 'email')->all());
        $this->assertNotSame(
            User::query()->firstWhere('email', 'kevin+member@example.com')->memberProfile->npn,
            User::query()->firstWhere('email', 'kkeeney+member@other.example')->memberProfile->npn,
        );
        $this->assertSame(10, User::query()->where('email', 'like', '%+%')->count());
    }

    public function test_an_existing_plus_tag_is_replaced(): void
    {
        $this->assertSame('kevin+member@example.com', DemoLoginSeeder::plusAddress('kevin+inbox@example.com', 'member'));
    }

    public function test_skipped_without_demo_login_email(): void
    {
        config(['portal.demo_login_email' => null]);

        $this->assertSame([], $this->runSeeder());
        $this->assertSame(0, $this->fictionalUsers());
    }

    public function test_rejects_an_invalid_address(): void
    {
        config(['portal.demo_login_email' => 'not-an-email']);

        $this->expectException(InvalidArgumentException::class);

        $this->runSeeder();
    }

    public function test_demo_seeder_lists_the_emails_and_how_to_set_a_password_but_no_passwords(): void
    {
        config(['portal.demo_seed_allowed' => true, 'portal.demo_login_email' => 'kevin@example.com']);

        Artisan::call('db:seed', ['--class' => DemoSeeder::class]);
        $output = Artisan::output();

        $this->assertStringContainsString('kevin+director@example.com', $output);
        $this->assertStringContainsString('kevin+agency@example.com', $output);
        $this->assertStringContainsString('Forgot your password?', $output);
        $this->assertStringContainsString('app:password-link', $output);
        $this->assertStringNotContainsStringIgnoringCase('| password', $output);

        // The accounts get Str::random(40) passwords: nothing of that shape may be printed.
        $this->assertDoesNotMatchRegularExpression('/\b[A-Za-z0-9]{40}\b/', $output);
    }
}
