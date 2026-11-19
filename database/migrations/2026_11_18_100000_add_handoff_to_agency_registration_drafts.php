<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drafts started on properx.org/apply ("My agency"): a short-lived one-time start link (hash only),
 * and a blind index of the principal's email, so an earlier unfinished draft can be found and its
 * owner emailed a link (never handed to whoever typed the email).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_registration_drafts', function (Blueprint $table) {
            $table->char('start_token_hash', 64)->nullable()->unique()->after('token_hash');
            $table->timestamp('start_token_expires_at')->nullable()->after('start_token_hash');
            $table->char('principal_email_index', 64)->nullable()->index()->after('start_token_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('agency_registration_drafts', function (Blueprint $table) {
            $table->dropUnique(['start_token_hash']);
            $table->dropIndex(['principal_email_index']);
            $table->dropColumn(['start_token_hash', 'start_token_expires_at', 'principal_email_index']);
        });
    }
};
