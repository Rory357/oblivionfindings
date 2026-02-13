<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // HR policies
        Schema::create('hr_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('title');
            $table->string('slug');
            $table->string('category');
            $table->boolean('is_active')->default(true);
            $table->boolean('requires_attestation')->default(true);
            $table->integer('attestation_frequency_months')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'category', 'is_active']);
        });

        // Policy versions
        Schema::create('hr_policy_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')->constrained('hr_policies')->cascadeOnDelete();
            $table->integer('version_number');
            $table->text('content_summary');
            $table->string('document_path');
            $table->date('effective_from');
            $table->boolean('is_current')->default(true);
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['policy_id', 'is_current']);
        });

        // Policy attestations
        Schema::create('hr_policy_attestations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('policy_id')->constrained('hr_policies')->cascadeOnDelete();
            $table->foreignId('policy_version_id')->constrained('hr_policy_versions')->cascadeOnDelete();
            $table->datetime('attested_at');
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('attestation_method')->default('web');
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'policy_id']);
            $table->index(['tenant_id', 'policy_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_policy_attestations');
        Schema::dropIfExists('hr_policy_versions');
        Schema::dropIfExists('hr_policies');
    }
};
