<?php

namespace App\Services\HealthSafety;

use App\Models\ChemicalExposureRecoveryCase;
use App\Models\ChemicalExposureRecoveryVersion;
use App\Models\ChemicalExposureSourceVersion;
use App\Models\ChemicalExposureCommandReceipt;
use App\Models\SubstanceExposureRecord;
use App\Models\ClientIncident;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\Incidents\IncidentAccessService;
use LogicException;

/** Unmounted governed continuation. Unknown old body/history/evidence are never loaded, copied or authorised here. */
final class ChemicalExposureAssociationRecoveryService
{
    public const IDENTITY = ['id', 'hazardous_substance_id', 'user_id', 'site_id', 'related_incident_id',
        'original_context', 'source_schema_version', 'source_revision', 'created_by'];

    public function __construct(private readonly ChemicalExposureAccessService $access,
        private readonly ChemicalExposureSourceService $source, private readonly ChemicalExposureRecoveryLedger $ledger) {}

    public function within(User $viewer, int $exposureId, callable $operation, ?HealthSafetyReadContext $context = null, bool $write = false): mixed
    {
        $consume = function (HealthSafetyReadContext $active) use ($viewer, $exposureId, $operation, $write) {
            $active->assertViewer($viewer);
            return $active->consumeViewer(ChemicalExposureCommandAccess::PERMISSIONS, function ($current, $reads, $sites) use ($exposureId, $operation, $write) {
                $this->access->assertRead($current, $reads); abort_unless($current->canDo('hazards.manage'), 403);
                $identity = $this->identity($exposureId, $current, $reads, $sites, $write);
                $case = $reads->query(ChemicalExposureRecoveryCase::query())->where('exposure_id', $exposureId)->first();
                if ($case !== null) { $this->proveCase($case, $identity, $current, $reads, $sites); }
                $result = $operation($identity, $case, $current, $reads, $sites);
                $fresh = $this->identity($exposureId, $current, $reads, $sites);
                if (ChemicalCommandValues::digest($fresh) !== ChemicalCommandValues::digest($identity)) {
                    throw new LogicException('The original sealed recovery identity changed during this callback.');
                }
                $finalCase = $reads->query(ChemicalExposureRecoveryCase::query())->where('exposure_id', $exposureId)->first();
                if (! $write && (($case === null) !== ($finalCase === null)
                    || ($case !== null && ChemicalCommandValues::digest($case->attributesToArray()) !== ChemicalCommandValues::digest($finalCase->attributesToArray())))) {
                    throw new LogicException('The later review changed while its detached result was being prepared.');
                }
                if ($finalCase !== null) { $this->proveCase($finalCase, $fresh, $current, $reads, $sites); }
                $this->assertFinalIdentity($fresh, $current, $reads, $sites);
                $reads->assertActive(); $this->detached($result);
                return $result;
            });
        };
        return $context !== null ? $consume($context) : HealthSafetyReadContext::within($viewer, ChemicalExposureCommandAccess::PERMISSIONS, $consume);
    }

    /** Identity/acceptance metadata only, including typed promotion from a linked unknown legacy origin. */
    private function identity(int $id, User $current, CurrentAuthorizationReads $reads, array $sites, bool $write = false): array
    {
        $query = $this->access->readableQuery($current, $reads, $sites)->whereKey($id);
        if ($write) { $query->getQuery()->beforeQuery(fn ($compiled) => $compiled->lock('for update nowait')); }
        $root = $query->firstOrFail(self::IDENTITY); $this->access->assertSite((int) $root->site_id, $reads, $sites);
        $identity = array_intersect_key($root->attributesToArray(), array_flip(self::IDENTITY));
        $versions = $reads->query(ChemicalExposureSourceVersion::query())->where('exposure_id', $id)
            ->orderBy('aggregate_revision')->get(['id', 'aggregate_revision', 'recorder_id']);
        $unknown = false; $anchors = []; $latest = null; $revision = 0;
        if ($versions->isEmpty()) {
            $unknown = $root->source_schema_version === 0 && $root->source_revision === 0
                && $root->related_incident_id !== null && $root->original_context === null;
        } else {
            if ($root->source_schema_version !== 1 || $root->source_revision < 1) { throw new LogicException('Governed origin cannot be disguised as legacy.'); }
            foreach ($versions as $version) {
                if ((int) $version->aggregate_revision !== ++$revision) { throw new LogicException('Retained origin sequence is incomplete.'); }
                $receipt = $reads->query(ChemicalExposureCommandReceipt::query())->where('exposure_id', $id)
                    ->where('source_version_id', $version->id)->firstOrFail(['id']);
                $binding = $this->source->admitReceiptMetadata((int) $receipt->id, $current, $reads, $sites);
                $this->source->admitVersionMetadata((int) $version->id, $current, $reads, $sites);
                foreach (['before', 'after'] as $side) {
                    if ($binding[$side] !== null && $binding[$side]['related_incident_id'] !== null && $binding[$side]['original_context'] === null) { $unknown = true; }
                }
                $anchors[] = ['version_id' => (int) $version->id, 'receipt_id' => (int) $receipt->id,
                    'binding_digest' => ChemicalCommandValues::digest($binding)];
                $latest = $binding['after'];
            }
            $actual = ['exposure_id' => $id, 'substance_id' => (int) $root->hazardous_substance_id, 'worker_id' => (int) $root->user_id,
                'site_id' => (int) $root->site_id, 'related_incident_id' => $root->related_incident_id, 'original_context' => $root->original_context];
            if ($root->source_revision !== $revision || ChemicalCommandValues::digest($latest) !== ChemicalCommandValues::digest($actual)) {
                throw new LogicException('Current sealed origin differs from accepted identity metadata.');
            }
        }
        if (! $unknown) { ChemicalCommandValues::invalid('source', 'This source has no genuine linked-unknown legacy origin requiring this recovery purpose.'); }
        return [...$identity, 'origin' => 'linked_legacy_unknown', 'accepted_origin' => $anchors];
    }

    public function inspect(User $viewer, int $exposureId, ?HealthSafetyReadContext $context = null): array
    {
        return $this->within($viewer, $exposureId, function ($identity, $case, $current, $reads, $sites) {
            $review = null;
            if ($case !== null) {
                $metadata = $this->proveCase($case, $identity, $current, $reads, $sites);
                $lastReview = null;
                foreach ($metadata as $version) { if ($version->command === 'record_legacy_review') { $lastReview = $version; } }
                if ($lastReview !== null) {
                    $review = ['id' => (int) $lastReview->id, 'revision' => $lastReview->revision, 'decision' => $lastReview->decision,
                        'reviewed_at' => $lastReview->reviewed_at?->toISOString(), 'recorded_at' => $lastReview->recorded_at?->toISOString(),
                        'association_origin' => 'later_reviewed', 'original_association_state' => 'unknown', 'facts' => null, 'evidence' => null];
                    if ($lastReview->decision === 'reviewed_association' && $lastReview->review_context !== null) {
                        $this->source->assertOriginalContext($lastReview->review_context, $current, $reads, $case->site_id);
                        $row = $reads->query(ChemicalExposureRecoveryVersion::query())->where('case_id', $case->id)->whereKey($lastReview->id)
                            ->where('content_digest', $lastReview->content_digest)->whereRaw('SHA2(CAST(content AS BINARY), 256) = ?', [$lastReview->content_digest])
                            ->firstOrFail(['id', 'content']);
                        if (hash('sha256', $row->getRawOriginal('content')) !== $lastReview->content_digest) { throw new LogicException('Later review body differs from admitted bytes.'); }
                        $review['facts'] = $row->content['facts'];
                        $this->proveCase($case, $identity, $current, $reads, $sites);
                    }
                }
            }
            return ['recovery_access' => 'admitted', 'legacy_source' => ['id' => $identity['id'], 'original_association_state' => 'unknown',
                'medical_record' => null, 'history' => null, 'history_state' => 'sealed', 'evidence' => null],
                'case_id' => $case?->id, 'expected_version' => $case?->revision ?? 0, 'review' => $review,
                'can' => ['record_review' => true, 'add_review_evidence' => $case !== null, 'read_old_body' => false],
                'href' => null, 'delivery' => 'not_attempted'];
        }, $context);
    }

    public function recordReview(User $actor, int $exposureId, array $input, ?HealthSafetyReadContext $context = null): array
    {
        $v = ChemicalCommandValues::normalize($input, ['decision' => ['required', 'in:unresolved,reviewed_association,reviewed_no_association'],
            'reviewed_at' => ['required', 'string'], 'incident_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:5000'], 'notes' => ['sometimes', 'nullable', 'string', 'max:5000']]);
        $v['reviewed_at'] = ChemicalCommandValues::instant($v['reviewed_at'], 'reviewed_at');
        if (trim($v['reason']) === '') { ChemicalCommandValues::invalid('reason', 'Record the later review basis.'); }
        if (($v['decision'] === 'reviewed_association') !== isset($v['incident_id'])) {
            ChemicalCommandValues::invalid('incident_id', 'Only an explicitly reviewed association has a selected incident.');
        }
        return $this->within($actor, $exposureId, function ($identity, $case, $current, $reads, $sites) use ($v) {
            $newContext = $v['decision'] === 'reviewed_association' ? $this->reviewContext($v['incident_id'], $identity['site_id'], $current, $reads) : null;
            $values = [...$v, 'sealed_digest' => ChemicalCommandValues::digest($identity), 'review_context' => $newContext];
            $intent = $this->ledger->claim($current, $identity, 'record_legacy_review', $values, $reads);
            if ($receipt = $this->ledger->receipt($intent, $reads)) {
                if ($case === null || $receipt->case_id !== (int) $case->id) { throw new LogicException('Recovery replay lost its original case.'); }
                $this->ledger->prove($case, $receipt->version_id, $reads);
                return array_replace(array_fill_keys(ChemicalExposureRecoveryLedger::KEYS, null), $receipt->accepted_result);
            }
            if ($case === null) {
                if ($v['expected_version'] !== 0) { ChemicalCommandValues::invalid('expected_version', 'The first later review starts at version zero.'); }
                $case = new ChemicalExposureRecoveryCase(['exposure_id' => $identity['id'], 'worker_id' => $identity['user_id'],
                    'site_id' => $identity['site_id'], 'substance_id' => $identity['hazardous_substance_id'], 'sealed_identity' => $identity,
                    'sealed_digest' => ChemicalCommandValues::digest($identity), 'revision' => 0, 'current_version_id' => null, 'created_at' => now()->utc()]);
                $this->ledger->save($case);
            }
            if ($newContext !== null) { $this->source->assertOriginalContext($newContext, $current, $reads, $case->site_id); }
            return $this->ledger->accept($case, $intent, $current, $reads, $v['decision'], $newContext, $v['reviewed_at'],
                ['reason' => $v['reason'], 'notes' => $v['notes'] ?? null, 'medical_fact_origin' => 'newly_entered_later_review']);
        }, $context, true);
    }

    public function proveCase(ChemicalExposureRecoveryCase $case, array $identity, User $current, CurrentAuthorizationReads $reads, array $sites): array
    {
        if ($case->exposure_id !== $identity['id'] || $case->worker_id !== $identity['user_id'] || $case->site_id !== $identity['site_id']
            || $case->substance_id !== $identity['hazardous_substance_id'] || $case->sealed_digest !== ChemicalCommandValues::digest($identity)
            || ChemicalCommandValues::digest($case->sealed_identity) !== $case->sealed_digest) { throw new LogicException('Recovery sealed identity was rebound.'); }
        $header = $reads->query(ChemicalExposureRecoveryCase::query())->whereKey($case->id)->firstOrFail();
        if (ChemicalCommandValues::digest($header->attributesToArray()) !== ChemicalCommandValues::digest($case->attributesToArray())) {
            throw new LogicException('Recovery current header changed before private review proof.');
        }
        $rows = $reads->query(ChemicalExposureRecoveryVersion::query())->where('case_id', $case->id)->orderBy('revision')
            ->get(['id', 'revision', 'previous_version_id', 'review_context']);
        $previous = null; $sequence = 0; $proved = [];
        foreach ($rows as $row) {
            if ($row->revision !== ++$sequence || $row->previous_version_id !== $previous) { throw new LogicException('Recovery retained sequence is incomplete.'); }
            if ($row->review_context !== null) { $this->source->assertOriginalContext($row->review_context, $current, $reads, $case->site_id); }
            $proved[] = $this->ledger->prove($case, (int) $row->id, $reads); $previous = (int) $row->id;
        }
        if ($case->revision !== $sequence || $case->current_version_id !== $previous) { throw new LogicException('Recovery current version pointer is damaged.'); }
        if (! $reads->query(ChemicalExposureRecoveryCase::query())->whereKey($case->id)->where('revision', $case->revision)
            ->where('current_version_id', $case->current_version_id)->where('sealed_digest', $case->sealed_digest)->exists()) {
            throw new LogicException('Recovery header changed after proof.');
        }
        return $proved;
    }

    /** Exact accepted audit only; arbitrary Case audits or numeric exposure fallbacks are never acceptance proof. */
    public function auditProof(int $auditId, User $current, CurrentAuthorizationReads $reads, array $sites): ?array
    {
        $this->access->assertRead($current, $reads); abort_unless($current->canDo('hazards.manage'), 403);
        $audit = $reads->query(\App\Models\AuditLog::query())->whereKey($auditId)
            ->first(['id', 'action', 'auditable_type', 'auditable_id']);
        if ($audit === null || ! in_array($audit->auditable_type, [ChemicalExposureRecoveryCase::class, (new ChemicalExposureRecoveryCase)->getMorphClass()], true)
            || ! in_array($audit->action, ['chemical.exposure.recovery.record_legacy_review', 'chemical.exposure.recovery.upload_review_basis'], true)) { return null; }
        $proof = $this->sourceProofForAuditSubject($audit->auditable_type, (int) $audit->auditable_id, $current, $reads, $sites);
        if ($proof === null) { return null; }
        $receipt = $reads->query(\App\Models\ChemicalExposureRecoveryReceipt::query())->where('case_id', $proof['case_id'])
            ->where('audit_log_id', $auditId)->first(['id', 'version_id']);
        if ($receipt === null) { return null; }
        $case = $reads->query(ChemicalExposureRecoveryCase::query())->whereKey($proof['case_id'])->firstOrFail();
        $version = $this->ledger->prove($case, $receipt->version_id, $reads);
        if ($audit->action !== 'chemical.exposure.recovery.'.$version->command) { return null; }
        return $proof;
    }
    /** Internal supplied-current evidence seam for the UNAPPLIED general-audit adapter; no fresh/nested actor wrapper. */
    public function sourceProofForAuditSubject(string $classOrMorph, int $id, User $current, CurrentAuthorizationReads $reads, array $sites): ?array
    {
        $this->access->assertRead($current, $reads); abort_unless($current->canDo('hazards.manage'), 403);
        $map = [ChemicalExposureRecoveryCase::class => 'case', ChemicalExposureRecoveryVersion::class => 'version',
            \App\Models\ChemicalExposureRecoveryReceipt::class => 'receipt', \App\Models\ChemicalExposureRecoveryStaging::class => 'staging',
            \App\Models\ChemicalExposureRecoveryEvidence::class => 'evidence'];
        $class = null;
        foreach ($map as $candidate => $kind) { if (in_array($classOrMorph, [$candidate, (new $candidate)->getMorphClass()], true)) { $class = $candidate; break; } }
        if ($id < 1) { return null; }
        if ($classOrMorph === \App\Models\ChemicalCommandIntent::class || $classOrMorph === (new \App\Models\ChemicalCommandIntent)->getMorphClass()) {
            $intent = $reads->query(\App\Models\ChemicalCommandIntent::query())->whereKey($id)->where('source_type', 'exposure_association_review')->first(['id', 'source_id']);
            if ($intent === null || $intent->source_id === null) { return null; }
            $identity = $this->identity($intent->source_id, $current, $reads, $sites);
            $case = $reads->query(ChemicalExposureRecoveryCase::query())->where('exposure_id', $identity['id'])->first();
            if ($case === null) { return null; }
            $proofs = $this->proveCase($case, $identity, $current, $reads, $sites);
            if (! collect($proofs)->contains(fn ($version) => $version->intent_id === $id)) { return null; }
        } else {
            if ($class === null) { return null; }
            $subject = $reads->query($class::query())->whereKey($id)->first($class === ChemicalExposureRecoveryCase::class ? ['id', 'exposure_id'] : ['id', 'case_id']);
            if ($subject === null) { return null; }
            $caseIdentity = $class === ChemicalExposureRecoveryCase::class ? $subject
                : $reads->query(ChemicalExposureRecoveryCase::query())->whereKey($subject->case_id)->first(['id', 'exposure_id']);
            if ($caseIdentity === null) { return null; }
            $identity = $this->identity($caseIdentity->exposure_id, $current, $reads, $sites);
            $case = $reads->query(ChemicalExposureRecoveryCase::query())->whereKey($caseIdentity->id)->where('exposure_id', $identity['id'])->firstOrFail();
            $this->proveCase($case, $identity, $current, $reads, $sites);
            if ($class === ChemicalExposureRecoveryVersion::class) { $this->ledger->prove($case, $id, $reads); }
            if ($class === \App\Models\ChemicalExposureRecoveryReceipt::class) {
                $receipt = $reads->query($class::query())->where('case_id', $case->id)->whereKey($id)->firstOrFail(['id', 'version_id']);
                $this->ledger->prove($case, $receipt->version_id, $reads);
            }
            // Unaccepted staging/private evidence has its own exact physical proof; conservative until that adapter is composed.
            if (in_array($class, [\App\Models\ChemicalExposureRecoveryStaging::class, \App\Models\ChemicalExposureRecoveryEvidence::class], true)) { return null; }
        }
        return ['attribution_state' => 'recorded', 'association_origin' => 'later_reviewed', 'original_association_state' => 'unknown',
            'source_id' => $identity['id'], 'case_id' => (int) $case->id, 'source_revision' => $case->revision,
            'site_id' => $case->site_id, 'worker_id' => $case->worker_id, 'old_medical_state' => 'sealed'];
    }

    private function reviewContext(int $id, int $siteId, User $current, CurrentAuthorizationReads $reads): array
    {
        $incident = app(IncidentAccessService::class)->apply(ClientIncident::query(), $current, $reads)->where('site_id', $siteId)
            ->whereKey($id)->firstOrFail(['id', 'site_id', 'client_id', 'shift_id', 'service_context_id', 'respite_stay_id']);
        $context = ['schema_version' => 1, 'site_id' => $siteId, 'incident_id' => (int) $incident->id, 'client_id' => (int) $incident->client_id,
            'shift_id' => $incident->shift_id === null ? null : (int) $incident->shift_id,
            'service_context_id' => $incident->service_context_id === null ? null : (int) $incident->service_context_id,
            'stay_id' => $incident->respite_stay_id === null ? null : (int) $incident->respite_stay_id];
        $this->source->assertOriginalContext($context, $current, $reads, $siteId);
        return $context;
    }

    /** Final SQL predicate has no model-retrieved gap and never selects the sealed medical body. */
    private function assertFinalIdentity(array $identity, User $current, CurrentAuthorizationReads $reads, array $sites): void
    {
        $query = $this->access->readableQuery($current, $reads, $sites)->whereKey($identity['id']);
        foreach (self::IDENTITY as $column) {
            $value = $identity[$column];
            if ($value === null) { $query->whereNull($column); }
            elseif ($column === 'original_context') {
                $query->whereRaw('original_context = CAST(? AS JSON)', [json_encode($value, JSON_THROW_ON_ERROR)]);
            } else { $query->where($column, $value); }
        }
        if (! $query->exists()) { throw new LogicException('The original sealed identity changed after final metadata admission.'); }
    }
    private function detached(mixed $value): void
    {
        if (is_array($value)) { foreach ($value as $entry) { $this->detached($entry); } }
        elseif ($value !== null && ! is_scalar($value)) { throw new LogicException('Recovery callbacks return detached scalar data only.'); }
    }
}
