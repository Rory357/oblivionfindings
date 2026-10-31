<?php

namespace App\Services\Incidents;

use App\Domain\SecurityDevices\Cctv\Services\CctvRecordingReferences;
use App\Domain\SecurityDevices\Cctv\Services\CctvAuthority;
use App\Domain\SecurityDevices\Cctv\Models\CctvSource;
use App\Models\{AuditLog, ClientIncident, IncidentEvidenceAggregate, IncidentEvidenceRevision, IncidentEvidenceWindow, IncidentEvidenceWindowSource, IncidentEvidenceWindowCommandReceipt, IncidentRecordingAttachment, IncidentRecordingCommandReceipt, User};
use App\Services\{AuditLogger, AuthorizationEvidenceLockService, CurrentAuthorizationReads};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** NEW reconstruction commands only; no existing incident writer, route or Devices mutation is called. */
final class IncidentRecordingCommandService
{
    public const ATTACH_PERMISSION = 'incidents.evidence.attach';
    public const WINDOW_PERMISSION = 'incidents.evidence.window.record';
    public const EXPAND_PERMISSION = 'incidents.evidence.window.expand';

    public function __construct(private readonly IncidentAccessService $access,
        private readonly IncidentIndependenceIntakeService $independence,
        private readonly IncidentOwnerEligibility $eligibility,
        private readonly AuthorizationEvidenceLockService $authorization,
        private readonly IncidentEvidenceWindowPolicySettings $policy,
        private readonly CctvRecordingReferences $references) {}

    /** Transport maps created=true to201, exact authorized replay to200; response is the approved public DTO. */
    public function attach(Request $request, int $incidentId, array $input): array
    {
        $input = IncidentRecordingReconstructionValues::attachInput($input);
        return $this->withinIncident($request, $incidentId, function (User $actor, CurrentAuthorizationReads $reads, Builder $parent, ClientIncident $incident) use ($input): array {
            abort_unless($input['expected_actor_id'] === (int) $actor->id, 403);
            $fingerprint = IncidentRecordingReconstructionValues::attachFingerprint((int) $actor->id, (int) $incident->id, $input);
            $aggregate = IncidentEvidenceAggregate::query()->whereKey($incident->id)->lockForUpdate()->first();
            if ($aggregate !== null) { $this->assertAggregate($aggregate, $reads); }
            $otherKind = $reads->query(IncidentEvidenceWindowCommandReceipt::query()->where('idempotency_key', $input['idempotency_key']))->first(['actor_user_id']);
            if ($otherKind !== null) {
                abort_unless((int) $otherKind->actor_user_id === (int) $actor->id, 404);
                abort(409, 'context_changed');
            }
            $prior = $reads->query(IncidentRecordingCommandReceipt::query()->where('idempotency_key', $input['idempotency_key']))->first();
            if ($prior !== null) {
                abort_unless((int) $prior->actor_user_id === (int) $actor->id, 404);
                abort_unless((int) $prior->incident_id === (int) $incident->id && hash_equals($prior->fingerprint, $fingerprint), 409, 'context_changed');
                abort_unless($aggregate !== null, 404);
                return ['created' => false, 'response' => $this->replay($actor, $reads, $parent, $incident, $aggregate, $prior, $input)];
            }
            $this->assertNewWrite($actor, $incident);
            abort_unless($aggregate !== null && (int) $aggregate->incident_evidence_version === $input['expected_incident_evidence_version']
                && (int) $aggregate->active_window_revision === $input['evidence_window_revision'], 409, 'context_changed');
            abort_unless((int) $aggregate->incident_evidence_version < 2147483647, 409, 'context_changed');
            [$window, $windowProof] = $this->window((int) $incident->id, $input['evidence_window_revision'], $reads);
            $anchor = IncidentRecordingContext::capture($parent, (int) $incident->id, $reads);
            abort_unless(IncidentRecordingReconstructionValues::digest($anchor) === $window['context_anchor_sha256'], 409, 'context_changed');
            abort_if($reads->query(IncidentRecordingAttachment::query()->where('incident_id', $incident->id)
                ->where('window_revision', $window['window_revision'])->where('reference_uuid', $input['reference_uuid']))->exists(), 409, 'already_linked');
            $resolved = $this->resolve($actor, $input['reference_uuid']);
            $reference = IncidentRecordingReconstructionValues::referenceForWindow($resolved['reference'], $window, $input['reference_uuid']);
            $this->sameParent($actor, $reads, $parent, (int) $incident->id, $anchor); $windowProof();
            $version = (int) $aggregate->incident_evidence_version + 1; $uuid = (string) Str::uuid();
            $sourceHash = IncidentRecordingReconstructionValues::digest($reference);
            $meta = IncidentRecordingAuditValues::attachment($window, $version, $uuid, $input['reference_uuid'], $input['idempotency_key'], $fingerprint, $sourceHash);
            $audit = $this->writeAudit($actor, $incident, IncidentRecordingAuditValues::RECORDING_ATTACHED, $meta, $reads);
            // AuditLog's actual creation instant is authoritative at its available whole-second precision.
            $at = $reads->query(AuditLog::query()->whereKey($audit))->firstOrFail()->created_at->utc()->format('Y-m-d\TH:i:s.u\Z');
            $confirmation = IncidentRecordingReconstructionValues::attachConfirmation((int) $actor->id, (int) $incident->id,
                $input, $window, $reference, $uuid, $version, $at);
            // Required MySQL FK order: original journal, immutable attachment, then command receipt.
            $journal = IncidentEvidenceRevision::create(['incident_id' => $incident->id, 'incident_evidence_version' => $version,
                'kind' => 'recording_attached', 'window_revision' => $window['window_revision'], 'attachment_uuid' => $uuid,
                'predecessor_attachment_uuid' => null, 'actor_user_id' => $actor->id, 'recorded_at' => $at,
                'encrypted_reason' => null, 'audit_log_id' => $audit]);
            if (!$journal->exists || !$journal->getKey()) { throw new LogicException('The original evidence journal insert was vetoed.'); }
            $attachment = IncidentRecordingAttachment::create(['attachment_uuid' => $uuid, 'incident_id' => $incident->id,
                'window_revision' => $window['window_revision'], 'attached_evidence_version' => $version,
                'reference_uuid' => $input['reference_uuid'], 'camera_id' => $reference['camera_id'],
                'recorder_device_id' => $reference['recorder_device_id'], 'site_id' => $reference['site_id'],
                'requested_start_at' => $window['start_at'], 'requested_end_at' => $window['end_at'],
                'encrypted_source_snapshot' => $reference, 'source_snapshot_sha256' => $sourceHash,
                'encrypted_purpose' => $input['purpose'], 'actor_user_id' => $actor->id, 'recorded_at' => $at, 'audit_log_id' => $audit]);
            if (!$attachment->exists || !$attachment->getKey()) { throw new LogicException('The original attachment insert was vetoed.'); }
            $receipt = IncidentRecordingCommandReceipt::create(['idempotency_key' => $input['idempotency_key'],
                'incident_id' => $incident->id, 'actor_user_id' => $actor->id, 'command' => IncidentRecordingReconstructionValues::ATTACH,
                'fingerprint' => $fingerprint, 'submitted_evidence_version' => $input['expected_incident_evidence_version'],
                'window_revision' => $window['window_revision'], 'confirmed_evidence_version' => $version,
                'attachment_uuid' => $uuid, 'confirmed_result' => $confirmation, 'confirmed_at' => $at, 'audit_log_id' => $audit]);
            foreach ([$journal, $attachment, $receipt] as $stored) {
                if (!$stored->exists || !$stored->getKey()) { throw new LogicException('Every accepted reconstruction record must physically persist.'); }
            }
            $aggregate->incident_evidence_version = $version; $aggregate->updated_by_user_id = $actor->id;
            if (!$aggregate->saveOrFail()) { throw new LogicException('The evidence aggregate update was vetoed.'); }
            $windowProof(); $this->sameParent($actor, $reads, $parent, (int) $incident->id, $anchor, true);
            $physical = $reads->query(IncidentRecordingCommandReceipt::query()->whereKey($receipt->id))->first();
            abort_unless($physical !== null, 404); $this->assertReceipt($physical, $input, $window, $reference, $reads);
            $this->assertAggregate($aggregate, $reads);
            // Write observers share this connection and can alter authority despite held SQL locks.
            // The actual source owner has no retained verifier API, so NEW write finalization resolves again.
            $finalSource = $this->resolve($actor, $input['reference_uuid']);
            abort_unless(IncidentRecordingReconstructionValues::digest($finalSource['reference']) === $sourceHash, 404);
            $this->sameParent($actor, $reads, $parent, (int) $incident->id, $anchor, true);
            $this->physicalAggregate($aggregate, $reads);
            return ['created' => true, 'response' => [...$confirmation, 'current' => $this->current($aggregate, $finalSource)]];
        });
    }

    public function recoverAttachment(Request $request, int $incidentId, int $expectedActorId, string $idempotencyKey): array
    {
        IncidentRecordingReconstructionValues::positive($expectedActorId, 'expected_actor_id');
        IncidentRecordingReconstructionValues::uuid($idempotencyKey, 'idempotency_key');
        return $this->withinIncident($request, $incidentId, function (User $actor, CurrentAuthorizationReads $reads, Builder $parent, ClientIncident $incident) use ($expectedActorId, $idempotencyKey): array {
            abort_unless((int) $actor->id === $expectedActorId, 403);
            $aggregate = IncidentEvidenceAggregate::query()->whereKey($incident->id)->lockForUpdate()->first();
            if ($aggregate !== null) { $this->assertAggregate($aggregate, $reads); }
            $receipt = $reads->query(IncidentRecordingCommandReceipt::query()->where('idempotency_key', $idempotencyKey)
                ->where('incident_id', $incident->id)->where('actor_user_id', $actor->id))->first();
            abort_unless($receipt !== null && $aggregate !== null, 404);
            // Recover only this actor's original command. Protected purpose is read after independent Devices resolution.
            return $this->replay($actor, $reads, $parent, $incident, $aggregate, $receipt, null);
        });
    }

    /** The policy mutex is acquired BEFORE the canonical incident mutex for a NEW window operation. */
    public function recordWindow(Request $request, int $incidentId, array $input): array
    {
        $input = IncidentEvidenceWindowCommandValues::input($input);
        return $this->policy->withLockedPolicy(function (array $policy) use ($request, $incidentId, $input): array {
            return $this->withinIncident($request, $incidentId, function (User $actor, CurrentAuthorizationReads $reads, Builder $parent, ClientIncident $incident) use ($policy, $input): array {
                abort_unless((int) $actor->id === $input['expected_actor_id'], 403);
                $fingerprint = IncidentEvidenceWindowCommandValues::fingerprint((int) $actor->id, (int) $incident->id, $input);
                $aggregate = IncidentEvidenceAggregate::query()->whereKey($incident->id)->lockForUpdate()->first();
                if ($aggregate !== null) { $this->assertAggregate($aggregate, $reads); }
                $prior = $reads->query(IncidentEvidenceWindowCommandReceipt::query()->where('idempotency_key', $input['idempotency_key']))->first();
                if ($prior !== null) {
                    abort_unless((int) $prior->actor_user_id === (int) $actor->id, 404);
                    abort_unless((int) $prior->incident_id === (int) $incident->id && hash_equals($prior->fingerprint, $fingerprint), 409, 'context_changed');
                    abort_unless($aggregate !== null, 404);
                    return ['created' => false, 'response' => $this->replayWindow($actor, $reads, $parent, $incident, $aggregate, $prior, $input)];
                }
                $foreignKind = $reads->query(IncidentRecordingCommandReceipt::query()->where('idempotency_key', $input['idempotency_key']))->first(['actor_user_id']);
                if ($foreignKind !== null) { abort((int) $foreignKind->actor_user_id === (int) $actor->id ? 409 : 404, 'context_changed'); }
                $this->assertNewWrite($actor, $incident);
                $version = $aggregate === null ? 0 : (int) $aggregate->incident_evidence_version;
                abort_unless($version === $input['expected_incident_evidence_version'] && $version < 2147483647, 409, 'context_changed');
                abort_unless(($policy['configuration_state'] ?? null) === 'configured' && ($policy['configuration_valid'] ?? false) === true
                    && $policy['policy_revision'] === $input['expected_policy_revision'] && is_array($policy['policy_snapshot'] ?? null), 409, 'policy_changed');
                $anchor = IncidentRecordingContext::capture($parent, (int) $incident->id, $reads);
                $rule = IncidentEvidenceWindowPolicyValues::effectiveRule($policy['policy_snapshot'], $anchor['incident_type'], $anchor['effective_site_id']);
                abort_unless($rule !== null, 409, 'policy_unavailable');
                $sources = IncidentRecordingReconstructionValues::sourceSet($input['approved_sources'], $anchor['effective_site_id']);
                $second = (new \DateTimeImmutable($anchor['occurred_at']))->getTimestamp();
                $baseStart = $second - $rule['before_seconds']; $baseEnd = $second + $rule['after_seconds'];
                $expanded = (new \DateTimeImmutable($input['requested_start_at']))->getTimestamp() < $baseStart
                    || (new \DateTimeImmutable($input['requested_end_at']))->getTimestamp() > $baseEnd;
                if ($expanded) { abort_unless($actor->canDo(self::EXPAND_PERMISSION), 403); }
                $this->assertSources($actor, $sources, $reads);
                $lastWindow = $reads->query(IncidentEvidenceWindow::query()->where('incident_id', $incident->id)->orderByDesc('window_revision'))->first(['window_revision']);
                $revision = $lastWindow === null ? 1 : (int) $lastWindow->window_revision + 1;
                IncidentRecordingReconstructionValues::revision($revision, 'window_revision');
                $recordedAt = now('UTC')->toImmutable();
                $at = $recordedAt->format('Y-m-d\TH:i:s.u\Z');
                $window = IncidentRecordingReconstructionValues::windowRecord(['incident_id' => (int) $incident->id,
                    'window_revision' => $revision, 'introduced_evidence_version' => $version + 1, 'actor_user_id' => (int) $actor->id,
                    'recorded_at' => $at, 'start_at' => $input['requested_start_at'], 'end_at' => $input['requested_end_at'],
                    'rule_identifier' => $rule['identifier'], 'rule_revision' => $policy['policy_revision'],
                    'policy_change_id' => $policy['setting_change_id'], 'rule_snapshot_sha256' => $policy['policy_digest'],
                    'override_reason' => $expanded ? $input['reason'] : null, 'context_anchor' => $anchor, 'approved_sources' => $sources,
                    // The pure digest excludes this audit FK; replace the placeholder after strict audit.
                    'audit_log_id' => 1]);
                $this->policy->assertRecordedWindowPolicy($window, $reads);
                $audit = $this->writeAudit($actor, $incident, IncidentRecordingAuditValues::WINDOW_RECORDED, IncidentRecordingAuditValues::window($window), $reads, $recordedAt);
                $auditAt = $reads->query(AuditLog::query()->whereKey($audit))->firstOrFail()->created_at->utc()->format('Y-m-d\TH:i:s\Z');
                abort_unless($auditAt === substr($at, 0, 19).'Z', 409, 'context_changed');
                $window['audit_log_id'] = $audit;
                if ($aggregate === null) {
                    $aggregate = IncidentEvidenceAggregate::create(['incident_id' => $incident->id, 'incident_evidence_version' => 1,
                        'active_window_revision' => null, 'created_by_user_id' => $actor->id, 'updated_by_user_id' => $actor->id]);
                    if (!$aggregate->exists) { throw new LogicException('The evidence aggregate insert was vetoed.'); }
                }
                $stored = $window;
                foreach (['override_reason', 'context_anchor', 'approved_sources'] as $key) { $stored['encrypted_'.$key] = $stored[$key]; unset($stored[$key]); }
                $record = IncidentEvidenceWindow::create($stored);
                if (!$record->exists || !$record->getKey()) { throw new LogicException('The original immutable window insert was vetoed.'); }
                foreach ($sources as $source) {
                    $member = IncidentEvidenceWindowSource::create(['incident_id' => $incident->id, 'window_revision' => $revision, ...$source]);
                    if (!$member->exists || !$member->getKey()) { throw new LogicException('The declared original source membership was vetoed.'); }
                }
                $journal = IncidentEvidenceRevision::create(['incident_id' => $incident->id, 'incident_evidence_version' => $version + 1,
                    'kind' => 'window_recorded', 'window_revision' => $revision, 'attachment_uuid' => null, 'predecessor_attachment_uuid' => null,
                    'actor_user_id' => $actor->id, 'recorded_at' => $at, 'encrypted_reason' => null, 'audit_log_id' => $audit]);
                if (!$journal->exists || !$journal->getKey()) { throw new LogicException('The original window journal insert was vetoed.'); }
                $confirmation = IncidentEvidenceWindowCommandValues::confirmation((int) $actor->id, $input, $window);
                $receipt = IncidentEvidenceWindowCommandReceipt::create(['idempotency_key' => $input['idempotency_key'],
                    'incident_id' => $incident->id, 'actor_user_id' => $actor->id, 'command' => IncidentEvidenceWindowCommandValues::COMMAND,
                    'fingerprint' => $fingerprint, 'submitted_evidence_version' => $version, 'submitted_policy_revision' => $input['expected_policy_revision'],
                    'confirmed_evidence_version' => $version + 1, 'window_revision' => $revision, 'encrypted_input' => $input,
                    'confirmed_result' => $confirmation, 'confirmed_at' => $at, 'audit_log_id' => $audit]);
                if (!$receipt->exists || !$receipt->getKey()) { throw new LogicException('The original window command receipt insert was vetoed.'); }
                $aggregate->incident_evidence_version = $version + 1; $aggregate->active_window_revision = $revision; $aggregate->updated_by_user_id = $actor->id;
                if (!$aggregate->saveOrFail()) { throw new LogicException('The active window selection update was vetoed.'); }
                $this->assertAggregate($aggregate, $reads);
                $this->sameParent($actor, $reads, $parent, (int) $incident->id, $anchor, true, self::WINDOW_PERMISSION);
                $this->assertSources($actor, $sources, $reads);
                $fresh = $this->authorization->lockForUserWithoutWaiting((int) $actor->id, ['*']);
                if ($expanded) { abort_unless($fresh->canDo(self::EXPAND_PERMISSION), 403); }
                $physical = $reads->query(IncidentEvidenceWindowCommandReceipt::query()->whereKey($receipt->id))->first();
                abort_unless($physical !== null, 404); $this->assertWindowReceipt($physical, $input, $window);
                [$physicalWindow, $proof] = $this->window((int) $incident->id, $revision, $reads); $proof();
                abort_unless(IncidentRecordingReconstructionValues::digest($physicalWindow) === IncidentRecordingReconstructionValues::digest($window), 404);
                $this->physicalAggregate($aggregate, $reads);
                return ['created' => true, 'response' => [...$confirmation, 'current' => ['incident_evidence_version' => $version + 1, 'evidence_window_revision' => $revision]]];
            }, self::WINDOW_PERMISSION);
        });
    }

    public function recoverWindow(Request $request, int $incidentId, int $expectedActorId, string $idempotencyKey): array
    {
        IncidentRecordingReconstructionValues::positive($expectedActorId, 'expected_actor_id');
        IncidentRecordingReconstructionValues::uuid($idempotencyKey, 'idempotency_key');
        return $this->withinIncident($request, $incidentId, function (User $actor, CurrentAuthorizationReads $reads, Builder $parent, ClientIncident $incident) use ($expectedActorId, $idempotencyKey): array {
            abort_unless((int) $actor->id === $expectedActorId, 403);
            $aggregate = IncidentEvidenceAggregate::query()->whereKey($incident->id)->lockForUpdate()->first();
            $receipt = $reads->query(IncidentEvidenceWindowCommandReceipt::query()->where('incident_id', $incident->id)
                ->where('actor_user_id', $actor->id)->where('idempotency_key', $idempotencyKey))->first();
            abort_unless($receipt !== null && $aggregate !== null, 404); $this->assertAggregate($aggregate, $reads);
            return $this->replayWindow($actor, $reads, $parent, $incident, $aggregate, $receipt, null);
        }, self::WINDOW_PERMISSION);
    }

    private function replayWindow(User $actor, CurrentAuthorizationReads $reads, Builder $parent, ClientIncident $incident,
        IncidentEvidenceAggregate $aggregate, IncidentEvidenceWindowCommandReceipt $receipt, ?array $input): array
    {
        [$window, $proof] = $this->window((int) $incident->id, (int) $receipt->window_revision, $reads);
        $anchor = IncidentRecordingContext::capture($parent, (int) $incident->id, $reads);
        $this->assertSources($actor, $window['approved_sources'], $reads);
        if ($window['override_reason'] !== null) { abort_unless($actor->canDo(self::EXPAND_PERMISSION), 403); }
        $original = IncidentEvidenceWindowCommandValues::input($receipt->encrypted_input);
        abort_unless($input === null || $input === $original, 409, 'context_changed');
        $confirmation = $this->assertWindowReceipt($receipt, $original, $window);
        abort_unless((int) $aggregate->incident_evidence_version >= $window['introduced_evidence_version'], 404);
        $proof(); $this->sameParent($actor, $reads, $parent, (int) $incident->id, $anchor, false, self::WINDOW_PERMISSION);
        $this->physicalAggregate($aggregate, $reads);
        return [...$confirmation, 'current' => ['incident_evidence_version' => (int) $aggregate->incident_evidence_version,
            'evidence_window_revision' => $aggregate->active_window_revision === null ? null : (int) $aggregate->active_window_revision]];
    }

    private function assertWindowReceipt(IncidentEvidenceWindowCommandReceipt $receipt, array $input, array $window): array
    {
        abort_unless($receipt->command === IncidentEvidenceWindowCommandValues::COMMAND && (int) $receipt->incident_id === $window['incident_id']
            && (int) $receipt->actor_user_id === $window['actor_user_id'] && (int) $receipt->submitted_evidence_version === $input['expected_incident_evidence_version']
            && (int) $receipt->submitted_policy_revision === $input['expected_policy_revision'] && (int) $receipt->window_revision === $window['window_revision']
            && (int) $receipt->confirmed_evidence_version === $window['introduced_evidence_version'] && (int) $receipt->audit_log_id === $window['audit_log_id']
            && $receipt->confirmed_at->utc()->format('Y-m-d\TH:i:s.u\Z') === $window['recorded_at']
            && $receipt->idempotency_key === $input['idempotency_key'] && $receipt->encrypted_input === $input
            && hash_equals($receipt->fingerprint, IncidentEvidenceWindowCommandValues::fingerprint($window['actor_user_id'], $window['incident_id'], $input))
            && ($window['override_reason'] === null || $window['override_reason'] === $input['reason']), 404);
        $confirmation = IncidentEvidenceWindowCommandValues::confirmation($window['actor_user_id'], $input, $window);
        abort_unless(IncidentRecordingReconstructionValues::digest($receipt->confirmed_result) === IncidentRecordingReconstructionValues::digest($confirmation), 404);
        return $confirmation;
    }

    /** Actual source-owner metadata authority. Explicit source selection never creates a bookmark or media session. */
    private function assertSources(User $actor, array $sources, CurrentAuthorizationReads $reads): void
    {
        if ($sources === []) { return; }
        $authority = app(CctvAuthority::class); $current = $authority->actor((int) $actor->id, false, true);
        abort_unless($current->canDo(CctvRecordingReferences::VIEW), 404);
        foreach ($sources as $member) {
            [$camera, $site] = $authority->referenceDevice($current, $member['camera_id']);
            [$recorder, $recorderSite] = $authority->referenceDevice($current, $member['recorder_device_id'], true);
            abort_unless($site === $member['site_id'] && $recorderSite === $site, 404);
            $source = $reads->query(CctvSource::query()->where('camera_id', $camera->id)->orderByDesc('revision'))->first();
            abort_unless($source !== null && (int) $source->recorder_device_id === (int) $recorder->id && (int) $source->site_id === $site, 404);
            $authority->referenceSource($current, $source, false);
        }
    }

    private function withinIncident(Request $request, int $incidentId, callable $operation, string $permission = self::ATTACH_PERMISSION): array
    {
        abort_unless($incidentId > 0 && $request->user() instanceof User, 403);
        try {
            return DB::transaction(function () use ($request, $incidentId, $operation, $permission): array {
                // Canonical incident mutex FIRST, including receipts/replay; this reads only identity before authorization.
                abort_unless(ClientIncident::query()->whereKey($incidentId)->lockForUpdate()->first(['id']) !== null, 404);
                return CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($request, $incidentId, $operation, $permission): array {
                    $actor = $this->authorization->lockForUserWithoutWaiting($request->user(), ['*']);
                    abort_unless($actor->canDo($permission) && $this->eligibility->activeInternal($actor, $reads), 403);
                    $parent = $this->independence->applySourceScopeInReads($this->access->apply(ClientIncident::query()->whereKey($incidentId), $actor, $reads), $actor, $reads);
                    $incident = $reads->query(clone $parent)->first(); abort_unless($incident !== null, 404);
                    $result = $operation($actor, $reads, $parent, $incident);
                    abort_unless($reads->query(clone $parent)->exists(), 404); $reads->assertActive();
                    IncidentRoutingSettings::detached($result); return $result;
                });
            });
        } catch (QueryException $error) {
            if (in_array((int) ($error->errorInfo[1] ?? 0), [1062, 1205, 1213, 3572], true)) { abort(409, 'context_changed'); }
            throw $error;
        }
    }

    private function assertNewWrite(User $actor, ClientIncident $incident): void
    {
        // ClientIncidentPolicy::update's exact lifecycle/edit predicate, after current parent access above.
        abort_unless($incident->status !== 'closed' && $actor->canDo('incidents.update')
            && ($actor->canDo('incidents.viewAny') || $incident->isEditableByReporter($actor)), 403);
    }

    private function resolve(User $actor, string $uuid): array
    {
        $request = Request::create('/', 'GET'); $request->setUserResolver(static fn () => $actor);
        $connection = DB::connection(); $level = $connection->transactionLevel();
        $resolved = $this->references->resolve($request, $uuid);
        if ($level < 1 || DB::connection() !== $connection || $connection->transactionLevel() !== $level) { throw new LogicException('Devices must retain the enclosing incident transaction.'); }
        abort_unless(($resolved['actor_id'] ?? null) === (int) $actor->id && is_array($resolved['reference'] ?? null), 404);
        return $resolved;
    }

    private function replay(User $actor, CurrentAuthorizationReads $reads, Builder $parent, ClientIncident $incident,
        IncidentEvidenceAggregate $aggregate, IncidentRecordingCommandReceipt $receipt, ?array $input): array
    {
        abort_unless((int) $receipt->actor_user_id === (int) $actor->id && (int) $receipt->incident_id === (int) $incident->id, 404);
        $attachment = $reads->query(IncidentRecordingAttachment::query()->where('incident_id', $incident->id)->where('attachment_uuid', $receipt->attachment_uuid))->first();
        abort_unless($attachment !== null, 404);
        $anchor = IncidentRecordingContext::capture($parent, (int) $incident->id, $reads);
        [$window, $proof] = $this->window((int) $incident->id, (int) $receipt->window_revision, $reads);
        $resolved = $this->resolve($actor, $attachment->reference_uuid);
        $reference = IncidentRecordingReconstructionValues::referenceForWindow($resolved['reference'], $window, $attachment->reference_uuid);
        $purpose = $attachment->encrypted_purpose;
        $original = ['expected_actor_id' => (int) $actor->id, 'idempotency_key' => $receipt->idempotency_key,
            'expected_incident_evidence_version' => (int) $receipt->submitted_evidence_version,
            'evidence_window_revision' => (int) $receipt->window_revision, 'reference_uuid' => $attachment->reference_uuid, 'purpose' => $purpose];
        $original = IncidentRecordingReconstructionValues::attachInput($original);
        abort_unless($purpose === $original['purpose'] && ($input === null || $input === $original), 409, 'context_changed');
        $confirmation = $this->assertReceipt($receipt, $original, $window, $reference, $reads);
        abort_unless((int) $aggregate->incident_evidence_version >= (int) $receipt->confirmed_evidence_version, 404);
        $proof(); $this->sameParent($actor, $reads, $parent, (int) $incident->id, $anchor);
        $this->physicalAggregate($aggregate, $reads);
        return [...$confirmation, 'current' => $this->current($aggregate, $resolved)];
    }

    private function current(IncidentEvidenceAggregate $aggregate, array $resolved): array
    {
        $source = $resolved['current_source'] ?? null;
        abort_unless(is_array($source) && in_array($source['state'] ?? null, ['ready', 'changed', 'withdrawn', 'unavailable'], true), 404);
        return ['incident_evidence_version' => (int) $aggregate->incident_evidence_version,
            'evidence_window_revision' => $aggregate->active_window_revision === null ? null : (int) $aggregate->active_window_revision,
            'current_source' => ['state' => $source['state'], 'source_verified_at' => $source['source_verified_at'] ?? null]];
    }

    private function sameParent(User $actor, CurrentAuthorizationReads $reads, Builder $parent, int $id, array $anchor, bool $newWrite = false, string $permission = self::ATTACH_PERMISSION): void
    {
        $fresh = $this->authorization->lockForUserWithoutWaiting((int) $actor->id, ['*']);
        abort_unless($fresh->canDo($permission) && $this->eligibility->activeInternal($fresh, $reads), 403);
        $current = $this->independence->applySourceScopeInReads($this->access->apply(ClientIncident::query()->whereKey($id), $fresh, $reads), $fresh, $reads);
        abort_unless(IncidentRecordingContext::capture($parent, $id, $reads) === $anchor
            && IncidentRecordingContext::capture($current, $id, $reads) === $anchor, 404);
        if ($newWrite) {
            $raw = $reads->query(clone $current)->setEagerLoads([])->toBase()->first(); abort_unless($raw !== null, 404);
            $this->assertNewWrite($fresh, (new ClientIncident)->setRawAttributes((array) $raw, true));
        }
    }

    private function physicalAggregate(IncidentEvidenceAggregate $aggregate, CurrentAuthorizationReads $reads): void
    {
        $raw = $reads->query(IncidentEvidenceAggregate::query()->whereKey($aggregate->incident_id))->toBase()->first();
        abort_unless($raw !== null && (int) $raw->incident_evidence_version === (int) $aggregate->incident_evidence_version
            && ($raw->active_window_revision === null ? null : (int) $raw->active_window_revision) === $aggregate->active_window_revision
            && (int) $raw->created_by_user_id === (int) $aggregate->created_by_user_id
            && (int) $raw->updated_by_user_id === (int) $aggregate->updated_by_user_id, 404);
    }

    /** Complete current structural provenance before any write; source narratives remain encrypted until their own read grant. */
    private function assertAggregate(IncidentEvidenceAggregate $aggregate, CurrentAuthorizationReads $reads): void
    {
        $this->physicalAggregate($aggregate, $reads); $id = (int) $aggregate->incident_id; $version = (int) $aggregate->incident_evidence_version;
        IncidentRecordingReconstructionValues::revision($version, 'incident_evidence_version');
        abort_if($reads->query(IncidentEvidenceRevision::query()->where('incident_id', $id)->where('incident_evidence_version', '>', $version))->exists(), 404);
        $journal = $reads->query(IncidentEvidenceRevision::query()->where('incident_id', $id)->where('incident_evidence_version', $version))->first();
        abort_unless($journal !== null && in_array($journal->kind, ['window_recorded', 'recording_attached', 'window_invalidated'], true), 404);
        $original = $reads->query(IncidentEvidenceRevision::query()->where('incident_id', $id)->where('incident_evidence_version', 1))->first();
        abort_unless($original !== null && $original->kind === 'window_recorded'
            && (int) $aggregate->created_by_user_id === (int) $original->actor_user_id
            && (int) $aggregate->updated_by_user_id === (int) $journal->actor_user_id, 404);
        $active = $aggregate->active_window_revision;
        abort_unless($active === null ? $journal->kind === 'window_invalidated'
            : $journal->kind !== 'window_invalidated' && (int) $journal->window_revision === $active, 404);
        [$window, $proof] = $this->window($id, (int) $journal->window_revision, $reads);
        abort_unless($window['introduced_evidence_version'] <= $version, 404);
        if ($journal->kind === 'window_recorded') {
            abort_unless($window['introduced_evidence_version'] === $version, 404);
        } elseif ($journal->kind === 'window_invalidated') {
            $values = IncidentRecordingReconstructionValues::revisionRecord(['incident_id' => $id, 'incident_evidence_version' => $version,
                'kind' => 'window_invalidated', 'window_revision' => (int) $journal->window_revision,
                'attachment_uuid' => $journal->attachment_uuid, 'predecessor_attachment_uuid' => $journal->predecessor_attachment_uuid,
                'actor_user_id' => (int) $journal->actor_user_id, 'recorded_at' => $journal->recorded_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
                'reason' => $journal->encrypted_reason, 'audit_log_id' => (int) $journal->audit_log_id]);
            abort_unless($values['reason'] === $journal->encrypted_reason, 404);
            $audit = $this->audit($id, (int) $journal->actor_user_id, IncidentRecordingAuditValues::WINDOW_INVALIDATED,
                IncidentRecordingAuditValues::invalidation($window, $version, $values['reason']), $reads, (int) $journal->audit_log_id);
            abort_unless($audit->created_at->utc()->format('Y-m-d\TH:i:s\Z') === $journal->recorded_at->utc()->format('Y-m-d\TH:i:s\Z'), 404);
        } else {
            $row = $reads->query(IncidentRecordingAttachment::query()->where('incident_id', $id)->where('attached_evidence_version', $version)
                ->where('attachment_uuid', $journal->attachment_uuid))->first();
            abort_unless($row !== null, 404);
            $receipt = $reads->query(IncidentRecordingCommandReceipt::query()->where('incident_id', $id)->where('attachment_uuid', $row->attachment_uuid))->first();
            abort_unless($receipt !== null && $receipt->command === IncidentRecordingReconstructionValues::ATTACH
                && (int) $row->window_revision === $window['window_revision'] && (int) $receipt->window_revision === $window['window_revision']
                && (int) $receipt->confirmed_evidence_version === $version && (int) $receipt->submitted_evidence_version + 1 === $version
                && (int) $receipt->actor_user_id === (int) $row->actor_user_id && (int) $journal->actor_user_id === (int) $row->actor_user_id
                && (int) $receipt->audit_log_id === (int) $row->audit_log_id && (int) $journal->audit_log_id === (int) $row->audit_log_id
                && $receipt->confirmed_at->equalTo($row->recorded_at) && $journal->recorded_at->equalTo($row->recorded_at)
                && $journal->predecessor_attachment_uuid === null && $journal->encrypted_reason === null, 404);
            IncidentRecordingReconstructionValues::uuid($receipt->idempotency_key, 'idempotency_key');
            IncidentRecordingReconstructionValues::hash($receipt->fingerprint, 'fingerprint');
            $audit = $this->audit($id, (int) $row->actor_user_id, IncidentRecordingAuditValues::RECORDING_ATTACHED,
                IncidentRecordingAuditValues::attachment($window, $version, $row->attachment_uuid, $row->reference_uuid,
                    $receipt->idempotency_key, $receipt->fingerprint, $row->source_snapshot_sha256), $reads, (int) $row->audit_log_id);
            abort_unless($audit->created_at->utc()->format('Y-m-d\TH:i:s\Z') === $row->recorded_at->utc()->format('Y-m-d\TH:i:s\Z'), 404);
        }
        $proof();
    }

    private function writeAudit(User $actor, ClientIncident $incident, string $action, array $meta, CurrentAuthorizationReads $reads, ?\DateTimeImmutable $recordedAt = null): int
    {
        $request = Request::create('/', 'POST'); $request->setUserResolver(static fn () => $actor);
        AuditLogger::logOrFail($action, $incident, $meta, $request, false, $recordedAt);
        $audit = $this->audit($incident->id, $actor->id, $action, $meta, $reads);
        return (int) $audit->id;
    }

    private function audit(int $incident, int $actor, string $action, array $meta, CurrentAuthorizationReads $reads, ?int $id = null): AuditLog
    {
        $rows = $reads->query(AuditLog::query()->where('auditable_type', (new ClientIncident)->getMorphClass())
            ->where('auditable_id', $incident)->where('action', $action)->where('meta->incident_evidence_version', $meta['incident_evidence_version']))->get();
        abort_unless($rows->count() === 1, 404); $row = $rows->sole();
        abort_unless(($id === null || (int) $row->id === $id) && (int) $row->user_id === $actor && $row->action === $action
            && IncidentRecordingReconstructionValues::digest($row->meta) === IncidentRecordingReconstructionValues::digest($meta), 404);
        return $row;
    }

    private function assertReceipt(IncidentRecordingCommandReceipt $receipt, array $input, array $window, array $reference, CurrentAuthorizationReads $reads): array
    {
        $row = $reads->query(IncidentRecordingAttachment::query()->where('incident_id', $receipt->incident_id)->where('attachment_uuid', $receipt->attachment_uuid))->first();
        abort_unless($row !== null && $receipt->command === IncidentRecordingReconstructionValues::ATTACH
            && (int) $row->actor_user_id === (int) $receipt->actor_user_id && (int) $row->window_revision === (int) $receipt->window_revision
            && (int) $row->attached_evidence_version === (int) $receipt->confirmed_evidence_version
            && (int) $row->audit_log_id === (int) $receipt->audit_log_id
            && $row->recorded_at->equalTo($receipt->confirmed_at)
            && $row->encrypted_purpose === $input['purpose'] && $row->reference_uuid === $reference['reference_uuid']
            && (int) $row->camera_id === $reference['camera_id'] && (int) $row->recorder_device_id === $reference['recorder_device_id']
            && (int) $row->site_id === $reference['site_id']
            && $row->requested_start_at->utc()->format('Y-m-d\TH:i:s\Z') === $window['start_at']
            && $row->requested_end_at->utc()->format('Y-m-d\TH:i:s\Z') === $window['end_at']
            && hash_equals($receipt->fingerprint, IncidentRecordingReconstructionValues::attachFingerprint((int) $receipt->actor_user_id, (int) $receipt->incident_id, $input))
            && hash_equals($row->source_snapshot_sha256, IncidentRecordingReconstructionValues::digest($reference))
            && IncidentRecordingReconstructionValues::digest($row->encrypted_source_snapshot) === IncidentRecordingReconstructionValues::digest($reference), 404);
        $confirmation = IncidentRecordingReconstructionValues::attachConfirmation((int) $receipt->actor_user_id, (int) $receipt->incident_id,
            $input, $window, $reference, $row->attachment_uuid, (int) $receipt->confirmed_evidence_version,
            $receipt->confirmed_at->utc()->format('Y-m-d\TH:i:s.u\Z'));
        abort_unless(IncidentRecordingReconstructionValues::digest($receipt->confirmed_result) === IncidentRecordingReconstructionValues::digest($confirmation), 404);
        $journal = $reads->query(IncidentEvidenceRevision::query()->where('incident_id', $receipt->incident_id)->where('incident_evidence_version', $receipt->confirmed_evidence_version))->first();
        abort_unless($journal !== null && $journal->kind === 'recording_attached' && $journal->attachment_uuid === $row->attachment_uuid
            && $journal->predecessor_attachment_uuid === null && $journal->encrypted_reason === null
            && (int) $journal->window_revision === (int) $row->window_revision && (int) $journal->actor_user_id === (int) $row->actor_user_id
            && (int) $journal->audit_log_id === (int) $row->audit_log_id && $journal->recorded_at->equalTo($row->recorded_at), 404);
        $audit = $this->audit((int) $row->incident_id, (int) $row->actor_user_id, IncidentRecordingAuditValues::RECORDING_ATTACHED,
            IncidentRecordingAuditValues::attachment($window, (int) $row->attached_evidence_version, $row->attachment_uuid,
                $row->reference_uuid, $receipt->idempotency_key, $receipt->fingerprint, $row->source_snapshot_sha256), $reads, (int) $row->audit_log_id);
        abort_unless($audit->created_at->utc()->format('Y-m-d\TH:i:s\Z') === $row->recorded_at->utc()->format('Y-m-d\TH:i:s\Z'), 404);
        return $confirmation;
    }

    private function window(int $incident, int $revision, CurrentAuthorizationReads $reads): array
    {
        $query = IncidentEvidenceWindow::query()->where('incident_id', $incident)->where('window_revision', $revision);
        $row = $reads->query(clone $query)->first(); abort_unless($row !== null, 404); $raw = $row->getRawOriginal();
        $window = [];
        foreach (['incident_id', 'window_revision', 'introduced_evidence_version', 'actor_user_id', 'rule_revision', 'policy_change_id', 'audit_log_id', 'effective_site_id'] as $key) { $window[$key] = (int) $row->{$key}; }
        foreach (['rule_identifier', 'rule_snapshot_sha256', 'context_anchor_sha256', 'source_set_sha256', 'window_sha256'] as $key) { $window[$key] = $row->{$key}; }
        $window['client_id'] = $row->client_id === null ? null : (int) $row->client_id;
        $window['recorded_at'] = $row->recorded_at->utc()->format('Y-m-d\TH:i:s.u\Z');
        $window['start_at'] = $row->start_at->utc()->format('Y-m-d\TH:i:s\Z'); $window['end_at'] = $row->end_at->utc()->format('Y-m-d\TH:i:s\Z');
        $window['override_reason'] = $row->encrypted_override_reason; $window['context_anchor'] = $row->encrypted_context_anchor; $window['approved_sources'] = $row->encrypted_approved_sources;
        IncidentRecordingReconstructionValues::assertWindowRecord($window); $this->policy->assertRecordedWindowPolicy($window, $reads);
        $sources = $reads->query(IncidentEvidenceWindowSource::query()->where('incident_id', $incident)->where('window_revision', $revision)->orderBy('id'))->get();
        $members = $sources->map(static fn ($source) => ['camera_id' => (int) $source->camera_id, 'recorder_device_id' => (int) $source->recorder_device_id, 'site_id' => (int) $source->site_id])->all();
        abort_unless(IncidentRecordingReconstructionValues::sourceSet($members, $window['effective_site_id']) === $window['approved_sources'], 404);
        $journal = $reads->query(IncidentEvidenceRevision::query()->where('incident_id', $incident)->where('incident_evidence_version', $window['introduced_evidence_version']))->first();
        abort_unless($journal !== null && $journal->kind === 'window_recorded' && (int) $journal->window_revision === $revision
            && $journal->attachment_uuid === null && $journal->predecessor_attachment_uuid === null && $journal->encrypted_reason === null
            && (int) $journal->actor_user_id === $window['actor_user_id'] && (int) $journal->audit_log_id === $window['audit_log_id']
            && $journal->recorded_at->utc()->format('Y-m-d\TH:i:s.u\Z') === $window['recorded_at'], 404);
        $audit = $this->audit($incident, $window['actor_user_id'], IncidentRecordingAuditValues::WINDOW_RECORDED,
            IncidentRecordingAuditValues::window($window), $reads, $window['audit_log_id']);
        abort_unless($audit->created_at->utc()->format('Y-m-d\TH:i:s\Z') === substr($window['recorded_at'], 0, 19).'Z', 404);
        $journalRaw = $journal->getRawOriginal(); $sourceRaw = $sources->map(fn ($source) => $source->getRawOriginal())->all();
        return [$window, function () use ($query, $raw, $journal, $journalRaw, $incident, $revision, $sourceRaw, $window, $reads): void {
            abort_unless($reads->query(clone $query)->first()?->getRawOriginal() === $raw
                && $reads->query(IncidentEvidenceRevision::query()->whereKey($journal->id))->first()?->getRawOriginal() === $journalRaw
                && $reads->query(IncidentEvidenceWindowSource::query()->where('incident_id', $incident)->where('window_revision', $revision)->orderBy('id'))->get()->map(fn ($source) => $source->getRawOriginal())->all() === $sourceRaw, 404);
            $this->policy->assertRecordedWindowPolicy($window, $reads);
            $this->audit($incident, $window['actor_user_id'], IncidentRecordingAuditValues::WINDOW_RECORDED, IncidentRecordingAuditValues::window($window), $reads, $window['audit_log_id']);
        }];
    }
}
