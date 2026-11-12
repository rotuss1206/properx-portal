<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The license verification queue (Agency spec 8.3): when the status last changed (the queue's
 * date, filtered and sorted) and staff's reason for "Needs attention" (shown to the person,
 * encrypted by the model).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_profiles', function (Blueprint $table) {
            $table->timestamp('license_verification_changed_at')->nullable()->after('license_verification');
            $table->text('license_verification_note')->nullable()->after('license_verification_changed_at');
            $table->index(['license_verification', 'license_verification_changed_at'], 'member_profiles_verification_queue_index');
        });

        // Records already waiting: their last change is the best date there is.
        DB::table('member_profiles')
            ->whereNotNull('license_verification')
            ->update(['license_verification_changed_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('member_profiles', function (Blueprint $table) {
            $table->dropIndex('member_profiles_verification_queue_index');
            $table->dropColumn(['license_verification_changed_at', 'license_verification_note']);
        });
    }
};
