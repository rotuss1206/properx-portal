<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Reference data every environment needs: roles and permissions, and the API Service
 * system account. Safe to re-run. Fictional people, agencies and documents come from
 * DemoSeeder, never from here.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            ServiceAccountSeeder::class,
        ]);
    }
}
