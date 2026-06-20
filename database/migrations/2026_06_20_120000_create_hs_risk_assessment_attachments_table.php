<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Risk Assessments redesign — supporting evidence attached to a risk assessment
 * (SWMS, method statements, hazard photos, SDS/COSHH sheets, plans, PDFs). Mirrors
 * `emergency_drill_attachments` / `fleet_incident_attachments` / `safeguarding_attachments`;
 * carries a per-file note + alt text (a11y) + a kind tag. Premium upload via the
 * shared AttachmentUploader.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hs_risk_assessment_attachments') || ! Schema::hasTable('hs_risk_assessments')) {
            return;
        }

        Schema::create('hs_risk_assessment_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hs_risk_assessment_id')->constrained('hs_risk_assessments')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk')->default('public');
            $table->string('original_name');
            $table->string('path');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('kind', 30)->nullable(); // swms|method_statement|photo|sds|plan|document
            $table->text('notes')->nullable();
            $table->string('alt_text')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('hs_risk_assessment_id', 'hraa_risk_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hs_risk_assessment_attachments');
    }
};
