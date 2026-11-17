<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The 24-month rule (client's answer): when an individual membership was canceled on properx.org
 * (reported by the WordPress plugin), and whether staff lifted the wait for a new application.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_profiles', function (Blueprint $table) {
            $table->timestamp('membership_canceled_at')->nullable()->after('payment_status_updated_at')->index();
            $table->timestamp('rejoin_block_lifted_at')->nullable()->after('membership_canceled_at');
            $table->string('rejoin_block_lifted_reason', 40)->nullable()->after('rejoin_block_lifted_at');
        });
    }

    public function down(): void
    {
        Schema::table('member_profiles', function (Blueprint $table) {
            $table->dropIndex(['membership_canceled_at']);
            $table->dropColumn(['membership_canceled_at', 'rejoin_block_lifted_at', 'rejoin_block_lifted_reason']);
        });
    }
};
