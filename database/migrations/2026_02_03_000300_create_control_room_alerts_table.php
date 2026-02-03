<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('control_room_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('source'); // fleet|personal_tracker|other
            $table->string('alert_type'); // e.g. vehicle.sos, geofence.breach
            $table->string('severity')->default('medium'); // low|medium|high|critical
            $table->string('status')->default('open'); // open|ack|triaging|resolved|closed
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->foreignId('fleet_signal_id')->nullable()->constrained('fleet_signals')->nullOnDelete();
            $table->dateTime('triggered_at');
            $table->dateTime('acknowledged_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['status', 'severity']);
            $table->index(['source', 'triggered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('control_room_alerts');
    }
};
