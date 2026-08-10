<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_device_credential_lease_grants', function (Blueprint $table): void {
            $table->id();
            $table->uuid('grant_uuid');
            $table->unsignedBigInteger('credential_reference_id');
            $table->unsignedInteger('reference_version');
            $table->unsignedBigInteger('site_id');
            $table->text('lease_id')->nullable();
            $table->char('lease_fingerprint', 64);
            $table->json('capabilities');
            $table->string('status', 24);
            $table->unsignedSmallInteger('revoke_attempts')->default(0);
            $table->string('last_failure_code', 40)->nullable();
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamp('last_revoke_attempt_at')->nullable();
            $table->timestamp('ended_at')->nullable();

            $table->unique('grant_uuid', 'sd_credential_lease_grant_uuid_unique');
            $table->unique('lease_fingerprint', 'sd_credential_lease_grant_fingerprint_unique');
            $table->index(['credential_reference_id', 'status', 'expires_at'], 'sd_credential_lease_grant_ref_state_index');
            $table->index(['status', 'expires_at'], 'sd_credential_lease_grant_expiry_index');
            $table->index(['site_id', 'status'], 'sd_credential_lease_grant_site_state_index');
            $table->foreign('credential_reference_id', 'sd_credential_lease_grant_ref_fk')
                ->references('id')
                ->on('security_device_credential_references')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_device_credential_lease_grants');
    }
};
