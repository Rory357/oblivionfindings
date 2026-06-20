<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clinical risk assessments — the net-new Assessments & Risk register. Stores a
 * completed standardised assessment (FRAT / Braden / MUST / IDDSI): the
 * clinician's structured inputs, the transparent computed total + risk band
 * (null for the IDDSI level classification), the component breakdown, and the
 * cited tool version. `organization_id` (set from the client) lets the register
 * scope by tenant directly; evidence attaches via the polymorphic
 * `clinical_attachments` morph (no new table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_risk_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->index();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('assessment_type');          // ClinicalAssessmentType value
            $table->timestamp('assessed_at');

            $table->json('inputs');                      // the clinician's structured answers
            $table->unsignedSmallInteger('total_score')->nullable(); // null for IDDSI
            $table->string('risk_band')->nullable();     // ClinicalRiskBand value; null for IDDSI
            $table->json('breakdown');                   // per-component contributions (transparency)
            $table->string('summary');
            $table->text('advice')->nullable();
            $table->json('meta')->nullable();
            $table->string('tool_version');

            $table->text('notes')->nullable();
            $table->date('review_due_at')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['client_id', 'assessment_type', 'assessed_at'], 'clin_ra_client_type_assessed');
            $table->index(['organization_id', 'risk_band'], 'clin_ra_org_band');
            $table->index('review_due_at', 'clin_ra_review_due');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_risk_assessments');
    }
};
