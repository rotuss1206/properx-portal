<?php

namespace App\Listeners;

use App\Http\Middleware\EnforceAbsoluteSessionTimeout;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * Fired after a full login, i.e. after the 2FA challenge when the user has 2FA.
 */
class RecordLogin
{
    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $event->user->forceFill(['last_login_at' => now()])->saveQuietly();

        // Start of the 8-hour absolute session window.
        if (request()->hasSession()) {
            request()->session()->put(EnforceAbsoluteSessionTimeout::SESSION_KEY, now()->getTimestamp());
        }

        AuditLog::record('login', user: $event->user);
    }
}
