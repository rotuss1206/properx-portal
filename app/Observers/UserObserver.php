<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\User;

/**
 * Audits password changes and two-factor enrolment (Spec 8), whatever the entry point
 * (Nova user security page, password reset, staff editing an account).
 */
class UserObserver
{
    public function updated(User $user): void
    {
        if ($user->wasChanged('password')) {
            AuditLog::record('password_changed', $user);
        }

        if ($user->wasChanged('two_factor_confirmed_at') && $user->two_factor_confirmed_at !== null) {
            AuditLog::record('mfa_enrolled', $user);
        }

        if ($user->wasChanged('two_factor_secret') && $user->two_factor_secret === null && $user->getOriginal('two_factor_secret') !== null) {
            AuditLog::record('mfa_disabled', $user);
        }
    }
}
