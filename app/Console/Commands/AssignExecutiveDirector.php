<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\StaffAccountCreated;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * The only way to give or take the Executive Director role (Nova cannot, Spec 3.7).
 *
 * Assigning creates the staff account when there is none (random unknown password; the
 * person sets theirs from the emailed link) and replaces any other staff role. Revoking
 * leaves the account without a role, so it can no longer sign in; the last Executive
 * Director cannot be revoked. Asks for confirmation unless --force. Audited as
 * executive_director_assigned / executive_director_revoked (the role change itself is
 * also audited as role_changed). Prints no password.
 */
#[Signature('app:assign-executive-director
    {email : Email address of the staff account}
    {--revoke : Take the Executive Director role away instead}
    {--name= : Full name, used when the account has to be created}
    {--force : Do not ask for confirmation}')]
#[Description('Assign or revoke the Executive Director role (not possible in Nova)')]
class AssignExecutiveDirector extends Command
{
    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        return $this->option('revoke') ? $this->revoke($email, $user) : $this->assign($email, $user);
    }

    private function assign(string $email, ?User $user): int
    {
        if ($user?->isExecutiveDirector()) {
            $this->info("{$email} is already the Executive Director.");

            return self::SUCCESS;
        }

        if ($user !== null && ($user->isMember() || $user->hasRole(Role::ApiService->value))) {
            $this->error('This account is not a staff account; use a separate staff email address.');

            return self::FAILURE;
        }

        $name = trim((string) $this->option('name'));

        if ($user === null) {
            if (Validator::make(['email' => $email], ['email' => 'required|email|max:254'])->fails()) {
                $this->error('This is not a valid email address.');

                return self::FAILURE;
            }

            if ($name === '') {
                $this->error('No account with this email address: pass --name="Full Name" to create it.');

                return self::FAILURE;
            }
        }

        $question = $user === null
            ? "Create a staff account for {$email} and make it the Executive Director?"
            : "Make {$email} the Executive Director?";

        if (! $this->confirmed($question)) {
            return self::FAILURE;
        }

        $created = $user === null;

        $user = DB::transaction(function () use ($user, $email, $name, $created) {
            $user ??= User::create(['name' => $name, 'email' => $email, 'password' => Str::password(64)]);
            $user->syncRoles([Role::ExecutiveDirector->value]);

            AuditLog::record('executive_director_assigned', $user, details: ['account_created' => $created, 'via' => 'console']);

            return $user;
        });

        if ($created) {
            $user->notify(new StaffAccountCreated);
            $this->info("Created {$email} as the Executive Director. A set-password email is on its way.");
            $this->comment("If it does not arrive: php artisan app:password-link {$email}");
        } else {
            $this->info("{$email} is now the Executive Director.");
        }

        return self::SUCCESS;
    }

    private function revoke(string $email, ?User $user): int
    {
        if ($user === null || ! $user->isExecutiveDirector()) {
            $this->error('No Executive Director with this email address.');

            return self::FAILURE;
        }

        if (User::role(Role::ExecutiveDirector->value)->count() <= 1) {
            $this->error('This is the last Executive Director: assign another one first.');

            return self::FAILURE;
        }

        if (! $this->confirmed("Revoke the Executive Director role from {$email}? The account will have no role and cannot sign in.")) {
            return self::FAILURE;
        }

        DB::transaction(function () use ($user) {
            $user->removeRole(Role::ExecutiveDirector->value);

            AuditLog::record('executive_director_revoked', $user, details: ['via' => 'console']);
        });

        $this->info("Revoked the Executive Director role from {$email}.");

        return self::SUCCESS;
    }

    private function confirmed(string $question): bool
    {
        if ($this->option('force') || $this->confirm($question)) {
            return true;
        }

        $this->comment('Nothing changed.');

        return false;
    }
}
