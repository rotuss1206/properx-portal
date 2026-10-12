<?php

use App\Http\Controllers\Api\ServiceAccountController;
use App\Http\Controllers\PostmarkWebhookController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Middleware\VerifyPostmarkWebhook;
use Illuminate\Support\Facades\Route;

/*
| Stateless machine-to-machine endpoints: no session, cookies or CSRF.
| Rate limited (see bootstrap/app.php).
*/

Route::post('/webhooks/postmark', PostmarkWebhookController::class)
    ->middleware(VerifyPostmarkWebhook::class)
    ->name('webhooks.postmark');

// Signature is verified in the controller (it needs the raw body); processing runs
// as the API Service account authenticated with API_SERVICE_KEY.
Route::post('/api/webhooks/stripe', StripeWebhookController::class)
    ->name('webhooks.stripe');

// Calls from automation authenticated with the API Service key (Spec 3.7, 8).
Route::middleware('auth.api-key')->prefix('api/service')->name('api.service.')->group(function () {
    Route::get('/whoami', ServiceAccountController::class)->name('whoami');
});
