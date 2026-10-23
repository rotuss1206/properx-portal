<?php

namespace Database\Seeders;

use App\Enums\AgencyStatus;
use App\Enums\AgencyTier;
use App\Enums\InviteStatus;
use App\Enums\NotificationCategory;
use App\Enums\RateType;
use App\Enums\Role;
use App\Models\Agency;
use App\Models\LicenseDocument;
use App\Models\MemberProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Login accounts for a real inbox (DEMO_LOGIN_EMAIL), one per role, on plus addresses:
 * DEMO_LOGIN_EMAIL=kevin@example.com gives kevin+director@example.com, kevin+coordinator@…,
 * kevin+compliance@…, kevin+member@… and kevin+agency@… (principal of a small demo agency).
 *
 * Passwords are random, reset on every run and shown once in the seeder output; they are never
 * logged or stored in plain text. Two-factor authentication is set up at the first login.
 * Called by DemoSeeder, so it runs only when DEMO_SEED_ALLOWED=true.
 */
class DemoLoginSeeder extends Seeder
{
    private const STAFF = [
        'director' => ['Demo Executive Director', Role::ExecutiveDirector],
        'coordinator' => ['Demo Membership Coordinator', Role::MembershipCoordinator],
        'compliance' => ['Demo Compliance Specialist', Role::ComplianceSpecialist],
    ];

    /**
     * Credentials created by the last run: [role label, email, password].
     *
     * @var list<array{string, string, string}>
     */
    public array $credentials = [];

    public function run(): void
    {
        // Also guarded here: the seeder can be run on its own with --class.
        if (config('portal.demo_seed_allowed') !== true) {
            throw new RuntimeException('Demo data is disabled. Set DEMO_SEED_ALLOWED=true (local development only) to seed it.');
        }

        $base = config('portal.demo_login_email');

        if (blank($base)) {
            return;
        }

        foreach (self::STAFF as $tag => [$name, $role]) {
            $this->account($base, $tag, $name, $role);
        }

        $member = $this->account($base, 'member', 'Demo Member', Role::Member);
        $this->profile($member, 'Demo', 'Member');
        LicenseDocument::factory()->for($member)->create();

        $principal = $this->account($base, 'agency', 'Demo Agency Principal', Role::Member);
        $this->agency($principal);

        $this->report();
    }

    /**
     * "kevin@example.com" + "member" → "kevin+member@example.com" (an existing +tag is replaced).
     */
    public static function plusAddress(string $base, string $tag): string
    {
        if (! filter_var($base, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('DEMO_LOGIN_EMAIL is not a valid email address.');
        }

        [$local, $domain] = explode('@', $base, 2);

        return Str::before($local, '+').'+'.$tag.'@'.$domain;
    }

    private function account(string $base, string $tag, string $name, Role $role): User
    {
        $email = self::plusAddress($base, $tag);
        $password = Str::password(20);

        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $name,
            'password' => $password,
            'email_verified_at' => now(),
        ])->save();
        $user->syncRoles([$role->value]);

        $this->credentials[] = [Str::headline($tag), $email, $password];

        return $user;
    }

    private function profile(User $user, string $firstName, string $lastName, ?Agency $agency = null): MemberProfile
    {
        $profile = $user->memberProfile ?? MemberProfile::factory()
            ->when($agency !== null, fn ($factory) => $factory->inAgency($agency, InviteStatus::AccountActive))
            ->for($user)
            ->create(['first_name' => $firstName, 'last_name' => $lastName]);

        foreach (NotificationCategory::cases() as $category) {
            $user->notificationSettings()->firstOrCreate(['category' => $category], ['enabled' => true]);
        }

        return $profile;
    }

    /**
     * A two-person agency (the minimum) led by the demo principal. Model events are muted
     * while seeding, so the roster side effects are applied explicitly.
     */
    private function agency(User $principal): void
    {
        $agency = $principal->ledAgency ?? Agency::factory()->create([
            'legal_name' => 'Demo Login Agency LLC',
            'tier' => AgencyTier::Agency,
            'rate_type' => RateType::Founding,
            'status' => AgencyStatus::Active,
        ]);

        $principalProfile = $this->profile($principal, 'Demo', 'Principal', $agency);

        if ($agency->members()->whereKeyNot($principalProfile->getKey())->doesntExist()) {
            $colleague = User::factory()->create(['email' => 'demo-agency.person02@properx.test']);
            $colleague->assignRole(Role::Member->value);
            $this->profile($colleague, 'Demo', 'Colleague', $agency);
        }

        $agency->forceFill(['principal_user_id' => $principal->getKey()])->saveQuietly();
        $agency->syncPrincipal();
        $agency->syncHeadcount();
    }

    private function report(): void
    {
        if ($this->command === null) {
            return;
        }

        $this->command->newLine();
        $this->command->warn('Demo login accounts (passwords are shown only now; two-factor setup at first login):');
        $this->command->table(['Role', 'Email', 'Password'], $this->credentials);
    }
}
