<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The issue date the member states when uploading a license, kept with the document. Additive;
 * older documents keep it empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('license_documents', function (Blueprint $table) {
            $table->date('license_issued_at')->nullable()->after('license_state');
        });
    }

    public function down(): void
    {
        Schema::table('license_documents', function (Blueprint $table) {
            $table->dropColumn('license_issued_at');
        });
    }
};
