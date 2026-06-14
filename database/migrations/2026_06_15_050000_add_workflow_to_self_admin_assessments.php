<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-administration is consent-first and multi-party (NICE SC1, NZ MOH
 * Medicines-Management Guide). Record the person's wish, who was involved, the
 * support adjustments and storage arrangement, per-medication scope, the signed
 * self-administration agreement, the reassessment cadence, and the supersede
 * link so a reassessment creates a new record without overwriting the old one.
 * The register is also made soft-deletable (clinical rows are never hard-lost).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medication_self_admin_assessments', function (Blueprint $table) {
            if (! Schema::hasColumn('medication_self_admin_assessments', 'wishes_to_self_administer')) {
                $table->boolean('wishes_to_self_administer')->default(true)->after('outcome');
            }
            if (! Schema::hasColumn('medication_self_admin_assessments', 'people_involved')) {
                $table->json('people_involved')->nullable()->after('wishes_to_self_administer');
            }
            if (! Schema::hasColumn('medication_self_admin_assessments', 'support_adjustments')) {
                $table->json('support_adjustments')->nullable()->after('support_needed');
            }
            if (! Schema::hasColumn('medication_self_admin_assessments', 'storage_location')) {
                $table->string('storage_location', 64)->nullable()->after('safe_storage_notes');
            }
            if (! Schema::hasColumn('medication_self_admin_assessments', 'reassessment_interval_months')) {
                $table->unsignedSmallInteger('reassessment_interval_months')->nullable()->after('reassessment_date');
            }
            if (! Schema::hasColumn('medication_self_admin_assessments', 'med_scope')) {
                $table->json('med_scope')->nullable()->after('reassessment_trigger');
            }
            if (! Schema::hasColumn('medication_self_admin_assessments', 'ordering_responsibility')) {
                $table->string('ordering_responsibility', 64)->nullable()->after('med_scope');
            }
            if (! Schema::hasColumn('medication_self_admin_assessments', 'agreement_responsibilities')) {
                $table->text('agreement_responsibilities')->nullable()->after('ordering_responsibility');
            }
            if (! Schema::hasColumn('medication_self_admin_assessments', 'agreement_signed_at')) {
                $table->timestamp('agreement_signed_at')->nullable()->after('agreement_responsibilities');
            }
            if (! Schema::hasColumn('medication_self_admin_assessments', 'agreement_signed_by')) {
                $table->foreignId('agreement_signed_by')->nullable()->after('agreement_signed_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('medication_self_admin_assessments', 'supersedes_id')) {
                $table->foreignId('supersedes_id')->nullable()->after('agreement_signed_by')->constrained('medication_self_admin_assessments')->nullOnDelete();
            }
            if (! Schema::hasColumn('medication_self_admin_assessments', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        Schema::table('medication_self_admin_assessments', function (Blueprint $table) {
            if (Schema::hasColumn('medication_self_admin_assessments', 'supersedes_id')) {
                $table->dropConstrainedForeignId('supersedes_id');
            }
            if (Schema::hasColumn('medication_self_admin_assessments', 'agreement_signed_by')) {
                $table->dropConstrainedForeignId('agreement_signed_by');
            }
            foreach (['wishes_to_self_administer', 'people_involved', 'support_adjustments', 'storage_location', 'reassessment_interval_months', 'med_scope', 'ordering_responsibility', 'agreement_responsibilities', 'agreement_signed_at'] as $col) {
                if (Schema::hasColumn('medication_self_admin_assessments', $col)) {
                    $table->dropColumn($col);
                }
            }
            if (Schema::hasColumn('medication_self_admin_assessments', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
