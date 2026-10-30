<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a member says an uploaded license document is (portal.member_area.license_document_types:
 * resident, non_resident, other). Existing documents keep null. Expand only.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('license_documents', function (Blueprint $table) {
            $table->string('document_type', 30)->nullable()->after('size');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('license_documents', function (Blueprint $table) {
            $table->dropColumn('document_type');
        });
    }
};
