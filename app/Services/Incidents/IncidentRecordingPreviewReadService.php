<?php

namespace App\Services\Incidents;

use App\Domain\SecurityDevices\Cctv\Models\CctvSource;
use App\Domain\SecurityDevices\Cctv\Services\{CctvAuthority, CctvRecordingChoiceReads, CctvRecordingReferences};
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\{ClientIncident, IncidentEvidenceWindow, User};
use App\Services\{AuthorizationEvidenceLockService, CurrentAuthorizationReads};
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Embedded, detached preview only. Commands independently establish every acceptance predicate. */
final class IncidentRecordingPreviewReadService
{
    public const LIMIT = 64;
    private const SCAN_LIMIT = 256;

    public function __construct(private readonly IncidentRegisterReadService $incidents,
        private readonly IncidentRecordingReferenceReadService $recordings,
        private readonly IncidentEvidenceWindowPolicySettings $policy,
        private readonly AuthorizationEvidenceLockService $authorization,
        private readonly IncidentOwnerEligibility $eligibility,
        private readonly IncidentAccessService $access,
        private readonly IncidentIndependenceIntakeService $independence) {}

    public function forIncident(Request $request, int $incidentId): array
    {
        // Same lock order as the new window command. No settings editor permission or full-policy output.
        return $this->policy->withLockedPolicy(fn (array $policy): array => $this->incidents->consumeSource($request, $incidentId,
            function (Request $working, int $id, CurrentAuthorizationReads $reads, $originalParent) use ($policy): array {
                $actor = $this->authorization->lockForUserWithoutWaiting((int) $working->user()->id, ['*']);
                abort_unless($actor->canDo('incidents.viewAny') || $actor->canDo('incidents.viewAssigned'), 403);
                $parent = $this->independence->applySourceScopeInReads($this->access->apply(ClientIncident::query()->whereKey($id), $actor, $reads), $actor, $reads);
                $raw = $reads->query(clone $parent)->setEagerLoads([])->toBase()->first(); abort_unless($raw !== null, 404);
                $incident = (new ClientIncident)->setRawAttributes((array) $raw, true);
                $currentRequest = clone $working; $currentRequest->setUserResolver(static fn () => $actor);
                // Reuse actual canonical aggregate/current-journal/original-window/audit proof.
                $evidence = $this->recordings->forIncident($currentRequest, $id);
                try { $anchor = IncidentRecordingContext::capture($parent, $id, $reads); }
                catch (\Illuminate\Validation\ValidationException $error) {
                    // No inferred occurrence, Site or type for a visible incident that has no reconstruction yet.
                    if ($evidence['incident_evidence_version'] !== null) { throw $error; }
                    $fresh = $this->authorization->lockForUserWithoutWaiting((int) $actor->id, ['*']);
                    abort_unless($fresh->canDo('incidents.viewAny') || $fresh->canDo('incidents.viewAssigned'), 403);
                    $tailParent = $this->independence->applySourceScopeInReads($this->access->apply(ClientIncident::query()->whereKey($id), $fresh, $reads), $fresh, $reads);
                    $tail = $reads->query(clone $tailParent)->setEagerLoads([])->toBase()->first();
                    abort_unless($tail !== null && (array) $tail === (array) $raw && $reads->query(clone $originalParent)->exists(), 404);
                    abort_unless($this->recordings->forIncident($currentRequest, $id) === $evidence, 404);
                    $this->assertFinalPreview($currentRequest, $id, $reads, $originalParent, $raw, null, $policy, $evidence,
                        ['can_record' => false, 'can_expand' => false, 'can_attach' => false], false);
                    return ['schema_version' => 1, 'actor_id' => (int) $actor->id, 'incident_id' => $id,
                        'incident_evidence_version' => 0, 'active_window_revision' => null,
                        'policy' => ['state' => 'unavailable', 'revision' => null, 'rule' => null],
                        'permissions' => ['can_record' => false, 'can_expand' => false, 'can_attach' => false],
                        'sources' => ['items' => [], 'limit' => self::LIMIT, 'complete' => true]];
                }
                abort_unless(IncidentRecordingContext::capture($originalParent, $id, $reads) === $anchor, 404);
                $flags = $this->permissions($actor, $incident, $reads, $evidence);
                $rule = null; $revision = null; $cameraSelection = false;
                if (($flags['can_record'] || $flags['can_attach']) && $policy['configuration_state'] === 'configured'
                    && $policy['configuration_valid'] === true && is_array($policy['policy_snapshot'])) {
                    $effective = IncidentEvidenceWindowPolicyValues::effectiveRule($policy['policy_snapshot'], $anchor['incident_type'], $anchor['effective_site_id']);
                    if ($effective !== null) {
                        $cameraSelection = in_array('camera', $effective['source_types'], true);
                        $second = (new \DateTimeImmutable($anchor['occurred_at']))->getTimestamp();
                        $utc = static fn (int $value): string => gmdate('Y-m-d\TH:i:s\Z', $value);
                        $rule = ['identifier' => $effective['identifier'], 'before_seconds' => $effective['before_seconds'],
                            'after_seconds' => $effective['after_seconds'], 'max_before_seconds' => $effective['max_before_seconds'],
                            'max_after_seconds' => $effective['max_after_seconds'],
                            'default_start_at' => $utc($second - $effective['before_seconds']), 'default_end_at' => $utc($second + $effective['after_seconds']),
                            'earliest_start_at' => $utc($second - $effective['max_before_seconds']), 'latest_end_at' => $utc($second + $effective['max_after_seconds'])];
                        $revision = $policy['policy_revision'];
                    }
                }
                if ($rule === null) { $flags['can_record'] = $flags['can_expand'] = false; }
                $finish = function (?CctvRecordingChoiceReads $choices) use ($working, $actor, $id, $reads, $originalParent,
                    $policy, $raw, $currentRequest, $evidence, $anchor, $flags, $rule, $revision, $cameraSelection): array {
                $members = [];
                if ($evidence['window'] !== null) {
                    $windowRaw = $reads->query(IncidentEvidenceWindow::query()->where('incident_id', $id)->where('window_revision', $evidence['window']['revision']))->toBase()->first();
                    abort_unless($windowRaw !== null, 404);
                    $members = (new IncidentEvidenceWindow)->setRawAttributes((array) $windowRaw, true)->encrypted_approved_sources;
                }
                $sources = $choices !== null && (($flags['can_record'] && $cameraSelection) || $flags['can_attach'])
                    ? $this->sources($actor, $anchor['effective_site_id'], $members, $reads, $flags['can_record'] && $cameraSelection, $choices)
                    : ['items' => [], 'limit' => self::LIMIT, 'complete' => true];
                $this->policy->withLockedPolicy(function (array $currentPolicy) use ($policy): array {
                    abort_unless($currentPolicy === $policy, 404); return [];
                });
                $evidenceTail = $this->recordings->forIncident($currentRequest, $id);
                abort_unless($evidenceTail['incident_evidence_version'] === $evidence['incident_evidence_version']
                    && $evidenceTail['window'] === $evidence['window'], 404);
                $fresh = $this->authorization->lockForUserWithoutWaiting((int) $actor->id, ['*']);
                abort_unless($fresh->canDo('incidents.viewAny') || $fresh->canDo('incidents.viewAssigned'), 403);
                $freshParent = $this->independence->applySourceScopeInReads($this->access->apply(ClientIncident::query()->whereKey($id), $fresh, $reads), $fresh, $reads);
                abort_unless(IncidentRecordingContext::capture($freshParent, $id, $reads) === $anchor
                    && IncidentRecordingContext::capture($originalParent, $id, $reads) === $anchor, 404);
                $tail = $reads->query(clone $freshParent)->setEagerLoads([])->toBase()->first();
                abort_unless($tail !== null && (array) $tail === (array) $raw, 404);
                $freshFlags = $this->permissions($fresh, (new ClientIncident)->setRawAttributes((array) $tail, true), $reads, $evidence);
                if ($rule === null) { $freshFlags['can_record'] = $freshFlags['can_expand'] = false; }
                abort_unless($freshFlags === $flags, 404);
                $this->assertFinalPreview($currentRequest, $id, $reads, $originalParent, $raw, $anchor, $policy, $evidence, $flags, $rule !== null);
                return ['schema_version' => 1, 'actor_id' => (int) $actor->id, 'incident_id' => $id,
                    'incident_evidence_version' => $evidence['incident_evidence_version'] ?? 0,
                    'active_window_revision' => $evidence['window']['revision'] ?? null,
                    'policy' => ['state' => $rule === null ? 'unavailable' : 'configured', 'revision' => $revision, 'rule' => $rule],
                    'permissions' => $flags, 'sources' => $sources];
                };
                // No query or external callback occurs after the source-owned final verifier.
                // A record operation with explicitly empty sources never gains a Devices prerequisite.
                $browse = false;
                if (($flags['can_record'] && $cameraSelection) || $flags['can_attach']) {
                    try { $browse = app(CctvAuthority::class)->actor((int) $actor->id, false, true)->canDo(CctvRecordingReferences::VIEW); }
                    catch (HttpExceptionInterface $error) { if (!in_array($error->getStatusCode(), [403, 404, 409, 503], true)) { throw $error; } }
                }
                return $browse ? CctvRecordingChoiceReads::consume((int) $actor->id, $reads, $finish) : $finish(null);
            }));
    }

    private function permissions(User $actor, ClientIncident $incident, CurrentAuthorizationReads $reads, array $evidence): array
    {
        $write = $this->eligibility->activeInternal($actor, $reads) && $incident->status !== 'closed'
            && $actor->canDo('incidents.update') && ($actor->canDo('incidents.viewAny') || $incident->isEditableByReporter($actor));
        $record = $write && $actor->canDo('incidents.evidence.window.record');
        $attach = $write && $actor->canDo('incidents.evidence.attach') && ($evidence['window']['context_state'] ?? null) === 'current';
        if ($attach) {
            try { $attach = app(CctvAuthority::class)->actor((int) $actor->id, false, true)->canDo(CctvRecordingReferences::VIEW); }
            catch (HttpExceptionInterface $error) { if (!in_array($error->getStatusCode(), [403, 404, 409, 503], true)) { throw $error; } $attach = false; }
        }
        return ['can_record' => $record, 'can_expand' => $record && $actor->canDo('incidents.evidence.window.expand'), 'can_attach' => $attach];
    }

    /** Private fixed H&S proof only. No caller callback/query/alternate authority can enter this block. */
    private function assertFinalPreview(Request $request, int $id, CurrentAuthorizationReads $reads, Builder $originalParent,
        object $originalIncident, ?array $anchor, array $policy, array $evidence, array $flags, bool $hasRule): void
    {
        $reads->assertActive(); $connection = DB::connection(); $level = $connection->transactionLevel();
        // The actual policy/reader APIs use nested transactions. Reject connection hooks that
        // would execute outside the Eloquent/query event dispatcher during those fixed reads.
        foreach (['beforeExecutingCallbacks', 'beforeStartingTransaction'] as $field) {
            $property = new \ReflectionProperty(\Illuminate\Database\Connection::class, $field);
            if ($property->getValue($connection) !== []) { throw new LogicException('Preview final proof cannot execute connection callbacks.'); }
        }
        $events = $connection->getEventDispatcher(); $connection->unsetEventDispatcher();
        try {
            Model::withoutEvents(function () use ($request, $id, $reads, $originalParent, $originalIncident, $anchor,
                $policy, $evidence, $flags, $hasRule, $connection, $level): void {
                $this->policy->assertLockedSnapshot($policy, $reads);
                $actor = $this->authorization->lockForUserWithoutWaiting((int) $request->user()->id, ['*']);
                abort_unless($actor->canDo('incidents.viewAny') || $actor->canDo('incidents.viewAssigned'), 403);
                $parent = $this->independence->applySourceScopeInReads($this->access->apply(ClientIncident::query()->whereKey($id), $actor, $reads), $actor, $reads);
                $raw = $reads->query(clone $parent)->setEagerLoads([])->toBase()->first();
                abort_unless($raw !== null && (array) $raw === (array) $originalIncident && $reads->query(clone $originalParent)->exists(), 404);
                if ($anchor !== null) {
                    abort_unless(IncidentRecordingContext::capture($parent, $id, $reads) === $anchor
                        && IncidentRecordingContext::capture($originalParent, $id, $reads) === $anchor, 404);
                }
                $working = clone $request; $working->setUserResolver(static fn () => $actor);
                abort_unless($this->recordings->forIncident($working, $id) === $evidence, 404);
                $currentFlags = $anchor === null ? ['can_record' => false, 'can_expand' => false, 'can_attach' => false]
                    : $this->permissions($actor, (new ClientIncident)->setRawAttributes((array) $raw, true), $reads, $evidence);
                if (!$hasRule) { $currentFlags['can_record'] = $currentFlags['can_expand'] = false; }
                abort_unless($currentFlags === $flags, 404);
                $reads->assertActive();
                if ($connection !== DB::connection() || DB::transactionLevel() !== $level) {
                    throw new LogicException('Preview final proof changed its original transaction.');
                }
            });
        } finally {
            if ($events === null) { $connection->unsetEventDispatcher(); }
            else { $connection->setEventDispatcher($events); }
        }
    }

    private function sources(User $actor, int $siteId, array $active, CurrentAuthorizationReads $reads, bool $recordCameras, CctvRecordingChoiceReads $choices): array
    {
        // Enumeration narrows candidates only. Every returned label and tuple comes from the
        // lexical source owner, whose final fixed proof runs after the complete preview consumer.
        $query = app(SecurityDevicesAccessService::class)->visibleDevicesForSite($actor, $siteId)
            ->where('devices.domain', 'security')->where('devices.category', 'cctv')
            ->where(fn ($query) => $query->where('devices.subcategory', '!=', 'nvr')->orWhereNull('devices.subcategory'))
            ->orderBy('devices.id')->limit(self::SCAN_LIMIT + 1)->select('devices.id');
        $ids = $reads->query($query)->toBase()->get()->pluck('id')->all();
        $complete = count($ids) <= self::SCAN_LIMIT; $items = []; $retained = [];
        foreach ($active as $tuple) {
            if ($tuple['site_id'] !== $siteId) { continue; }
            if (count($retained) === self::LIMIT) { $complete = false; break; }
            try {
                $choice = $choices->tuple($tuple['camera_id'], $tuple['recorder_device_id'], $siteId);
                $key = $tuple['camera_id'].':'.$tuple['recorder_device_id'].':'.$siteId;
                $retained[$key] = true;
                $matches = $choice['current_source_matches']; unset($choice['current_source_matches']);
                $items[$key] = [...$choice, 'in_active_window' => true, 'can_record_window' => $recordCameras && $matches];
            } catch (HttpExceptionInterface $error) { if (!in_array($error->getStatusCode(), [403, 404, 409, 503], true)) { throw $error; } }
        }
        foreach (array_slice($ids, 0, self::SCAN_LIMIT) as $id) {
            $source = $reads->query(CctvSource::query()->where('camera_id', $id)->orderByDesc('revision'))->toBase()->first();
            if ($source === null || (int) $source->site_id !== $siteId) { continue; }
            $tuple = ['camera_id' => (int) $id, 'recorder_device_id' => (int) $source->recorder_device_id, 'site_id' => $siteId];
            $key = $tuple['camera_id'].':'.$tuple['recorder_device_id'].':'.$siteId;
            if (!isset($retained[$key]) && count($retained) === self::LIMIT) { $complete = false; break; }
            try {
                $choice = $choices->tuple($tuple['camera_id'], $tuple['recorder_device_id'], $siteId);
                $retained[$key] = true;
                if (!$choice['current_source_matches']) { continue; }
                unset($choice['current_source_matches']);
                $items[$key] = [...$choice, 'in_active_window' => in_array($tuple, $active, true), 'can_record_window' => $recordCameras];
            } catch (HttpExceptionInterface $error) { if (!in_array($error->getStatusCode(), [403, 404, 409, 503], true)) { throw $error; } }
        }
        return ['items' => array_values($items), 'limit' => self::LIMIT, 'complete' => $complete];
    }
}
