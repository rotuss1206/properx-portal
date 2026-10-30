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

// In-app "renewal due" notices for the member area, once per paid period (<= 30 days ahead).
Schedule::command('app:send-renewal-notices')
    ->dailyAt('07:00')
    ->timezone(config('portal.schedule_timezone'))
    ->withoutOverlapping(5)
    ->onOneServer();

// Unfinished agency enrollments are kept for portal.registration.draft_days after their last save.
Schedule::command('model:prune', ['--model' => [AgencyRegistrationDraft::class]])
    ->dailyAt('01:30')
    ->timezone(config('portal.schedule_timezone'))
    ->onOneServer();
