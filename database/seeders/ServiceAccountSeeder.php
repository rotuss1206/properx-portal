<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use App\Services\ServiceAccount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The API Service system account (Spec 3.7): the actor of automated work such as Stripe
 * webhooks. Reference data, needed in every environment. It has no web login (not a Nova
 * role) and a random password nobody knows. Safe to re-run: an existing account is kept.
 */
class ServiceAccountSeeder extends Seeder
{
    public const EMAIL = 'api-service@properx.test';

    public function run(): void
    {
        if (app(ServiceAccount::class)->user() !== null) {
            return;
        }

        User::factory()
            ->create([
                'name' => 'API Service',
                'email' => self::EMAIL,
                'password' => Str::random(64),
            ])
            ->assignRole(Role::ApiService->value);
    }
}
