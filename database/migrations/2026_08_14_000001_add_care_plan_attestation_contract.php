<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('care_plans', function (Blueprint $table): void {
            $table->json('attestation_policy')->nullable()->after('content');
        });

        DB::table('care_plans')
            ->whereNull('attestation_policy')
            ->update([
                'attestation_policy' => json_encode(
                    [
                        'version' => 1,
                        'requirement' => 'eligible_attestation',
                        'satisfying_states' => [
                            'direct_authenticated',
                            'witnessed',
                            'authorised_representative',
                        ],
                        'governance_review_required' => true,
                    ],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ),
            ]);

        Schema::table('care_plan_sign_offs', function (Blueprint $table): void {
            $table->string('attestation_state', 48)->nullable()->after('care_plan_id');
            $table->string('signer_type', 48)->nullable()->after('attestation_state');
            $table->foreignId('signer_user_id')->nullable()->after('signer_type')->constrained('users')->restrictOnDelete();
            $table->foreignId('signer_client_id')->nullable()->after('signer_user_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('authority_next_of_kin_id')->nullable()->after('signer_client_id')->constrained('next_of_kins')->restrictOnDelete();
            $table->foreignId('capacity_evidence_consent_id')->nullable()->after('authority_next_of_kin_id')->constrained('client_consents')->restrictOnDelete();
            $table->foreignId('clinical_credential_id')->nullable()->after('capacity_evidence_consent_id')->constrained('staff_credentials')->restrictOnDelete();
            $table->string('authority_basis', 100)->nullable()->after('clinical_credential_id');
            $table->string('capacity_basis', 64)->nullable()->after('authority_basis');
            $table->string('evidence_type', 64)->nullable()->after('capacity_basis');
            $table->string('evidence_reference', 255)->nullable()->after('evidence_type');
            $table->text('outcome_reason')->nullable()->after('acknowledgement');
            $table->unsignedInteger('plan_version')->nullable()->after('outcome_reason');
            $table->char('plan_version_digest', 64)->nullable()->after('plan_version');
            $table->string('digest_algorithm', 24)->nullable()->after('plan_version_digest');
            $table->unsignedSmallInteger('digest_payload_version')->nullable()->after('digest_algorithm');
            $table->json('policy_snapshot')->nullable()->after('digest_payload_version');
            $table->json('identity_provenance')->nullable()->after('policy_snapshot');
            $table->char('signer_fingerprint', 64)->nullable()->after('identity_provenance');
            $table->char('submission_fingerprint', 64)->nullable()->after('signer_fingerprint');
            $table->char('active_identity_key', 64)->nullable()->after('submission_fingerprint');
            $table->boolean('gate_satisfying')->default(false)->after('active_identity_key');
            $table->timestamp('signer_asserted_at')->nullable()->after('gate_satisfying');
            $table->foreignId('witnessed_by')->nullable()->after('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('witnessed_at')->nullable()->after('witnessed_by');
            $table->timestamp('superseded_at')->nullable()->after('witnessed_at');
            $table->string('superseded_reason', 255)->nullable()->after('superseded_at');
            $table->timestamp('revoked_at')->nullable()->after('superseded_reason');
            $table->foreignId('revoked_by')->nullable()->after('revoked_at')->constrained('users')->restrictOnDelete();
            $table->string('revocation_reason', 500)->nullable()->after('revoked_by');

            $table->unique('active_identity_key', 'care_plan_attest_active_identity_uq');
            $table->index(
                ['care_plan_id', 'plan_version_digest', 'gate_satisfying'],
                'care_plan_attest_current_gate_idx',
            );
            $table->index(
                ['care_plan_id', 'attestation_state', 'revoked_at', 'superseded_at'],
                'care_plan_attest_state_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('care_plan_sign_offs', function (Blueprint $table): void {
            $table->dropUnique('care_plan_attest_active_identity_uq');
            $table->dropIndex('care_plan_attest_current_gate_idx');
            $table->dropIndex('care_plan_attest_state_idx');
            $table->dropConstrainedForeignId('signer_user_id');
            $table->dropConstrainedForeignId('signer_client_id');
            $table->dropConstrainedForeignId('authority_next_of_kin_id');
            $table->dropConstrainedForeignId('capacity_evidence_consent_id');
            $table->dropConstrainedForeignId('clinical_credential_id');
            $table->dropConstrainedForeignId('witnessed_by');
            $table->dropConstrainedForeignId('revoked_by');
            $table->dropColumn([
                'attestation_state',
                'signer_type',
                'authority_basis',
                'capacity_basis',
                'evidence_type',
                'evidence_reference',
                'outcome_reason',
                'plan_version',
                'plan_version_digest',
                'digest_algorithm',
                'digest_payload_version',
                'policy_snapshot',
                'identity_provenance',
                'signer_fingerprint',
                'submission_fingerprint',
                'active_identity_key',
                'gate_satisfying',
                'signer_asserted_at',
                'witnessed_at',
                'superseded_at',
                'superseded_reason',
                'revoked_at',
                'revocation_reason',
            ]);
        });

        Schema::table('care_plans', function (Blueprint $table): void {
            $table->dropColumn('attestation_policy');
        });
    }
};
