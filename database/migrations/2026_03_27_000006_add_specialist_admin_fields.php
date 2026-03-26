<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_medication_administrations', function (Blueprint $table) {
            // Insulin fields
            $table->decimal('blood_glucose_level', 5, 1)->nullable()->after('notes');
            $table->decimal('insulin_units_given', 5, 1)->nullable()->after('blood_glucose_level');
            $table->string('injection_site', 100)->nullable()->after('insulin_units_given');

            // Inhaler fields
            $table->boolean('inhaler_technique_observed')->nullable()->after('injection_site');
            $table->boolean('spacer_used')->nullable()->after('inhaler_technique_observed');
            $table->integer('peak_flow_before')->nullable()->after('spacer_used');
            $table->integer('peak_flow_after')->nullable()->after('peak_flow_before');

            // Topical fields
            $table->string('topical_area', 255)->nullable()->after('peak_flow_after');
            $table->string('topical_skin_condition', 255)->nullable()->after('topical_area');
        });
    }

    public function down(): void
    {
        Schema::table('client_medication_administrations', function (Blueprint $table) {
            $table->dropColumn([
                'blood_glucose_level',
                'insulin_units_given',
                'injection_site',
                'inhaler_technique_observed',
                'spacer_used',
                'peak_flow_before',
                'peak_flow_after',
                'topical_area',
                'topical_skin_condition',
            ]);
        });
    }
};
