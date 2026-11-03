<?php

namespace App\Listeners;

use App\Http\Controllers\Security\TwoFactorSetupController;
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

        AuditLog::record('login', user: $event->user);

        // The password was just proved: two-factor setup need not ask for it again straight away.
        if (request()->hasSession()) {
            request()->session()->put(TwoFactorSetupController::FRESH_LOGIN_KEY, time());
        }
    }
}
