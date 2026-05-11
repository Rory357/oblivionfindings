<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queclink_devices', function (Blueprint $table) {
            $table->id();
            $table->string('imei', 20)->unique();
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();

            $table->string('model_hint', 50)->nullable();
            $table->string('protocol_version', 10)->nullable();
            $table->string('firmware_version', 50)->nullable();

            $table->enum('status', ['pending', 'paired', 'rejected'])->default('pending');
            $table->enum('pending_pairing_type', ['vehicle', 'staff', 'client'])->nullable();

            $table->enum('connection_state', ['connected', 'disconnected'])->default('disconnected');
            $table->string('current_session_id', 64)->nullable();
            $table->string('remote_address', 64)->nullable();

            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_frame_at')->nullable();
            $table->string('last_count_number', 4)->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'tenant_id']);
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queclink_devices');
    }
};
