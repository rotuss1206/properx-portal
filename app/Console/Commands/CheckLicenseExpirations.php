<?php

namespace App\Console\Commands;

use App\Enums\DeliveryStatus;
use App\Enums\LicenseStatus;
use App\Enums\NotificationCategory;
use App\Enums\ReminderType;
use App\Enums\Role;
use App\Models\MemberProfile;
use App\Models\NotificationSetting;
use App\Models\ReminderLog;
use App\Models\User;
use App\Notifications\LicenseExpiryReminder;
use App\Notifications\ScheduledTaskFailed;
use Carbon\CarbonInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Nightly license job (ТЗ, розділ 3.5): refresh license statuses and send
 * 60 / 30 / 7-day expiry reminders, honouring notification preferences.
 */
#[Signature('app:check-license-expirations')]
#[Description('Recalculate license statuses and send license expiry reminders')]
class CheckLicenseExpirations extends Command
{
    /** @var array{statuses: int, sent: int, skipped: int} */
    private array $stats = ['statuses' => 0, 'sent' => 0, 'skipped' => 0];

    /** @var list<string> */
    private array $errors = [];

    public function handle(): int
    {
        $today = today();

        try {
            MemberProfile::query()
                ->with(['user.notificationSettings'])
                ->chunkById(200, function ($profiles) use ($today) {
                    foreach ($profiles as $profile) {
                        $this->processProfile($profile, $today);
                    }
                });
        } catch (Throwable $e) {
            report($e);
            $this->errors[] = 'Job aborted: '.$e->getMessage();
        }

        $this->info(sprintf(
            'Statuses updated: %d, reminders sent: %d, skipped (opted out): %d, errors: %d.',
            $this->stats['statuses'], $this->stats['sent'], $this->stats['skipped'], count($this->errors),
        ));

        if ($this->errors !== []) {
            $this->alertExecutiveDirector();

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function processProfile(MemberProfile $profile, CarbonInterface $today): void
    {
        try {
            $this->refreshStatus($profile, $today);

            $type = self::dueReminder($profile->license_expires_at, $today);

            if ($type === null || $this->alreadyHandled($profile, $type)) {
                return;
            }

            $this->remind($profile, $type);
        } catch (Throwable $e) {
            report($e);
            $this->errors[] = "Member profile #{$profile->getKey()}: {$e->getMessage()}";
        }
    }

    private function refreshStatus(MemberProfile $profile, CarbonInterface $today): void
    {
        $status = LicenseStatus::fromExpiryDate($profile->license_expires_at, $today);

        if ($profile->license_status !== $status) {
            $profile->license_status = $status;
            $profile->save();
            $this->stats['statuses']++;
        }
    }

    /**
     * The most urgent reminder whose window has been reached, e.g. 45 days left → 60-day
     * reminder, 5 days left → 7-day reminder. Missed earlier reminders are not sent late.
     */
    public static function dueReminder(?CarbonInterface $expiresAt, CarbonInterface $today): ?ReminderType
    {
        if ($expiresAt === null || $expiresAt->copy()->startOfDay()->lt($today->copy()->startOfDay())) {
            return null;
        }

        $daysLeft = (int) $today->copy()->startOfDay()->diffInDays($expiresAt->copy()->startOfDay());

        $types = ReminderType::cases();
        usort($types, fn (ReminderType $a, ReminderType $b) => $a->value <=> $b->value);

        foreach ($types as $type) {
            if ($daysLeft <= $type->value) {
                return $type;
            }
        }

        return null;
    }

    private function alreadyHandled(MemberProfile $profile, ReminderType $type): bool
    {
        return ReminderLog::query()
            ->where('user_id', $profile->user_id)
            ->where('reminder_type', $type)
            ->whereDate('license_expires_at', $profile->license_expires_at)
            ->exists();
    }

    private function remind(MemberProfile $profile, ReminderType $type): void
    {
        $user = $profile->user;
        $wantsEmail = $this->wantsLicenseExpiryEmails($user);

        // Logged first, so a crash after sending can never cause a duplicate email.
        $log = $user->reminderLogs()->create([
            'reminder_type' => $type,
            'license_expires_at' => $profile->license_expires_at,
            'sent_at' => now(),
            'delivery_status' => $wantsEmail ? DeliveryStatus::Sent : DeliveryStatus::Skipped,
        ]);

        if (! $wantsEmail) {
            $this->stats['skipped']++;

            return;
        }

        try {
            $user->notify(new LicenseExpiryReminder($profile, $type));
            $this->stats['sent']++;
        } catch (Throwable $e) {
            $log->update(['delivery_status' => DeliveryStatus::Failed]);

            throw $e;
        }
    }

    /**
     * Categories are opt-out: a missing setting means enabled.
     */
    private function wantsLicenseExpiryEmails(User $user): bool
    {
        $setting = $user->notificationSettings
            ->first(fn (NotificationSetting $setting) => $setting->category === NotificationCategory::LicenseExpiry);

        return $setting?->enabled ?? true;
    }

    private function alertExecutiveDirector(): void
    {
        $directors = User::role(Role::ExecutiveDirector->value)->get();

        if ($directors->isEmpty()) {
            $this->error('No Executive Director to notify about the failure.');

            return;
        }

        try {
            Notification::send($directors, new ScheduledTaskFailed($this->getName(), $this->errors));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
