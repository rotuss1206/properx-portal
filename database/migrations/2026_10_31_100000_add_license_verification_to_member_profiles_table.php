<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff's verification of a person's license (NPN and resident state; Agency spec 3.5):
 * verified, pending_review or needs_attention. Licensed people start as pending_review;
 * agency employees hold no license, so theirs stays null. Expand only.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('member_profiles', function (Blueprint $table) {
            $table->string('license_verification', 20)->nullable()->after('license_status')->index();
        });

        DB::table('member_profiles')
            ->where(fn ($query) => $query->whereNull('person_type')->orWhere('person_type', '!=', 'agency_employee'))
            ->update(['license_verification' => 'pending_review']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('member_profiles', function (Blueprint $table) {
            $table->dropIndex(['license_verification']);
            $table->dropColumn('license_verification');
        });
    }
};
