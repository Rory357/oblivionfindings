<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            if (!Schema::hasColumn('assets', 'has_wheelchair_ramp')) {
                $table->boolean('has_wheelchair_ramp')->default(false)->after('alert_config');
                $table->boolean('has_hoist')->default(false);
                $table->boolean('has_child_seat_anchors')->default(false);
                $table->boolean('has_medical_storage')->default(false);
                $table->integer('seating_capacity')->nullable();
                $table->text('accessibility_notes')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn([
                'has_wheelchair_ramp',
                'has_hoist',
                'has_child_seat_anchors',
                'has_medical_storage',
                'seating_capacity',
                'accessibility_notes',
            ]);
        });
    }
};
