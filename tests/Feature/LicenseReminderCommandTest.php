<?php

namespace Tests\Feature;

use App\Enums\DeliveryStatus;
use App\Enums\LicenseStatus;
use App\Enums\NotificationCategory;
use App\Enums\ReminderType;
use App\Enums\Role;
use App\Models\ReminderLog;
use App\Models\User;
use App\Notifications\LicenseExpiryReminder;
use App\Notifications\ScheduledTaskFailed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

class LicenseReminderCommandTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        Notification::fake();
        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(2, 0));
    }

    private function memberExpiringIn(int $days): User
    {
        $member = $this->member();
        $member->memberProfile->update(['license_expires_at' => today()->addDays($days)]);

        return $member->fresh(['memberProfile']);
    }

    public function test_sends_the_matching_reminder_for_60_30_and_7_days(): void
    {
        $expectations = [
            60 => ReminderType::Days60,
            45 => ReminderType::Days60,
            30 => ReminderType::Days30,
            12 => ReminderType::Days30,
            7 => ReminderType::Days7,
            0 => ReminderType::Days7,
        ];

        $members = collect($expectations)->map(fn ($type, $days) => $this->memberExpiringIn($days));
        $notReminded = [$this->memberExpiringIn(61), $this->member('expired')];

        $this->artisan('app:check-license-expirations')->assertSuccessful();

        foreach ($expectations as $days => $type) {
            Notification::assertSentTo($members[$days], LicenseExpiryReminder::class, fn ($n) => $n->type === $type);
            $this->assertDatabaseHas('reminder_logs', [
                'user_id' => $members[$days]->id,
                'reminder_type' => $type->value,
                'delivery_status' => DeliveryStatus::Sent->value,
            ]);
        }

        foreach ($notReminded as $member) {
            Notification::assertNotSentTo($member, LicenseExpiryReminder::class);
        }
    }

    public function test_reminder_is_queued_and_has_html_text_and_unsubscribe_link(): void
    {
        $member = $this->memberExpiringIn(30);

        $notification = new LicenseExpiryReminder($member->memberProfile, ReminderType::Days30);
        $this->assertInstanceOf(ShouldQueue::class, $notification);

        $mail = $notification->toMail($member);
        $preferencesUrl = LicenseExpiryReminder::preferencesUrl($member->memberProfile);

        $html = (string) $mail->render();
        $text = (string) app(Markdown::class)->renderText($mail->markdown, $mail->data());

        $this->assertSame('Your insurance license expires in 30 days', $mail->subject);
        $this->assertStringContainsString('<html', $html);
        $this->assertStringContainsString($preferencesUrl, $html);
        $this->assertStringNotContainsString('<html', $text);
        $this->assertStringContainsString($preferencesUrl, $text);
        $this->assertStringContainsString($member->memberProfile->license_expires_at->toFormattedDateString(), $text);
    }

    public function test_opted_out_member_is_logged_as_skipped(): void
    {
        $member = $this->memberExpiringIn(30);
        $member->notificationSettings()->create(['category' => NotificationCategory::LicenseExpiry, 'enabled' => false]);

        $this->artisan('app:check-license-expirations')->assertSuccessful();

        Notification::assertNotSentTo($member, LicenseExpiryReminder::class);
        $this->assertDatabaseHas('reminder_logs', [
            'user_id' => $member->id,
            'reminder_type' => ReminderType::Days30->value,
            'delivery_status' => DeliveryStatus::Skipped->value,
        ]);
    }

    public function test_other_disabled_categories_do_not_block_reminders(): void
    {
        $member = $this->memberExpiringIn(7);
        $member->notificationSettings()->create(['category' => NotificationCategory::Announcements, 'enabled' => false]);

        $this->artisan('app:check-license-expirations')->assertSuccessful();

        Notification::assertSentTo($member, LicenseExpiryReminder::class);
    }

    public function test_never_sends_the_same_reminder_twice(): void
    {
        $member = $this->memberExpiringIn(30);

        $this->artisan('app:check-license-expirations')->assertSuccessful();
        $this->travel(1)->day();
        $this->artisan('app:check-license-expirations')->assertSuccessful();

        Notification::assertSentToTimes($member, LicenseExpiryReminder::class, 1);
        $this->assertSame(1, ReminderLog::where('user_id', $member->id)->count());
    }

    public function test_next_threshold_is_sent_later_and_renewal_restarts_the_cycle(): void
    {
        $member = $this->memberExpiringIn(30);
        $this->artisan('app:check-license-expirations');

        $this->travel(23)->days(); // 7 days left
        $this->artisan('app:check-license-expirations');

        $member->memberProfile->update(['license_expires_at' => today()->addDays(60)]);
        $this->artisan('app:check-license-expirations');

        $this->assertEqualsCanonicalizing(
            [30, 7, 60],
            ReminderLog::where('user_id', $member->id)->pluck('reminder_type')->map->value->all(),
        );
        Notification::assertSentToTimes($member, LicenseExpiryReminder::class, 3);
    }

    public function test_recalculates_license_statuses(): void
    {
        $member = $this->memberExpiringIn(90);
        $profile = $member->memberProfile;
        $this->assertSame(LicenseStatus::Active, $profile->license_status);

        $this->travel(40)->days(); // now 50 days left

        $this->artisan('app:check-license-expirations')->assertSuccessful();
        $this->assertSame(LicenseStatus::Expiring, $profile->fresh()->license_status);

        $this->travel(60)->days(); // now expired

        $this->artisan('app:check-license-expirations')->assertSuccessful();
        $this->assertSame(LicenseStatus::Expired, $profile->fresh()->license_status);
    }

    public function test_failure_alerts_the_executive_director(): void
    {
        $director = $this->staff(Role::ExecutiveDirector);
        $this->memberExpiringIn(30);

        Event::listen('eloquent.creating: '.ReminderLog::class, function () {
            throw new \RuntimeException('Database is read-only');
        });

        $this->artisan('app:check-license-expirations')->assertFailed();

        Notification::assertSentTo($director, ScheduledTaskFailed::class, function (ScheduledTaskFailed $n) {
            return $n->task === 'app:check-license-expirations'
                && str_contains($n->errors[0], 'Database is read-only');
        });
    }

    public function test_is_scheduled_nightly(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command, 'app:check-license-expirations'));

        $this->assertNotNull($event);
        $this->assertSame('0 2 * * *', $event->expression);
        $this->assertSame(config('portal.schedule_timezone'), $event->timezone);
    }
}
