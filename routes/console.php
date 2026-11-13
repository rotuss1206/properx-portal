<?php

use App\Models\AgencyRegistrationDraft;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nightly, off-peak (Spec 3.5). The platform caps a scheduled task at 120 seconds, so the
// command stops at its time budget and the next hourly run (02:00-05:00) picks up the rest;
// re-runs are idempotent. On failure the command itself alerts the Executive Director.
Schedule::command('app:check-license-expirations')
    ->cron('0 2-5 * * *')
    ->timezone(config('portal.schedule_timezone'))
    ->withoutOverlapping(5)
    ->onOneServer();

// Renewal reminders (portal.renewals.reminder_days before the period ends), email and the bell.
// Hourly 07:00-09:00 so a run stopped by the 120-second limit is finished by the next; each
// reminder is logged first and never sent twice.
Schedule::command('app:send-renewal-reminders')
    ->cron('0 7-9 * * *')
    ->timezone(config('portal.schedule_timezone'))
    ->withoutOverlapping(5)
    ->onOneServer();

// After a failed renewal: overdue reminders, then the end of the grace period (inactive, the
// "expired" emails). Hourly, so access closes within the hour the grace period ends.
Schedule::command('app:expire-memberships')
    ->hourlyAt(20)
    ->timezone(config('portal.schedule_timezone'))
    ->withoutOverlapping(5)
    ->onOneServer();

// People removed from an agency leave it on its renewal date (G4). Hourly, idempotent.
Schedule::command('app:apply-agency-removals')
    ->hourlyAt(25)
    ->timezone(config('portal.schedule_timezone'))
    ->withoutOverlapping(5)
    ->onOneServer();

// Agency invitation reminders (day 3, day 7). Hourly 09:00-11:00 so a run stopped by the 120-second
// limit is finished by the next; each reminder is logged first and never sent twice.
Schedule::command('app:send-invitation-reminders')
    ->cron('0 9-11 * * *')
    ->timezone(config('portal.schedule_timezone'))
    ->withoutOverlapping(5)
    ->onOneServer();

// Unfinished agency enrollments are kept for portal.registration.draft_days after their last save.
Schedule::command('model:prune', ['--model' => [AgencyRegistrationDraft::class]])
    ->dailyAt('01:30')
    ->timezone(config('portal.schedule_timezone'))
    ->onOneServer();
