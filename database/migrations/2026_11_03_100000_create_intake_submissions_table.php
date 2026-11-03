<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paid applications received from the marketing site (POST /api/intake/individual): one row per
 * source + entry, so a repeated delivery gets the stored answer. No member data, ids and
 * outcomes only. The subscription id sits next to the customer id for matching Stripe events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('source', 40);
            $table->string('entry_id', 32);
            $table->foreignId('member_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('outcome', 20)->index();
            $table->json('result')->nullable();
            $table->timestamps();

            $table->unique(['source', 'entry_id']);
        });

        Schema::table('member_profiles', function (Blueprint $table) {
            $table->string('stripe_subscription_id', 64)->nullable()->after('stripe_customer_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('member_profiles', function (Blueprint $table) {
            $table->dropIndex(['stripe_subscription_id']);
            $table->dropColumn('stripe_subscription_id');
        });

        Schema::dropIfExists('intake_submissions');
    }
};
