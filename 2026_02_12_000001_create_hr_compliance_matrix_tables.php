<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Compliance Requirements Catalog
        // Defines what needs to be checked (e.g., "First Aid Cert", "Police Vetting")
        Schema::create('hr_compliance_requirements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();

            $table->string('code')->unique(); // e.g., 'FIRST_AID_CERT'
            $table->string('name');
            $table->text('description')->nullable();
            
            $table->string('category'); // training, vetting, licence, competency, attestation
            $table->string('check_type'); // credential, training_course, background_check, policy_attestation, manual
            
            $table->unsignedBigInteger('reference_id')->nullable(); // FK to training_course_id, policy_id, etc.
            
            $table->integer('validity_months')->nullable(); // null = lifetime
            $table->integer('renewal_reminder_days')->default(60);
            
            $table->boolean('hard_stop')->default(false); // If true, blocks rostering when expired
            $table->boolean('is_active')->default(true);
            
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'category', 'is_active']);
        });

        // 2. Compliance Matrix (Role mapping)
        // Maps requirements to RBAC roles (e.g., "Support Workers must have First Aid")
        Schema::create('hr_compliance_matrix', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();

            $table->foreignId('requirement_id')->constrained('hr_compliance_requirements')->cascadeOnDelete();
            
            $table->string('role'); // RBAC role name
            $table->string('site_type')->nullable(); // head_office, house, facility, null=all
            
            $table->boolean('is_mandatory')->default(true);
            $table->string('notes')->nullable();
            
            $table->timestamps();

            // Prevent duplicate rules for the same role/site combination
            $table->unique(['tenant_id', 'requirement_id', 'role', 'site_type'], 'hr_matrix_unique');
        });

        // 3. Staff Compliance Status (The calculated state)
        // Stores the current status of every staff member against every requirement they need
        Schema::create('hr_staff_compliance_status', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('requirement_id')->constrained('hr_compliance_requirements')->cascadeOnDelete();
            
            $table->string('status'); // compliant, expiring_soon, expired, not_started, exempt, suspended
            
            // Polymorphic relation to the evidence (TrainingRecord, StaffCredential, etc)
            $table->nullableMorphs('evidence'); 
            
            $table->date('valid_from')->nullable();
            $table->date('expires_at')->nullable();
            
            $table->text('exemption_reason')->nullable();
            $table->foreignId('exempted_by')->nullable()->constrained('users')->nullOnDelete();
            
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['tenant_id', 'status', 'expires_at']);
            $table->index(['requirement_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hr_staff_compliance_status');
        Schema::dropIfExists('hr_compliance_matrix');
        Schema::dropIfExists('hr_compliance_requirements');
    }
};