<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cctv_sources', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('camera_id');
            $table->unsignedBigInteger('recorder_device_id');
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('collector_id');
            $table->unsignedBigInteger('discovery_scope_id');
            $table->unsignedBigInteger('actor_id');
            $table->unsignedInteger('revision');
            $table->boolean('enabled')->default(true);
            $table->string('stream_state', 24)->default('not_requested');
            $table->timestamp('stream_verified_at')->nullable();
            $table->string('fingerprint', 64);
            $table->string('provider_fingerprint', 64);
            $table->longText('configuration');
            $table->timestamp('verified_at');
            $table->timestamps();
            $table->unique(['camera_id', 'revision'], 'cctv_source_revision');
            $table->index(['camera_id', 'enabled'], 'cctv_current_source');
            foreach (['camera_id' => 'devices', 'recorder_device_id' => 'devices', 'site_id' => 'sites', 'collector_id' => 'monitoring_collectors', 'discovery_scope_id' => 'monitoring_discovery_scopes', 'actor_id' => 'users'] as $column => $target) {
                $table->foreign($column, 'cctv_src_'.$column)->references('id')->on($target)->restrictOnDelete();
            }
        });
        Schema::create('cctv_live_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('camera_id');
            $table->unsignedBigInteger('actor_id');
            $table->unsignedBigInteger('collector_id');
            $table->string('actor_fingerprint', 64);
            $table->string('browser_fingerprint', 64);
            $table->uuid('request_key');
            $table->string('request_hash', 64);
            $table->string('purpose', 2000);
            $table->unsignedInteger('generation')->default(1);
            $table->uuid('negotiation_uuid')->nullable();
            $table->string('status', 24)->default('requested');
            $table->string('reason_code', 40)->nullable();
            $table->timestamp('authority_until');
            $table->timestamp('session_expires_at');
            $table->timestamp('ice_expires_at')->nullable();
            $table->longText('material')->nullable();
            $table->longText('worker_grant')->nullable();
            $table->timestamps();
            $table->unique(['actor_id', 'browser_fingerprint', 'request_key'], 'cctv_start_receipt');
            $table->index(['collector_id', 'status'], 'cctv_collector_sessions');
            foreach (['source_id' => 'cctv_sources', 'camera_id' => 'devices', 'actor_id' => 'users', 'collector_id' => 'monitoring_collectors'] as $column => $target) {
                $table->foreign($column, 'cctv_live_'.$column)->references('id')->on($target)->restrictOnDelete();
            }
        });
        Schema::create('cctv_collector_presence', function (Blueprint $table): void {
            $table->unsignedBigInteger('collector_id')->primary();
            $table->boolean('available');
            $table->timestamp('observed_at');
            $table->foreign('collector_id', 'cctv_presence_collector')->references('id')->on('monitoring_collectors')->cascadeOnDelete();
        });
        Schema::create('cctv_request_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('owner', 100);
            $table->uuid('request_key');
            $table->string('input_hash', 64);
            $table->string('context_fingerprint', 64)->nullable();
            $table->longText('response')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['owner', 'request_key'], 'cctv_request_receipt');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cctv_request_receipts');
        Schema::dropIfExists('cctv_collector_presence');
        Schema::dropIfExists('cctv_live_sessions');
        Schema::dropIfExists('cctv_sources');
    }
};
