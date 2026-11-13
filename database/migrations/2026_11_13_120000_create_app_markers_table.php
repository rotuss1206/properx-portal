<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facts the application records about itself once, e.g. when the membership expiry emails were
 * first switched on (App\Models\AppMarker). Not configuration, not personal data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_markers', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->string('value', 255);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_markers');
    }
};
