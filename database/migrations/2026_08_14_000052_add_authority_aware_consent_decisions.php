<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_authority_scopes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('next_of_kin_id')->constrained('next_of_kins')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->foreignId('representative_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('consent_type_id')->constrained('consent_types')->restrictOnDelete();
            $table->string('authority_type', 80);
            $table->text('purpose');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('valid_from');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('verified_at');
            $table->foreignId('verified_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('capacity_evidence_consent_id')
                ->nullable()
                ->constrained('client_consents')
                ->restrictOnDelete();
            $table->string('evidence_reference', 255)->nullable();
            $table->json('evidence_snapshot')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('revocation_reason', 500)->nullable();
            $table->timestamps();

            $table->index(
                ['client_id', 'representative_user_id', 'consent_type_id'],
                'consent_authority_scope_subject_idx',
            );
            $table->index(
                ['site_id', 'valid_from', 'expires_at', 'revoked_at'],
                'consent_authority_scope_current_idx',
            );
        });

        Schema::table('consent_requests', function (Blueprint $table): void {
            $table->foreignId('site_id')->nullable()->after('client_id')->constrained('sites')->restrictOnDelete();
            $table->foreignId('consent_type_version_id')
                ->nullable()
                ->after('consent_type_id')
                ->constrained('consent_type_versions')
                ->restrictOnDelete();
            $table->foreignId('authority_scope_id')
                ->nullable()
                ->after('authority_next_of_kin_id')
                ->constrained('consent_authority_scopes')
                ->restrictOnDelete();
            $table->foreignId('capacity_evidence_consent_id')
                ->nullable()
                ->after('authority_scope_id')
                ->constrained('client_consents')
                ->restrictOnDelete();
            $table->string('decision_kind', 64)->nullable()->after('response_user_agent');
            $table->unsignedSmallInteger('decision_contract_version')->nullable()->after('decision_kind');
            $table->json('decision_evidence')->nullable()->after('decision_contract_version');

            $table->index(
                ['client_id', 'site_id', 'consent_type_id', 'status'],
                'consent_request_bound_decision_idx',
            );
        });

        Schema::table('client_consents', function (Blueprint $table): void {
            $table->foreignId('site_id')->nullable()->after('client_id')->constrained('sites')->restrictOnDelete();
            $table->unsignedBigInteger('source_consent_request_id')->nullable()->after('consent_type_version_id');
            $table->string('decision_state', 64)->default('governance_review_required')->after('source_consent_request_id');
            $table->string('decision_basis', 64)->nullable()->after('decision_state');
            $table->foreignId('decision_client_id')
                ->nullable()
                ->after('decision_basis')
                ->constrained('clients')
                ->restrictOnDelete();
            $table->foreignId('decision_actor_user_id')
                ->nullable()
                ->after('decision_client_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->foreignId('authority_scope_id')
                ->nullable()
                ->after('decision_actor_user_id')
                ->constrained('consent_authority_scopes')
                ->restrictOnDelete();
            $table->foreignId('capacity_evidence_consent_id')
                ->nullable()
                ->after('authority_scope_id')
                ->constrained('client_consents')
                ->restrictOnDelete();
            $table->text('decision_purpose')->nullable()->after('capacity_evidence_consent_id');
            $table->unsignedSmallInteger('decision_contract_version')->nullable()->after('decision_purpose');
            $table->json('decision_evidence')->nullable()->after('decision_contract_version');
            $table->boolean('gate_satisfying')->default(false)->after('decision_evidence');
            $table->string('governance_review_reason', 255)->nullable()->after('gate_satisfying');

            $table->unique('source_consent_request_id', 'client_consent_source_request_uq');
            $table->index(
                ['client_id', 'site_id', 'consent_type_id', 'gate_satisfying'],
                'client_consent_consumability_idx',
            );
            $table->index(
                ['authority_scope_id', 'status', 'expires_at'],
                'client_consent_authority_current_idx',
            );
        });

        Schema::table('client_consents', function (Blueprint $table): void {
            $table->foreign('source_consent_request_id', 'client_consent_source_request_fk')
                ->references('id')
                ->on('consent_requests')
                ->restrictOnDelete();
        });

        DB::table('consent_requests')->orderBy('id')->chunkById(500, function ($requests): void {
            foreach ($requests as $request) {
                $siteId = DB::table('clients')->where('id', $request->client_id)->value('site_id');
                $versionId = DB::table('consent_type_versions')
                    ->where('consent_type_id', $request->consent_type_id)
                    ->orderByDesc('version')
                    ->value('id');

                DB::table('consent_requests')->where('id', $request->id)->update([
                    'site_id' => $siteId,
                    'consent_type_version_id' => $versionId,
                ]);
            }
        });

        DB::table('client_consents')->update([
            'decision_state' => 'governance_review_required',
            'gate_satisfying' => false,
            'governance_review_reason' => 'legacy_authority_evidence_not_sufficiently_bound',
        ]);
        DB::table('consent_requests')
            ->where('status', 'approved')
            ->whereNotNull('resulting_consent_id')
            ->update([
                'decision_kind' => 'governance_review_required',
                'decision_contract_version' => 1,
            ]);

        $this->classifyLegacyPortalDecisions();
    }

    public function down(): void
    {
        $hasScopedAuthority = DB::table('consent_authority_scopes')->exists();
        $hasBoundDecision = DB::table('client_consents')
            ->where(function ($query): void {
                $query->whereNotNull('source_consent_request_id')
                    ->orWhere('gate_satisfying', true)
                    ->orWhereNotNull('authority_scope_id');
            })
            ->exists();

        if ($hasScopedAuthority || $hasBoundDecision) {
            throw new RuntimeException(
                'Cannot remove the authority-aware consent contract while scoped authority or bound decisions exist.',
            );
        }

        Schema::table('client_consents', function (Blueprint $table): void {
            $table->dropForeign('client_consent_source_request_fk');
            $table->dropForeign(['authority_scope_id']);
            $table->dropUnique('client_consent_source_request_uq');
            $table->dropIndex('client_consent_consumability_idx');
            $table->dropIndex('client_consent_authority_current_idx');
            $table->dropConstrainedForeignId('site_id');
            $table->dropConstrainedForeignId('decision_client_id');
            $table->dropConstrainedForeignId('decision_actor_user_id');
            $table->dropConstrainedForeignId('capacity_evidence_consent_id');
            $table->dropColumn([
                'authority_scope_id',
                'source_consent_request_id',
                'decision_state',
                'decision_basis',
                'decision_purpose',
                'decision_contract_version',
                'decision_evidence',
                'gate_satisfying',
                'governance_review_reason',
            ]);
        });

        Schema::table('consent_requests', function (Blueprint $table): void {
            $table->dropIndex('consent_request_bound_decision_idx');
            $table->dropConstrainedForeignId('site_id');
            $table->dropConstrainedForeignId('consent_type_version_id');
            $table->dropConstrainedForeignId('authority_scope_id');
            $table->dropConstrainedForeignId('capacity_evidence_consent_id');
            $table->dropColumn([
                'decision_kind',
                'decision_contract_version',
                'decision_evidence',
            ]);
        });

        Schema::dropIfExists('consent_authority_scopes');
    }

    private function classifyLegacyPortalDecisions(): void
    {
        DB::table('consent_requests')
            ->where('status', 'approved')
            ->whereNotNull('resulting_consent_id')
            ->orderBy('id')
            ->chunkById(200, function ($requests): void {
                foreach ($requests as $request) {
                    $consent = DB::table('client_consents')->where('id', $request->resulting_consent_id)->first();

                    if (! $consent
                        || (int) $consent->client_id !== (int) $request->client_id
                        || (int) $consent->consent_type_id !== (int) $request->consent_type_id
                        || (int) ($consent->given_by_user_id ?? 0) !== (int) $request->recipient_user_id) {
                        continue;
                    }

                    if ($request->recipient_relationship !== 'next_of_kin') {
                        continue;
                    }

                    if (DB::table('client_consents')
                        ->where('source_consent_request_id', $request->id)
                        ->orWhere(function ($query) use ($consent, $request): void {
                            $query->where('id', $consent->id)
                                ->whereNotNull('source_consent_request_id')
                                ->where('source_consent_request_id', '!=', $request->id);
                        })
                        ->exists()) {
                        continue;
                    }

                    DB::table('client_consents')->where('id', $consent->id)->update([
                        'source_consent_request_id' => $request->id,
                        'decision_state' => 'informational_acknowledgement',
                        'decision_basis' => 'informational_only',
                        'decision_client_id' => $request->client_id,
                        'decision_actor_user_id' => $request->recipient_user_id,
                        'decision_purpose' => $request->purpose,
                        'decision_contract_version' => 1,
                        'gate_satisfying' => false,
                        'governance_review_reason' => 'legacy_informational_response_never_authoritative',
                    ]);
                    DB::table('consent_requests')->where('id', $request->id)->update([
                        'decision_kind' => 'informational_acknowledgement',
                        'decision_contract_version' => 1,
                    ]);
                }
            });
    }
};
