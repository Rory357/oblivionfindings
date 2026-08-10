<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_collectors', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->uuid('collector_uuid');
            $table->string('name');
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->timestamp('last_seen_at')->nullable();
            $table->json('config')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'collector_uuid'], 'monitoring_collectors_tenant_uuid_uq');
            $table->index(['tenant_id', 'status'], 'monitoring_collectors_tenant_status_idx');
        });

        Schema::create('monitoring_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('interval_seconds')->default(60);
            $table->unsignedSmallInteger('failure_confirmations')->default(3);
            $table->unsignedSmallInteger('recovery_confirmations')->default(2);
            $table->unsignedInteger('stale_after_seconds')->default(300);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name'], 'monitoring_profiles_tenant_name_uq');
        });

        Schema::create('monitors', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('profile_id')->constrained('monitoring_profiles')->restrictOnDelete();
            $table->foreignId('collector_id')->nullable()->constrained('monitoring_collectors')->nullOnDelete();
            $table->string('kind');
            $table->string('name');
            $table->string('target');
            $table->json('config')->nullable();
            $table->string('current_state')->default('unknown');
            $table->string('pending_state')->nullable();
            $table->unsignedSmallInteger('pending_count')->default(0);
            $table->boolean('affects_availability')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_observation_at')->nullable();
            $table->timestamp('last_state_changed_at')->nullable();
            $table->timestamp('suppressed_until')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'current_state'], 'monitors_tenant_state_idx');
            $table->index(['device_id', 'is_enabled'], 'monitors_device_enabled_idx');
        });

        Schema::create('monitor_observations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('monitor_id')->constrained('monitors')->cascadeOnDelete();
            $table->string('source_key');
            $table->string('state');
            $table->decimal('value', 20, 6)->nullable();
            $table->string('unit')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->text('message')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamp('observed_at');
            $table->timestamp('ingested_at');
            $table->timestamps();

            $table->unique(['monitor_id', 'source_key'], 'monitor_observations_monitor_source_uq');
            $table->index(['monitor_id', 'observed_at'], 'monitor_observations_monitor_observed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_observations');
        Schema::dropIfExists('monitors');
        Schema::dropIfExists('monitoring_profiles');
        Schema::dropIfExists('monitoring_collectors');
    }
};
