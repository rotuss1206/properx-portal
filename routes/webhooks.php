<?php

use App\Http\Controllers\PostmarkWebhookController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Middleware\VerifyPostmarkWebhook;
use Illuminate\Support\Facades\Route;

/*
| Stateless machine-to-machine endpoints: no session, cookies or CSRF.
*/

Route::post('/webhooks/postmark', PostmarkWebhookController::class)
    ->middleware(VerifyPostmarkWebhook::class)
    ->name('webhooks.postmark');

// Signature is verified in the controller (it needs the raw body).
Route::post('/api/webhooks/stripe', StripeWebhookController::class)
    ->name('webhooks.stripe');
