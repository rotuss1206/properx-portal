<?php

use App\Support\BlindIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypted casts for designated fields (KEENEY platform rule):
 * - member_profiles: Member ID (new member_number), NPN and certificate code, each with
 *   a blind index (HMAC, BLIND_INDEX_KEY) so they can still be looked up;
 * - license_documents: original file name, MIME type, admin notes;
 * - tax_documents: document type, tax year, original file name.
 * Existing rows are encrypted in place.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('member_profiles', function (Blueprint $table) {
            $table->dropIndex(['npn']);
            $table->dropUnique(['certificate_code']);
        });

        Schema::table('member_profiles', function (Blueprint $table) {
            $table->text('member_number')->nullable()->after('user_id');
            $table->text('npn')->nullable()->change();
            $table->text('certificate_code')->change();
            $table->char('member_number_index', 64)->nullable()->unique()->after('member_number');
            $table->char('npn_index', 64)->nullable()->index()->after('npn');
            $table->char('certificate_code_index', 64)->nullable()->unique()->after('certificate_code');
        });

        Schema::table('license_documents', function (Blueprint $table) {
            $table->text('original_filename')->change();
            $table->text('mime_type')->change();
        });

        Schema::table('tax_documents', function (Blueprint $table) {
            // Keep an index for the user_id foreign key before dropping the composite one.
            $table->index('user_id');
        });

        Schema::table('tax_documents', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'tax_year']);
            $table->dropIndex(['document_type']);
        });

        Schema::table('tax_documents', function (Blueprint $table) {
            $table->text('document_type')->change();
            $table->text('tax_year')->change();
            $table->text('original_filename')->change();
        });

        $this->encryptExistingRows();
    }

    private function encryptExistingRows(): void
    {
        $encrypt = fn ($value) => $value === null ? null : Crypt::encryptString((string) $value);

        DB::table('member_profiles')->orderBy('id')->each(function ($row) use ($encrypt) {
            $memberNumber = sprintf('PPX-M%06d', $row->user_id);

            DB::table('member_profiles')->where('id', $row->id)->update([
                'member_number' => $encrypt($memberNumber),
                'member_number_index' => BlindIndex::for('member_number', $memberNumber),
                'npn' => $encrypt($row->npn),
                'npn_index' => BlindIndex::for('npn', $row->npn),
                'certificate_code' => $encrypt($row->certificate_code),
                'certificate_code_index' => BlindIndex::for('certificate_code', $row->certificate_code),
            ]);
        });

        DB::table('license_documents')->orderBy('id')->each(function ($row) use ($encrypt) {
            DB::table('license_documents')->where('id', $row->id)->update([
                'original_filename' => $encrypt($row->original_filename),
                'mime_type' => $encrypt($row->mime_type),
                'admin_notes' => $encrypt($row->admin_notes),
            ]);
        });

        DB::table('tax_documents')->orderBy('id')->each(function ($row) use ($encrypt) {
            DB::table('tax_documents')->where('id', $row->id)->update([
                'document_type' => $encrypt($row->document_type),
                'tax_year' => $encrypt($row->tax_year),
                'original_filename' => $encrypt($row->original_filename),
            ]);
        });
    }

    /**
     * Reverse the migrations (decrypts the data back).
     */
    public function down(): void
    {
        $decrypt = fn ($value) => $value === null ? null : Crypt::decryptString($value);

        foreach (['member_profiles' => ['npn', 'certificate_code'], 'license_documents' => ['original_filename', 'mime_type', 'admin_notes'], 'tax_documents' => ['document_type', 'tax_year', 'original_filename']] as $table => $columns) {
            DB::table($table)->orderBy('id')->each(function ($row) use ($table, $columns, $decrypt) {
                DB::table($table)->where('id', $row->id)->update(
                    collect($columns)->mapWithKeys(fn ($column) => [$column => $decrypt($row->{$column})])->all()
                );
            });
        }

        Schema::table('member_profiles', function (Blueprint $table) {
            $table->dropUnique(['member_number_index']);
            $table->dropIndex(['npn_index']);
            $table->dropUnique(['certificate_code_index']);
            $table->dropColumn(['member_number', 'member_number_index', 'npn_index', 'certificate_code_index']);
        });

        Schema::table('member_profiles', function (Blueprint $table) {
            $table->string('npn', 20)->nullable()->index()->change();
            $table->string('certificate_code', 32)->unique()->change();
        });

        Schema::table('license_documents', function (Blueprint $table) {
            $table->string('original_filename')->change();
            $table->string('mime_type', 100)->change();
        });

        Schema::table('tax_documents', function (Blueprint $table) {
            $table->string('document_type', 50)->index()->change();
            $table->unsignedSmallInteger('tax_year')->change();
            $table->string('original_filename')->change();
            $table->index(['user_id', 'tax_year']);
        });

        Schema::table('tax_documents', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });
    }
};
