<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('endpoint_agents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('device_id')->unique()->constrained('devices');
            $table->foreignId('site_id')->constrained('sites');
            $table->string('assignment_fingerprint', 64);
            $table->string('platform', 20);
            $table->string('enrolment_hash', 64)->nullable()->unique();
            $table->timestamp('enrolment_expires_at')->nullable();
            $table->string('credential_hash', 64)->nullable()->unique();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->string('agent_version', 40)->nullable();
            $table->string('boot_id', 100)->nullable();
            $table->unsignedBigInteger('sequence')->default(0);
            $table->string('heartbeat_hash', 64)->nullable();
            $table->json('inventory')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->timestamps();
        });
        Schema::create('endpoint_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('endpoint_agent_id')->constrained('endpoint_agents');
            $table->foreignId('device_command_attempt_id')->unique()->constrained('device_command_attempts');
            $table->uuid('agent_uuid');
            $table->string('status', 30)->default('queued');
            $table->longText('encrypted_payload');
            $table->string('payload_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('result')->nullable();
            $table->timestamps();
            $table->index(['endpoint_agent_id', 'status']);
        });
        Schema::create('endpoint_intune_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->unique()->constrained('devices');
            $table->uuid('managed_device_id')->unique();
            $table->json('snapshot')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->string('sync_status', 30)->default('pending');
            $table->foreignId('linked_by_user_id')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('endpoint_intune_links');
        Schema::dropIfExists('endpoint_deliveries');
        Schema::dropIfExists('endpoint_agents');
    }
};
