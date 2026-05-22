<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'fluid_intake_min_ml')) {
                $table->unsignedInteger('fluid_intake_min_ml')->nullable();
            }
            if (! Schema::hasColumn('clients', 'fluid_intake_max_ml')) {
                $table->unsignedInteger('fluid_intake_max_ml')->nullable();
            }
            if (! Schema::hasColumn('clients', 'seizure_duration_escalation_seconds')) {
                $table->unsignedInteger('seizure_duration_escalation_seconds')
                    ->nullable()
                    ->comment('Per-client override; falls back to 300s (5 min) global default.');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $cols = [];
            foreach ([
                'fluid_intake_min_ml',
                'fluid_intake_max_ml',
                'seizure_duration_escalation_seconds',
            ] as $column) {
                if (Schema::hasColumn('clients', $column)) {
                    $cols[] = $column;
                }
            }
            if ($cols) {
                $table->dropColumn($cols);
            }
        });
    }
};
