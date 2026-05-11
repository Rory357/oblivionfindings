<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queclink_pending_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('queclink_device_id')->constrained('queclink_devices')->cascadeOnDelete();
            $table->string('imei', 20);
            $table->unsignedBigInteger('tenant_id')->nullable();

            $table->string('command_word', 10);
            $table->text('raw_command');
            $table->char('serial_number', 4);

            $table->enum('status', ['queued', 'sent', 'acked', 'failed', 'expired'])->default('queued');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('acked_at')->nullable();
            $table->text('ack_response')->nullable();
            $table->text('failed_reason')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index(['imei', 'status']);
            $table->index(['queclink_device_id', 'status']);
            $table->index(['status', 'expires_at']);
            $table->unique(['imei', 'serial_number', 'created_at'], 'qpc_imei_serial_created_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queclink_pending_commands');
    }
};
