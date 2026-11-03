<?php

use App\Http\Controllers\Security\TwoFactorSetupController;
use Illuminate\Support\Facades\Route;

/*
 * First-time two-factor setup for members and staff (App\Http\Middleware\RequireTwoFactor sends
 * people here). Only a signed-in person, on their own account.
 */
Route::middleware(['web', 'auth'])
    ->prefix('security/setup-2fa')
    ->name('security.two-factor')
    ->controller(TwoFactorSetupController::class)
    ->group(function () {
        Route::get('/', 'intro');
        Route::post('/', 'start')->middleware('throttle:two-factor-setup')->name('.start');
        Route::get('scan', 'scan')->name('.scan');
        Route::get('verify', 'verifyForm')->name('.verify');
        Route::post('verify', 'verify')->middleware('throttle:two-factor-setup')->name('.confirm');
        Route::get('recovery', 'recovery')->name('.recovery');
        Route::get('recovery/download', 'download')->name('.recovery.download');
        Route::post('recovery', 'finish')->name('.finish');
    });
