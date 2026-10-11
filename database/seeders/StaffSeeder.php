<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
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
        User::factory()
            ->create([
                'name' => 'API Service',
                'email' => 'api-service@properx.test',
                'password' => Str::random(64),
            ])
            ->assignRole(Role::ApiService->value);
    }
}
