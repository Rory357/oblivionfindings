<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_vehicle_appointment_commands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            $table->foreignId('work_order_id')->constrained('fleet_work_orders')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('request_key', 100);
            $table->string('fingerprint', 64);
            $table->json('before_json')->nullable();
            $table->unsignedInteger('resulting_version');
            $table->timestamp('created_at');
            $table->unique(['asset_id', 'request_key'], 'vehicle_appointment_command_identity');
        });
    }

    public function down(): void
    {
        if (DB::table('fleet_vehicle_appointment_commands')->exists()) {
            throw new RuntimeException('Appointment command evidence must be retained.');
        }
        Schema::dropIfExists('fleet_vehicle_appointment_commands');
    }
};
