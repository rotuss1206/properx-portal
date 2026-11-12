<?php

use App\Services\ReferenceData;
use Illuminate\Database\Migrations\Migration;

/**
 * The license verification queue's permission for the Membership Coordinator and the Executive
 * Director (App\Services\ReferenceData: additive and idempotent).
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
