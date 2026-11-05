<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the member reads when a license document is rejected, apart from staff's internal
 * admin_notes. Encrypted by the model's cast. Old rejections stay without a reason: admin_notes
 * may hold internal remarks, so they are not copied over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('license_documents', function (Blueprint $table) {
            $table->text('rejection_reason')->nullable()->after('admin_notes');
        });
    }

    public function down(): void
    {
        Schema::table('license_documents', function (Blueprint $table) {
            $table->dropColumn('rejection_reason');
        });
    }
};
