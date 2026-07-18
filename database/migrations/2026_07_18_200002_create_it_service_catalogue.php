<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('it_catalog_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('it_service_id')->nullable()->constrained('it_services')->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('outcome_type')->default('service_request');
            $table->string('category')->default('other');
            $table->string('provisioning_type')->nullable();
            $table->string('default_priority')->default('normal');
            $table->boolean('requires_approval')->default(false);
            $table->boolean('is_published')->default(true);
            $table->boolean('internal_only')->default(false);
            $table->unsignedInteger('form_schema_version')->default(1);
            $table->json('form_schema');
            $table->json('search_terms')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'slug'], 'it_catalog_items_tenant_slug_uq');
            $table->index(['tenant_id', 'is_published', 'sort_order'], 'it_catalog_items_discovery_idx');
        });

        Schema::create('it_catalog_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('catalog_item_id')->constrained('it_catalog_items')->restrictOnDelete();
            $table->foreignId('requester_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('schema_version');
            $table->json('schema_snapshot');
            $table->json('submitted_values');
            $table->string('idempotency_key', 100);
            $table->string('result_type');
            $table->unsignedBigInteger('result_id');
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'requester_user_id', 'idempotency_key'],
                'it_catalog_submissions_idempotency_uq',
            );
            $table->index(['result_type', 'result_id'], 'it_catalog_submissions_result_idx');
            $table->index(['tenant_id', 'catalog_item_id', 'submitted_at'], 'it_catalog_submissions_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('it_catalog_submissions');
        Schema::dropIfExists('it_catalog_items');
    }
};
