<?php

namespace Database\Seeders;

use App\Enums\AgencyStatus;
use App\Enums\AgencyTier;
use App\Enums\AgencyType;
use App\Enums\InviteStatus;
use App\Enums\PersonType;
use App\Enums\RateType;
use App\Enums\Role;
use App\Enums\VerificationStatus;
use App\Models\Agency;
use App\Models\LicenseDocument;
use App\Models\User;
use Database\Seeders\Support\DemoPeople;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Login accounts for a real inbox (DEMO_LOGIN_EMAIL), one per role, on plus addresses:
 * DEMO_LOGIN_EMAIL=kevin@example.com gives kevin+director@example.com, kevin+coordinator@…,
 * kevin+compliance@…, kevin+member@… and kevin+agency@… (principal of a small demo agency).
 *
 * Passwords are random and never shown (seeder output can end up in platform logs): the owner of
 * the inbox sets one with "Forgot your password?" on the login page, or staff print a one-time
 * link with `php artisan app:password-link <email>`. Two-factor authentication is set up at the
 * first login. Fixed data, no Faker; re-running creates no duplicates. Called by DemoSeeder.
 */
class DemoLoginSeeder extends Seeder
{
    private const STAFF = [
        'director' => ['Demo Executive Director', Role::ExecutiveDirector],
        'coordinator' => ['Demo Membership Coordinator', Role::MembershipCoordinator],
        'compliance' => ['Demo Compliance Specialist', Role::ComplianceSpecialist],
    ];

    private const AGENCY_NAME = 'Demo Login Agency LLC';

    /**
     * Accounts of the last run: [role label, email]. No passwords.
     *
     * @var list<array{string, string}>
     */
    public array $accounts = [];

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

        [$member, $created] = $this->account($base, 'member', 'Demo Member', Role::Member);
        DemoPeople::profile($member, [
            'first_name' => 'Demo',
            'last_name' => 'Member',
            'phone' => DemoPeople::phone(90),
            'address' => DemoPeople::address(90),
            'firm_name' => 'Demo Insurance Services',
            'npn' => '39000090',
            'license_number' => 'TX-9000090',
            'license_state' => 'TX',
            'license_type' => 'Life & Health',
            'license_expires_at' => today()->addYear(),
        ]);
        DemoPeople::notificationSettings($member);

        if ($created) {
            (new LicenseDocument)->forceFill([
                'user_id' => $member->getKey(),
                'stored_filename' => DemoPeople::pendingFile(LicenseDocument::DIRECTORY),
                'original_filename' => 'license-demo-member.pdf',
                'mime_type' => 'application/pdf',
                'size' => 284_160,
                'uploaded_at' => now()->subWeek(),
                'verification_status' => VerificationStatus::Approved,
            ])->save();
        }

        [$principal] = $this->account($base, 'agency', 'Demo Agency Principal', Role::Member);
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

    /**
     * @return array{0: User, 1: bool}
     */
    private function account(string $base, string $tag, string $name, Role $role): array
    {
        $email = self::plusAddress($base, $tag);

        // A real inbox: always a random password nobody knows, also locally; set on creation only.
        [$user, $created] = DemoPeople::user($email, $name, $role, Str::random(40));

        $this->accounts[] = [Str::headline($tag), $email];

        return [$user, $created];
    }

    /**
     * A two-person agency (the minimum) led by the demo principal. Model events are muted
     * while seeding, so the roster side effects are applied explicitly.
     */
    private function agency(User $principal): void
    {
        $agency = Agency::query()->firstOrNew(['legal_name' => self::AGENCY_NAME]);

        if (! $agency->exists) {
            $agency->forceFill([
                'agency_type' => AgencyType::HealthLife,
                'fein' => '74-1000999',
                'legal_address' => DemoPeople::address(91),
                'mailing_same_as_legal' => true,
                'phone' => DemoPeople::phone(91),
                'website' => 'https://www.demologinagency.example',
                'billing_email' => 'billing@demologinagency.example',
                'referral_source' => 'referral',
                'tier' => AgencyTier::Agency,
                'rate_type' => RateType::Founding,
                'status' => AgencyStatus::Active,
            ])->save();
        }

        $this->agencyProfile($principal, 'Demo', 'Principal', $agency, PersonType::LicensedProducer, 92);

        [$colleague] = DemoPeople::user('demo-agency.person02@properx.test', 'Demo Colleague', Role::Member);
        $this->agencyProfile($colleague, 'Demo', 'Colleague', $agency, PersonType::AgencyEmployee, 93);

        $agency->forceFill(['principal_user_id' => $principal->getKey()])->saveQuietly();
        $agency->syncPrincipal();
        $agency->syncHeadcount();
    }

    private function agencyProfile(User $user, string $first, string $last, Agency $agency, PersonType $type, int $index): void
    {
        $license = $type === PersonType::AgencyEmployee ? [] : [
            'npn' => sprintf('%08d', 39_000_000 + $index),
            'license_number' => sprintf('TX-%07d', 9_000_000 + $index),
            'license_state' => 'TX',
            'license_type' => 'Life & Health',
            'license_expires_at' => today()->addYear(),
        ];

        DemoPeople::profile($user, $license + [
            'first_name' => $first,
            'last_name' => $last,
            'phone' => DemoPeople::phone($index),
            'address' => DemoPeople::address($index),
            'firm_name' => $agency->legal_name,
            'agency_id' => $agency->getKey(),
            'person_type' => $type,
            'invite_status' => InviteStatus::AccountActive,
        ]);
        DemoPeople::notificationSettings($user);
    }

    private function report(): void
    {
        if ($this->command === null) {
            return;
        }

        $this->command->newLine();
        $this->command->info('Demo login accounts (no passwords are shown):');
        $this->command->table(['Role', 'Email'], $this->accounts);
        $this->command->line('Set a password with "Forgot your password?" on the login page, or print a one-time link');
        $this->command->line('with `php artisan app:password-link <email>` if email is not delivered. Two-factor setup follows at first login.');
    }
}
