<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Amount (cents), currency and plan name of a paid invoice (invoice.paid), shown in the
 * member's activity without calling the Stripe API. Not PHI. Expand only.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stripe_events', function (Blueprint $table) {
            $table->unsignedBigInteger('amount')->nullable()->after('outcome');
            $table->char('currency', 3)->nullable()->after('amount');
            $table->string('plan_name', 120)->nullable()->after('currency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stripe_events', function (Blueprint $table) {
            $table->dropColumn(['amount', 'currency', 'plan_name']);
        });
    }
};
