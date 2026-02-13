<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Compliance requirement definitions
        Schema::create('hr_compliance_requirements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category');
            $table->string('check_type');
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->integer('validity_months')->nullable();
            $table->integer('renewal_reminder_days')->default(60);
            $table->boolean('hard_stop')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'category', 'is_active']);
        });

        // Role-to-requirement mapping matrix
        Schema::create('hr_compliance_matrix', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('requirement_id')->constrained('hr_compliance_requirements')->cascadeOnDelete();
            $table->string('role');
            $table->string('site_type')->nullable();
            $table->boolean('is_mandatory')->default(true);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'requirement_id', 'role', 'site_type'], 'hr_comp_matrix_tenant_req_role_site_unique');
        });

        // Per-staff compliance tracking
        Schema::create('hr_staff_compliance_status', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('requirement_id')->constrained('hr_compliance_requirements')->cascadeOnDelete();
            $table->string('status')->default('not_started');
            $table->string('evidence_type')->nullable();
            $table->unsignedBigInteger('evidence_id')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('expires_at')->nullable();
            $table->text('exemption_reason')->nullable();
            $table->foreignId('exempted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('last_checked_at')->nullable();
            $table->datetime('next_check_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['tenant_id', 'status', 'expires_at']);
            $table->index(['requirement_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_staff_compliance_status');
        Schema::dropIfExists('hr_compliance_matrix');
        Schema::dropIfExists('hr_compliance_requirements');
    }
};
