<?php

use App\Services\ReferenceData;
use Illuminate\Database\Migrations\Migration;

/**
 * "Cancel agency membership" for the Executive Director (App\Services\ReferenceData: additive
 * and idempotent).
 */
return new class extends Migration
{
    public function up(): void
    {
        app(ReferenceData::class)->seed();
    }

    /**
     * Reference data is never deleted.
     */
    public function down(): void
    {
        //
    }
};
