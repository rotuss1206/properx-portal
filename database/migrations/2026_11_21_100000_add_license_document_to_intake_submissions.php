<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The license copy sent with a paid application from properx.org (plugin 2.3.0) becomes one License
 * Document; the submission keeps which, so a repeated delivery stores nothing twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intake_submissions', function (Blueprint $table) {
            $table->foreignId('license_document_id')->nullable()->after('member_profile_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('intake_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('license_document_id');
        });
    }
};
