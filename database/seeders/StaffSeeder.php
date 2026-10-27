<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Services\ApiKeys\ApiKeyManager;
use App\Services\ServiceAccount;
use Database\Seeders\Support\DemoPeople;
use Illuminate\Database\Seeder;

/**
 * One fictional account per staff role ("password" with APP_ENV=local, random elsewhere), plus
 * the local API key of the API Service account. Idempotent.
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
            DemoPeople::user($email, $name, $role);
        }

        // The service account itself is reference data (seed_reference_data migration). Register
        // the key from .env so local calls to /api/service/* keep working after a reseed (the
        // Stripe webhook needs no key). Without one, run `php artisan app:api-key:rotate`.
        $service = app(ServiceAccount::class)->user();

        if ($service !== null && filled(config('services.api_service.key'))) {
            app(ApiKeyManager::class)->register($service, config('services.api_service.key'), 'from environment');
        }
    }
}
