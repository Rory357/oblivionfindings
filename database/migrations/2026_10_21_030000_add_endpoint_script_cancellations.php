<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Action receipts and transport history only; canonical commands/deliveries
        // remain the execution owners. History never cascades with account deletion.
        Schema::create('endpoint_script_cancel_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_command_request_id');
            $table->foreign('device_command_request_id', 'endpoint_script_cancel_command_fk')->references('id')->on('device_command_requests');
            $table->foreignId('actor_user_id')->constrained('users');
            $table->uuid('request_key');
            $table->char('request_hash', 64);
            $table->text('receipt_json');
            $table->char('receipt_hash', 64);
            $table->timestamp('created_at');
            $table->unique(['actor_user_id', 'request_key'], 'endpoint_script_cancel_request');
        });
        Schema::create('endpoint_script_cancel_leases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('endpoint_delivery_id')->constrained('endpoint_deliveries');
            $table->unsignedInteger('sequence');
            $table->char('payload_hash', 64);
            $table->longText('encrypted_envelope');
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->unique(['endpoint_delivery_id', 'sequence'], 'endpoint_script_cancel_lease');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('endpoint_script_cancel_leases');
        Schema::dropIfExists('endpoint_script_cancel_receipts');
    }
};
