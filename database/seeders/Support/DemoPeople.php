<?php

namespace Database\Seeders\Support;

use App\Enums\AgencyRole;
use App\Enums\LicenseStatus;
use App\Enums\MemberStatus;
use App\Enums\MemberType;
use App\Enums\NotificationCategory;
use App\Enums\Role;
use App\Models\MemberProfile;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Fixed fictional people for the demo seeders: no Faker and no factories (both are dev-only
 * Composer packages, missing from the platform image), deterministic values by index, and
 * idempotent creation. The @properx.test accounts get "password" locally (APP_ENV=local) and a
 * random unknown password elsewhere; real demo logins come from DEMO_LOGIN_EMAIL.
 * Phone numbers use the fictional 555-01xx range, web addresses the reserved .example domain.
 */
final class DemoPeople
{
    public const FIRST_NAMES = [
        'Avery', 'Jordan', 'Morgan', 'Taylor', 'Riley', 'Casey', 'Quinn', 'Harper', 'Rowan', 'Emerson',
        'Parker', 'Reese', 'Hayden', 'Skyler', 'Dakota', 'Finley', 'Sawyer', 'Logan', 'Peyton', 'Cameron',
        'Blake', 'Drew', 'Elliot', 'Jamie', 'Kendall', 'Lane', 'Marley', 'Noel', 'Oakley', 'Remy',
    ];

    public const LAST_NAMES = [
        'Alvarez', 'Bennett', 'Castillo', 'Dawson', 'Ellison', 'Fletcher', 'Garrison', 'Holloway', 'Ingram', 'Jennings',
        'Keller', 'Lawson', 'Maddox', 'Navarro', 'Oakley', 'Prescott', 'Quintero', 'Ramsey', 'Sullivan', 'Thornton',
        'Underwood', 'Vasquez', 'Whitaker', 'Xiong', 'Yarbrough', 'Zimmerman', 'Ashford', 'Brennan', 'Crawford', 'Delgado',
    ];

    /** @var list<array{string, string, string}> city, state, ZIP */
    public const CITIES = [
        ['Austin', 'TX', '78701'], ['Dallas', 'TX', '75201'], ['Houston', 'TX', '77002'], ['Phoenix', 'AZ', '85004'],
        ['Denver', 'CO', '80202'], ['Atlanta', 'GA', '30303'], ['Tampa', 'FL', '33602'], ['Nashville', 'TN', '37203'],
        ['Charlotte', 'NC', '28202'], ['Columbus', 'OH', '43215'],
    ];

    public const STREETS = ['Oak Street', 'Maple Avenue', 'Cedar Lane', 'Elm Drive', 'Pine Road', 'Willow Way', 'Birch Court', 'Main Street'];

    /**
     * Password of the fictional @properx.test accounts: "password" with APP_ENV=local (local
     * development, as before), a random one nobody knows anywhere else.
     */
    public static function testPassword(): string
    {
        return app()->isLocal() ? 'password' : Str::random(40);
    }

    /**
     * The user with this email, created when missing (with $password, by default testPassword())
     * and given the roles it lacks. Returns whether it was created, so callers seed its history once.
     *
     * @return array{0: User, 1: bool}
     */
    public static function user(string $email, string $name, Role $role, ?string $password = null): array
    {
        $user = User::query()->firstOrNew(['email' => $email]);
        $created = ! $user->exists;

        if ($created) {
            $user->forceFill([
                'name' => $name,
                'password' => $password ?? self::testPassword(),
                'email_verified_at' => now(),
            ])->save();
        }

        if (! $user->hasRole($role->value)) {
            $user->assignRole($role->value);
        }

        return [$user, $created];
    }

    /**
     * @return array{0: string, 1: string} first and last name
     */
    public static function name(int $index): array
    {
        // 7 and 30 are coprime: within any run of 30 people no last name repeats.
        $first = self::FIRST_NAMES[$index % count(self::FIRST_NAMES)];
        $last = self::LAST_NAMES[($index * 7 + intdiv($index, count(self::LAST_NAMES))) % count(self::LAST_NAMES)];

        return [$first, $last];
    }

    public static function address(int $index): string
    {
        [$city, $state, $zip] = self::CITIES[$index % count(self::CITIES)];

        return sprintf('%d %s, %s, %s %s', 100 + ($index * 37) % 9800, self::STREETS[$index % count(self::STREETS)], $city, $state, $zip);
    }

    public static function state(int $index): string
    {
        return self::CITIES[$index % count(self::CITIES)][1];
    }

    public static function phone(int $index): string
    {
        return sprintf('(%03d) 555-01%02d', [512, 214, 713, 602, 303, 404, 813, 615, 704, 614][$index % 10], $index % 100);
    }

    /**
     * A member profile, created once; values the model's saving hooks would set are set here,
     * because model events are muted while seeding. An existing profile is kept unchanged.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function profile(User $user, array $attributes): MemberProfile
    {
        $existing = MemberProfile::query()->where('user_id', $user->getKey())->first();

        if ($existing !== null) {
            return $existing;
        }

        $expiresAt = $attributes['license_expires_at'] ?? null;
        $inAgency = isset($attributes['agency_id']);

        $profile = new MemberProfile;
        $profile->forceFill(array_merge([
            'status' => MemberStatus::Active,
            'payment_status' => 'unknown',
            'agency_role' => $inAgency ? AgencyRole::Member : null,
        ], $attributes, [
            'user_id' => $user->getKey(),
            'member_type' => $inAgency ? MemberType::AgencyMember : MemberType::Individual,
            'license_status' => LicenseStatus::fromExpiryDate($expiresAt instanceof CarbonInterface ? $expiresAt : null),
            'certificate_code' => MemberProfile::generateCertificateCode(),
            'member_number' => MemberProfile::formatMemberNumber($user->getKey()),
        ]))->save();

        $user->setRelation('memberProfile', $profile);

        return $profile;
    }

    /**
     * A unique path that does not exist yet: DemoFilesSeeder writes the real file for it.
     */
    public static function pendingFile(string $directory): string
    {
        return $directory.'/demo-pending-'.Str::uuid();
    }

    /**
     * Opt-out categories: every category enabled unless listed in $disabled.
     *
     * @param  list<NotificationCategory>  $disabled
     */
    public static function notificationSettings(User $user, array $disabled = []): void
    {
        foreach (NotificationCategory::cases() as $category) {
            $user->notificationSettings()->firstOrCreate(
                ['category' => $category],
                ['enabled' => ! in_array($category, $disabled, true)],
            );
        }
    }
}
