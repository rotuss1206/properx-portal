<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The date a member's certificate for a term was first issued: printed on every later copy of that
 * term (PDF or PNG) and shown by /verify-certificate, instead of the day of the download.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_profile_id')->constrained()->cascadeOnDelete();
            // The term's "Valid through" date; null when the certificate has none (an agency without a paid period yet).
            $table->date('term_end')->nullable();
            $table->date('issued_on');
            $table->timestamps();

            $table->unique(['member_profile_id', 'term_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_issues');
    }
};
