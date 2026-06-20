<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Emergency Drills redesign — capture the fields the gold-standard register/wizards
 * surface but the original schema lacked:
 *  - is_unannounced: FENZ-style unannounced drills (Schedule wizard checkbox).
 *  - assembly_point / evacuation_scheme: real values behind the detail "Overview"
 *    card (were hard-coded demo text in the design mockup).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('emergency_drills')) {
            return;
        }

        Schema::table('emergency_drills', function (Blueprint $table) {
            if (! Schema::hasColumn('emergency_drills', 'is_unannounced')) {
                $table->boolean('is_unannounced')->default(false)->after('scenario_description');
            }
            if (! Schema::hasColumn('emergency_drills', 'assembly_point')) {
                $table->string('assembly_point')->nullable()->after('is_unannounced');
            }
            if (! Schema::hasColumn('emergency_drills', 'evacuation_scheme')) {
                $table->string('evacuation_scheme')->nullable()->after('assembly_point');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('emergency_drills')) {
            return;
        }

        Schema::table('emergency_drills', function (Blueprint $table) {
            foreach (['is_unannounced', 'assembly_point', 'evacuation_scheme'] as $col) {
                if (Schema::hasColumn('emergency_drills', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
