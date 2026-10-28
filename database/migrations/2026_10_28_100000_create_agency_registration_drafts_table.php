<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unfinished agency enrollments (/join/agency). The answers are one encrypted JSON
 * document; the session holds the draft id, and the token (stored as a SHA-256 hash)
 * is for the "continue later" link. Expired drafts are pruned daily.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('agency_registration_drafts', function (Blueprint $table) {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->text('data');
            $table->unsignedTinyInteger('step')->default(1);
            $table->unsignedTinyInteger('furthest_step')->default(1);
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agency_registration_drafts');
    }
};
