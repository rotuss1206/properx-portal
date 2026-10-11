<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ties each reminder to the expiry date it was about, so a renewed license
 * starts a fresh 60/30/7 cycle and the same reminder is never sent twice.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('reminder_logs', function (Blueprint $table) {
            $table->date('license_expires_at')->nullable()->after('reminder_type');

            $table->unique(['user_id', 'reminder_type', 'license_expires_at'], 'reminder_logs_unique_per_cycle');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reminder_logs', function (Blueprint $table) {
            $table->dropUnique('reminder_logs_unique_per_cycle');
            $table->dropColumn('license_expires_at');
        });
    }
};
