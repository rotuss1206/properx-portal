<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * End of the paid membership period, from Stripe (current_period_end of the subscription,
 * period end of a paid invoice). Drives the "Renewal due" card in the Nova sidebar.
 * Expand only: agencies get the column now, the webhook fills it once agency customers
 * are matched (Stripe work for agencies).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('member_profiles', function (Blueprint $table) {
            $table->timestamp('paid_through_at')->nullable()->after('payment_event_at');
            $table->index('paid_through_at');
        });

        Schema::table('agencies', function (Blueprint $table) {
            $table->timestamp('paid_through_at')->nullable()->after('stripe_subscription_id');
            $table->index('paid_through_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropIndex(['paid_through_at']);
            $table->dropColumn('paid_through_at');
        });

        Schema::table('member_profiles', function (Blueprint $table) {
            $table->dropIndex(['paid_through_at']);
            $table->dropColumn('paid_through_at');
        });
    }
};
