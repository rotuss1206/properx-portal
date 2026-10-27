<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\LibraryDocument;
use App\Models\LicenseDocument;
use App\Models\MemberProfile;
use App\Models\NotificationSetting;
use App\Models\PartnerCode;
use App\Models\ReminderLog;
use App\Models\TaxDocument;
use App\Models\User;
use App\Services\ReferenceData;
use Database\Seeders\DemoSeeder;
use Faker\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * DemoSeeder must run on the platform image too, where Faker and the factories' dev
 * dependencies are not installed, and must be safe to run more than once.
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * DemoSeeder and everything it calls.
     */
    private const SOURCES = [
        'database/seeders/DemoSeeder.php',
        'database/seeders/RolesAndPermissionsSeeder.php',
        'database/seeders/ServiceAccountSeeder.php',
        'database/seeders/StaffSeeder.php',
        'database/seeders/MemberSeeder.php',
        'database/seeders/AgencySeeder.php',
        'database/seeders/LibrarySeeder.php',
        'database/seeders/DemoLoginSeeder.php',
        'database/seeders/DemoFilesSeeder.php',
        'database/seeders/Support/DemoPeople.php',
        'database/seeders/Support/DemoFile.php',
        'app/Services/ReferenceData.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['portal.demo_seed_allowed' => true, 'portal.demo_login_email' => 'kevin@example.com']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sources(): iterable
    {
        foreach (self::SOURCES as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('sources')]
    public function test_source_uses_no_faker_or_factories(string $path): void
    {
        // Code only: comments may mention Faker when explaining why it is not used.
        $code = collect(token_get_all(file_get_contents(base_path($path))))
            ->reject(fn ($token) => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
            ->map(fn ($token) => is_array($token) ? $token[1] : $token)
            ->implode('');

        $this->assertDoesNotMatchRegularExpression('/\bfake\s*\(|->faker\b|::factory\s*\(|\bFaker\\\\/', $code);
    }

    public function test_runs_with_faker_unavailable(): void
    {
        // Any fake() call or factory instantiation now fails, as it would without dev dependencies.
        $unavailable = fn () => throw new LogicException('Faker is not available.');
        $this->app->bind(Generator::class, $unavailable);
        $this->app->bind(Generator::class.':'.config('app.faker_locale', 'en_US'), $unavailable);
        $this->assertThrows(fn () => fake()->name(), LogicException::class);
        $this->assertThrows(fn () => User::factory()->make(), LogicException::class);

        $this->artisan('db:seed', ['--class' => DemoSeeder::class])->assertSuccessful();

        $this->assertNotNull(User::query()->firstWhere('email', 'member30@properx.test'));
        $this->assertSame(4, Agency::query()->count()); // three demo agencies and the demo login agency
    }

    public function test_running_again_creates_no_duplicates(): void
    {
        $this->artisan('db:seed', ['--class' => DemoSeeder::class])->assertSuccessful();
        $first = $this->counts();

        $this->artisan('db:seed', ['--class' => DemoSeeder::class])->assertSuccessful();

        $this->assertSame($first, $this->counts());
        $this->assertSame(30 + 5 + 12 + 55 + 3 + 5 + 1 + 1, $first['users']); // + staff, demo logins, colleague, service account
    }

    public function test_test_accounts_use_password_only_in_the_local_environment(): void
    {
        $this->app['env'] = 'local';
        $this->artisan('db:seed', ['--class' => DemoSeeder::class])->assertSuccessful();

        foreach (['director@properx.test', 'member01@properx.test', 'principal01@properx.test', 'agency01.person02@properx.test'] as $email) {
            $this->assertTrue(Hash::check('password', User::query()->where('email', $email)->sole()->password), $email);
        }

        // Real inboxes (DEMO_LOGIN_EMAIL) and the service account never get it.
        $this->assertFalse(Hash::check('password', User::query()->where('email', 'kevin+director@example.com')->sole()->password));
        $this->assertFalse(Hash::check('password', User::query()->where('email', ReferenceData::SERVICE_ACCOUNT_EMAIL)->sole()->password));
    }

    public function test_test_accounts_get_random_passwords_outside_the_local_environment(): void
    {
        $this->assertFalse($this->app->isLocal());
        $this->artisan('db:seed', ['--class' => DemoSeeder::class])->assertSuccessful();

        $hashes = User::query()->where('email', 'like', '%@properx.test')->pluck('password', 'email');

        foreach (['director@properx.test', 'member01@properx.test', 'principal01@properx.test'] as $email) {
            $this->assertFalse(Hash::check('password', $hashes[$email]), $email);
        }
    }

    public function test_seeded_identifiers_are_unique_and_indexed(): void
    {
        $this->artisan('db:seed', ['--class' => DemoSeeder::class])->assertSuccessful();

        $profiles = MemberProfile::query()->get();
        $npns = $profiles->pluck('npn')->filter();

        $this->assertSame($npns->count(), $npns->unique()->count());
        $this->assertSame($profiles->count(), $profiles->pluck('certificate_code')->unique()->count());
        $this->assertSame(0, MemberProfile::query()->whereNotNull('npn')->whereNull('npn_index')->count());

        $member = User::query()->firstWhere('email', 'member01@properx.test')->memberProfile;
        $this->assertTrue($member->is(MemberProfile::query()->whereBlindIndex('npn', $member->npn)->first()));
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return [
            'users' => User::query()->count(),
            'member_profiles' => MemberProfile::query()->count(),
            'agencies' => Agency::query()->count(),
            'partner_codes' => PartnerCode::query()->count(),
            'library_documents' => LibraryDocument::query()->count(),
            'license_documents' => LicenseDocument::query()->count(),
            'tax_documents' => TaxDocument::query()->count(),
            'reminder_logs' => ReminderLog::query()->count(),
            'notification_settings' => NotificationSetting::query()->count(),
            'audit_logs' => AuditLog::query()->count(),
            'headcount' => (int) Agency::query()->sum('headcount'),
        ];
    }
}
