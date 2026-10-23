<?php

namespace App\Services\HealthSafety;

use App\Models\ChemicalCommandIntent;
use App\Models\ChemicalExposureCommandReceipt;
use App\Models\ChemicalExposureFollowup;
use App\Models\ChemicalExposureReview;
use App\Models\ChemicalExposureSourceDelivery;
use App\Models\ChemicalExposureSourceVersion;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\HazardousSubstance;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\SubstanceExposureRecord;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\Incidents\IncidentAccessService;
use App\Services\Respite\RespiteStayScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use LogicException;

/** Governed private source facts. No review is a clinical fitness, competence or WorkSafe approval. */
final class ChemicalExposureSourceService
{
    public const BODY_ADMISSION_FIELDS = ['id', 'hazardous_substance_id', 'user_id', 'site_id', 'related_incident_id',
        'original_context', 'source_schema_version', 'source_revision', 'created_by'];

    /** Proposed metadata-only guard: no root medical fields, version.content, names or refreshed viewer fallback. */
    public function admitExposureIdentityBeforeBody(SubstanceExposureRecord $identity, User $current,
        CurrentAuthorizationReads $reads, array $sites): void
    {
        $this->access->assertOperation($current, $reads, 'read');
        $this->access->site((int) $identity->site_id, $reads, $sites);
        $this->assertLegacyNoopReceiptIdentity($identity, $current, $reads, $sites);
        $versions = $reads->query(ChemicalExposureSourceVersion::query())->where('exposure_id', $identity->id);
        $latest = (clone $versions)->orderByDesc('aggregate_revision')->first(['id']);
        if ($identity->source_schema_version === 0 && $identity->source_revision === 0 && ! $latest) {
            if ($identity->related_incident_id !== null || $identity->original_context !== null) {
                ChemicalCommandValues::invalid('source', 'Original legacy association is unproved. Review its source before private body access.');
            }
            $receipts = $reads->query(ChemicalExposureCommandReceipt::query())->where(function ($scope) use ($identity): void {
                $scope->where('exposure_id', $identity->id)->orWhere(fn ($source) => $source
                    ->where('source_type', 'exposure')->where('source_id', $identity->id));
            });
            if ($reads->query(ChemicalExposureSourceVersion::query())->where('source_type', 'exposure')
                    ->where('source_id', $identity->id)->exists()
                || (clone $receipts)->whereNotNull('source_version_id')->exists()
                || (clone $receipts)->where('exposure_id', '!=', $identity->id)->exists()) {
                ChemicalCommandValues::invalid('source', 'Retained governed receipt identity cannot be relabelled as legacy.');
            }
            // Legitimate unchanged legacy receipts do not certify facts, but their original access remains required.
            return; // Genuine no-association legacy remains unknown, never retrospectively certified.
        }
        if ($identity->source_schema_version !== 1 || $identity->source_revision < 1 || ! $latest) {
            ChemicalCommandValues::invalid('source', 'Canonical source provenance requires repair before private body access.');
        }
        // A later known association cannot retrospectively prove a linked legacy origin or unseal its copied body.
        $lineage = $reads->query(ChemicalExposureSourceVersion::query())->where('exposure_id', $identity->id)
            ->orderBy('aggregate_revision')->get(['id']);
        foreach ($lineage as $historical) {
            $receipt = $reads->query(ChemicalExposureCommandReceipt::query())->where('exposure_id', $identity->id)
                ->where('source_version_id', $historical->id)->firstOrFail(['id']);
            $original = $this->admitReceiptMetadata((int) $receipt->id, $current, $reads, $sites);
            foreach (['before', 'after'] as $side) {
                $tuple = $original[$side];
                if ($tuple !== null && $tuple['related_incident_id'] !== null && $tuple['original_context'] === null) {
                    ChemicalCommandValues::invalid('source', 'The retained linked legacy origin is unproved. A later association does not authorise its sealed facts.');
                }
            }
        }
        $this->admitVersionMetadata((int) $latest->id, $current, $reads, $sites);
        $receiptIdentity = $reads->query(ChemicalExposureCommandReceipt::query())->where('source_version_id', $latest->id)->firstOrFail(['id']);
        $binding = $this->admitReceiptMetadata((int) $receiptIdentity->id, $current, $reads, $sites);
        $tuple = ['exposure_id' => (int) $identity->id, 'substance_id' => (int) $identity->hazardous_substance_id,
            'worker_id' => (int) $identity->user_id, 'site_id' => (int) $identity->site_id,
            'related_incident_id' => $identity->related_incident_id, 'original_context' => $identity->original_context];
        if (ChemicalCommandValues::digest($tuple) !== ChemicalCommandValues::digest($binding['after'])) {
            ChemicalCommandValues::invalid('source', 'Current private identity differs from its original acceptance-bound tuple.');
        }
        $query = $reads->query(ChemicalExposureSourceVersion::query())->where('exposure_id', $identity->id)->whereKey($latest->id)
            ->where('aggregate_revision', $identity->source_revision)->select(['id']);
        $query->selectRaw("JSON_OBJECT('id', JSON_EXTRACT(content, '$.root_after.id'),"
            . "'hazardous_substance_id', JSON_EXTRACT(content, '$.root_after.hazardous_substance_id'),"
            . "'user_id', JSON_EXTRACT(content, '$.root_after.user_id'), 'site_id', JSON_EXTRACT(content, '$.root_after.site_id'),"
            . "'related_incident_id', JSON_EXTRACT(content, '$.root_after.related_incident_id'),"
            . "'original_context', JSON_EXTRACT(content, '$.root_after.original_context'),"
            . "'source_schema_version', JSON_EXTRACT(content, '$.root_after.source_schema_version'),"
            . "'source_revision', JSON_EXTRACT(content, '$.root_after.source_revision'),"
            . "'created_by', JSON_EXTRACT(content, '$.root_after.created_by')) AS admitted_identity");
        $versionIdentity = $query->first();
        $after = $versionIdentity ? json_decode($versionIdentity->getRawOriginal('admitted_identity'), true, flags: JSON_THROW_ON_ERROR) : null;
        $actual = array_intersect_key($identity->attributesToArray(), array_flip(self::BODY_ADMISSION_FIELDS));
        if (! is_array($after) || ChemicalCommandValues::digest($after) !== ChemicalCommandValues::digest($actual)) {
            ChemicalCommandValues::invalid('source', 'Canonical original source identity differs from retained acceptance.');
        }
        if ($identity->original_context !== null) {
            $this->assertOriginalContext($identity->original_context, $current, $reads, (int) $identity->site_id);
        } elseif ($identity->related_incident_id !== null) {
            ChemicalCommandValues::invalid('source', 'Original association is unknown. No current Client placement supplies it.');
        }
    }
    public function __construct(private readonly ChemicalExposureCommandAccess $access,
        private readonly ChemicalExposureAccessService $read, private readonly ChemicalExposureLedger $ledger) {}

    public function create(HazardousSubstance|int $substance, User $actor, array $input): array
    {
        $v = $this->values($input, true);
        return $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($substance, $v) {
            $this->access->assertOperation($current, $reads, 'capture');
            $parent = $this->access->substance($substance, $reads);
            $this->access->site($v['site_id'], $reads, $sites);
            $this->access->staff($v['user_id'], $v['site_id'], $reads);
            $context = $this->originalContext($v['related_incident_id'] ?? null, $v['site_id'], $current, $reads);
            $intent = $this->ledger->claim($current, $reads, null, (int) $parent->id, 'create_exposure', $v, $sites);
            if ($receipt = $this->ledger->receipt($intent, $reads)) {
                $root = $this->captureSource($receipt->exposure_id, $current, $reads, $sites, (int) $parent->id);
                return $this->ledger->reconstruct($receipt, $intent, $root, $current, $reads, $sites, 'capture');
            }
            $root = new SubstanceExposureRecord(['medical_attention_sought' => null, 'incident_reported' => null,
                ...$this->payload($v), 'hazardous_substance_id' => $parent->id, 'created_by' => $current->id, 'updated_by' => $current->id]);
            $root->forceFill(['source_schema_version' => 1, 'source_revision' => 1, 'original_context' => $context]);
            $this->save($root);
            $reported = ChemicalExposureReportedFacts::capture($this->ledger->snapshot($root),
                array_intersect_key($v, array_flip(ChemicalExposureReportedFacts::FIELDS)), $this->reportedIdentity($root, $intent, $current, 1));
            $version = $this->ledger->version($intent, $root, $root, 'exposure', $current, $reads, null, null,
                ['reported_facts' => $reported]);
            $result = $this->ledger->accept($intent, $root, $root, 'exposure', $version, $current, $reads, true, $sites);
            $this->stageDelivery($root, $version, $current, 'health_safety');
            return $result;
        });
    }

    public function correct(SubstanceExposureRecord|int $source, User $actor, array $input): array
    {
        $v = $this->values($input, false);
        return $this->command($source, $actor, 'correct_exposure', $v, function ($root, $current, $reads, $sites) use ($v) {
            $before = $this->ledger->snapshot($root);
            $payload = $this->payload($v);
            $siteId = $payload['site_id'] ?? (int) $root->site_id;
            $workerId = $payload['user_id'] ?? (int) $root->user_id;
            if ($siteId !== (int) $root->site_id || $workerId !== (int) $root->user_id) {
                foreach ([ChemicalExposureReview::class, ChemicalExposureFollowup::class, \App\Models\ChemicalExposureEvidence::class] as $model) {
                    $q = $model === \App\Models\ChemicalExposureEvidence::class ? $model::withTrashed() : $model::query();
                    if ($reads->query($q)->where('exposure_id', $root->id)->exists()) {
                        ChemicalCommandValues::invalid('site_id', 'Retained private child evidence prevents worker or Site rebinding.');
                    }
                }
                if ($reads->query(\App\Models\ChemicalExposureEvidenceStaging::query())->where('exposure_id', $root->id)
                    ->where('state', '!=', 'cleaned')->exists()) {
                    ChemicalCommandValues::invalid('site_id', 'Resolve retained physical evidence before changing the occurrence context.');
                }
                $this->access->site($siteId, $reads, $sites);
                $this->access->staff($workerId, $siteId, $reads);
                if (! array_key_exists('related_incident_id', $v) && $root->related_incident_id !== null) {
                    ChemicalCommandValues::invalid('related_incident_id', 'Explicitly review the original association before changing the occurrence context.');
                }
            }
            if (array_key_exists('related_incident_id', $v)) {
                $root->original_context = $this->originalContext($v['related_incident_id'], $siteId, $current, $reads);
            } elseif ($siteId !== (int) $root->site_id && is_array($root->original_context)
                && ($root->original_context['schema_version'] ?? null) === 1 && $root->related_incident_id === null) {
                // A proved absent association remains absent at the explicitly corrected Site; legacy unknown stays unknown.
                $root->original_context = $this->originalContext(null, $siteId, $current, $reads);
            }
            $root->fill($payload);
            // Omitted association remains exactly historical; ordinary narrative edits do not certify legacy context.
            return [$root, 'exposure', $before, $root->isDirty(), ['reason' => $v['reason']]];
        });
    }

    public function review(SubstanceExposureRecord|int $source, User $actor, array $input): array
    {
        $v = ChemicalCommandValues::normalize($input, ['reviewed_at' => ['required', 'string'],
            'outcome' => ['required', 'string', 'in:recorded,followup_required'], 'notes' => ['required', 'string', 'max:5000']]);
        $v['reviewed_at'] = ChemicalCommandValues::instant($v['reviewed_at'], 'reviewed_at');
        $this->nonblank($v['notes'], 'notes');
        return $this->command($source, $actor, 'review_exposure', $v, function ($root, $current) use ($v) {
            if ($v['reviewed_at'] < $root->exposed_at->copy()->utc()->format('Y-m-d H:i:s.u')) {
                ChemicalCommandValues::invalid('reviewed_at', 'A review cannot precede the recorded occurrence.');
            }
            $child = new ChemicalExposureReview(['exposure_id' => $root->id, 'source_schema_version' => 1,
                'source_revision' => $root->source_revision + 1, 'reviewed_at' => $v['reviewed_at'],
                'outcome' => $v['outcome'], 'notes' => $v['notes'], 'recorded_by' => $current->id,
                'recorder_snapshot' => ['id' => (int) $current->id, 'name' => $current->name]]);
            $this->save($child);
            return [$child, 'review', null, true, []];
        });
    }

    public function assignFollowup(SubstanceExposureRecord|int $source, User $actor, array $input): array
    {
        $v = ChemicalCommandValues::normalize($input, ['assigned_to' => ['required', 'integer', 'min:1'],
            'instruction' => ['required', 'string', 'max:5000'], 'due_at' => ['required', 'string']]);
        $v['due_at'] = ChemicalCommandValues::instant($v['due_at'], 'due_at', false);
        $this->nonblank($v['instruction'], 'instruction');
        return $this->command($source, $actor, 'assign_followup', $v, function ($root, $current, $reads) use ($v) {
            $receiver = $this->assertFollowupAssignee($root, $v['assigned_to'], $reads);
            $child = new ChemicalExposureFollowup(['exposure_id' => $root->id, 'source_schema_version' => 1,
                'source_revision' => $root->source_revision + 1, 'assigned_to' => $receiver->id,
                'assignee_snapshot' => ['id' => (int) $receiver->id, 'name' => $receiver->name],
                'instruction' => $v['instruction'], 'due_at' => $v['due_at'], 'recorded_by' => $current->id]);
            $this->save($child);
            return [$child, 'followup', null, true, ['recipient_id' => (int) $receiver->id]];
        });
    }

    /** Administrative transfer of incomplete named work; source content and original recorder are preserved. */
    public function reassignFollowup(SubstanceExposureRecord|int $source, int $followupId, User $actor, array $input): array
    {
        $v = ChemicalCommandValues::normalize($input, ['assigned_to' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:2000']]);
        $this->nonblank($v['reason'], 'reason');
        $v['followup_id'] = $followupId;
        return $this->command($source, $actor, 'reassign_followup', $v, function ($root, $current, $reads, $sites) use ($v, $followupId) {
            $identity = $reads->query(ChemicalExposureFollowup::query())->where('exposure_id', $root->id)
                ->whereKey($followupId)->firstOrFail(['id','exposure_id','source_schema_version','source_revision']);
            $this->assertSourceIdentityBeforeBody($root, $identity, 'followup', $reads, $current, $sites);
            $versionIdentity = $reads->query(ChemicalExposureSourceVersion::query())->where('exposure_id', $root->id)
                ->where('source_type', 'followup')->where('source_id', $identity->id)->orderByDesc('aggregate_revision')->first(['id']);
            if ($versionIdentity) { $this->admitVersionMetadata((int) $versionIdentity->id, $current, $reads, $sites); }
            $child = $reads->query(ChemicalExposureFollowup::query())->where('exposure_id', $root->id)->whereKey($identity->id)
                ->where('source_schema_version', $identity->source_schema_version)->where('source_revision', $identity->source_revision)->firstOrFail();
            $this->currentVersion($root, $child, 'followup', $reads, $current, $sites);
            if ($child->completed_at !== null) { ChemicalCommandValues::invalid('followup_id', 'Completed follow-up attribution is immutable.'); }
            if ($v['assigned_to'] === (int) $child->assigned_to) {
                ChemicalCommandValues::invalid('assigned_to', 'Choose a different eligible named assignee.');
            }
            $receiver = $this->assertFollowupAssignee($root, $v['assigned_to'], $reads);
            $before = $this->ledger->snapshot($child);
            $this->supersedePendingFollowupDeliveries($root, (int) $child->id, $current, $reads, $sites);
            $child->forceFill(['assigned_to' => (int) $receiver->id,
                'assignee_snapshot' => ['id' => (int) $receiver->id, 'name' => $receiver->name],
                'source_schema_version' => 1, 'source_revision' => $root->source_revision + 1]);
            $this->save($child);
            return [$child, 'followup', $before, true, ['reason' => $v['reason'],
                'previous_recipient_id' => (int) $before['assigned_to'], 'recipient_id' => (int) $receiver->id]];
        });
    }
    public function completeFollowup(SubstanceExposureRecord|int $source, int $followupId, User $actor, array $input): array
    {
        $v = ChemicalCommandValues::normalize($input, ['completed_at' => ['required', 'string'],
            'completion_notes' => ['required', 'string', 'max:5000']]);
        $v['completed_at'] = ChemicalCommandValues::instant($v['completed_at'], 'completed_at');
        $this->nonblank($v['completion_notes'], 'completion_notes');
        $v['followup_id'] = $followupId;
        return $this->command($source, $actor, 'complete_followup', $v, function ($root, $current, $reads, $sites) use ($v, $followupId) {
            $identity = $reads->query(ChemicalExposureFollowup::query())->where('exposure_id', $root->id)->whereKey($followupId)
                ->firstOrFail(['id','exposure_id','source_schema_version','source_revision']);
            $this->assertSourceIdentityBeforeBody($root, $identity, 'followup', $reads, $current, $sites);
            $child = $reads->query(ChemicalExposureFollowup::query())->where('exposure_id', $root->id)->whereKey($identity->id)
                ->where('source_schema_version', $identity->source_schema_version)->where('source_revision', $identity->source_revision)->firstOrFail();
            $assignmentVersion = $this->currentVersion($root, $child, 'followup', $reads, $current, $sites);
            abort_unless((int) $child->assigned_to === (int) $current->id, 403);
            $this->assertPrivateReceiver((int) $child->assigned_to, (int) $root->site_id, $reads, true);
            $assignedAt = $assignmentVersion?->recorded_at ?? $child->created_at;
            if ($assignedAt === null) { ChemicalCommandValues::invalid('completed_at', 'Assignment timing is unknown. Record a governed transfer before completing this legacy work.'); }
            if ($v['completed_at'] < $assignedAt->copy()->utc()->format('Y-m-d H:i:s.u')) {
                ChemicalCommandValues::invalid('completed_at', 'Completion cannot precede this follow-up assignment.');
            }
            $before = $this->ledger->snapshot($child);
            if ($child->completed_at !== null) {
                ChemicalCommandValues::invalid('completed_at', 'This retained completion cannot be overwritten.');
            }
            $child->forceFill(['completed_at' => $v['completed_at'], 'completed_by' => $current->id,
                'completion_notes' => $v['completion_notes'], 'source_schema_version' => 1, 'source_revision' => $root->source_revision + 1]);
            $this->save($child);
            return [$child, 'followup', $before, true, [
                'assignment_attribution_state' => $assignmentVersion === null ? 'legacy_unknown' : 'recorded',
                'completion_attribution_state' => 'recorded']];
        });
    }

    /** Exact fresh source proof for derived readers; no narrative, file, actor-name or copied command body escapes. */
    public function sourceProof(SubstanceExposureRecord|int $source, User $current, CurrentAuthorizationReads $reads, array $sites): array
    {
        $root = $this->read->assertExposure($source, $current, $reads, $sites);
        try { $version = $this->currentVersion($root, $root, 'exposure', $reads, $current, $sites); }
        catch (LogicException) { return ['attribution_state' => 'repair_required', 'source_id' => (int) $root->id]; }
        $context = $version ? $root->original_context : null;
        if ($context !== null && (! is_array($context) || ($context['site_id'] ?? null) !== (int) $root->site_id
            || ($context['incident_id'] ?? null) !== $root->related_incident_id)) {
            return ['attribution_state' => 'repair_required', 'source_id' => (int) $root->id];
        }
        $recordedContext = is_array($context) && ($context['schema_version'] ?? null) === 1;
        if ($context !== null) {
            try { $this->assertOriginalContext($context, $current, $reads, (int) $root->site_id); }
            catch (LogicException) { return ['attribution_state' => 'repair_required', 'source_id' => (int) $root->id]; }
        }
        $legacyPointer = ! $recordedContext && $root->related_incident_id !== null;
        return ['attribution_state' => $version ? 'recorded' : 'legacy_unknown', 'source_id' => (int) $root->id,
            'source_revision' => (int) $root->source_revision, 'source_version_id' => $version?->id,
            'substance_id' => (int) $root->hazardous_substance_id, 'worker_id' => (int) $root->user_id, 'site_id' => (int) $root->site_id,
            'event_category' => 'exposure', 'asset_id' => null,
            'incident_id' => $recordedContext ? $context['incident_id'] : null,
            'incident_binding' => $recordedContext ? ($context['incident_id'] === null ? 'absent' : 'recorded') : ($legacyPointer ? 'unknown' : 'absent'),
            'client_id' => $recordedContext ? $context['client_id'] : null,
            'client_binding' => $recordedContext ? ($context['client_id'] === null ? 'absent' : 'recorded') : 'unknown',
            'shift_id' => $recordedContext ? $context['shift_id'] : null,
            'shift_binding' => $recordedContext ? ($context['shift_id'] === null ? 'absent' : 'recorded') : 'unknown',
            'service_context_id' => $recordedContext ? $context['service_context_id'] : null,
            'stay_id' => $recordedContext ? $context['stay_id'] : null,
            'context_binding' => $recordedContext ? 'recorded' : 'unknown'];
    }

    /** Admit every original no-op context; only current schema0 identity must equal the latest accepted no-op. */
    private function assertLegacyNoopReceiptIdentity(SubstanceExposureRecord $identity, User $current,
        CurrentAuthorizationReads $reads, array $sites, string $purpose = 'read'): void
    {
        $latest = null;
        $receipts = $reads->query(ChemicalExposureCommandReceipt::query())->where('exposure_id', $identity->id)
            ->whereNull('source_version_id')->orderBy('id')->get(['id','source_type','source_id','command']);
        foreach ($receipts as $receiptIdentity) {
            if ($receiptIdentity->source_type !== 'exposure' || $receiptIdentity->source_id !== (int) $identity->id
                || ! in_array($receiptIdentity->command, ChemicalExposureLedger::NOOP_COMMANDS, true)) {
                ChemicalCommandValues::invalid('source', 'Unversioned receipt does not own a legitimate unchanged root command.');
            }
            $binding = $this->admitReceiptMetadata((int) $receiptIdentity->id, $current, $reads, $sites, $purpose);
            if (! is_array($binding['before']) || ChemicalCommandValues::digest($binding['before']) !== ChemicalCommandValues::digest($binding['after'])) {
                ChemicalCommandValues::invalid('source', 'Retained unchanged legacy command cannot introduce a different source identity.');
            }
            foreach (['before','after'] as $side) {
                $tuple = $binding[$side];
                if ($tuple !== null && $tuple['related_incident_id'] !== null && $tuple['original_context'] === null) {
                    ChemicalCommandValues::invalid('source', 'Retained linked legacy no-op origin is unproved. Clearing today\'s pointer does not authorise its sealed facts.');
                }
            }
            $latest = $binding;
        }
        if ($latest !== null && $identity->source_schema_version === 0 && $identity->source_revision === 0) {
            $actual = ['exposure_id' => (int) $identity->id, 'substance_id' => (int) $identity->hazardous_substance_id,
                'worker_id' => (int) $identity->user_id, 'site_id' => (int) $identity->site_id,
                'related_incident_id' => $identity->related_incident_id, 'original_context' => $identity->original_context];
            if (ChemicalCommandValues::digest($latest['after']) !== ChemicalCommandValues::digest($actual)) {
                ChemicalCommandValues::invalid('source', 'Current legacy identity differs from its latest accepted unchanged source tuple.');
            }
        }
    }

    /** Metadata-only admission for callers BEFORE child notes, names or private file paths are hydrated. */
    public function assertSourceIdentityBeforeBody(SubstanceExposureRecord $root, Model $identity, string $kind,
        CurrentAuthorizationReads $reads, User $current, array $sites, string $purpose = 'read'): void
    {
        $reads->assertActive();
        if (! in_array($purpose, ['read', 'capture'], true)) { throw new LogicException('Unknown exposure proof purpose.'); }
        $this->access->assertOperation($current, $reads, $purpose);
        $classes = ['exposure' => SubstanceExposureRecord::class, 'review' => ChemicalExposureReview::class,
            'followup' => ChemicalExposureFollowup::class, 'evidence' => \App\Models\ChemicalExposureEvidence::class];
        if (! isset($classes[$kind]) || $identity::class !== $classes[$kind]
            || ! $root->exists || ! $identity->exists) { throw new LogicException('Exposure proof requires an exact persisted source class and kind.'); }
        $this->access->id($root); $this->access->id($identity);
        $this->access->site((int) $root->site_id, $reads, $sites);
        // Bounded identity only: an in-memory parent rebind cannot turn a foreign legacy root into this Site's source.
        $canonical = $reads->query(SubstanceExposureRecord::query())->whereIn('site_id', $sites)->whereKey($root->id)
            ->firstOrFail(self::BODY_ADMISSION_FIELDS);
        $this->access->site((int) $canonical->site_id, $reads, $sites);
        $fields = array_flip(self::BODY_ADMISSION_FIELDS);
        if (ChemicalCommandValues::digest(array_intersect_key($canonical->attributesToArray(), $fields))
            !== ChemicalCommandValues::digest(array_intersect_key($root->attributesToArray(), $fields))) {
            throw new LogicException('Exposure parent identity changed before source provenance admission.');
        }
        $this->assertLegacyNoopReceiptIdentity($canonical, $current, $reads, $sites, $purpose);
        // ANY kind/ID retains its original aggregate, even if current markers/parent were externally rebound.
        $versions = $reads->query(ChemicalExposureSourceVersion::query())->where('source_type', $kind)->where('source_id', $identity->id);
        $receipts = $reads->query(ChemicalExposureCommandReceipt::query())->where('source_type', $kind)->where('source_id', $identity->id);
        if ($kind === 'exposure') {
            if ((int) $identity->id !== (int) $root->id
                || ChemicalCommandValues::digest(array_intersect_key($identity->attributesToArray(), $fields))
                    !== ChemicalCommandValues::digest(array_intersect_key($root->attributesToArray(), $fields))) {
                throw new LogicException('Exposure proof does not own this root identity.');
            }
            if ((clone $versions)->where('exposure_id', '!=', $root->id)->exists()
                || (clone $receipts)->where('exposure_id', '!=', $root->id)->exists()) {
                throw new LogicException('Exposure root retains governed ownership under another aggregate.');
            }
            if ($identity->source_schema_version === 0 && $identity->source_revision === 0) {
                $rootReceipts = $reads->query(ChemicalExposureCommandReceipt::query())->where('exposure_id', $root->id);
                if ((clone $versions)->exists() || (clone $rootReceipts)->whereNotNull('source_version_id')->exists()) {
                    throw new LogicException('Retained governed root cannot be relabelled as legacy.');
                }
            }
            return;
        }
        if ($purpose !== 'read') { throw new LogicException('Capture authority cannot admit saved private child provenance.'); }
        $class = $classes[$kind];
        $query = $kind === 'evidence' ? $class::withTrashed() : $class::query();
        $child = $reads->query($query)->whereKey($identity->id)->firstOrFail(['id','exposure_id','source_schema_version','source_revision']);
        if ((int) $child->exposure_id !== (int) $root->id || (int) $identity->exposure_id !== (int) $root->id
            || $child->source_schema_version !== $identity->source_schema_version || $child->source_revision !== $identity->source_revision) {
            throw new LogicException('Exposure child identity does not belong to this exact current parent.');
        }
        if ((clone $versions)->where('exposure_id', '!=', $root->id)->exists()
            || (clone $receipts)->where('exposure_id', '!=', $root->id)->exists()) {
            throw new LogicException('Exposure child retains governed ownership under another original aggregate.');
        }
        $retainedVersion = (clone $versions)->exists();
        $retained = $retainedVersion || (clone $receipts)->exists();
        // ANY retained identity is examined before either root or child can be admitted as legacy.
        // No historical Site, actor, content, UUID or file path is hydrated by this refusal check.
        if ($child->source_schema_version === 0 && $child->source_revision === 0) {
            if ($retained) { throw new LogicException('Retained governed child cannot be relabelled as legacy.'); }
            return; // Genuine canonical schema0/revision0 child with no governed history remains unknown.
        }
        if ($child->source_schema_version !== 1 || $child->source_revision < 1 || ! $retainedVersion) {
            throw new LogicException('Exposure child provenance markers or original history require repair.');
        }
    }

    /** Private internal proof. Existing changed siblings keep their accepted child revision after a later aggregate advance. */
    public function currentVersion(SubstanceExposureRecord $root, Model $source, string $kind,
        CurrentAuthorizationReads $reads, User $current, array $sites, string $purpose = 'read'): ?ChemicalExposureSourceVersion
    {
        $reads->assertActive();
        if (! in_array($purpose, ['read', 'capture'], true)) { throw new LogicException('Unknown exposure proof purpose.'); }
        $this->access->assertOperation($current, $reads, $purpose);
        $this->access->site((int) $root->site_id, $reads, $sites);
        $this->assertSourceIdentityBeforeBody($root, $source, $kind, $reads, $current, $sites, $purpose);
        $rootVersions = $reads->query(ChemicalExposureSourceVersion::query())->where('exposure_id', $root->id);
        $retained = (clone $rootVersions)->exists();
        if ($root->source_schema_version === 0 && $root->source_revision === 0 && ! $retained) {
            if ($kind !== 'exposure' && (int) $source->source_schema_version !== 0) {
                throw new LogicException('Typed exposure child has no aggregate provenance.');
            }
            return null;
        }
        if ($root->source_schema_version !== 1 || $root->source_revision < 1) {
            throw new LogicException('Exposure source provenance markers were downgraded.');
        }
        $latestIdentity = (clone $rootVersions)->orderByDesc('aggregate_revision')->first(['id']);
        if (! $latestIdentity) { throw new LogicException('Exposure latest version is missing.'); }
        $admitted = $this->admitVersionMetadata((int) $latestIdentity->id, $current, $reads, $sites, $purpose);
        // Admit every retained reported-origin Site/context before the latest private contract/body is loaded.
        foreach ((clone $rootVersions)->whereJsonContainsKey('content->facts->reported_facts')->get(['id']) as $reportedIdentity) {
            $this->admitVersionMetadata((int) $reportedIdentity->id, $current, $reads, $sites, $purpose);
        }
        $latest = (clone $rootVersions)->whereKey($latestIdentity->id)->first();
        $this->assertAdmittedMetadata($latest, $admitted);
        $this->assertCurrentVersion($latest, $root, 'root_after', $reads, $current, $sites, $purpose);
        $this->reportedContractInReads($root, $latest, $current, $reads, $sites, $purpose);
        if ($kind === 'exposure') { return $latest; }
        if ((int) $source->exposure_id !== (int) $root->id) { throw new LogicException('Exposure child ownership conflicts.'); }
        $childVersions = $reads->query(ChemicalExposureSourceVersion::query())->where('exposure_id', $root->id)
            ->where('source_type', $kind)->where('source_id', $source->id);
        if ((int) $source->source_schema_version === 0 && (int) $source->source_revision === 0 && ! (clone $childVersions)->exists()) { return null; }
        if ((int) $source->source_schema_version !== 1) { throw new LogicException('Exposure child markers were downgraded.'); }
        $childIdentity = (clone $childVersions)->orderByDesc('aggregate_revision')->first(['id']);
        if (! $childIdentity) { throw new LogicException('Exposure child version is missing.'); }
        $admitted = $this->admitVersionMetadata((int) $childIdentity->id, $current, $reads, $sites, $purpose);
        $v = (clone $childVersions)->whereKey($childIdentity->id)->first();
        $this->assertAdmittedMetadata($v, $admitted);
        $this->assertCurrentVersion($v, $source, 'source_after', $reads, $current, $sites, $purpose);
        if ($v->worker_id !== (int) $root->user_id || $v->site_id !== (int) $root->site_id
            || $v->substance_id !== (int) $root->hazardous_substance_id) {
            throw new LogicException('Exposure immutable child tuple conflicts with its parent.');
        }
        return $v;
    }

    /** Same-active-callback source proof for private audit subjects. Audit authority does not become recovery ownership. */
    public function sourceProofForAuditSubject(string $classOrMorph, int $id, User $current,
        CurrentAuthorizationReads $reads, array $sites): ?array
    {
        $this->read->assertRead($current, $reads);
        if ($id < 1) { return null; }
        $kinds = [SubstanceExposureRecord::class => 'exposure', ChemicalExposureSourceVersion::class => 'version',
            ChemicalExposureCommandReceipt::class => 'receipt', ChemicalExposureReview::class => 'review',
            ChemicalExposureFollowup::class => 'followup', \App\Models\ChemicalExposureEvidence::class => 'evidence',
            \App\Models\ChemicalExposureEvidenceStaging::class => 'staging',
            \App\Models\ChemicalExposureEvidenceCleanup::class => 'cleanup',
            ChemicalExposureSourceDelivery::class => 'delivery', ChemicalCommandIntent::class => 'intent'];
        $class = null;
        foreach ($kinds as $candidate => $kind) {
            if (in_array($classOrMorph, [$candidate, (new $candidate)->getMorphClass()], true)) { $class = $candidate; break; }
        }
        if ($class === null) { return null; }
        $kind = $kinds[$class];
        if ($kind === 'exposure') { return $this->sourceProof($id, $current, $reads, $sites); }
        if ($kind === 'intent') {
            // Admission uses identity only. Shared intent actor snapshots and fingerprints are private body.
            $identity = $reads->query(ChemicalCommandIntent::query())->select(['id', 'source_id', 'parent_id'])
                ->where('source_type', 'exposure')->whereKey($id)->first();
            if (! $identity) { return null; }
            $rootId = $identity->source_id;
            if ($rootId === null) {
                $receiptIdentity = $reads->query(ChemicalExposureCommandReceipt::query())->select(['exposure_id'])
                    ->where('intent_id', $identity->id)->first();
                $rootId = $receiptIdentity?->exposure_id;
            }
            if ($rootId === null) { return null; }
            $root = $this->read->assertExposure((int) $rootId, $current, $reads, $sites);
            $proof = $this->sourceProof($root, $current, $reads, $sites);
            if ($proof['attribution_state'] === 'repair_required') { return $proof; }
            try {
                $receiptIdentity = $reads->query(ChemicalExposureCommandReceipt::query())->select(['id', 'exposure_id', 'source_version_id'])
                    ->where('intent_id', $identity->id)->where('exposure_id', $root->id)->first();
                if ($receiptIdentity) { $this->admitReceiptMetadata((int) $receiptIdentity->id, $current, $reads, $sites); }
                if ($receiptIdentity?->source_version_id !== null) {
                    $this->admitVersionMetadata((int) $receiptIdentity->source_version_id, $current, $reads, $sites);
                }
                $intent = $reads->query(ChemicalCommandIntent::query())->where('source_type', 'exposure')->whereKey($id)->firstOrFail();
                if ($intent->parent_id !== (int) $root->hazardous_substance_id) {
                    throw new LogicException('Exposure audit intent parent conflicts.');
                }
                $receipt = $reads->query(ChemicalExposureCommandReceipt::query())->where('intent_id', $intent->id)
                    ->where('exposure_id', $root->id)->first();
                if ($receipt) {
                    $this->assertHistoricalAcceptanceSite($receipt, $current, $reads, $sites);
                    $this->ledger->assertAcceptance($receipt, $intent, $root->getMorphClass(), $reads, $current, $sites);
                } else {
                    $stage = $reads->query(\App\Models\ChemicalExposureEvidenceStaging::query())->select(['id'])
                        ->where('exposure_id', $root->id)->where('intent_id', $intent->id)->first();
                    if (! $stage || ! app(ChemicalExposureEvidenceService::class)
                        ->auditSubjectIsProven('staging', (int) $stage->id, $root, $current, $reads, $sites)) {
                        return null;
                    }
                }
                return $proof;
            } catch (LogicException) {
                return ['attribution_state' => 'repair_required', 'source_id' => (int) $root->id];
            }
        }
        $q = $class === \App\Models\ChemicalExposureEvidence::class ? $class::withTrashed() : $class::query();
        $identity = $reads->query($q)->select(['id', 'exposure_id'])->whereIn('exposure_id',
            $this->read->readableQuery($current, $reads, $sites)->select('substance_exposure_records.id'))->whereKey($id)->first();
        if (! $identity) { return null; }
        $root = $this->read->assertExposure((int) $identity->exposure_id, $current, $reads, $sites);
        $proof = $this->sourceProof($root, $current, $reads, $sites);
        if ($proof['attribution_state'] === 'repair_required') { return $proof; }
        try {
            if (in_array($kind, ['staging', 'cleanup'], true)) {
                if (! app(ChemicalExposureEvidenceService::class)->auditSubjectIsProven($kind, $id, $root, $current, $reads, $sites)) {
                    throw new LogicException('Exposure recovery audit subject is unconfirmed.');
                }
                return $proof;
            }
            if ($kind === 'version') {
                $versionIdentity = $reads->query(ChemicalExposureSourceVersion::query())->select(['id', 'site_id'])
                    ->where('exposure_id', $root->id)->whereKey($id)->firstOrFail();
                $this->access->site((int) $versionIdentity->site_id, $reads, $sites);
                $admittedVersion = $this->admitVersionMetadata((int) $versionIdentity->id, $current, $reads, $sites);
            }
            if ($kind === 'delivery') {
                $deliveryIdentity = $reads->query(ChemicalExposureSourceDelivery::query())->select(['id', 'exposure_id', 'source_version_id'])
                    ->where('exposure_id', $root->id)->whereKey($id)->firstOrFail();
                $this->admitVersionMetadata((int) $deliveryIdentity->source_version_id, $current, $reads, $sites);
            }
            if ($kind === 'receipt') {
                $receiptIdentity = $reads->query(ChemicalExposureCommandReceipt::query())->select(['id', 'exposure_id', 'source_version_id'])
                    ->where('exposure_id', $root->id)->whereKey($id)->firstOrFail();
                $this->admitReceiptMetadata((int) $receiptIdentity->id, $current, $reads, $sites);
                if ($receiptIdentity->source_version_id !== null) {
                    $this->admitVersionMetadata((int) $receiptIdentity->source_version_id, $current, $reads, $sites);
                }
            }
            $rowQuery = $reads->query($class === \App\Models\ChemicalExposureEvidence::class ? $class::withTrashed() : $class::query())
                ->where('exposure_id', $root->id)->whereKey($id);
            if (in_array($kind, ['review','followup','evidence'], true)) {
                $childIdentity = (clone $rowQuery)->firstOrFail(['id','exposure_id','source_schema_version','source_revision']);
                $this->assertSourceIdentityBeforeBody($root, $childIdentity, $kind, $reads, $current, $sites);
                $rowQuery->where('source_schema_version', $childIdentity->source_schema_version)->where('source_revision', $childIdentity->source_revision);
            }
            $row = $rowQuery->firstOrFail();
            if ($kind === 'version') {
                $this->assertAdmittedMetadata($row, $admittedVersion);
                $this->access->site((int) $row->site_id, $reads, $sites);
                $this->ledger->assertDigest($row);
                $receipt = $reads->query(ChemicalExposureCommandReceipt::query())->where('source_version_id', $row->id)->first();
                $intent = $receipt ? $reads->query(ChemicalCommandIntent::query())->whereKey($receipt->intent_id)->where('source_type', 'exposure')->first() : null;
                if (! $receipt || ! $intent) { throw new LogicException('Exposure audit version has no retained acceptance.'); }
                $this->ledger->assertAcceptance($receipt, $intent, $root->getMorphClass(), $reads, $current, $sites);
            } elseif ($kind === 'receipt') {
                $intent = $reads->query(ChemicalCommandIntent::query())->whereKey($row->intent_id)->where('source_type', 'exposure')->first();
                if (! $intent) { throw new LogicException('Exposure audit receipt has no original intent.'); }
                $this->assertHistoricalAcceptanceSite($row, $current, $reads, $sites);
                $this->ledger->assertAcceptance($row, $intent, $root->getMorphClass(), $reads, $current, $sites);
            } elseif ($kind === 'delivery') {
                $versionIdentity = $reads->query(ChemicalExposureSourceVersion::query())->select(['id', 'site_id'])
                    ->where('exposure_id', $root->id)->whereKey($row->source_version_id)->first();
                if (! $versionIdentity) { throw new LogicException('Exposure delivery original source version is missing.'); }
                $this->access->site((int) $versionIdentity->site_id, $reads, $sites);
                $admittedVersion = $this->admitVersionMetadata((int) $versionIdentity->id, $current, $reads, $sites);
                $version = $reads->query(ChemicalExposureSourceVersion::query())->where('exposure_id', $root->id)->whereKey($versionIdentity->id)->first();
                if (! $version) { throw new LogicException('Exposure delivery original source version is missing.'); }
                $this->assertAdmittedMetadata($version, $admittedVersion);
                $this->ledger->assertDigest($version);
                $receipt = $reads->query(ChemicalExposureCommandReceipt::query())->where('exposure_id', $root->id)
                    ->where('source_version_id', $version->id)->first();
                $intent = $receipt ? $reads->query(ChemicalCommandIntent::query())->where('source_type', 'exposure')->whereKey($receipt->intent_id)->first() : null;
                if (! $receipt || ! $intent) { throw new LogicException('Exposure delivery has no anchored original acceptance.'); }
                $this->ledger->assertAcceptance($receipt, $intent, $root->getMorphClass(), $reads, $current, $sites);
                if ($row->actor_id !== $version->actor_id
                    || ! in_array($row->kind, ['health_safety', 'followup'], true)
                    || ($row->kind === 'health_safety' && $row->recipient_id !== null)
                    || ($row->kind === 'followup' && (! in_array($version->command, ['assign_followup', 'reassign_followup'], true)
                        || $version->source_type !== 'followup'
                        || $row->recipient_id !== ($version->content['source_after']['assigned_to'] ?? null)
                        || $row->recipient_id !== ($version->content['facts']['recipient_id'] ?? null)))) {
                    throw new LogicException('Exposure delivery purpose or original actor is damaged.');
                }
                $after = $version->content['root_after'];
                $membership = ['exposure_id' => (int) $after['id'], 'substance_id' => (int) $after['hazardous_substance_id'],
                    'worker_id' => (int) $after['user_id'], 'site_id' => (int) $after['site_id'], 'source_revision' => (int) $after['source_revision'],
                    'source_version_id' => (int) $version->id, 'original_context' => $after['original_context'] ?? null];
                if (ChemicalCommandValues::digest($membership) !== ChemicalCommandValues::digest($row->membership)) {
                    throw new LogicException('Exposure delivery immutable membership is damaged.');
                }
            } else {
                $this->currentVersion($root, $row, $kind, $reads, $current, $sites);
            }
            return $proof;
        } catch (LogicException) {
            return ['attribution_state' => 'repair_required', 'source_id' => (int) $root->id];
        }
    }
    private function assertHistoricalAcceptanceSite(ChemicalExposureCommandReceipt $receipt, User $current,
        CurrentAuthorizationReads $reads, array $sites): void
    {
        if ($receipt->source_version_id === null) { return; }
        $identity = $reads->query(ChemicalExposureSourceVersion::query())->select(['id', 'site_id'])
            ->where('exposure_id', $receipt->exposure_id)->whereKey($receipt->source_version_id)->first();
        if (! $identity) { throw new LogicException('Exposure retained acceptance source identity is missing.'); }
        $this->access->site((int) $identity->site_id, $reads, $sites);
        $this->admitVersionMetadata((int) $identity->id, $current, $reads, $sites);
    }
    /** Receipt identity metadata is acceptance-anchored before historical UUID/result/actor bodies are admitted. */
    public function admitReceiptMetadata(int $id, User $current, CurrentAuthorizationReads $reads,
        array $sites, string $purpose = 'read'): array
    {
        $this->access->assertOperation($current, $reads, $purpose);
        $identity = $reads->query(ChemicalExposureCommandReceipt::query())->select(['id', 'exposure_id', 'audit_log_id',
            'source_tuple', 'source_tuple_digest'])->whereKey($id)->firstOrFail();
        $binding = $identity->source_tuple;
        if (! is_array($binding) || array_diff(array_keys($binding), ['before', 'after']) !== []
            || array_diff(['before', 'after'], array_keys($binding)) !== [] || ! is_array($binding['after'])
            || ($binding['before'] !== null && ! is_array($binding['before']))
            || ! is_string($identity->source_tuple_digest) || ! preg_match('/^[a-f0-9]{64}$/D', $identity->source_tuple_digest)
            || ! hash_equals($identity->source_tuple_digest, ChemicalCommandValues::digest($binding))) {
            throw new LogicException('Exposure original receipt source metadata is damaged.');
        }
        $audit = $reads->query(\App\Models\AuditLog::query())->select(['id'])
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.source_tuple_digest')) AS accepted_tuple_digest")
            ->whereKey($identity->audit_log_id)->first();
        if (! $audit || $audit->getRawOriginal('accepted_tuple_digest') !== $identity->source_tuple_digest) {
            throw new LogicException('Exposure original receipt source metadata is not acceptance-anchored.');
        }
        foreach (array_filter([$binding['before'], $binding['after']], fn ($tuple) => $tuple !== null) as $tuple) {
            $keys = ['exposure_id', 'substance_id', 'worker_id', 'site_id', 'related_incident_id', 'original_context'];
            if (array_diff($keys, array_keys($tuple)) !== [] || array_diff(array_keys($tuple), $keys) !== []
                || $tuple['exposure_id'] !== (int) $identity->exposure_id) {
                throw new LogicException('Exposure original receipt tuple is malformed.');
            }
            foreach (['exposure_id', 'substance_id', 'worker_id', 'site_id'] as $key) {
                if (! is_int($tuple[$key]) || $tuple[$key] < 1) { throw new LogicException('Exposure original receipt identity is malformed.'); }
            }
            if ($tuple['related_incident_id'] !== null && (! is_int($tuple['related_incident_id']) || $tuple['related_incident_id'] < 1)) {
                throw new LogicException('Exposure original receipt association identity is malformed.');
            }
            $this->access->site($tuple['site_id'], $reads, $sites);
            if ($tuple['original_context'] !== null) {
                if (! is_array($tuple['original_context']) || ($tuple['original_context']['incident_id'] ?? null) !== $tuple['related_incident_id']) {
                    throw new LogicException('Exposure original receipt context conflicts with its association.');
                }
                $this->assertContext($tuple['original_context'], $current, $reads, $tuple['site_id']);
            }
        }
        if ($purpose === 'capture') {
            $this->access->staff($binding['after']['worker_id'], $binding['after']['site_id'], $reads);
        }
        // Null original context is unknown, never today's Client or shift inferred from a legacy pointer.
        return $binding;
    }
    /** Read only typed historical identity/context metadata before the private immutable body. */
    public function admitVersionMetadata(int $id, User $current, CurrentAuthorizationReads $reads,
        array $sites, string $purpose = 'read'): array
    {
        $this->access->assertOperation($current, $reads, $purpose);
        $receiptIdentity = $reads->query(ChemicalExposureCommandReceipt::query())->select(['id', 'source_content_digest', 'audit_log_id'])
            ->where('source_version_id', $id)->first();
        if (! $receiptIdentity) { throw new LogicException('Exposure version has no retained acceptance identity.'); }
        $binding = $this->admitReceiptMetadata((int) $receiptIdentity->id, $current, $reads, $sites, $purpose);
        $audit = $reads->query(\App\Models\AuditLog::query())->select(['id'])
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.source_content_digest')) AS accepted_content_digest")
            ->whereKey($receiptIdentity->audit_log_id)->first();
        if (! is_string($receiptIdentity->source_content_digest) || ! preg_match('/^[a-f0-9]{64}$/D', $receiptIdentity->source_content_digest)
            || ! $audit || $audit->getRawOriginal('accepted_content_digest') !== $receiptIdentity->source_content_digest) {
            throw new LogicException('Exposure historical version digest has no strict acceptance anchor.');
        }
        $identity = $reads->query(ChemicalExposureSourceVersion::query())->select(['id', 'site_id', 'content_digest'])
            ->selectRaw("JSON_EXTRACT(content, '$.root_before.site_id') AS before_site")
            ->selectRaw("JSON_EXTRACT(content, '$.root_before.original_context') AS before_context")
            ->selectRaw("JSON_EXTRACT(content, '$.root_after.original_context') AS after_context")->whereKey($id)->firstOrFail();
        if ($identity->content_digest !== $receiptIdentity->source_content_digest) {
            throw new LogicException('Exposure historical digest differs from its original acceptance.');
        }
        $decode = fn (string $key) => json_decode($identity->getRawOriginal($key) ?? 'null', true, flags: JSON_THROW_ON_ERROR);
        $metadata = ['site_id' => (int) $identity->site_id, 'before_site' => $decode('before_site'),
            'before_context' => $decode('before_context'), 'after_context' => $decode('after_context')];
        $expectedMetadata = ['site_id' => $binding['after']['site_id'], 'before_site' => $binding['before']['site_id'] ?? null,
            'before_context' => $binding['before']['original_context'] ?? null, 'after_context' => $binding['after']['original_context']];
        if (ChemicalCommandValues::digest($metadata) !== ChemicalCommandValues::digest($expectedMetadata)) {
            throw new LogicException('Exposure historical identity metadata differs from its accepted original binding.');
        }
        $this->access->site($metadata['site_id'], $reads, $sites);
        if ($metadata['before_site'] !== null) {
            if (! is_int($metadata['before_site']) || $metadata['before_site'] < 1) { throw new LogicException('Exposure historical Site metadata is malformed.'); }
            $this->access->site($metadata['before_site'], $reads, $sites);
        }
        foreach (['before_context' => $metadata['before_site'], 'after_context' => $metadata['site_id']] as $key => $site) {
            if ($metadata[$key] !== null) {
                if (! is_array($metadata[$key]) || $site === null) { throw new LogicException('Exposure historical context metadata is malformed.'); }
                $this->assertContext($metadata[$key], $current, $reads, $site);
            }
        }
        return $metadata;
    }

    public function assertAdmittedMetadata(?ChemicalExposureSourceVersion $version, array $admitted): void
    {
        if (! $version || ChemicalCommandValues::digest($admitted) !== ChemicalCommandValues::digest([
            'site_id' => $version->site_id, 'before_site' => $version->content['root_before']['site_id'] ?? null,
            'before_context' => $version->content['root_before']['original_context'] ?? null,
            'after_context' => $version->content['root_after']['original_context'] ?? null])) {
            throw new LogicException('Exposure private body differs from its admitted historical metadata.');
        }
    }
    /** Same-active-callback original association admission; supported identity metadata only, no copied narrative. */
    public function assertOriginalContext(array $retained, User $current, CurrentAuthorizationReads $reads, int $siteId,
        string $purpose = 'read'): void
    {
        if (! in_array($purpose, ['read', 'capture'], true)) { throw new LogicException('Unknown original exposure context purpose.'); }
        if ($purpose === 'capture') { $this->access->assertOperation($current, $reads, 'capture'); }
        else { $this->read->assertRead($current, $reads); }
        $this->assertContext($retained, $current, $reads, $siteId);
    }
    private function assertContext(array $retained, User $current, CurrentAuthorizationReads $reads, int $siteId): void
    {
        $keys = ['schema_version', 'site_id', 'incident_id', 'client_id', 'shift_id', 'service_context_id', 'stay_id'];
        if (array_diff(array_keys($retained), $keys) !== [] || array_diff($keys, array_keys($retained)) !== []
            || $retained['schema_version'] !== 1 || $retained['site_id'] !== $siteId) {
            throw new LogicException('Exposure original context metadata is malformed.');
        }
        foreach (['incident_id', 'client_id', 'shift_id', 'service_context_id', 'stay_id'] as $key) {
            if ($retained[$key] !== null && (! is_int($retained[$key]) || $retained[$key] < 1)) {
                throw new LogicException('Exposure original context identifier is malformed.');
            }
        }
        $actual = $this->originalContext($retained['incident_id'], $siteId, $current, $reads);
        if (ChemicalCommandValues::digest($actual) !== ChemicalCommandValues::digest($retained)) {
            throw new LogicException('Exposure original context differs from its current canonical source.');
        }
    }
    public function confirmationForKey(User $actor, string $key, ?int $exposureId = null): ?array
    {
        if (! Str::isUuid($key)) { return null; }
        $key = strtolower($key);
        return $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($key, $exposureId) {
            // Unknown record recovery is capture-only, without saved medical read.
            $captureOnly = $exposureId === null || ! $this->read->canRead($current);
            $this->access->assertOperation($current, $reads, $captureOnly ? 'capture' : 'read');
            $identity = $reads->query(ChemicalExposureCommandReceipt::query())->select(['id', 'exposure_id', 'intent_id', 'command'])
                ->where('actor_id', $current->id)->where('idempotency_key', $key)
                ->when($exposureId !== null, fn ($q) => $q->where('exposure_id', $exposureId))
                ->when($captureOnly, fn ($q) => $q->where('command', 'create_exposure'))->first();
            if (! $identity) { return null; }
            $intentIdentity = $reads->query(ChemicalCommandIntent::query())->select(['id', 'parent_id'])
                ->whereKey($identity->intent_id)->where('actor_id', $current->id)->where('source_type', 'exposure')->first();
            if (! $intentIdentity) { throw new LogicException('Exposure acceptance original intent is missing.'); }
            $root = $identity->command === 'create_exposure'
                ? $this->captureSource((int) $identity->exposure_id, $current, $reads, $sites, (int) $intentIdentity->parent_id)
                : $this->access->source((int) $identity->exposure_id, $current, $reads, $sites);
            $this->admitReceiptMetadata((int) $identity->id, $current, $reads, $sites, $identity->command === 'create_exposure' ? 'capture' : 'read');
            $receipt = $reads->query(ChemicalExposureCommandReceipt::query())->where('actor_id', $current->id)->whereKey($identity->id)->firstOrFail();
            $intent = $reads->query(ChemicalCommandIntent::query())->where('actor_id', $current->id)->where('source_type', 'exposure')
                ->whereKey($intentIdentity->id)->firstOrFail();
            if ($receipt->command !== 'create_exposure') { $this->assertOriginalCommand($root, $receipt, $current, $reads, $sites); }
            if ($receipt->command !== 'create_exposure') { $this->currentVersion($root, $root, 'exposure', $reads, $current, $sites); }
            return $this->ledger->reconstruct($receipt, $intent, $root, $current, $reads, $sites, $receipt->command === 'create_exposure' ? 'capture' : 'read');
        });
    }

    /** Detached private fact projection only; no saved read grant arises from capture or receipt recovery. */
    public function reportedFactsForSource(SubstanceExposureRecord|int $source, User $current,
        CurrentAuthorizationReads $reads, array $sites): array
    {
        $root = $this->read->assertExposure($this->access->id($source), $current, $reads, $sites);
        return $this->projectReportedRoot($root, $current, $reads, $sites);
    }

    /** A presenter may shape this SAME admitted model only after an exact canonical snapshot comparison. */
    public function reportedFactsForSnapshot(SubstanceExposureRecord $presented, User $current,
        CurrentAuthorizationReads $reads, array $sites): array
    {
        $root = $this->read->assertExposure($this->access->id($presented), $current, $reads, $sites);
        if (ChemicalCommandValues::digest($this->ledger->snapshot($root)) !== ChemicalCommandValues::digest($this->ledger->snapshot($presented))) {
            throw new LogicException('The presented exposure changed before its reported-fact projection.');
        }
        return $this->projectReportedRoot($presented, $current, $reads, $sites);
    }

    private function projectReportedRoot(SubstanceExposureRecord $root, User $current,
        CurrentAuthorizationReads $reads, array $sites): array
    {
        $version = $this->currentVersion($root, $root, 'exposure', $reads, $current, $sites);
        [$contract, $times] = $this->reportedContractInReads($root, $version, $current, $reads, $sites);
        $tuple = ['exposure_id' => (int) $root->id, 'worker_id' => (int) $root->user_id,
            'site_id' => (int) $root->site_id, 'substance_id' => (int) $root->hazardous_substance_id];
        $projection = ChemicalExposureReportedFacts::project($contract, $tuple);
        foreach (ChemicalExposureReportedFacts::FIELDS as $field) { $projection['fact_states'][$field]['reported_at'] = $times[$field] ?? null; }
        return [...$projection, 'response' => ChemicalExposureReportedFacts::response($contract, $tuple)];
    }

    private function reportedIdentity(SubstanceExposureRecord $root, ChemicalCommandIntent $intent, User $actor, int $revision): array
    {
        return ['intent_id' => (int) $intent->id, 'revision' => $revision, 'actor_id' => (int) $actor->id,
            'exposure_id' => (int) $root->id, 'worker_id' => (int) $root->user_id,
            'site_id' => (int) $root->site_id, 'substance_id' => (int) $root->hazardous_substance_id];
    }

    private function reportedContractInReads(SubstanceExposureRecord $root, ?ChemicalExposureSourceVersion $version,
        User $current, CurrentAuthorizationReads $reads, array $sites, string $purpose = 'read'): array
    {
        $reads->assertActive();
        $this->access->assertOperation($current, $reads, $purpose);
        $snapshot = $this->ledger->snapshot($root);
        $facts = $version?->content['facts'] ?? [];
        if (! is_array($facts)) { throw new LogicException('Exposure reported-fact container is damaged.'); }
        if (! array_key_exists('reported_facts', $facts)) {
            if ($reads->query(ChemicalExposureSourceVersion::query())->where('exposure_id', $root->id)
                ->whereJsonContainsKey('content->facts->reported_facts')->exists()) {
                throw new LogicException('Retained reported facts cannot be downgraded to an unconfirmed legacy map.');
            }
            return [ChemicalExposureReportedFacts::legacy($snapshot), []];
        }
        if (! is_array($facts['reported_facts'])) { throw new LogicException('Exposure reported-fact contract is damaged.'); }
        $contract = $facts['reported_facts'];
        ChemicalExposureReportedFacts::assertContract($contract, $snapshot);
        $times = []; $accepted = [];
        foreach (ChemicalExposureReportedFacts::FIELDS as $field) {
            $entry = $contract[$field]; $evidence = $entry['reported_in'];
            if ($evidence === null) { continue; }
            if ($evidence['exposure_id'] !== (int) $root->id || $evidence['revision'] > $version->aggregate_revision) {
                throw new LogicException('Reported fact does not belong to this accepted source history.');
            }
            if (! isset($accepted[$evidence['revision']])) {
                $identity = $reads->query(ChemicalExposureSourceVersion::query())->select(['id'])
                    ->where('exposure_id', $root->id)->where('aggregate_revision', $evidence['revision'])->first();
                if (! $identity) { throw new LogicException('Reported fact original accepted version is missing.'); }
                $metadata = $this->admitVersionMetadata((int) $identity->id, $current, $reads, $sites, $purpose);
                $original = $reads->query(ChemicalExposureSourceVersion::query())->where('exposure_id', $root->id)
                    ->where('aggregate_revision', $evidence['revision'])->whereKey($identity->id)->firstOrFail();
                $this->assertAdmittedMetadata($original, $metadata);
                $receipt = $reads->query(ChemicalExposureCommandReceipt::query())->where('source_version_id', $original->id)->first();
                $intent = $receipt ? $reads->query(ChemicalCommandIntent::query())->where('source_type', 'exposure')->whereKey($receipt->intent_id)->first() : null;
                if (! $receipt || ! $intent) { throw new LogicException('Reported fact original acceptance is missing.'); }
                $this->ledger->assertAcceptance($receipt, $intent, $root->getMorphClass(), $reads, $current, $sites, $purpose);
                if (! in_array($original->command, ['create_exposure', 'correct_exposure'], true)) {
                    throw new LogicException('A child command cannot claim an original reported answer.');
                }
                $originalContract = $original->content['facts']['reported_facts'] ?? null;
                if (! is_array($originalContract)) { throw new LogicException('Reported fact original field contract is missing.'); }
                ChemicalExposureReportedFacts::assertContract($originalContract, $original->content['root_after']);
                $accepted[$evidence['revision']] = $original;
            }
            $original = $accepted[$evidence['revision']];
            $originalIdentity = ['intent_id' => (int) $original->intent_id, 'revision' => (int) $original->aggregate_revision,
                'actor_id' => (int) $original->actor_id, 'exposure_id' => (int) $original->exposure_id,
                'worker_id' => (int) $original->worker_id, 'site_id' => (int) $original->site_id, 'substance_id' => (int) $original->substance_id];
            if (ChemicalCommandValues::digest($evidence) !== ChemicalCommandValues::digest($originalIdentity)
                || ChemicalCommandValues::digest($entry) !== ChemicalCommandValues::digest($original->content['facts']['reported_facts'][$field])) {
                throw new LogicException('Reported field differs from its ORIGINAL accepted actor, tuple or value.');
            }
            $times[$field] = $original->recorded_at->copy()->utc()->toIso8601String();
        }
        return [$contract, $times];
    }

    public function command(SubstanceExposureRecord|int $source, User $actor, string $command, array $v, callable $mutate, ?callable $afterAcceptance = null): array
    {
        if (! in_array($command, ['correct_exposure', 'review_exposure', 'assign_followup', 'reassign_followup', 'complete_followup',
            'upload_evidence', 'remove_evidence'], true)) { throw new LogicException('This private command is not an allocated exposure operation.'); }
        return $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($source, $actor, $command, $v, $mutate, $afterAcceptance) {
            $root = $this->access->source($source, $current, $reads, $sites);
            $previousVersion = $this->currentVersion($root, $root, 'exposure', $reads, $current, $sites);
            [$priorReported] = $this->reportedContractInReads($root, $previousVersion, $current, $reads, $sites);
            $intent = $this->ledger->claim($current, $reads, (int) $root->id, (int) $root->hazardous_substance_id, $command, $v, $sites);
            if ($receipt = $this->ledger->receipt($intent, $reads)) {
                $this->assertOriginalCommand($root, $receipt, $current, $reads, $sites);
                $result = $this->ledger->reconstruct($receipt, $intent, $root, $current, $reads, $sites);
                if ($afterAcceptance) { $afterAcceptance($root, $current, $reads, $result, $sites); }
                return $result;
            }
            if ($root->source_revision !== $v['expected_version']) {
                ChemicalCommandValues::invalid('expected_version', 'This private source changed. Review current work while retaining your entries.');
            }
            $before = $this->ledger->snapshot($root);
            [$child, $kind, $childBefore, $changed, $facts] = $mutate($root, $current, $reads, $sites);
            $reported = ChemicalExposureReportedFacts::transition($priorReported, $before, $this->ledger->snapshot($root),
                $command === 'correct_exposure' ? array_intersect_key($v, array_flip(ChemicalExposureReportedFacts::FIELDS)) : [],
                $this->reportedIdentity($root, $intent, $current, (int) $root->source_revision + 1));
            $changed = $changed || $reported['provenance_changed'];
            if ($changed) {
                $facts['reported_facts'] = $reported['contract'];
                $root->forceFill(['source_schema_version' => 1, 'source_revision' => $root->source_revision + 1, 'updated_by' => $current->id]);
                $this->save($root);
                $version = $this->ledger->version($intent, $root, $child, $kind, $current, $reads, $before, $childBefore, $facts);
            } else { $version = null; }
            $result = $this->ledger->accept($intent, $root, $child, $kind, $version, $current, $reads, $changed, $sites);
            if ($afterAcceptance) { $afterAcceptance($root, $current, $reads, $result, $sites); }
            if ($version) {
                $this->stageDelivery($root, $version, $current, 'health_safety');
                if (in_array($command, ['assign_followup', 'reassign_followup'], true)) {
                    $this->stageDelivery($root, $version, $current, 'followup', $facts['recipient_id'] ?? null);
                }
            }
            return $result;
        });
    }

    public function assertOriginalCommand(SubstanceExposureRecord $root, ChemicalExposureCommandReceipt $receipt,
        User $current, CurrentAuthorizationReads $reads, array $sites): void
    {
        $this->access->assertOperation($current, $reads, 'manage');
        if ($receipt->command === 'complete_followup') {
            $child = $reads->query(ChemicalExposureFollowup::query())->where('exposure_id', $root->id)->whereKey($receipt->source_id)->firstOrFail();
            abort_unless((int) $child->assigned_to === (int) $current->id, 403);
            $this->assertPrivateReceiver((int) $child->assigned_to, (int) $root->site_id, $reads, true);
        }
        if (in_array($receipt->command, ['assign_followup', 'reassign_followup'], true)) {
            if ($receipt->source_version_id === null) { throw new LogicException('Named assignment requires an accepted source version.'); }
            $admitted = $this->admitVersionMetadata($receipt->source_version_id, $current, $reads, $sites);
            $version = $reads->query(ChemicalExposureSourceVersion::query())->where('exposure_id', $root->id)
                ->where('source_type', 'followup')->where('source_id', $receipt->source_id)->whereKey($receipt->source_version_id)->firstOrFail();
            $this->assertAdmittedMetadata($version, $admitted);
            $recipient = $version->content['facts']['recipient_id'] ?? null;
            if (! is_int($recipient) || $recipient !== ($version->content['source_after']['assigned_to'] ?? null)) {
                throw new LogicException('Original named assignment recipient is unconfirmed.');
            }
            // Re-prove the ORIGINAL accepted receiver, never substitute a subsequent assignee on retry.
            $this->assertFollowupAssignee($root, $recipient, $reads, $receipt->source_version_id);
        }
    }

    public function captureSource(int $id, User $current, CurrentAuthorizationReads $reads, array $sites, int $substanceId): SubstanceExposureRecord
    {
        $this->access->assertOperation($current, $reads, 'capture');
        $root = $reads->query(SubstanceExposureRecord::query())->select(['id', 'hazardous_substance_id', 'user_id', 'site_id',
            'created_by', 'source_schema_version', 'source_revision'])->where('created_by', $current->id)
            ->where('hazardous_substance_id', $substanceId)->whereIn('site_id', $sites)
            ->whereHas('site', fn ($q) => $q->active()->notArchived()->whereNull('archived_at'))->whereKey($id)->firstOrFail();
        $this->access->site((int) $root->site_id, $reads, $sites);
        $this->access->substance($substanceId, $reads);
        $this->access->staff((int) $root->user_id, (int) $root->site_id, $reads);
        return $root;
    }

    public function save(Model $row): void
    {
        // Check submitted intent before save listeners; defaults/null columns are read from the real persisted row afterward.
        $expected = $this->ledger->snapshot($row);
        if (! $row->save()) { throw new LogicException('Exposure source save was vetoed.'); }
        $stored = $row instanceof \App\Models\ChemicalExposureEvidence ? $row::withTrashed()->whereKey($row->id)->lockForUpdate()->first()
            : $row::query()->whereKey($row->id)->lockForUpdate()->first();
        if (! $stored || (int) $stored->id !== (int) $row->id
            || ChemicalCommandValues::digest(array_intersect_key($this->ledger->snapshot($stored), $expected)) !== ChemicalCommandValues::digest($expected)) {
            throw new LogicException('Exposure source facts were not persisted exactly.');
        }
        $row->setRawAttributes($stored->getAttributes(), true);
    }

    private function assertCurrentVersion(?ChemicalExposureSourceVersion $v, Model $source, string $key, CurrentAuthorizationReads $reads, User $current, array $sites, string $purpose): void
    {
        if (! $v || $v->aggregate_revision !== (int) $source->source_revision) { throw new LogicException('Exposure current source version is missing.'); }
        $this->ledger->assertDigest($v);
        if (ChemicalCommandValues::digest($v->content[$key] ?? null) !== ChemicalCommandValues::digest($this->ledger->snapshot($source))) {
            throw new LogicException('Exposure current source facts differ from the immutable acceptance.');
        }
        $receipt = $reads->query(ChemicalExposureCommandReceipt::query())->where('source_version_id', $v->id)->first();
        $intent = $receipt ? $reads->query(ChemicalCommandIntent::query())->whereKey($receipt->intent_id)
            ->where('source_type', 'exposure')->first() : null;
        if (! $receipt || ! $intent) { throw new LogicException('Exposure current source has no retained acceptance.'); }
        $this->ledger->assertAcceptance($receipt, $intent, (new SubstanceExposureRecord)->getMorphClass(), $reads, $current, $sites, $purpose);
    }

    private function values(array $input, bool $create): array
    {
        $prefix = $create ? 'required' : 'sometimes';
        $v = ChemicalCommandValues::normalize($input, [
            'user_id' => [$prefix, 'integer', 'min:1'], 'site_id' => [$prefix, 'integer', 'min:1'],
            'exposed_at' => [$prefix, 'string'], 'exposure_type' => [$prefix, 'string', 'in:inhalation,skin_contact,eye_contact,ingestion,injection,other'],
            'exposure_duration' => ['sometimes', 'nullable', 'string', 'max:255'], 'circumstances' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'symptoms' => ['sometimes', 'nullable', 'string', 'max:2000'], 'first_aid_given' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'medical_treatment' => ['sometimes', 'nullable', 'string', 'in:none,first_aid,medical,hospitalisation,death'],
            'medical_attention_sought' => ['sometimes', 'nullable', 'boolean'], 'medical_outcome' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'incident_reported' => ['sometimes', 'nullable', 'boolean'], 'related_incident_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            ...($create ? [] : ['reason' => ['required', 'string', 'max:2000']])], $create);
        if (isset($v['exposed_at'])) {
            $v['exposed_at'] = ChemicalCommandValues::instant($v['exposed_at'], 'exposed_at');
            if (! str_ends_with($v['exposed_at'], '.000000')) {
                ChemicalCommandValues::invalid('exposed_at', 'Record the occurrence to the supported whole-second precision.');
            }
        }
        if (! $create) { $this->nonblank($v['reason'], 'reason'); }
        // Care sought, recorded treatment/outcome category and incident reported are independent reported facts.
        return $v;
    }

    private function payload(array $v): array
    {
        return array_diff_key($v, ['idempotency_key' => true, 'expected_version' => true, 'reason' => true]);
    }

    private function nonblank(string $text, string $field): void
    {
        if (trim($text) === '') { ChemicalCommandValues::invalid($field, 'Record a nonblank factual explanation.'); }
    }

    public function assertPrivateReceiver(int $id, int $siteId, CurrentAuthorizationReads $reads, bool $requireManage = false): User
    {
        $receiver = $this->access->staff($id, $siteId, $reads);
        $fresh = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($receiver, ChemicalExposureCommandAccess::PERMISSIONS);
        $this->read->assertRead($fresh, $reads);
        if ($requireManage) { abort_unless($fresh->canDo('hazards.manage'), 403); }
        return $fresh;
    }

    /** New assignments must be executable and independently readable; historical display never calls this gate. */
    public function assertFollowupAssignee(SubstanceExposureRecord $root, int $id, CurrentAuthorizationReads $reads,
        ?int $acceptedVersionId = null): User
    {
        $receiver = $this->assertPrivateReceiver($id, (int) $root->site_id, $reads, true);
        $this->access->withCurrentViewer($receiver, function ($current, $receiverReads, $sites) use ($root, $acceptedVersionId) {
            $proof = $this->sourceProof((int) $root->id, $current, $receiverReads, $sites);
            if ($proof['attribution_state'] === 'repair_required' || ($proof['incident_binding'] ?? null) === 'unknown'
                || ($proof['site_id'] ?? null) !== (int) $root->site_id || ($proof['worker_id'] ?? null) !== (int) $root->user_id
                || ($proof['substance_id'] ?? null) !== (int) $root->hazardous_substance_id) {
                ChemicalCommandValues::invalid('assigned_to', 'Review the original private source association before assigning this work.');
            }
            if ($acceptedVersionId !== null) { $this->admitVersionMetadata($acceptedVersionId, $current, $receiverReads, $sites); }
            return true;
        });
        return $receiver;
    }

    private function supersedePendingFollowupDeliveries(SubstanceExposureRecord $root, int $followupId,
        User $current, CurrentAuthorizationReads $reads, array $sites): void
    {
        $versions = $reads->query(ChemicalExposureSourceVersion::query())->where('exposure_id', $root->id)
            ->where('source_type', 'followup')->where('source_id', $followupId)->select('id');
        $identities = $reads->query(ChemicalExposureSourceDelivery::query())->where('exposure_id', $root->id)
            ->where('kind', 'followup')->where('state', 'pending')->whereIn('source_version_id', $versions)->get(['id']);
        foreach ($identities as $identity) {
            $proof = $this->sourceProofForAuditSubject(ChemicalExposureSourceDelivery::class, (int) $identity->id, $current, $reads, $sites);
            if (($proof['attribution_state'] ?? null) !== 'recorded') { throw new LogicException('Original pending assignment delivery is damaged.'); }
            $row = $reads->query(ChemicalExposureSourceDelivery::query())->where('exposure_id', $root->id)
                ->where('kind', 'followup')->where('state', 'pending')->whereKey($identity->id)->firstOrFail();
            $row->state = 'superseded'; $this->save($row);
        }
    }
    private function originalContext(?int $incidentId, int $siteId, User $actor, CurrentAuthorizationReads $reads): array
    {
        $context = ['schema_version' => 1, 'site_id' => $siteId, 'incident_id' => null, 'client_id' => null,
            'shift_id' => null, 'service_context_id' => null, 'stay_id' => null];
        if ($incidentId === null) { return $context; }
        $incident = app(IncidentAccessService::class)->apply(ClientIncident::query(), $actor, $reads)
            ->select(['id', 'client_id', 'site_id', 'shift_id', 'service_context_id', 'respite_stay_id'])
            ->where('site_id', $siteId)->whereKey($incidentId)->firstOrFail();
        $client = $reads->query(Client::query())->whereKey($incident->client_id)->firstOrFail(['id', 'site_id']);
        abort_unless(app(ClientPolicy::class)->viewFromCurrentEvidence($actor, $client, $reads), 403);
        if ($incident->shift_id !== null) {
            abort_unless($reads->query(Shift::query())->whereKey($incident->shift_id)->where('site_id', $siteId)
                ->where('client_id', $incident->client_id)->exists(), 404);
        }
        if ($incident->service_context_id !== null) {
            abort_unless($reads->query(ServiceContext::query())->availableToSite($siteId)
                ->where('is_active', true)->whereKey($incident->service_context_id)->exists(), 404);
        }
        if ($incident->respite_stay_id !== null) {
            abort_unless($actor->canDo('respite.viewAny'), 403);
            $request = Request::create('/');
            $request->setUserResolver(fn () => $actor);
            $stay = app(RespiteStayScope::class)->resolveAuthorizedHealthSafetyStay($request, (int) $incident->respite_stay_id, false, $reads);
            abort_unless((int) $stay->client_id === (int) $incident->client_id && app(RespiteStayScope::class)->siteId($stay) === $siteId, 404);
        }
        return ['schema_version' => 1, 'site_id' => $siteId, 'incident_id' => (int) $incident->id,
            'client_id' => (int) $incident->client_id, 'shift_id' => $incident->shift_id === null ? null : (int) $incident->shift_id,
            'service_context_id' => $incident->service_context_id === null ? null : (int) $incident->service_context_id,
            'stay_id' => $incident->respite_stay_id === null ? null : (int) $incident->respite_stay_id];
    }

    private function stageDelivery(SubstanceExposureRecord $root, ChemicalExposureSourceVersion $version, User $actor,
        string $kind, ?int $recipient = null): void
    {
        $delivery = new ChemicalExposureSourceDelivery(['exposure_id' => $root->id, 'source_version_id' => $version->id,
            'actor_id' => $actor->id, 'kind' => $kind, 'recipient_id' => $recipient,
            'membership' => ['exposure_id' => (int) $root->id, 'substance_id' => (int) $root->hazardous_substance_id,
                'worker_id' => (int) $root->user_id, 'site_id' => (int) $root->site_id, 'source_revision' => (int) $root->source_revision,
                'source_version_id' => (int) $version->id, 'original_context' => $root->original_context],
            'state' => 'pending', 'attempts' => 0]);
        $this->save($delivery);
    }
}
