<?php

use App\Services\ReferenceData;
use Illuminate\Database\Migrations\Migration;

/**
 * Roles, permissions and the API Service account (App\Services\ReferenceData). The KEENEY
 * pipeline runs only `php artisan migrate --force`, never a seeder, so reference data
 * ships as migrations. Additive and idempotent: safe where the roles already exist.
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
