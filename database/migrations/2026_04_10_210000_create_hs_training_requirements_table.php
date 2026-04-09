<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hs_training_requirements', function (Blueprint $table) {
            $table->id();

            // ── Tenant isolation ──
            $table->unsignedBigInteger('organization_id')->nullable()->index();

            // ── Identity ──
            $table->string('name');
            $table->string('code', 40)->unique();

            // ── What HR compliance requirement this maps to ──
            // Links to HrComplianceRequirement.id — the existing HR-owned record.
            // H&S does NOT own the training; it declares that it is required.
            $table->unsignedBigInteger('hr_compliance_requirement_id')->nullable();

            // ── Scope: who does this apply to? ──
            $table->string('scope_type', 20)->default('global');
            // Values: global, role, site, client
            $table->json('scope_roles')->nullable();      // role names, e.g. ["support_worker", "team_leader"]
            $table->json('scope_site_ids')->nullable();    // specific site IDs
            $table->json('scope_client_ids')->nullable();  // specific client IDs

            // ── Enforcement ──
            $table->string('enforcement_mode', 20)->default('warn');
            // Values: warn, block
            // warn = warning in eligibility check (manager can override)
            // block = hard block (still uses ShiftEligibilityOverride if needed)

            // ── Validity ──
            $table->unsignedSmallInteger('validity_months')->nullable();
            // If set, enrollment must be within this window. If null, defer to HR requirement validity.

            $table->unsignedSmallInteger('grace_period_days')->default(30);
            // Buffer after expiry before enforcement kicks in.

            // ── Metadata ──
            $table->string('regulatory_reference', 100)->nullable();
            $table->text('rationale')->nullable();
            $table->boolean('is_active')->default(true);

            // ── Provenance ──
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // ── Indexes ──
            $table->index(['scope_type', 'is_active']);
            $table->index(['hr_compliance_requirement_id']);

            // ── Foreign keys ──
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hs_training_requirements');
    }
};
