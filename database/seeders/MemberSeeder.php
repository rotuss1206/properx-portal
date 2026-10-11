<?php

namespace Database\Seeders;

use App\Enums\NotificationCategory;
use App\Enums\PaymentStatus;
use App\Enums\ReminderType;
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
                $user = $this->createMember($state, sprintf('member%02d@properx.test', $index));
                $this->setPaymentStatus($user->memberProfile, $index);
            }
        }
    }

    /**
     * Fixed payment scenarios for member01–member05 (documented test accounts), random for the rest.
     */
    private function setPaymentStatus(MemberProfile $profile, int $index): void
    {
        [$status, $daysAgo] = match ($index) {
            1 => [PaymentStatus::Active, 40],
            2 => [PaymentStatus::PastDue, 3],    // within the 14-day grace period
            3 => [PaymentStatus::PastDue, 20],   // grace period over: restricted
            4 => [PaymentStatus::Canceled, 10],  // restricted
            5 => [PaymentStatus::Unknown, null], // no Stripe data yet
            default => fake()->randomElement([
                [PaymentStatus::Active, fake()->numberBetween(1, 300)],
                [PaymentStatus::Active, fake()->numberBetween(1, 300)],
                [PaymentStatus::Active, fake()->numberBetween(1, 300)],
                [PaymentStatus::PastDue, fake()->numberBetween(1, 30)],
                [PaymentStatus::Canceled, fake()->numberBetween(1, 90)],
                [PaymentStatus::Unknown, null],
            ]),
        };

        $profile->forceFill([
            'payment_status' => $status,
            'payment_status_updated_at' => $daysAgo === null ? null : now()->subDays($daysAgo),
            'payment_event_at' => $daysAgo === null ? null : now()->subDays($daysAgo),
            'stripe_customer_id' => $status === PaymentStatus::Unknown ? null : 'cus_demo'.str_pad((string) $index, 6, '0', STR_PAD_LEFT),
        ])->saveQuietly();
    }

    private function createMember(string $state, string $email): User
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

        // History of reminders that would already have gone out for this expiry date.
        foreach (ReminderType::cases() as $type) {
            $dueAt = $profile->license_expires_at->copy()->subDays($type->value)->setTime(2, 0);

            if ($dueAt->isFuture()) {
                continue;
            }

            ReminderLog::factory()->for($user)->create([
                'reminder_type' => $type,
                'license_expires_at' => $profile->license_expires_at,
                'sent_at' => $dueAt,
            ]);
        }

        AuditLog::factory()->count(3)->for($user)->create();

        return $user->load('memberProfile');
    }
}
