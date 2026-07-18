<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('it_service_identities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('public_id', 24)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->char('token_hash', 64);
            $table->json('abilities');
            $table->json('allowed_work_types');
            $table->json('allowed_site_ids');
            $table->json('allowed_fields');
            $table->boolean('require_signature')->default(false);
            $table->unsignedSmallInteger('rate_limit_per_minute')->default(60);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'revoked_at', 'expires_at'], 'it_service_identities_active_idx');
        });

        Schema::create('it_api_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('service_identity_id')->constrained('it_service_identities')->restrictOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained('it_tickets')->nullOnDelete();
            $table->string('method', 10);
            $table->string('path', 500);
            $table->string('idempotency_key', 100)->nullable();
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['service_identity_id', 'idempotency_key'],
                'it_api_requests_identity_idempotency_uq',
            );
            $table->index(
                ['tenant_id', 'service_identity_id', 'created_at'],
                'it_api_requests_tenant_identity_created_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('it_api_requests');
        Schema::dropIfExists('it_service_identities');
    }
};
