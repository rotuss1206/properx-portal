<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * License copies uploaded for people who do not exist yet: in the agency enrollment (owner: the draft)
 * and in an agency's "Add people" (owner: the agency). The file is already in the documents vault,
 * encrypted; the row becomes the person's License Document once they are created, and is pruned
 * (with its file) when it is never used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_license_files', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('owner_type', 20);
            $table->unsignedBigInteger('owner_id');
            $table->string('stored_filename');
            $table->text('original_filename');
            $table->text('mime_type');
            $table->unsignedInteger('size');
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_license_files');
    }
};
