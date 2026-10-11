<?php

use App\Http\Controllers\PostmarkWebhookController;
use App\Http\Middleware\VerifyPostmarkWebhook;
use Illuminate\Support\Facades\Route;

/*
| Stateless machine-to-machine endpoints: no session, cookies or CSRF.
*/

Route::post('/webhooks/postmark', PostmarkWebhookController::class)
    ->middleware(VerifyPostmarkWebhook::class)
    ->name('webhooks.postmark');
