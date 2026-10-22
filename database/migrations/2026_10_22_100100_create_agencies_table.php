<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agencies enroll two or more people on one annual invoice. Identifiers and contact
 * details are encrypted at rest (text columns); the agency NPN and FEIN keep blind
 * indexes (HMAC, BLIND_INDEX_KEY) so duplicates can be found.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('agencies', function (Blueprint $table) {
            $table->id();
            $table->string('legal_name')->index();
            $table->string('dba')->nullable();
            $table->string('agency_type', 20);
            $table->text('agency_npn')->nullable();
            $table->char('agency_npn_index', 64)->nullable()->unique();
            $table->text('fein')->nullable();
            $table->char('fein_index', 64)->nullable()->unique();
            $table->text('legal_address');
            $table->text('mailing_address')->nullable();
            $table->boolean('mailing_same_as_legal')->default(true);
            $table->text('phone');
            $table->string('website')->nullable();
            $table->text('billing_email');
            $table->string('referral_source', 30)->nullable();
            $table->string('tier', 20)->index();
            $table->string('rate_type', 20)->index();
            $table->foreignId('partner_code_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('headcount')->default(0)->index();
            // One agency per principal.
            $table->foreignId('principal_user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->string('stripe_customer_id')->nullable()->unique();
            $table->string('stripe_subscription_id')->nullable()->unique();
            $table->string('status', 20)->default('pending')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agencies');
    }
};
