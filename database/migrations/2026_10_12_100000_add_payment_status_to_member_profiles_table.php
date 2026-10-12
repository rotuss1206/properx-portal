<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membership payment status received from Stripe (Spec 7). The portal never takes payments.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('member_profiles', function (Blueprint $table) {
            $table->string('stripe_customer_id', 64)->nullable()->unique()->after('certificate_code');
            $table->string('payment_status', 20)->default('unknown')->after('stripe_customer_id');
            // When the status last changed (Stripe event time); starts the past-due grace period.
            $table->timestamp('payment_status_updated_at')->nullable()->after('payment_status');
            // Time of the newest Stripe event applied; older events arriving late are ignored.
            $table->timestamp('payment_event_at')->nullable()->after('payment_status_updated_at');

            $table->index(['payment_status', 'payment_status_updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('member_profiles', function (Blueprint $table) {
            $table->dropIndex(['payment_status', 'payment_status_updated_at']);
            $table->dropUnique(['stripe_customer_id']);
            $table->dropColumn(['stripe_customer_id', 'payment_status', 'payment_status_updated_at', 'payment_event_at']);
        });
    }
};
