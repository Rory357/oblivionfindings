<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('endpoint_remote_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->unique()->constrained('devices');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('enabled')->default(false);
            $table->string('consent_mode', 20)->default('attended');
            $table->boolean('allow_control')->default(false);
            $table->unsignedSmallInteger('max_minutes')->default(30);
            $table->foreignId('updated_by_user_id')->constrained('users');
            $table->timestamps();
        });
        Schema::create('endpoint_remote_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('request_uuid')->unique();
            $table->string('request_hash', 64);
            $table->foreignId('device_id')->constrained('devices');
            $table->foreignId('endpoint_agent_id')->constrained('endpoint_agents');
            $table->uuid('agent_uuid');
            $table->foreignId('actor_user_id')->constrained('users');
            $table->string('actor_fingerprint', 64);
            $table->foreignId('it_ticket_id')->nullable()->constrained('it_tickets');
            $table->unsignedInteger('policy_version');
            $table->string('access_mode', 20);
            $table->string('consent_mode', 20);
            $table->string('purpose', 500);
            $table->string('status', 20)->default('requested');
            $table->text('consent_challenge')->nullable();
            $table->uuid('desktop_session')->nullable();
            $table->timestamp('request_expires_at');
            $table->timestamp('session_expires_at');
            $table->timestamp('technician_seen_at');
            $table->timestamp('allowed_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 40)->nullable();
            $table->timestamps();
            $table->index(['device_id', 'status']);
            $table->index(['endpoint_agent_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('endpoint_remote_sessions');
        Schema::dropIfExists('endpoint_remote_policies');
    }
};
