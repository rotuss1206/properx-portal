<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;

/**
 * Prints a one-time link to set an account's password, for when the reset email cannot be
 * delivered (e.g. Azure Communication Services is unavailable). It is the same link the
 * "Forgot your password?" email carries: single use, expiring after auth.passwords.users.expire
 * minutes, and replacing any earlier link of that account.
 *
 * Audited (password_link_created); never written to the application log.
 */
#[Signature('app:password-link {email : The email address of the portal account}')]
#[Description('Print a one-time link to set the password of a portal account')]
class CreatePasswordLink extends Command
{
    public function handle(): int
    {
        $user = User::query()->where('email', (string) $this->argument('email'))->first();

        // Portal (Nova) accounts only: the API Service account has no web login.
        if ($user === null || ! Gate::forUser($user)->allows('viewNova')) {
            $this->error('No portal account with this email address.');

            return self::FAILURE;
        }

        $token = Password::broker()->createToken($user);
        // The link Nova's "Forgot your password?" email carries (PendingFortifyConfiguration).
        $url = route('nova.pages.password.reset', ['token' => $token]);

        AuditLog::record('password_link_created', $user);

        $this->line($url);
        $this->newLine();
        $this->comment(sprintf(
            'Single use, valid for %d minutes; it replaces any earlier link for this account. Share it only with the account owner.',
            config('auth.passwords.'.config('auth.defaults.passwords').'.expire'),
        ));

        return self::SUCCESS;
    }
}
