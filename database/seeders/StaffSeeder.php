<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use App\Services\ApiKeys\ApiKeyManager;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * One test account per staff role (password: "password") and the API service account.
 */
class StaffSeeder extends Seeder
{
    private const STAFF = [
        'director@properx.test' => ['Jordan Ellis', Role::ExecutiveDirector],
        'coordinator@properx.test' => ['Casey Morgan', Role::MembershipCoordinator],
        'compliance@properx.test' => ['Riley Chen', Role::ComplianceSpecialist],
    ];

    public function run(): void
    {
        foreach (self::STAFF as $email => [$name, $role]) {
            User::factory()
                ->create(['name' => $name, 'email' => $email])
                ->assignRole($role->value);
        }

        // Machine account: random password nobody knows, it authenticates by API key only.
        $service = User::factory()
            ->create([
                'name' => 'API Service',
                'email' => 'api-service@properx.test',
                'password' => Str::random(64),
            ])
            ->assignRole(Role::ApiService->value);

        // Register the key from .env so local webhooks keep working after a reseed.
        // Without one, run `php artisan app:api-key:rotate` and put the key in .env.
        if (filled(config('services.api_service.key'))) {
            app(ApiKeyManager::class)->register($service, config('services.api_service.key'), 'from environment');
        }
    }
}
