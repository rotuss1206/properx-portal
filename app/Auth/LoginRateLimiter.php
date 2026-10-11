<?php

namespace App\Auth;

use Illuminate\Http\Request;
use Laravel\Fortify\LoginRateLimiter as FortifyLoginRateLimiter;

/**
 * Spec §8: at most 5 failed login attempts per 15 minutes from one IP address.
 * Only failed attempts count (Fortify increments on failure).
 */
class LoginRateLimiter extends FortifyLoginRateLimiter
{
    public function tooManyAttempts(Request $request)
    {
        return $this->limiter->tooManyAttempts($this->throttleKey($request), config('portal.security.login_max_attempts'));
    }

    public function increment(Request $request)
    {
        $this->limiter->hit($this->throttleKey($request), config('portal.security.login_decay_minutes') * 60);
    }

    protected function throttleKey(Request $request)
    {
        return 'login|'.$request->ip();
    }
}
