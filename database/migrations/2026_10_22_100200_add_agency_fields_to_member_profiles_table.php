<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agency membership on member profiles, and encryption of the contact details the
 * principal and every member share (phone, address); existing rows are encrypted in place.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('member_profiles', function (Blueprint $table) {
            $table->string('member_type', 20)->default('individual')->index()->after('user_id');
            $table->foreignId('agency_id')->nullable()->after('member_type')->constrained()->nullOnDelete();
            $table->string('agency_role', 20)->nullable()->after('agency_id');
            $table->string('person_type', 20)->nullable()->after('agency_role');
            $table->string('invite_status', 20)->nullable()->index()->after('person_type');
            $table->string('status', 20)->default('active')->index()->after('invite_status');

            $table->text('phone')->nullable()->change();
            $table->text('address')->nullable()->change();
        });

        DB::table('member_profiles')->orderBy('id')->each(function ($row) {
            DB::table('member_profiles')->where('id', $row->id)->update([
                'phone' => $row->phone === null ? null : Crypt::encryptString($row->phone),
                'address' => $row->address === null ? null : Crypt::encryptString($row->address),
            ]);
        });
    }

    /**
     * Reverse the migrations (decrypts the data back).
     */
    public function down(): void
    {
        DB::table('member_profiles')->orderBy('id')->each(function ($row) {
            DB::table('member_profiles')->where('id', $row->id)->update([
                'phone' => $row->phone === null ? null : Crypt::decryptString($row->phone),
                'address' => $row->address === null ? null : Crypt::decryptString($row->address),
            ]);
        });

        Schema::table('member_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agency_id');
            $table->dropIndex(['member_type']);
            $table->dropIndex(['invite_status']);
            $table->dropIndex(['status']);
            $table->dropColumn(['member_type', 'agency_role', 'person_type', 'invite_status', 'status']);

            $table->string('phone', 32)->nullable()->change();
            $table->string('address')->nullable()->change();
        });
    }
};
