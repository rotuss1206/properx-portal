<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removing a person from an agency (Agency spec 8.1, G4): effective at the agency's next renewal
 * (the person keeps access until then), with the principal's reason from a fixed list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_profiles', function (Blueprint $table) {
            $table->timestamp('removal_effective_at')->nullable()->after('status')->index();
            $table->string('removal_reason', 40)->nullable()->after('removal_effective_at');
            $table->timestamp('removal_scheduled_at')->nullable()->after('removal_reason');
        });
    }

    public function down(): void
    {
        Schema::table('member_profiles', function (Blueprint $table) {
            $table->dropIndex(['removal_effective_at']);
            $table->dropColumn(['removal_effective_at', 'removal_reason', 'removal_scheduled_at']);
        });
    }
};
