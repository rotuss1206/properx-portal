<?php

namespace Tests\Feature;

use App\Enums\AgencyRole;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\DemoLoginSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_refuses_to_run_on_its_own_unless_demo_data_is_allowed(): void
    {
        config(['portal.demo_seed_allowed' => false, 'portal.demo_login_email' => 'kevin@example.com']);

        try {
            $this->runSeeder();
            $this->fail('The seeder ran without DEMO_SEED_ALLOWED.');
        } catch (RuntimeException) {
            $this->assertSame(0, User::query()->count());
        }
    }

    /**
     * Runs like DemoSeeder does (model events muted) and returns the credentials it created.
     *
     * @return array<string, string> email => password
     */
    private function runSeeder(): array
    {
        $seeder = app(DemoLoginSeeder::class);
        Model::withoutEvents(fn () => $seeder->run());

        return collect($seeder->credentials)->mapWithKeys(fn (array $row) => [$row[1] => $row[2]])->all();
    }

    public function test_creates_one_login_per_role_on_plus_addresses_with_random_passwords(): void
    {
        config(['portal.demo_login_email' => 'kevin@example.com']);

        $credentials = $this->runSeeder();

        $this->assertSame(array_keys(self::ACCOUNTS), array_keys($credentials));
        $this->assertCount(5, array_unique($credentials));

        foreach (self::ACCOUNTS as $email => $role) {
            $user = User::query()->where('email', $email)->sole();

            $this->assertTrue($user->hasRole($role->value), "{$email} has {$role->value}");
            $this->assertTrue(Hash::check($credentials[$email], $user->password), "{$email} signs in with the printed password");
            $this->assertFalse(Hash::check('password', $user->password));
            $this->assertGreaterThanOrEqual(20, strlen($credentials[$email]));
        }

        $this->assertNotNull(User::query()->firstWhere('email', 'kevin+member@example.com')->memberProfile);
    }

    public function test_agency_login_leads_a_small_agency(): void
    {
        config(['portal.demo_login_email' => 'kevin@example.com']);

        $this->runSeeder();

        $principal = User::query()->where('email', 'kevin+agency@example.com')->sole();
        $agency = $principal->ledAgency;

        $this->assertNotNull($agency);
        $this->assertTrue($principal->hasRole(Role::AgencyPrincipal->value));
        $this->assertSame(AgencyRole::Principal, $principal->memberProfile->agency_role);
        $this->assertSame(2, $agency->fresh()->headcount);
    }

    public function test_running_again_resets_passwords_without_duplicates(): void
    {
        config(['portal.demo_login_email' => 'kevin@example.com']);

        $first = $this->runSeeder();
        $second = $this->runSeeder();

        $this->assertSame(5, User::query()->where('email', 'like', 'kevin+%@example.com')->count());
        $this->assertNotEquals($first['kevin+director@example.com'], $second['kevin+director@example.com']);

        $principal = User::query()->where('email', 'kevin+agency@example.com')->sole();
        $this->assertTrue($principal->hasRole(Role::AgencyPrincipal->value));
        $this->assertSame(2, $principal->ledAgency->headcount);
    }

    public function test_an_existing_plus_tag_is_replaced(): void
    {
        $this->assertSame('kevin+member@example.com', DemoLoginSeeder::plusAddress('kevin+inbox@example.com', 'member'));
    }

    public function test_skipped_without_demo_login_email(): void
    {
        config(['portal.demo_login_email' => null]);

        $this->assertSame([], $this->runSeeder());
        $this->assertSame(0, User::query()->count());
    }

    public function test_rejects_an_invalid_address(): void
    {
        config(['portal.demo_login_email' => 'not-an-email']);

        $this->expectException(InvalidArgumentException::class);

        $this->runSeeder();
    }

    public function test_demo_seeder_prints_the_credentials_once(): void
    {
        config(['portal.demo_seed_allowed' => true, 'portal.demo_login_email' => 'kevin@example.com']);

        $this->artisan('db:seed', ['--class' => DemoSeeder::class])
            ->expectsOutputToContain('kevin+director@example.com')
            ->expectsOutputToContain('kevin+agency@example.com')
            ->assertSuccessful();
    }
}
