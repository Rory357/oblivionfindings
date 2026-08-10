<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_command_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('batch_uuid')->unique();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('workspace', 40);
            $table->string('capability', 120);
            $table->unsignedSmallInteger('capability_version')->default(1);
            $table->string('risk', 20);
            $table->string('confirmation_mode', 40);
            $table->text('reason');
            $table->json('safe_parameter_summary')->nullable();
            $table->string('idempotency_key', 128);
            $table->string('contract_hash', 64);
            $table->unsignedSmallInteger('target_count');
            $table->unsignedSmallInteger('included_count');
            $table->unsignedSmallInteger('excluded_count');
            $table->unsignedSmallInteger('site_count');
            $table->timestamp('impact_acknowledged_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['requested_by_user_id', 'capability', 'idempotency_key'],
                'device_command_batches_idempotency_unique',
            );
            $table->index(['requested_by_user_id', 'created_at'], 'device_command_batches_requester_created_index');
        });

        Schema::create('device_command_batch_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_command_batch_id')->constrained('device_command_batches')->restrictOnDelete();
            $table->foreignId('device_id')->constrained('devices')->restrictOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->restrictOnDelete();
            $table->foreignId('device_command_request_id')->nullable()->constrained('device_command_requests')->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('inclusion_status', 20);
            $table->string('safe_exclusion_code', 80)->nullable();
            $table->text('safe_exclusion_reason')->nullable();
            $table->timestamp('created_at');

            $table->unique(['device_command_batch_id', 'device_id'], 'device_command_batch_targets_device_unique');
            $table->unique('device_command_request_id', 'device_command_batch_targets_request_unique');
            $table->index(['device_command_batch_id', 'position'], 'device_command_batch_targets_position_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_command_batch_targets');
        Schema::dropIfExists('device_command_batches');
    }
};
