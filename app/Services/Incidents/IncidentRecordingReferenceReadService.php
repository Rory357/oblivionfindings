<?php

namespace App\Services\Incidents;

use App\Domain\SecurityDevices\Cctv\Services\CctvRecordingReferences;
use App\Models\IncidentEvidenceAggregate;
use App\Models\IncidentEvidenceRevision;
use App\Models\IncidentEvidenceWindow;
use App\Models\IncidentEvidenceWindowSource;
use App\Models\IncidentRecordingAttachment;
use App\Models\IncidentRecordingCommandReceipt;
use App\Models\AuditLog;
use App\Models\ClientIncident;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Incident-owned bindings only. No route, source UUID parameter, grant or media capability is provided here. */
final class IncidentRecordingReferenceReadService
{
    public const PER_PAGE = 20;

    public function __construct(private readonly IncidentRegisterReadService $incidents,
        private readonly CctvRecordingReferences $references,
        private readonly IncidentEvidenceWindowPolicySettings $policy) {}

    public function forIncident(Request $request, int $incidentId, int $page = 1): array
    {
        abort_unless($page > 0 && $page <= 100000, 404);
        return $this->incidents->consumeSource($request, $incidentId,
            function (Request $working, int $id, CurrentAuthorizationReads $reads, Builder $parent) use ($page): array {
                $connection = DB::connection(); $level = $connection->transactionLevel();
                if ($parent->getConnection() !== $connection || $level < 1) {
                    throw new LogicException('Recording evidence must share the canonical incident transaction.');
                }
                $aggregateQuery = IncidentEvidenceAggregate::query()->whereKey($id);
                $aggregate = $this->rawOne($aggregateQuery, $reads);
                if ($aggregate === null) {
                    abort_unless($reads->query((clone $parent)->whereKey($id))->exists(), 404);
                    abort_unless($this->rawOne($aggregateQuery, $reads) === null, 404);
                    return $this->envelope($id, null, null, [], $page, 0);
                }
                $anchor = IncidentRecordingContext::capture($parent, $id, $reads);
                $version = (int) $aggregate['incident_evidence_version'];
                IncidentRecordingReconstructionValues::revision($version, 'incident_evidence_version');
                abort_if($reads->query(IncidentEvidenceRevision::query()->where('incident_id', $id)->where('incident_evidence_version', '>', $version))->exists(), 404);
                $query = IncidentRecordingAttachment::query()->where('incident_id', $id);
                $total = (int) $reads->query(clone $query)->count();
                $pageQuery = (clone $query)->orderByDesc('id')->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE);
                $originalRows = $this->rawMany($pageQuery, $reads);
                $windows = []; $proofs = []; $windowProofs = []; $items = [];
                $active = $aggregate['active_window_revision'] === null ? null : (int) $aggregate['active_window_revision'];
                if ($active !== null) { IncidentRecordingReconstructionValues::revision($active, 'active_window_revision'); }
                $latestQuery = IncidentEvidenceRevision::query()->where('incident_id', $id)->where('incident_evidence_version', $version);
                $latest = $this->rawOne($latestQuery, $reads);
                abort_unless($latest !== null && in_array($latest['kind'], ['window_recorded', 'recording_attached', 'window_invalidated'], true)
                    && ($active === null ? $latest['kind'] === 'window_invalidated'
                        : $latest['kind'] !== 'window_invalidated' && $active === (int) $latest['window_revision']), 404);
                $journal = (new IncidentEvidenceRevision)->setRawAttributes($latest, true);
                $journalValues = IncidentRecordingReconstructionValues::revisionRecord(['incident_id' => $id,
                    'incident_evidence_version' => $version, 'kind' => $journal->kind, 'window_revision' => (int) $journal->window_revision,
                    'attachment_uuid' => $journal->attachment_uuid, 'predecessor_attachment_uuid' => $journal->predecessor_attachment_uuid,
                    'actor_user_id' => (int) $journal->actor_user_id, 'recorded_at' => $journal->recorded_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
                    'reason' => $journal->encrypted_reason, 'audit_log_id' => (int) $journal->audit_log_id]);
                abort_unless($journalValues['reason'] === $journal->encrypted_reason, 404);
                $proofs[] = function () use ($latestQuery, $latest, $reads): void { abort_unless($this->rawOne($latestQuery, $reads) === $latest, 404); };
                $windowIds = array_values(array_unique(array_filter([$active, (int) $latest['window_revision'], ...array_map(static fn (array $row): int => (int) $row['window_revision'], $originalRows)])));
                foreach ($windowIds as $revision) {
                    [$window, $proof] = $this->window($id, $revision, $version, $reads);
                    $windows[$revision] = $window; $proofs[] = $proof; $windowProofs[$revision] = $proof;
                }
                $latestWindow = $windows[(int) $latest['window_revision']];
                if ($latest['kind'] === 'window_invalidated') {
                    $proofs[] = $this->auditBinding((int) $latest['audit_log_id'], $id, (int) $latest['actor_user_id'],
                        IncidentRecordingAuditValues::WINDOW_INVALIDATED,
                        IncidentRecordingAuditValues::invalidation($latestWindow, $version, $journalValues['reason']), $reads,
                        $journalValues['recorded_at']);
                } elseif ($latest['kind'] === 'window_recorded') {
                    abort_unless($latestWindow['introduced_evidence_version'] === $version, 404);
                } else {
                    // Current journal may be outside this requested history page; prove its original identity too.
                    $currentAttachmentQuery = IncidentRecordingAttachment::query()->where('incident_id', $id)
                        ->where('attached_evidence_version', $version)->where('attachment_uuid', $latest['attachment_uuid']);
                    $currentRaw = $this->rawOne($currentAttachmentQuery, $reads); abort_unless($currentRaw !== null, 404);
                    $currentAttachment = (new IncidentRecordingAttachment)->setRawAttributes($currentRaw, true);
                    $proofs[] = $this->attachmentBinding($currentAttachment, $latestWindow, $version, $reads);
                    [$currentReceipt, $currentReceiptProof] = $this->commandReceipt($currentAttachment, $reads);
                    $proofs[] = $currentReceiptProof;
                    $proofs[] = $this->auditBinding((int) $currentAttachment->audit_log_id, $id, (int) $currentAttachment->actor_user_id,
                        IncidentRecordingAuditValues::RECORDING_ATTACHED,
                        IncidentRecordingAuditValues::attachment($latestWindow, $version, $currentAttachment->attachment_uuid,
                            $currentAttachment->reference_uuid, $currentReceipt->idempotency_key, $currentReceipt->fingerprint,
                            $currentAttachment->source_snapshot_sha256), $reads, $journalValues['recorded_at']);
                    $proofs[] = function () use ($currentAttachmentQuery, $currentRaw, $reads): void {
                        abort_unless($this->rawOne($currentAttachmentQuery, $reads) === $currentRaw, 404);
                    };
                }
                foreach ($originalRows as $raw) {
                    $row = (new IncidentRecordingAttachment)->setRawAttributes($raw, true);
                    $window = $windows[(int) $row->window_revision];
                    $bindingProof = $this->attachmentBinding($row, $window, $version, $reads);
                    [$receipt, $receiptProof] = $this->commandReceipt($row, $reads);
                    $auditProof = $this->auditBinding((int) $row->audit_log_id, $id, (int) $row->actor_user_id,
                        IncidentRecordingAuditValues::RECORDING_ATTACHED,
                        IncidentRecordingAuditValues::attachment($window, (int) $row->attached_evidence_version,
                            $row->attachment_uuid, $row->reference_uuid, $receipt->idempotency_key,
                            $receipt->fingerprint, $row->source_snapshot_sha256), $reads, $row->recorded_at->utc()->format('Y-m-d\TH:i:s.u\Z'));
                    $rowQuery = IncidentRecordingAttachment::query()->where('incident_id', $id)->whereKey($row->id);
                    $rowProof = function () use ($rowQuery, $raw, $reads): void { abort_unless($this->rawOne($rowQuery, $reads) === $raw, 404); };
                    $proofs[] = $bindingProof; $proofs[] = $rowProof; $proofs[] = $receiptProof; $proofs[] = $auditProof;
                    $this->sameParent($parent, $id, $reads, $anchor);
                    $windowProofs[(int) $row->window_revision](); $bindingProof(); $rowProof(); $receiptProof();
                    $item = ['attachment_uuid' => $row->attachment_uuid, 'window_revision' => (int) $row->window_revision,
                        'attached_evidence_version' => (int) $row->attached_evidence_version,
                        'recorded_at' => $row->recorded_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
                        'requested_start_at' => $window['start_at'], 'requested_end_at' => $window['end_at'],
                        'access' => 'restricted', 'reference' => null];
                    // A new query-free request prevents incident filters/return URLs crossing the Devices boundary.
                    $sourceRequest = Request::create('/', 'GET');
                    $sourceRequest->setUserResolver(static fn () => $working->user());
                    try {
                        // resolve() nests its transaction on this connection. Its actor, receipt,
                        // device/privacy and current-source share locks remain held until the
                        // enclosing canonical incident transaction completes, including after
                        // its callback-local CurrentAuthorizationReads instance is disposed.
                        $resolved = $this->references->resolve($sourceRequest, $row->reference_uuid);
                    } catch (HttpExceptionInterface $error) {
                        if (! in_array($error->getStatusCode(), [403, 404, 409, 503], true)) { throw $error; }
                        // A bound incident row may disclose generic restriction, never which source check failed.
                        $resolved = null;
                    }
                    if (DB::connection() !== $connection || $connection->transactionLevel() !== $level) {
                        throw new LogicException('Source resolution changed the incident transaction lifetime.');
                    }
                    $this->sameParent($parent, $id, $reads, $anchor);
                    $windowProofs[(int) $row->window_revision](); $bindingProof(); $rowProof(); $receiptProof();
                    if ($resolved !== null) {
                        $item = $this->readable($item, $row, $window, $receipt, $resolved, (int) $working->user()->id);
                    }
                    $items[] = $item;
                }
                $this->sameParent($parent, $id, $reads, $anchor);
                foreach ($proofs as $proof) { $proof(); }
                abort_unless($this->rawOne($aggregateQuery, $reads) === $aggregate
                    && (int) $reads->query(clone $query)->count() === $total
                    && $this->rawMany($pageQuery, $reads) === $originalRows, 404);
                $selected = $active === null ? null : $windows[$active];
                $summary = $selected === null ? null : ['revision' => $active, 'start_at' => $selected['start_at'],
                    'end_at' => $selected['end_at'], 'rule_identifier' => $selected['rule_identifier'], 'rule_revision' => $selected['rule_revision'],
                    'context_state' => IncidentRecordingReconstructionValues::digest($selected['context_anchor']) === IncidentRecordingReconstructionValues::digest($anchor)
                        ? 'current' : 'changed'];
                return $this->envelope($id, $version, $summary, $items, $page, $total);
            });
    }

    private function envelope(int $incident, ?int $version, ?array $window, array $items, int $page, int $total): array
    {
        $more = $page * self::PER_PAGE < $total;
        return ['schema_version' => 1, 'incident_id' => $incident, 'incident_evidence_version' => $version, 'window' => $window,
            'references' => ['items' => $items, 'page' => $page, 'per_page' => self::PER_PAGE, 'total' => $total,
                'has_more' => $more, 'next_page' => $more ? $page + 1 : null, 'previous_page' => $page > 1 ? $page - 1 : null]];
    }

    private function sameParent(Builder $parent, int $id, CurrentAuthorizationReads $reads, array $first): void
    {
        abort_unless(IncidentRecordingContext::capture($parent, $id, $reads) === $first, 404);
    }

    /** Lock the whole original window/source inventory and prove the actual historical policy owner. */
    private function window(int $incident, int $revision, int $version, CurrentAuthorizationReads $reads): array
    {
        $query = IncidentEvidenceWindow::query()->where('incident_id', $incident)->where('window_revision', $revision);
        $raw = $this->rawOne($query, $reads); abort_unless($raw !== null, 404);
        $row = (new IncidentEvidenceWindow)->setRawAttributes($raw, true);
        $window = [];
        foreach (['incident_id', 'window_revision', 'introduced_evidence_version', 'actor_user_id', 'rule_revision', 'policy_change_id', 'audit_log_id'] as $key) {
            $window[$key] = (int) $row->{$key};
        }
        foreach (['rule_identifier', 'rule_snapshot_sha256', 'context_anchor_sha256', 'source_set_sha256', 'window_sha256'] as $key) { $window[$key] = $row->{$key}; }
        $window['effective_site_id'] = (int) $row->effective_site_id;
        $window['client_id'] = $row->client_id === null ? null : (int) $row->client_id;
        $window['recorded_at'] = $row->recorded_at->utc()->format('Y-m-d\TH:i:s.u\Z');
        $window['start_at'] = $row->start_at->utc()->format('Y-m-d\TH:i:s\Z');
        $window['end_at'] = $row->end_at->utc()->format('Y-m-d\TH:i:s\Z');
        $window['override_reason'] = $row->encrypted_override_reason;
        $window['context_anchor'] = $row->encrypted_context_anchor;
        $window['approved_sources'] = $row->encrypted_approved_sources;
        IncidentRecordingReconstructionValues::assertWindowRecord($window);
        abort_unless($window['introduced_evidence_version'] <= $version, 404);
        $journalQuery = IncidentEvidenceRevision::query()->where('incident_id', $incident)
            ->where('incident_evidence_version', $window['introduced_evidence_version']);
        $journal = $this->rawOne($journalQuery, $reads);
        abort_unless($journal !== null && $journal['kind'] === 'window_recorded'
            && (int) $journal['window_revision'] === $revision && $journal['attachment_uuid'] === null
            && $journal['predecessor_attachment_uuid'] === null
            && (int) $journal['actor_user_id'] === $window['actor_user_id']
            && (int) $journal['audit_log_id'] === $window['audit_log_id']
            && (new IncidentEvidenceRevision)->setRawAttributes($journal, true)->recorded_at->utc()->format('Y-m-d\TH:i:s.u\Z') === $window['recorded_at'], 404);
        $sourcesQuery = IncidentEvidenceWindowSource::query()->where('incident_id', $incident)->where('window_revision', $revision)->orderBy('id');
        $sources = $this->rawMany($sourcesQuery, $reads);
        $members = array_map(static fn (array $source): array => ['camera_id' => (int) $source['camera_id'],
            'recorder_device_id' => (int) $source['recorder_device_id'], 'site_id' => (int) $source['site_id']], $sources);
        abort_unless(IncidentRecordingReconstructionValues::sourceSet($members, $window['effective_site_id']) === $window['approved_sources'], 404);
        $this->policy->assertRecordedWindowPolicy($window, $reads);
        $auditProof = $this->auditBinding($window['audit_log_id'], $incident, $window['actor_user_id'],
            IncidentRecordingAuditValues::WINDOW_RECORDED, IncidentRecordingAuditValues::window($window), $reads, $window['recorded_at']);
        $proof = function () use ($query, $raw, $journalQuery, $journal, $sourcesQuery, $sources, $window, $reads, $auditProof): void {
            abort_unless($this->rawOne($query, $reads) === $raw && $this->rawMany($sourcesQuery, $reads) === $sources
                && $this->rawOne($journalQuery, $reads) === $journal, 404);
            $this->policy->assertRecordedWindowPolicy($window, $reads);
            $auditProof();
        };
        return [$window, $proof];
    }

    private function attachmentBinding(IncidentRecordingAttachment $row, array $window, int $version,
        CurrentAuthorizationReads $reads): \Closure
    {
        IncidentRecordingReconstructionValues::uuid($row->attachment_uuid, 'attachment_uuid');
        IncidentRecordingReconstructionValues::uuid($row->reference_uuid, 'reference_uuid');
        abort_unless((int) $row->incident_id === $window['incident_id'] && (int) $row->attached_evidence_version >= 2
            && (int) $row->attached_evidence_version <= $version
            && $window['introduced_evidence_version'] < (int) $row->attached_evidence_version
            && $row->requested_start_at->utc()->format('Y-m-d\TH:i:s\Z') === $window['start_at']
            && $row->requested_end_at->utc()->format('Y-m-d\TH:i:s\Z') === $window['end_at']
            && in_array(['camera_id' => (int) $row->camera_id, 'recorder_device_id' => (int) $row->recorder_device_id,
                'site_id' => (int) $row->site_id], $window['approved_sources'], true), 404);
        $query = IncidentEvidenceRevision::query()->where('incident_id', $row->incident_id)
            ->where('incident_evidence_version', $row->attached_evidence_version);
        $revision = $this->rawOne($query, $reads);
        abort_unless($revision !== null && $revision['kind'] === 'recording_attached'
            && $revision['attachment_uuid'] === $row->attachment_uuid && (int) $revision['window_revision'] === (int) $row->window_revision
            && $revision['predecessor_attachment_uuid'] === null
            && (int) $revision['actor_user_id'] === (int) $row->actor_user_id && (int) $revision['audit_log_id'] === (int) $row->audit_log_id
            && (new IncidentEvidenceRevision)->setRawAttributes($revision, true)->recorded_at->utc()->format('Y-m-d\TH:i:s.u\Z')
                === $row->recorded_at->utc()->format('Y-m-d\TH:i:s.u\Z'), 404);
        return function () use ($query, $revision, $reads): void { abort_unless($this->rawOne($query, $reads) === $revision, 404); };
    }

    /** The immutable original command is structural provenance, never a current source grant. */
    private function commandReceipt(IncidentRecordingAttachment $row, CurrentAuthorizationReads $reads): array
    {
        $query = IncidentRecordingCommandReceipt::query()->where('incident_id', $row->incident_id)->where('attachment_uuid', $row->attachment_uuid);
        $raw = $this->rawOne($query, $reads); abort_unless($raw !== null, 404);
        $receipt = (new IncidentRecordingCommandReceipt)->setRawAttributes($raw, true);
        IncidentRecordingReconstructionValues::uuid($receipt->idempotency_key, 'idempotency_key');
        IncidentRecordingReconstructionValues::hash($receipt->fingerprint, 'fingerprint');
        abort_unless($receipt->command === IncidentRecordingReconstructionValues::ATTACH
            && (int) $receipt->actor_user_id === (int) $row->actor_user_id
            && (int) $receipt->window_revision === (int) $row->window_revision
            && (int) $receipt->confirmed_evidence_version === (int) $row->attached_evidence_version
            && (int) $receipt->submitted_evidence_version + 1 === (int) $row->attached_evidence_version
            && (int) $receipt->audit_log_id === (int) $row->audit_log_id
            && $receipt->confirmed_at->utc()->format('Y-m-d\TH:i:s.u\Z') === $row->recorded_at->utc()->format('Y-m-d\TH:i:s.u\Z'), 404);
        return [$receipt, function () use ($query, $raw, $reads): void { abort_unless($this->rawOne($query, $reads) === $raw, 404); }];
    }

    /** Exact incident/action/revision audit identity, including rejection of conflicting duplicate audits. */
    private function auditBinding(int $auditId, int $incidentId, int $actorId, string $action, array $meta, CurrentAuthorizationReads $reads, string $recordedAt): \Closure
    {
        $query = AuditLog::query()->whereKey($auditId);
        $raw = $this->rawOne($query, $reads);
        abort_unless($raw !== null && (int) $raw['user_id'] === $actorId
            && $raw['auditable_type'] === (new ClientIncident)->getMorphClass() && (int) $raw['auditable_id'] === $incidentId
            && $raw['action'] === $action
            // audit_logs stores whole seconds; provenance retains its separately checked microseconds.
            && (new AuditLog)->setRawAttributes($raw, true)->created_at->utc()->format('Y-m-d\TH:i:s\Z')
                === substr($recordedAt, 0, 19).'Z'
            && IncidentRecordingReconstructionValues::digest((new AuditLog)->setRawAttributes($raw, true)->meta)
                === IncidentRecordingReconstructionValues::digest($meta), 404);
        $inventory = AuditLog::query()->where('auditable_type', (new ClientIncident)->getMorphClass())
            ->where('auditable_id', $incidentId)->where('action', $action)
            ->where('meta->incident_evidence_version', $meta['incident_evidence_version']);
        $audits = $this->rawMany($inventory, $reads);
        abort_unless(count($audits) === 1 && (int) $audits[0]['id'] === $auditId, 404);
        return function () use ($query, $raw, $reads, $inventory, $audits): void {
            abort_unless($this->rawOne($query, $reads) === $raw && $this->rawMany($inventory, $reads) === $audits, 404);
        };
    }

    private function readable(array $item, IncidentRecordingAttachment $row, array $window, IncidentRecordingCommandReceipt $receipt, array $resolved, int $actor): array
    {
        abort_unless(($resolved['actor_id'] ?? null) === $actor && is_array($resolved['reference'] ?? null), 404);
        // No saved provider/creator/purpose text is decrypted until current Devices authority succeeds.
        $snapshot = $row->encrypted_source_snapshot;
        abort_unless(is_array($snapshot) && hash_equals($row->source_snapshot_sha256, IncidentRecordingReconstructionValues::digest($snapshot)), 404);
        IncidentRecordingReconstructionValues::referenceForWindow($snapshot, $window, $row->reference_uuid);
        abort_unless(IncidentRecordingReconstructionValues::digest($snapshot) === IncidentRecordingReconstructionValues::digest($resolved['reference']), 404);
        $current = $resolved['current_source'] ?? null;
        abort_unless(is_array($current) && in_array($current['state'] ?? null, ['ready', 'changed', 'withdrawn', 'unavailable'], true)
            && (int) data_get($resolved, 'camera.id') === (int) $row->camera_id
            && (int) data_get($resolved, 'recorder.id') === (int) $row->recorder_device_id
            && (int) data_get($resolved, 'camera.site.id') === (int) $row->site_id, 404);
        $purpose = IncidentRecordingReconstructionValues::text($row->encrypted_purpose, 'purpose', 10, 500);
        abort_unless($purpose === $row->encrypted_purpose, 404);
        $input = ['expected_actor_id' => (int) $row->actor_user_id, 'idempotency_key' => $receipt->idempotency_key,
            'expected_incident_evidence_version' => (int) $receipt->submitted_evidence_version,
            'evidence_window_revision' => (int) $row->window_revision, 'reference_uuid' => $row->reference_uuid, 'purpose' => $purpose];
        abort_unless(hash_equals($receipt->fingerprint, IncidentRecordingReconstructionValues::attachFingerprint((int) $row->actor_user_id, (int) $row->incident_id, $input)), 404);
        $confirmation = IncidentRecordingReconstructionValues::attachConfirmation((int) $row->actor_user_id, (int) $row->incident_id,
            $input, $window, $snapshot, $row->attachment_uuid, (int) $row->attached_evidence_version,
            $row->recorded_at->utc()->format('Y-m-d\TH:i:s.u\Z'));
        abort_unless(IncidentRecordingReconstructionValues::digest($confirmation) === IncidentRecordingReconstructionValues::digest($receipt->confirmed_result), 404);
        return [...$item, 'access' => 'readable', 'reference' => ['reference_uuid' => $row->reference_uuid,
            'camera' => ['id' => (int) $row->camera_id, 'name' => (string) data_get($resolved, 'camera.name')],
            'recorder' => ['id' => (int) $row->recorder_device_id, 'name' => (string) data_get($resolved, 'recorder.name')],
            'site' => ['id' => (int) $row->site_id, 'name' => (string) data_get($resolved, 'camera.site.name')],
            'purpose' => $purpose, 'state' => 'reference_only', 'preserved' => false, 'window_coverage' => 'not_verified',
            'recording_verified_at' => null, 'vendor_retention_until' => null,
            'current_source' => ['state' => $current['state'], 'source_verified_at' => $current['source_verified_at'] ?? null]]];
    }

    private function rawOne(Builder $query, CurrentAuthorizationReads $reads): ?array
    {
        $row = $reads->query((clone $query)->setEagerLoads([]))->toBase()->first();
        return $row === null ? null : (array) $row;
    }

    private function rawMany(Builder $query, CurrentAuthorizationReads $reads): array
    {
        return $reads->query((clone $query)->setEagerLoads([]))->toBase()->get()->map(static fn ($row): array => (array) $row)->all();
    }
}
