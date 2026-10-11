<?php

namespace Database\Seeders;

use App\Models\LibraryDocument;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            StaffSeeder::class,
            MemberSeeder::class,
        ]);

        LibraryDocument::factory()->count(12)->published()->create();
        LibraryDocument::factory()->count(3)->draft()->create();

        $this->call(DemoFilesSeeder::class);
    }
}
