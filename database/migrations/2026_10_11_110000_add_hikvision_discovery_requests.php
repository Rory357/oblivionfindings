<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cctv_hikvision_discovery_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            foreach (['actor_id', 'device_id', 'site_id', 'collector_id', 'discovery_scope_id', 'credential_reference_id'] as $column) {
                $table->unsignedBigInteger($column);
            }
            $table->uuid('request_key');
            $table->string('input_hash', 64);
            $table->unsignedInteger('source_revision');
            $table->string('actor_fingerprint', 64);
            $table->string('context_fingerprint', 64);
            $table->string('credential_fingerprint', 64);
            $table->string('purpose', 2000);
            $table->longText('configuration');
            $table->string('status', 24)->default('queued');
            $table->string('reason_code', 40)->nullable();
            $table->uuid('preparation_key')->nullable();
            $table->timestamp('preparation_until')->nullable();
            $table->unsignedBigInteger('credential_lease_grant_id')->nullable();
            $table->longText('operation')->nullable();
            $table->string('operation_hash', 64)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('authority_until')->nullable();
            $table->timestamp('request_expires_at');
            $table->longText('result')->nullable();
            $table->string('result_hash', 64)->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->timestamps();
            $table->unique(['actor_id', 'request_key'], 'cctv_hikvision_actor_request');
            $table->index(['collector_id', 'status', 'id'], 'cctv_hikvision_pending');
            foreach (['actor_id' => 'users', 'device_id' => 'devices', 'site_id' => 'sites', 'collector_id' => 'monitoring_collectors', 'discovery_scope_id' => 'monitoring_discovery_scopes', 'credential_reference_id' => 'security_device_credential_references', 'credential_lease_grant_id' => 'security_device_credential_lease_grants'] as $column => $target) {
                $table->foreign($column, 'cctv_hk_'.$column)->references('id')->on($target)->restrictOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cctv_hikvision_discovery_requests');
    }
};
