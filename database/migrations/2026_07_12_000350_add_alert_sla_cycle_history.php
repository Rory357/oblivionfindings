<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('control_room_alert_sla', function (Blueprint $table) {
            $table->unsignedInteger('cycle_number')->default(1);
            $table->timestamp('cycle_started_at')->nullable();
            $table->json('cycle_history')->nullable();
            $table->string('ended_as', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('control_room_alert_sla', function (Blueprint $table) {
            $table->dropColumn([
                'cycle_number',
                'cycle_started_at',
                'cycle_history',
                'ended_as',
            ]);
        });
    }
};
