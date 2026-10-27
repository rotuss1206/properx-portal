<?php

namespace Database\Seeders;

use App\Services\ReferenceData;
use Illuminate\Database\Seeder;

/**
 * Roles and permissions from Spec 3.7 (App\Services\ReferenceData, shared with the
 * seed_reference_data migration). Additive and safe to re-run.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(ReferenceData $referenceData): void
    {
        $referenceData->seedRolesAndPermissions();
    }
}
