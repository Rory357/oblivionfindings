<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'meal_iddsi_level')) {
                $table->unsignedTinyInteger('meal_iddsi_level')->nullable()->after('fluid_intake_max_ml');
            }
            if (! Schema::hasColumn('clients', 'meal_iddsi_label')) {
                $table->string('meal_iddsi_label')->nullable()->after('meal_iddsi_level');
            }
            if (! Schema::hasColumn('clients', 'meal_fluids_label')) {
                $table->string('meal_fluids_label')->nullable()->after('meal_iddsi_label');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            foreach (['meal_iddsi_level', 'meal_iddsi_label', 'meal_fluids_label'] as $col) {
                if (Schema::hasColumn('clients', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
