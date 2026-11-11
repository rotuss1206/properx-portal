<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invitations of agency people (Agency spec 7): one row per emailed link, the token kept only as
 * a SHA-256 hash. A reminder emails a new link of the same invitation (reminder_of_id) that
 * expires with it; a resend revokes every open link. Reminders are deduplicated per invitation in
 * reminder_logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reminder_of_id')->nullable()->constrained('agency_invitations')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('sent_at');
            $table->timestamp('expires_at')->index();
            // The password was set through this link.
            $table->timestamp('used_at')->nullable();
            // Profile checked and consents given (for the principal: the password set).
            $table->timestamp('accepted_at')->nullable();
            $table->json('consents')->nullable();
            $table->timestamp('revoked_at')->nullable();
            // "Ask for a new invitation" from the page of this link.
            $table->timestamp('renewal_requested_at')->nullable();
            $table->timestamps();

            $table->index(['member_profile_id', 'sent_at']);
            $table->index(['used_at', 'revoked_at', 'sent_at']);
        });

        Schema::table('reminder_logs', function (Blueprint $table) {
            $table->foreignId('agency_invitation_id')->nullable()->after('license_expires_at')->constrained()->cascadeOnDelete();

            $table->unique(['agency_invitation_id', 'reminder_type'], 'reminder_logs_unique_per_invitation');
        });
    }

    public function down(): void
    {
        Schema::table('reminder_logs', function (Blueprint $table) {
            $table->dropUnique('reminder_logs_unique_per_invitation');
            $table->dropConstrainedForeignId('agency_invitation_id');
        });

        Schema::dropIfExists('agency_invitations');
    }
};
