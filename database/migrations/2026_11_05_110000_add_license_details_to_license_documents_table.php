<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the member states about the license when uploading a document, kept with the document:
 * the profile is not overwritten. Additive; older documents keep these empty. license_number is
 * encrypted by the model's cast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('license_documents', function (Blueprint $table) {
            $table->text('license_number')->nullable()->after('document_type');
            $table->string('license_state', 2)->nullable()->after('license_number');
            $table->date('license_expires_at')->nullable()->after('license_state');
        });
    }

    public function down(): void
    {
        Schema::table('license_documents', function (Blueprint $table) {
            $table->dropColumn(['license_number', 'license_state', 'license_expires_at']);
        });
    }
};
