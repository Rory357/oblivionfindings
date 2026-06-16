<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Controlled-drug schedule classification (CD Schedule 2 / 3 / 4) for at-a-glance
 * risk on the register. Nullable — set when recording a CD movement; existing
 * rows stay null until classified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_medications', function (Blueprint $table) {
            $table->unsignedTinyInteger('cd_schedule')->nullable()->after('controlled_drug');
        });
    }

    public function down(): void
    {
        Schema::table('client_medications', function (Blueprint $table) {
            $table->dropColumn('cd_schedule');
        });
    }
};
