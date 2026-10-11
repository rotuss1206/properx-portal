<?php

namespace Database\Seeders;

use App\Enums\NotificationCategory;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\LicenseDocument;
use App\Models\MemberProfile;
use App\Models\NotificationSetting;
use App\Models\ReminderLog;
use App\Models\TaxDocument;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * 30 members with a mix of active, expiring (within 60 days) and expired licenses.
 */
class MemberSeeder extends Seeder
{
    private const COUNTS = [
        'active' => 14,
        'expiring' => 9,
        'expired' => 7,
    ];

    public function run(): void
    {
        $index = 1;

        foreach (self::COUNTS as $state => $count) {
            for ($i = 0; $i < $count; $i++, $index++) {
                $this->createMember($state, sprintf('member%02d@properx.test', $index));
            }
        }
    }

    private function createMember(string $state, string $email): void
    {
        $user = User::factory()->create(['email' => $email]);
        $user->assignRole(Role::Member->value);

        $profile = MemberProfile::factory()->{$state}()->for($user)->create();
        $user->update(['name' => $profile->fullName()]);

        foreach (NotificationCategory::cases() as $category) {
            NotificationSetting::factory()->for($user)->create([
                'category' => $category,
                'enabled' => fake()->boolean(85),
            ]);
        }

        LicenseDocument::factory()->count(fake()->numberBetween(1, 2))->for($user)->create();
        TaxDocument::factory()->count(fake()->numberBetween(0, 2))->for($user)->create();

        if ($state !== 'active') {
            ReminderLog::factory()->count(fake()->numberBetween(1, 3))->for($user)->create();
        }

        AuditLog::factory()->count(3)->for($user)->create();
    }
}
