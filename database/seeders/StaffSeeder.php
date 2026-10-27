<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use App\Services\ApiKeys\ApiKeyManager;
use App\Services\ServiceAccount;
use Illuminate\Database\Seeder;

/**
 * One test account per staff role (password: "password"), plus the local API key of the
 * API Service account (created by ServiceAccountSeeder).
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

        // The service account itself comes from ServiceAccountSeeder. Register the key from .env
        // so local calls to /api/service/* keep working after a reseed (the Stripe webhook needs
        // no key). Without one, run `php artisan app:api-key:rotate` and put the key in .env.
        $service = app(ServiceAccount::class)->user();

        if ($service !== null && filled(config('services.api_service.key'))) {
            app(ApiKeyManager::class)->register($service, config('services.api_service.key'), 'from environment');
        }
    }
}
