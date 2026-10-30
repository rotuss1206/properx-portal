<?php

namespace Database\Seeders;

use App\Enums\DeliveryStatus;
use App\Enums\LicenseVerification;
use App\Enums\NotificationCategory;
use App\Enums\PaymentStatus;
use App\Enums\ReminderType;
use App\Enums\Role;
use App\Enums\VerificationStatus;
use App\Models\AuditLog;
use App\Models\LicenseDocument;
use App\Models\MemberProfile;
use App\Models\StripeEvent;
use App\Models\TaxDocument;
use App\Models\User;
use App\Notifications\Member\LicenseDocumentReviewed;
use App\Notifications\Member\RenewalDue;
use App\Notifications\Member\TaxDocumentAvailable;
use Carbon\CarbonInterface;
use Database\Seeders\Support\DemoPeople;
use Illuminate\Database\Seeder;

/**
 * 30 fictional individual members (member01@ … member30@properx.test) with active, expiring
 * (within 60 days) and expired licenses, documents and history. Fixed data, no Faker;
 * idempotent: an existing member is left as it is.
 */
class MemberSeeder extends Seeder
{
    private const MEMBERS = 30;

    // member01–14 active, member15–23 expiring, member24–30 expired.
    private const LAST_ACTIVE = 14;

    private const LAST_EXPIRING = 23;

    /** @var list<array{string, string, int}> extension, MIME type, size in bytes */
    private const LICENSE_FILES = [
        ['pdf', 'application/pdf', 284_160],
        ['jpg', 'image/jpeg', 1_048_576],
        ['png', 'image/png', 512_000],
    ];

    public function run(): void
    {
        for ($index = 1; $index <= self::MEMBERS; $index++) {
            [$first, $last] = DemoPeople::name($index);
            [$user, $created] = DemoPeople::user(sprintf('member%02d@properx.test', $index), "{$first} {$last}", Role::Member);

            $profile = DemoPeople::profile($user, [
                'first_name' => $first,
                'last_name' => $last,
                'phone' => DemoPeople::phone($index),
                'address' => DemoPeople::address($index),
                'firm_name' => $index % 5 === 0 ? null : "{$last} Insurance Services",
                'npn' => sprintf('%08d', 31_000_000 + $index * 7_919),
                'license_number' => sprintf('%s-%07d', DemoPeople::state($index), 4_100_000 + $index * 131),
                'license_state' => DemoPeople::state($index),
                'license_type' => MemberProfile::LICENSE_TYPES[$index % count(MemberProfile::LICENSE_TYPES)],
                'license_expires_at' => $this->expiryDate($index),
            ]);

            if (! $created) {
                // Added later: filled in for existing members too (only what is missing).
                $this->setPaidThrough($profile, $index);
                $this->memberAreaDemo($user, $profile->refresh(), $index);

                continue; // History was seeded with the member.
            }

            $this->setPaymentStatus($profile, $index);
            $this->setPaidThrough($profile, $index);
            DemoPeople::notificationSettings($user, $index % 6 === 0 ? [NotificationCategory::LicenseExpiry] : []);
            $this->licenseDocuments($user, $index);
            $this->taxDocuments($user, $index);
            $this->reminderHistory($user, $profile);
            $this->auditHistory($user, $index);
            $this->memberAreaDemo($user, $profile->refresh(), $index);
        }
    }

    /**
     * Member area dashboard data for member01-member05 (docs/specs/member-area.md, stage 2):
     * license verification, in-app notifications, activity and paid invoices. Fills only what
     * is missing, so it also completes members seeded before.
     */
    private function memberAreaDemo(User $user, MemberProfile $profile, int $index): void
    {
        if ($index > 5) {
            return;
        }

        // member01 verified, member03 needs attention, the rest pending review.
        $verification = [1 => LicenseVerification::Verified, 3 => LicenseVerification::NeedsAttention][$index] ?? null;

        if ($verification && $profile->license_verification === LicenseVerification::PendingReview) {
            $profile->forceFill(['license_verification' => $verification])->saveQuietly();
        }

        $licenseDocument = LicenseDocument::query()->whereBelongsTo($user)->oldest('id')->first();

        if (! AuditLog::query()->where('user_id', $user->getKey())->where('action', 'certificate_generated')->exists()) {
            foreach (['document_uploaded' => 21, 'document_downloaded' => 9, 'certificate_generated' => 5, 'login' => 1] as $action => $daysAgo) {
                $resource = match ($action) {
                    'certificate_generated' => $profile,
                    'document_uploaded', 'document_downloaded' => $licenseDocument,
                    default => null,
                };

                (new AuditLog)->forceFill([
                    'user_id' => $user->getKey(),
                    'action' => $action,
                    'resource_type' => $resource?->getMorphClass(),
                    'resource_id' => $resource?->getKey(),
                    'ip_address' => '203.0.113.'.($index % 250 + 1),
                    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) DemoBrowser/1.0',
                    'created_at' => now()->subDays($daysAgo),
                ])->save();
            }
        }

        // Paid invoices (amounts and plan as stored from invoice.paid), one per year.
        if ($profile->paid_through_at !== null && $profile->stripe_customer_id !== null) {
            foreach ([0 => 19900, 1 => 17900] as $yearsBack => $amount) {
                $paidAt = $profile->paid_through_at->copy()->subYears($yearsBack + 1);

                if ($paidAt->isFuture()) {
                    continue;
                }

                StripeEvent::query()->firstOrCreate(
                    ['id' => sprintf('evt_demo_m%02d_%d', $index, $yearsBack)],
                    ['type' => 'invoice.paid', 'member_profile_id' => $profile->getKey(), 'outcome' => 'updated', 'amount' => $amount, 'currency' => 'USD', 'plan_name' => 'Annual membership'],
                )->forceFill(['created_at' => $paidAt])->save();
            }
        }

        if ($user->notifications()->exists()) {
            return;
        }

        $taxDocument = TaxDocument::query()->whereBelongsTo($user)->oldest('id')->first();
        $notices = array_filter([
            [$profile->paid_through_at ? new RenewalDue($profile->paid_through_at->toDateString()) : null, 4, false],
            [$taxDocument ? new TaxDocumentAvailable($taxDocument->getKey()) : null, 7, true],
            [$licenseDocument ? new LicenseDocumentReviewed($licenseDocument->getKey(), VerificationStatus::Approved) : null, 2, false],
        ], fn (array $notice) => $notice[0] !== null);

        foreach ($notices as [$notification, $daysAgo, $read]) {
            $user->notify($notification);
            $user->notifications()->latest()->first()?->forceFill([
                'created_at' => now()->subDays($daysAgo),
                'read_at' => $read ? now()->subDays($daysAgo - 1) : null,
            ])->save();
        }
    }

    private function expiryDate(int $index): CarbonInterface
    {
        return match (true) {
            $index <= self::LAST_ACTIVE => today()->addDays(75 + $index * 23),
            $index <= self::LAST_EXPIRING => today()->addDays(($index - self::LAST_ACTIVE) * 6),
            default => today()->subDays(($index - self::LAST_EXPIRING) * 17),
        };
    }

    /**
     * Fixed payment scenarios for member01–member05 (documented test accounts), a fixed cycle for the rest.
     */
    private function setPaymentStatus(MemberProfile $profile, int $index): void
    {
        [$status, $daysAgo] = match ($index) {
            1 => [PaymentStatus::Active, 40],
            2 => [PaymentStatus::PastDue, 3],    // within the 14-day grace period
            3 => [PaymentStatus::PastDue, 20],   // grace period over: restricted
            4 => [PaymentStatus::Canceled, 10],  // restricted
            5 => [PaymentStatus::Unknown, null], // no Stripe data yet
            default => [
                [PaymentStatus::Active, 30 + $index * 7],
                [PaymentStatus::Active, 12 + $index],
                [PaymentStatus::PastDue, 2 + $index % 25],
                [PaymentStatus::Canceled, 5 + $index * 2],
                [PaymentStatus::Active, 60 + $index],
                [PaymentStatus::Unknown, null],
            ][$index % 6],
        };

        $profile->forceFill([
            'payment_status' => $status,
            'payment_status_updated_at' => $daysAgo === null ? null : now()->subDays($daysAgo),
            'payment_event_at' => $daysAgo === null ? null : now()->subDays($daysAgo),
            'stripe_customer_id' => $status === PaymentStatus::Unknown ? null : sprintf('cus_demo%06d', $index),
        ])->saveQuietly();
    }

    /**
     * End of the paid period (Stripe), so the sidebar's "Renewal due" card shows locally:
     * active members renew in 76 (member01), 30 or 200 days; past due and canceled members'
     * periods have ended; no date without Stripe data. Only fills an empty date.
     */
    private function setPaidThrough(MemberProfile $profile, int $index): void
    {
        if ($profile->paid_through_at !== null) {
            return;
        }

        $days = match ($profile->payment_status) {
            PaymentStatus::Active => $index === 1 ? 76 : [30, 76, 200][$index % 3],
            PaymentStatus::PastDue, PaymentStatus::Canceled => -(int) $profile->payment_status_updated_at?->diffInDays(now()),
            default => null,
        };

        if ($days !== null) {
            $profile->forceFill(['paid_through_at' => today()->addDays($days)->endOfDay()])->saveQuietly();
        }
    }

    /**
     * Files are written by DemoFilesSeeder; the placeholder path does not exist yet.
     */
    private function licenseDocuments(User $user, int $index): void
    {
        $statuses = [VerificationStatus::Approved, VerificationStatus::Pending, VerificationStatus::Rejected];

        for ($number = 1; $number <= 1 + $index % 2; $number++) {
            [$extension, $mimeType, $size] = self::LICENSE_FILES[($index + $number) % count(self::LICENSE_FILES)];
            $status = $statuses[($index + $number) % count($statuses)];

            (new LicenseDocument)->forceFill([
                'user_id' => $user->getKey(),
                'stored_filename' => DemoPeople::pendingFile(LicenseDocument::DIRECTORY),
                'original_filename' => sprintf('license-%02d-%d.%s', $index, $number, $extension),
                'mime_type' => $mimeType,
                'size' => $size,
                'uploaded_at' => now()->subDays($index * 5 + $number),
                'verification_status' => $status,
                'admin_notes' => $status === VerificationStatus::Rejected ? 'Document is unreadable, please re-upload.' : null,
            ])->save();
        }
    }

    private function taxDocuments(User $user, int $index): void
    {
        for ($number = 0; $number < $index % 3; $number++) {
            $type = TaxDocument::DOCUMENT_TYPES[($index + $number) % count(TaxDocument::DOCUMENT_TYPES)];
            $year = now()->year - 1 - $number;
            $uploadedAt = now()->subDays(20 + $index * 3 + $number);

            (new TaxDocument)->forceFill([
                'user_id' => $user->getKey(),
                'document_type' => $type,
                'tax_year' => $year,
                'stored_filename' => DemoPeople::pendingFile(TaxDocument::DIRECTORY),
                'original_filename' => strtolower(str_replace(' ', '-', $type))."-{$year}.pdf",
                'uploaded_at' => $uploadedAt,
                'downloaded_at' => ($index + $number) % 2 === 0 ? $uploadedAt->copy()->addDays(2) : null,
            ])->save();
        }
    }

    /**
     * Reminders that would already have gone out for this expiry date.
     */
    private function reminderHistory(User $user, MemberProfile $profile): void
    {
        $statuses = [DeliveryStatus::Delivered, DeliveryStatus::Delivered, DeliveryStatus::Sent, DeliveryStatus::Skipped];

        foreach (ReminderType::cases() as $position => $type) {
            $dueAt = $profile->license_expires_at->copy()->subDays($type->value)->setTime(2, 0);

            if ($dueAt->isFuture()) {
                continue;
            }

            $user->reminderLogs()->create([
                'reminder_type' => $type,
                'license_expires_at' => $profile->license_expires_at,
                'sent_at' => $dueAt,
                'delivery_status' => $statuses[($user->getKey() + $position) % count($statuses)],
            ]);
        }
    }

    private function auditHistory(User $user, int $index): void
    {
        foreach (['login', 'profile_updated', 'logout'] as $position => $action) {
            (new AuditLog)->forceFill([
                'user_id' => $user->getKey(),
                'action' => $action,
                'ip_address' => '203.0.113.'.($index % 250 + 1), // documentation range (RFC 5737)
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) DemoBrowser/1.0',
                'created_at' => now()->subDays($index % 28 + 1)->addMinutes($position * 7),
            ])->save();
        }
    }
}
