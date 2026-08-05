<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_tenant_secrets', function (Blueprint $table): void {
            $table->text('secret_encrypted')->nullable()->change();
        });
        Schema::table('integration_site_secrets', function (Blueprint $table): void {
            $table->text('secret_encrypted')->nullable()->change();
        });

        Schema::create('integration_secret_references', function (Blueprint $table): void {
            $table->id();
            $table->uuid('reference_uuid')->unique();
            $table->foreignId('provider_connection_id')->nullable()
                ->constrained('integration_tenant_secrets', indexName: 'isr_provider_connection_fk')
                ->restrictOnDelete();
            $table->foreignId('site_secret_id')->nullable()
                ->constrained('integration_site_secrets', indexName: 'isr_site_secret_fk')
                ->restrictOnDelete();
            $table->string('provider', 64);
            $table->string('purpose', 64);
            $table->text('secret_manager_reference');
            $table->char('secret_manager_reference_hash', 64);
            $table->unsignedInteger('secret_manager_version');
            $table->char('secret_manager_fingerprint', 64);
            $table->string('status', 32)->default('active');
            $table->timestamp('cutover_at');
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('cleanup_pending_at')->nullable();
            $table->timestamp('cleanup_last_attempt_at')->nullable();
            $table->unsignedInteger('cleanup_attempts')->default(0);
            $table->timestamps();

            $table->unique(['provider_connection_id', 'purpose'], 'isr_provider_purpose_unique');
            $table->unique(['site_secret_id', 'purpose'], 'isr_site_purpose_unique');
            $table->index(['provider', 'purpose', 'status'], 'isr_provider_purpose_status_index');
            $table->index(['cleanup_pending_at', 'id'], 'isr_cleanup_pending_index');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('integration_secret_references')
            && DB::table('integration_secret_references')->whereNotNull('cleanup_pending_at')->exists()) {
            throw new RuntimeException(
                'Provider secret references cannot be rolled back while external cleanup is pending. Complete the governed cleanup retry before retrying.',
            );
        }

        if (DB::table('integration_tenant_secrets')->whereNull('secret_encrypted')->exists()
            || DB::table('integration_site_secrets')->whereNull('secret_encrypted')->exists()) {
            throw new RuntimeException(
                'Provider secret references cannot be rolled back after legacy credentials have been finalized. Restore every legacy credential before retrying.',
            );
        }

        Schema::dropIfExists('integration_secret_references');

        Schema::table('integration_tenant_secrets', function (Blueprint $table): void {
            $table->text('secret_encrypted')->nullable(false)->change();
        });
        Schema::table('integration_site_secrets', function (Blueprint $table): void {
            $table->text('secret_encrypted')->nullable(false)->change();
        });
    }
};
