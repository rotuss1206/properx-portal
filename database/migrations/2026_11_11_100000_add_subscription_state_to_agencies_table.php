<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Stripe says about an agency's subscription (Agency spec 6): when the last payment event
 * happened (an older event never overwrites a newer one) and when the last payment did (a failure
 * reported after a later payment changes nothing), how many people the subscription bills,
 * whether it ends at the period's end, and where the principal pays an overdue invoice (Stripe's
 * hosted invoice page, encrypted: the link opens the invoice).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->timestamp('payment_event_at')->nullable()->after('paid_through_at');
            $table->timestamp('paid_event_at')->nullable()->after('payment_event_at');
            $table->unsignedInteger('billed_headcount')->nullable()->after('headcount');
            $table->boolean('cancel_at_period_end')->default(false)->after('payment_event_at');
            $table->text('payment_url')->nullable()->after('cancel_at_period_end');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn(['payment_event_at', 'paid_event_at', 'billed_headcount', 'cancel_at_period_end', 'payment_url']);
        });
    }
};
