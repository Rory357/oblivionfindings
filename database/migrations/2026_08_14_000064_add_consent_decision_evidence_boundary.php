<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consent_requests', function (Blueprint $table): void {
            $table->string('capacity_outcome', 32)->nullable()->after('authority_next_of_kin_id');
            $table->foreignId('capacity_assessor_user_id')->nullable()->after('capacity_outcome')->constrained('users')->restrictOnDelete();
            $table->timestamp('capacity_assessed_at')->nullable()->after('capacity_assessor_user_id');
            $table->timestamp('capacity_assessment_expires_at')->nullable()->after('capacity_assessed_at');
            $table->text('capacity_assessment_reason')->nullable()->after('capacity_assessment_expires_at');
            $table->string('capacity_evidence_type', 80)->nullable()->after('capacity_assessment_reason');
            $table->string('capacity_evidence_reference', 255)->nullable()->after('capacity_evidence_type');
            $table->text('best_interests_process_reason')->nullable()->after('capacity_evidence_reference');
            $table->string('best_interests_evidence_type', 80)->nullable()->after('best_interests_process_reason');
            $table->string('best_interests_evidence_reference', 255)->nullable()->after('best_interests_evidence_type');
            $table->json('best_interests_consultees')->nullable()->after('best_interests_evidence_reference');
            $table->foreignId('decision_evidence_recorded_by_user_id')->nullable()->after('best_interests_consultees')->constrained('users')->restrictOnDelete();
            $table->timestamp('decision_evidence_recorded_at')->nullable()->after('decision_evidence_recorded_by_user_id');
            $table->char('decision_scope_digest', 64)->nullable()->after('decision_evidence_recorded_at');
            $table->foreignId('decision_evidence_accepted_by_user_id')->nullable()->after('decision_scope_digest')->constrained('users')->restrictOnDelete();
            $table->timestamp('decision_evidence_accepted_at')->nullable()->after('decision_evidence_accepted_by_user_id');
            $table->foreignId('decision_evidence_revoked_by_user_id')->nullable()->after('decision_evidence_accepted_at')->constrained('users')->restrictOnDelete();
            $table->timestamp('decision_evidence_revoked_at')->nullable()->after('decision_evidence_revoked_by_user_id');
            $table->text('decision_evidence_revocation_reason')->nullable()->after('decision_evidence_revoked_at');

            $table->index(['client_id', 'decision_scope_digest'], 'consent_req_decision_scope_idx');
            $table->unique('decision_scope_digest', 'consent_req_decision_digest_uq');
            $table->index(['status', 'decision_evidence_revoked_at'], 'consent_req_evidence_state_idx');
        });

        Schema::table('client_consents', function (Blueprint $table): void {
            $table->foreignId('consent_request_id')->nullable()->after('consent_type_version_id')->constrained('consent_requests')->restrictOnDelete();
            $table->char('decision_evidence_digest', 64)->nullable()->after('consent_request_id');

            $table->unique('consent_request_id', 'client_consents_request_uq');
            $table->index('decision_evidence_digest', 'client_consents_evidence_digest_idx');
        });
    }

    public function down(): void
    {
        $hasDecisionEvidence = Schema::hasColumn('consent_requests', 'decision_scope_digest')
            && DB::table('consent_requests')->whereNotNull('decision_scope_digest')->exists();
        $hasMaterialisedEvidence = Schema::hasColumn('client_consents', 'consent_request_id')
            && DB::table('client_consents')->whereNotNull('consent_request_id')->exists();

        if ($hasDecisionEvidence || $hasMaterialisedEvidence) {
            throw new RuntimeException(
                'Cannot roll back the consent decision evidence boundary while recorded or accepted evidence exists.',
            );
        }

        Schema::table('client_consents', function (Blueprint $table): void {
            $table->dropUnique('client_consents_request_uq');
            $table->dropIndex('client_consents_evidence_digest_idx');
            $table->dropConstrainedForeignId('consent_request_id');
            $table->dropColumn('decision_evidence_digest');
        });

        Schema::table('consent_requests', function (Blueprint $table): void {
            $table->dropIndex('consent_req_decision_scope_idx');
            $table->dropUnique('consent_req_decision_digest_uq');
            $table->dropIndex('consent_req_evidence_state_idx');
            $table->dropConstrainedForeignId('capacity_assessor_user_id');
            $table->dropConstrainedForeignId('decision_evidence_recorded_by_user_id');
            $table->dropConstrainedForeignId('decision_evidence_accepted_by_user_id');
            $table->dropConstrainedForeignId('decision_evidence_revoked_by_user_id');
            $table->dropColumn([
                'capacity_outcome',
                'capacity_assessed_at',
                'capacity_assessment_expires_at',
                'capacity_assessment_reason',
                'capacity_evidence_type',
                'capacity_evidence_reference',
                'best_interests_process_reason',
                'best_interests_evidence_type',
                'best_interests_evidence_reference',
                'best_interests_consultees',
                'decision_evidence_recorded_at',
                'decision_scope_digest',
                'decision_evidence_accepted_at',
                'decision_evidence_revoked_at',
                'decision_evidence_revocation_reason',
            ]);
        });
    }
};
