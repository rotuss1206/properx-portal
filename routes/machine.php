<?php

use App\Http\Controllers\Api\ServiceAccountController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Stateless machine-to-machine endpoints: no session, cookies or CSRF.
| Rate limited (see bootstrap/app.php).
*/

// Stripe is authenticated by its signature only, verified in the controller (it needs the
// raw body); processing is audited as the API Service account. No API key.
// Its own, higher rate limit instead of the machine API's 120 per minute.
Route::post('/api/webhooks/stripe', StripeWebhookController::class)
    ->withoutMiddleware('throttle:120,1')
    ->middleware('throttle:stripe-webhook')
    ->name('webhooks.stripe');

// Calls from automation authenticated with the API Service key (Spec 3.7, 8).
Route::middleware('auth.api-key')->prefix('api/service')->name('api.service.')->group(function () {
    Route::get('/whoami', ServiceAccountController::class)->name('whoami');
});
