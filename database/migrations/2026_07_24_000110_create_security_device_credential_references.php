<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_device_credential_references', function (Blueprint $table): void {
            $table->id();
            $table->uuid('reference_uuid');
            $table->string('reference_key', 191);
            $table->unsignedBigInteger('site_id');
            $table->string('provider', 64);
            $table->string('purpose', 64);
            $table->json('capabilities');
            $table->text('secret_manager_reference');
            $table->char('secret_manager_reference_hash', 64);
            $table->string('status', 20)->index();
            $table->string('rotation_status', 20)->index();
            $table->string('test_status', 20)->index();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('last_rotated_by_user_id')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamp('last_rotated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique('reference_uuid', 'sd_credential_ref_uuid_unique');
            $table->unique('reference_key', 'sd_credential_ref_key_unique');
            $table->unique('secret_manager_reference_hash', 'sd_credential_ref_secret_hash_unique');
            $table->index(['site_id', 'provider', 'purpose'], 'sd_credential_ref_scope_index');
            $table->foreign('site_id', 'sd_credential_ref_site_fk')->references('id')->on('sites')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'sd_credential_ref_created_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('last_rotated_by_user_id', 'sd_credential_ref_rotated_by_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('security_device_credential_reference_audits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('credential_reference_id');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('action', 40);
            $table->unsignedInteger('version');
            $table->json('safe_context')->nullable();
            $table->timestamp('occurred_at');

            $table->index(['credential_reference_id', 'occurred_at'], 'sd_credential_ref_audit_time_index');
            $table->foreign('credential_reference_id', 'sd_credential_ref_audit_ref_fk')->references('id')->on('security_device_credential_references')->restrictOnDelete();
            $table->foreign('actor_user_id', 'sd_credential_ref_audit_actor_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('security_device_credential_lease_audits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('credential_reference_id')->nullable();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->string('action', 40);
            $table->char('reference_fingerprint', 64);
            $table->char('lease_fingerprint', 64)->nullable();
            $table->json('capabilities')->nullable();
            $table->json('safe_context')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('occurred_at');

            $table->index(['credential_reference_id', 'occurred_at'], 'sd_credential_lease_audit_time_index');
            $table->index(['site_id', 'occurred_at'], 'sd_credential_lease_site_time_index');
            $table->foreign('credential_reference_id', 'sd_credential_lease_audit_ref_fk')->references('id')->on('security_device_credential_references')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_device_credential_lease_audits');
        Schema::dropIfExists('security_device_credential_reference_audits');
        Schema::dropIfExists('security_device_credential_references');
    }
};
