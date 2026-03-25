<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('name');
            $table->string('target_url', 1500);
            $table->text('signing_secret')->nullable()->comment('encrypted');
            $table->json('event_types');
            $table->json('headers')->nullable();
            $table->unsignedTinyInteger('timeout_seconds')->default(10);
            $table->unsignedTinyInteger('retry_limit')->default(3);
            $table->boolean('is_active')->default(true);
            $table->dateTime('last_delivery_at')->nullable();
            $table->string('last_status')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active'], 'hr_webhook_endpoint_tenant_active_idx');
            $table->unique(['tenant_id', 'name'], 'hr_webhook_endpoint_tenant_name_unique');
        });

        Schema::create('hr_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('endpoint_id')->constrained('hr_webhook_endpoints')->cascadeOnDelete();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('event_type');
            $table->uuid('event_uuid');
            $table->json('payload');
            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(3);
            $table->dateTime('queued_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->text('response_body')->nullable();
            $table->text('error_message')->nullable();
            $table->string('idempotency_key')->unique();
            $table->timestamps();

            $table->index(['tenant_id', 'event_type'], 'hr_webhook_delivery_tenant_event_idx');
            $table->index(['endpoint_id', 'status'], 'hr_webhook_delivery_endpoint_status_idx');
            $table->index(['event_uuid'], 'hr_webhook_delivery_event_uuid_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_webhook_deliveries');
        Schema::dropIfExists('hr_webhook_endpoints');
    }
};
