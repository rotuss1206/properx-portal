<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Processed Stripe webhook events; the event id makes redelivered events no-ops.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stripe_events', function (Blueprint $table) {
            $table->string('id', 255)->primary();
            $table->string('type', 100)->index();
            $table->foreignId('member_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('outcome', 30);
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stripe_events');
    }
};
