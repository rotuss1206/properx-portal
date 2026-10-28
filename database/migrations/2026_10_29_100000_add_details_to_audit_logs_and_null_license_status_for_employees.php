<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - audit_logs.details: what changed, without PHI (record IDs, role names, field names,
 *   tier and rate type values, a staff-entered reason). Names are looked up when shown.
 * - member_profiles.license_status becomes nullable: agency employees hold no license
 *   (Agency spec 3.5), so they have no license status.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->json('details')->nullable()->after('resource_id');
        });

        Schema::table('member_profiles', function (Blueprint $table) {
            $table->string('license_status', 20)->nullable()->default(null)->change();
        });

        DB::table('member_profiles')->where('person_type', 'agency_employee')->update(['license_status' => null]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('member_profiles')->whereNull('license_status')->update(['license_status' => 'expired']);

        Schema::table('member_profiles', function (Blueprint $table) {
            $table->string('license_status', 20)->default('active')->nullable(false)->change();
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn('details');
        });
    }
};
