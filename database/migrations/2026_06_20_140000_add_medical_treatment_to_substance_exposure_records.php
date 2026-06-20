<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capture the degree of harm from a substance exposure (none → first aid →
 * medical → hospitalisation → death) so the observer can classify the event
 * against the WorkSafe NZ notifiable threshold (HSWA 2015 ss.23–25). The legacy
 * `medical_attention_sought` boolean is derived from this. Nullable + additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('substance_exposure_records', function (Blueprint $table) {
            $table->string('medical_treatment')->nullable()->after('medical_attention_sought');
        });
    }

    public function down(): void
    {
        Schema::table('substance_exposure_records', function (Blueprint $table) {
            $table->dropColumn('medical_treatment');
        });
    }
};
