<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\User;

/**
 * The system user the application acts as for automated work it was not asked to do by a
 * person (Stripe webhooks, scheduled jobs), so audit entries have an actor. Found by role:
 * no API key is involved. API keys authenticate external callers only (auth.api-key).
 */
class ServiceAccount
{
    public function user(): ?User
    {
        return User::role(Role::ApiService->value)->orderBy('id')->first();
    }
}
