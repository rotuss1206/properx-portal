<?php

namespace Database\Seeders;

use App\Models\LibraryDocument;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Fictional staff, members, agencies and documents for local development and demos:
 *
 *     DEMO_SEED_ALLOWED=true php artisan migrate:fresh --seeder=DemoSeeder
 *
 * Refuses to run unless DEMO_SEED_ALLOWED=true, which is never set on the platform.
 * The @properx.test accounts use the password "password"; with DEMO_LOGIN_EMAIL set,
 * DemoLoginSeeder adds one account per role on its plus addresses, with random passwords.
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
            RolesAndPermissionsSeeder::class,
            StaffSeeder::class,
            MemberSeeder::class,
            AgencySeeder::class,
        ]);

        LibraryDocument::factory()->count(12)->published()->create();
        LibraryDocument::factory()->count(3)->draft()->create();

        // Accounts on plus addresses of DEMO_LOGIN_EMAIL, with random passwords (skipped when unset).
        $this->call(DemoLoginSeeder::class);

        $this->call(DemoFilesSeeder::class);
    }
}
