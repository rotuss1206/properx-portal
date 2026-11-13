<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Renewals (H1-H3): when an agency became past due (its grace period counts from there), and the
 * paid period a renewal reminder or an "expired" email belongs to, so each goes once per period.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->timestamp('past_due_since')->nullable()->after('payment_event_at')->index();
        });

        // Agencies past due now: their latest payment event is the best start there is.
        DB::table('agencies')->where('status', 'past_due')->update(['past_due_since' => DB::raw('COALESCE(payment_event_at, updated_at)')]);

        Schema::table('reminder_logs', function (Blueprint $table) {
            $table->date('period_ends_at')->nullable()->after('license_expires_at');
            $table->unique(['user_id', 'reminder_type', 'period_ends_at'], 'reminder_logs_unique_per_period');
        });
    }

    public function down(): void
    {
        Schema::table('reminder_logs', function (Blueprint $table) {
            $table->dropUnique('reminder_logs_unique_per_period');
            $table->dropColumn('period_ends_at');
        });

        Schema::table('agencies', function (Blueprint $table) {
            $table->dropIndex(['past_due_since']);
            $table->dropColumn('past_due_since');
        });
    }
};
