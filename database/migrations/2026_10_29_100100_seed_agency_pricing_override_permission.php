<?php

use App\Services\ReferenceData;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds "agencies.pricing.override" (Executive Director only: the Change tier and Change
 * rate type actions). Additive and idempotent, like seed_reference_data.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        app(ReferenceData::class)->seed();
    }

    /**
     * Reverse the migrations: reference data is never deleted (roles may be assigned).
     */
    public function down(): void
    {
        //
    }
};
