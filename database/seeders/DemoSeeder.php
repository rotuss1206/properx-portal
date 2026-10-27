<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Fictional staff, members, agencies and documents for local development and demos:
 *
 *     DEMO_SEED_ALLOWED=true php artisan migrate:fresh --seeder=DemoSeeder
 *
 * Refuses to run unless DEMO_SEED_ALLOWED=true, which is never set on the platform. Fixed data
 * without Faker or factories (dev-only packages, missing from the platform image); idempotent,
 * so running it again adds nothing. The @properx.test accounts get random passwords: sign in
 * with the DEMO_LOGIN_EMAIL accounts (DemoLoginSeeder), whose passwords are printed.
 */
class DemoSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        if (config('portal.demo_seed_allowed') !== true) {
            throw new RuntimeException('Demo data is disabled. Set DEMO_SEED_ALLOWED=true (local development only) to seed it.');
        }

        $this->call([
            // Reference data normally comes from the seed_reference_data migration; harmless here.
            RolesAndPermissionsSeeder::class,
            ServiceAccountSeeder::class,
            StaffSeeder::class,
            MemberSeeder::class,
            AgencySeeder::class,
            LibrarySeeder::class,
            // Accounts on plus addresses of DEMO_LOGIN_EMAIL, with random passwords (skipped when unset).
            DemoLoginSeeder::class,
            DemoFilesSeeder::class,
        ]);
    }
}
