<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nightly, off-peak (ТЗ, розділ 3.5). On failure the command itself alerts the Executive Director.
Schedule::command('app:check-license-expirations')
    ->dailyAt('02:00')
    ->timezone(config('portal.schedule_timezone'))
    ->withoutOverlapping()
    ->onOneServer();
