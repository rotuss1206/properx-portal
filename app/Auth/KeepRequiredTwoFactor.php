<?php

namespace App\Auth;

use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-factor authentication stays on while it is required (Spec 8, KEENEY rule). Fortify's disable
 * route is still registered, also under /nova/user-security, so the action itself refuses, whatever
 * the page shows. With NOVA_REQUIRE_2FA=false (local only) Fortify's behaviour returns.
 */
class KeepRequiredTwoFactor extends DisableTwoFactorAuthentication
{
    /**
     * @param  mixed  $user
     */
    public function __invoke($user): void
    {
        abort_if($user->requiresTwoFactor(), Response::HTTP_FORBIDDEN, 'Two-factor authentication is required and cannot be turned off.');

        parent::__invoke($user);
    }
}
