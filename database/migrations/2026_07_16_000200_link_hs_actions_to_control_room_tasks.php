<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hs_corrective_actions', function (Blueprint $table) {
            $table->foreignId('source_control_room_task_id')
                ->nullable()
                ->after('hs_investigation_id')
                ->unique()
                ->constrained('control_room_alert_tasks')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hs_corrective_actions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_control_room_task_id');
        });
    }
};
