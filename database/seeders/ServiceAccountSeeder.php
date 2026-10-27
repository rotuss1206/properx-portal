<?php

namespace Database\Seeders;

use App\Services\ReferenceData;
use Illuminate\Database\Seeder;

/**
 * The API Service system account (App\Services\ReferenceData, shared with the
 * seed_reference_data migration). Safe to re-run: an existing account is kept.
 */
class ServiceAccountSeeder extends Seeder
{
    public const EMAIL = ReferenceData::SERVICE_ACCOUNT_EMAIL;

    public function run(ReferenceData $referenceData): void
    {
        $referenceData->ensureServiceAccount();
    }
}
