<?php

namespace App\Auth;

use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fortify's enable route takes force=1, which replaces the secret of a confirmed account with a new
 * one nobody has proved they hold (the account stays "confirmed"), so the person's authenticator app
 * stops working. While two-factor authentication is required that is refused like turning it off
 * (App\Auth\KeepRequiredTwoFactor). First-time setup and an unconfirmed attempt are not affected.
 */
class KeepConfirmedTwoFactorSecret extends EnableTwoFactorAuthentication
{
    /**
     * @param  mixed  $user
     * @param  bool  $force
     */
    public function __invoke($user, $force = false): void
    {
        abort_if(
            $force === true && $user->requiresTwoFactor() && $user->hasConfirmedTwoFactor(),
            Response::HTTP_FORBIDDEN,
            'The two-factor secret of a confirmed account cannot be replaced.',
        );

        parent::__invoke($user, $force);
    }
}
