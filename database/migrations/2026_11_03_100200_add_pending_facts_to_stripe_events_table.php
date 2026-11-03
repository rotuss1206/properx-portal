<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stripe often reports a new member's payment before their application reaches the portal. Such
 * an event cannot be matched yet, so the customer id and what the event says (status, paid period,
 * time) are kept and applied when the member is created. Ids, enum values and dates only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stripe_events', function (Blueprint $table) {
            $table->string('stripe_customer_id', 64)->nullable()->after('member_profile_id')->index();
            $table->string('pending_status', 20)->nullable()->after('stripe_customer_id');
            $table->timestamp('pending_paid_through_at')->nullable()->after('pending_status');
            $table->timestamp('event_created_at')->nullable()->after('pending_paid_through_at');
        });
    }

    public function down(): void
    {
        Schema::table('stripe_events', function (Blueprint $table) {
            $table->dropIndex(['stripe_customer_id']);
            $table->dropColumn(['stripe_customer_id', 'pending_status', 'pending_paid_through_at', 'event_created_at']);
        });
    }
};
