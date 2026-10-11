<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('member_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('phone', 32)->nullable();
            $table->string('address')->nullable();
            $table->string('firm_name')->nullable();
            $table->string('npn', 20)->nullable()->index();
            $table->string('license_number', 50)->nullable()->index();
            $table->char('license_state', 2)->nullable();
            $table->string('license_type', 100)->nullable();
            $table->date('license_expires_at')->nullable()->index();
            $table->string('license_status', 20)->default('active');
            $table->string('certificate_code', 32)->unique();
            $table->timestamps();

            $table->index(['license_status', 'license_expires_at']);
            $table->index(['last_name', 'first_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('member_profiles');
    }
};
