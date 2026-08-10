<?php

namespace App\Domain\Monitoring\Discovery\Services;

use App\Domain\Monitoring\Discovery\Contracts\DiscoveryAdapter;
use App\Domain\Monitoring\Discovery\Data\DiscoveredIdentity;
use App\Domain\Monitoring\Discovery\Data\DiscoveryProbeResult;
use App\Domain\Monitoring\Discovery\Data\DiscoveryTarget;
use App\Domain\Monitoring\Discovery\Data\IdentityMatchResult;
use App\Domain\Monitoring\Discovery\Models\DeviceIdentityEvidence;
use App\Domain\Monitoring\Discovery\Models\DiscoveryCandidate;
use App\Domain\Monitoring\Discovery\Models\DiscoveryResult;
use App\Domain\Monitoring\Discovery\Models\DiscoveryRun;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Exceptions\RuntimePayloadInvalid;
use App\Domain\Monitoring\Exceptions\RuntimeScopeViolation;
use App\Domain\Monitoring\Exceptions\RuntimeSiteScopeViolation;
use App\Domain\Monitoring\Jobs\CompleteDiscoveryRun;
use App\Domain\Monitoring\Jobs\RunDiscoveryScope;
use App\Domain\Monitoring\Models\MonitoringCollector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;

final class DiscoveryRunner
{
    private const ACTIVE_STATUSES = ['queued', 'running'];

    private const TERMINAL_STATUSES = ['completed', 'failed', 'cancelled'];

    public function __construct(
        private readonly DiscoveryAdapter $adapter,
        private readonly DiscoveryScopeValidator $scopeValidator,
        private readonly DeviceIdentityMatcher $identityMatcher,
        private readonly DiscoveryCandidateService $candidates,
    ) {}

    public function start(DiscoveryScope $scope, string $trigger): DiscoveryRun
    {
        if (preg_match('/^[a-z][a-z0-9_.-]*(?::[A-Za-z0-9_.-]+){0,4}$/', $trigger) !== 1
            || strlen($trigger) > 190) {
            throw new UnexpectedValueException('Discovery trigger is invalid.');
        }

        $created = false;
        $run = DB::transaction(function () use ($scope, $trigger, &$created): DiscoveryRun {
            $locked = DiscoveryScope::query()->lockForUpdate()->findOrFail($scope->id);
            $this->assertScope($locked);

            $active = DiscoveryRun::query()
                ->where('discovery_scope_id', $locked->id)
                ->whereIn('status', self::ACTIVE_STATUSES)
                ->lockForUpdate()
                ->latest('id')
                ->first();
            if ($active !== null) {
                return $active;
            }

            $plannedTargets = $this->countTargets($locked);
            $created = true;

            return DiscoveryRun::query()->create([
                'discovery_scope_id' => $locked->id,
                'run_uuid' => (string) Str::orderedUuid(),
                'status' => 'queued',
                'trigger' => $trigger,
                'scope_snapshot' => $locked->snapshot(),
                'planned_targets' => $plannedTargets,
            ]);
        }, 3);

        if ($created) {
            RunDiscoveryScope::dispatch((int) $run->id)->afterCommit();
        }

        return $run;
    }

    public function execute(int $runId): DiscoveryRun
    {
        $run = $this->beginRun($runId);
        if (in_array($run->status, self::TERMINAL_STATUSES, true)) {
            return $run;
        }

        $scope = $this->scopeFromSnapshot($run);
        try {
            $this->assertScope($scope);
            $targetCount = $this->seedTargetResults($run, $scope);
        } catch (Throwable) {
            return $this->failRun($run, 'scope_validation_failed');
        }

        if ($targetCount !== (int) $run->planned_targets) {
            return $this->failRun($run, 'target_plan_drift');
        }
        if ($scope->collector_id !== null) {
            return $run->fresh();
        }

        $this->adapter->begin($scope);
        foreach ($this->adapter->targets($scope) as $target) {
            $freshRun = DiscoveryRun::query()->findOrFail($run->id);
            if ($freshRun->status === 'cancelled') {
                return $freshRun;
            }
            if ($freshRun->status !== 'running') {
                return $freshRun;
            }

            $reference = $this->targetReference($run, $target);
            $stored = DiscoveryResult::query()
                ->where('discovery_run_id', $run->id)
                ->where('target_reference_hash', $reference)
                ->firstOrFail();
            if ($stored->outcome !== 'pending') {
                continue;
            }

            try {
                $result = $this->adapter->discover($scope, $target);
            } catch (Throwable) {
                $result = DiscoveryProbeResult::failed('adapter_failure');
            }

            $this->persistResult($run, $scope, $stored, $result);
        }

        $run = DiscoveryRun::query()->findOrFail($run->id);
        if ($run->status === 'running') {
            CompleteDiscoveryRun::dispatch((int) $run->id);
        }

        return $run;
    }

    /**
     * Return a bounded, signed-workload-ready slice of pending targets for one collector.
     *
     * @return list<array<string, mixed>>
     */
    public function collectorWork(MonitoringCollector $collector, int $maximumTargets): array
    {
        if ($maximumTargets < 1 || $maximumTargets > 4096) {
            throw new UnexpectedValueException('Collector discovery workload limit is invalid.');
        }

        $remaining = $maximumTargets;
        $work = [];
        $runs = DiscoveryRun::query()
            ->with('scope')
            ->where('status', 'running')
            ->whereHas('scope', fn ($query) => $query
                ->where('collector_id', $collector->id)
                ->where('site_id', $collector->site_id)
                ->where('status', 'active'))
            ->orderBy('id')
            ->limit(16)
            ->get();

        foreach ($runs as $run) {
            if ($remaining === 0) {
                break;
            }
            $scope = $this->scopeFromSnapshot($run);
            $this->assertScope($scope);
            if ((int) $scope->collector_id !== (int) $collector->id
                || (int) $scope->site_id !== (int) $collector->site_id) {
                continue;
            }

            $pending = DiscoveryResult::query()
                ->where('discovery_run_id', $run->id)
                ->where('outcome', 'pending')
                ->pluck('target_reference_hash')
                ->flip();
            if ($pending->isEmpty()) {
                CompleteDiscoveryRun::dispatch((int) $run->id)->afterCommit();

                continue;
            }

            $targets = [];
            foreach ($this->adapter->targets($scope) as $target) {
                if (! $target instanceof DiscoveryTarget) {
                    throw new UnexpectedValueException('Discovery adapter returned an invalid target.');
                }
                if (! $pending->has($this->targetReference($run, $target))) {
                    continue;
                }
                $targets[] = ['target' => $target->host, 'source' => $target->source];
                $remaining--;
                if ($remaining === 0) {
                    break;
                }
            }
            if ($targets === []) {
                continue;
            }

            $work[] = [
                'id' => (string) $run->run_uuid,
                'site_id' => (int) $scope->site_id,
                'cidrs' => array_values($scope->cidrs ?? []),
                'protocols' => array_values($scope->protocols ?? []),
                'exclusions' => array_values($scope->exclusions ?? []),
                'port_bounds' => $scope->port_bounds ?? [],
                'packets_per_second' => (int) $scope->packets_per_second,
                'targets' => $targets,
            ];
        }

        return $work;
    }

    /** @param array<string, mixed> $payload */
    public function recordCollectorResult(MonitoringCollector $collector, array $payload): DiscoveryRun
    {
        $allowed = ['item_type', 'run_id', 'target', 'observed_at', 'outcome', 'failure_code', 'identity'];
        if (array_diff(array_keys($payload), $allowed) !== []) {
            throw new RuntimePayloadInvalid('Collector discovery result contains unsupported fields.');
        }
        $runUuid = $payload['run_id'] ?? null;
        $targetValue = $payload['target'] ?? null;
        $observedAt = $payload['observed_at'] ?? null;
        if (! is_string($runUuid) || ! Str::isUuid($runUuid)
            || ! is_string($targetValue) || $targetValue === '' || strlen($targetValue) > 253
            || ! is_string($observedAt)) {
            throw new RuntimePayloadInvalid('Collector discovery identity is invalid.');
        }
        try {
            $observed = CarbonImmutable::parse($observedAt)->utc();
        } catch (Throwable) {
            throw new RuntimePayloadInvalid('Collector discovery timestamp is invalid.');
        }
        $maximumAge = max(3600, min(691_200, (int) config('monitoring.collector.maximum_backlog_age_seconds', 691_200)));
        if ($observed->gt(CarbonImmutable::now('UTC')->addMinutes(5))
            || $observed->lt(CarbonImmutable::now('UTC')->subSeconds($maximumAge))) {
            throw new RuntimePayloadInvalid('Collector discovery timestamp is outside the accepted window.');
        }

        $run = DiscoveryRun::query()->with('scope')->where('run_uuid', $runUuid)->firstOrFail();
        if ((int) data_get($run->scope_snapshot, 'site_id') !== (int) $collector->site_id) {
            throw new RuntimeSiteScopeViolation('Collector discovery Site does not match its enrolled Site.');
        }
        if ((int) data_get($run->scope_snapshot, 'collector_id') !== (int) $collector->id
            || (int) $run->scope?->collector_id !== (int) $collector->id) {
            throw new RuntimeScopeViolation('Collector discovery run is outside its assigned scope.');
        }
        if ($run->status !== 'running' && ! in_array($run->status, self::TERMINAL_STATUSES, true)) {
            throw new RuntimeScopeViolation('Collector discovery run is not accepting results.');
        }

        try {
            $target = new DiscoveryTarget($targetValue, 'cidr');
        } catch (Throwable) {
            throw new RuntimePayloadInvalid('Collector discovery target is invalid.');
        }
        $stored = DiscoveryResult::query()
            ->where('discovery_run_id', $run->id)
            ->where('target_reference_hash', $this->targetReference($run, $target))
            ->first();
        if ($stored === null) {
            throw new RuntimeScopeViolation('Collector discovery target is outside the signed run.');
        }
        if (in_array($run->status, self::TERMINAL_STATUSES, true)) {
            return $run;
        }
        if ($stored->outcome !== 'pending') {
            return $run->fresh();
        }

        $scope = $this->scopeFromSnapshot($run);
        $result = $this->collectorProbeResult($payload);
        $this->persistResult($run, $scope, $stored, $result, $observed);
        if (! DiscoveryResult::query()
            ->where('discovery_run_id', $run->id)
            ->where('outcome', 'pending')
            ->exists()) {
            CompleteDiscoveryRun::dispatch((int) $run->id)->afterCommit();
        }

        return $run->fresh();
    }

    public function complete(int $runId): DiscoveryRun
    {
        return DB::transaction(function () use ($runId): DiscoveryRun {
            $run = DiscoveryRun::query()->lockForUpdate()->findOrFail($runId);
            if (in_array($run->status, self::TERMINAL_STATUSES, true)) {
                return $run;
            }
            if ($run->status !== 'running') {
                throw new UnexpectedValueException('Discovery run is not ready for completion.');
            }

            DiscoveryResult::query()
                ->where('discovery_run_id', $run->id)
                ->where('outcome', 'pending')
                ->update([
                    'outcome' => 'unresolved',
                    'failure_code' => 'no_result',
                    'observed_at' => now(),
                    'updated_at' => now(),
                ]);

            $run->forceFill([
                ...$this->summary($run),
                'status' => 'completed',
                'completed_at' => now(),
                'failure_summary' => $this->failureSummary($run),
            ])->save();

            return $run->fresh();
        }, 3);
    }

    public function cancel(int $runId, string $reason): DiscoveryRun
    {
        if (preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/', $reason) !== 1) {
            throw new UnexpectedValueException('Discovery cancellation reason is invalid.');
        }

        return DB::transaction(function () use ($runId, $reason): DiscoveryRun {
            $run = DiscoveryRun::query()->lockForUpdate()->findOrFail($runId);
            if (in_array($run->status, self::TERMINAL_STATUSES, true)) {
                return $run;
            }

            DiscoveryResult::query()
                ->where('discovery_run_id', $run->id)
                ->where('outcome', 'pending')
                ->update([
                    'outcome' => 'unresolved',
                    'failure_code' => 'run_cancelled',
                    'observed_at' => now(),
                    'updated_at' => now(),
                ]);
            $run->forceFill([
                ...$this->summary($run),
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'failure_summary' => $reason,
            ])->save();

            return $run->fresh();
        }, 3);
    }

    private function beginRun(int $runId): DiscoveryRun
    {
        if ($runId < 1) {
            throw new UnexpectedValueException('Discovery run identity is invalid.');
        }

        return DB::transaction(function () use ($runId): DiscoveryRun {
            $run = DiscoveryRun::query()->lockForUpdate()->findOrFail($runId);
            if ($run->status === 'queued') {
                $run->forceFill(['status' => 'running', 'started_at' => now()])->save();
            } elseif (! in_array($run->status, [...self::ACTIVE_STATUSES, ...self::TERMINAL_STATUSES], true)) {
                throw new UnexpectedValueException('Discovery run status is invalid.');
            }

            return $run->fresh();
        }, 3);
    }

    private function assertScope(DiscoveryScope $scope): void
    {
        $failure = $this->scopeValidator->validateScope($scope);
        if ($failure !== null) {
            throw new UnexpectedValueException($failure['reason']);
        }
    }

    private function countTargets(DiscoveryScope $scope): int
    {
        $count = 0;
        foreach ($this->adapter->targets($scope) as $target) {
            if (! $target instanceof DiscoveryTarget || ++$count > (int) $scope->max_targets_per_run) {
                throw new UnexpectedValueException('Discovery adapter exceeded the governed target plan.');
            }
        }

        return $count;
    }

    private function seedTargetResults(DiscoveryRun $run, DiscoveryScope $scope): int
    {
        $count = 0;
        $rows = [];
        $timestamp = now();
        foreach ($this->adapter->targets($scope) as $target) {
            if (! $target instanceof DiscoveryTarget || ++$count > (int) $scope->max_targets_per_run) {
                throw new UnexpectedValueException('Discovery adapter exceeded the governed target plan.');
            }
            $rows[] = [
                'discovery_run_id' => $run->id,
                'discovery_candidate_id' => null,
                'target_reference_hash' => $this->targetReference($run, $target),
                'target_source' => $target->source,
                'outcome' => 'pending',
                'failure_code' => null,
                'evidence_hash' => null,
                'observed_at' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
            if (count($rows) === 500) {
                DiscoveryResult::query()->insertOrIgnore($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DiscoveryResult::query()->insertOrIgnore($rows);
        }

        return $count;
    }

    private function persistResult(
        DiscoveryRun $run,
        DiscoveryScope $scope,
        DiscoveryResult $stored,
        DiscoveryProbeResult $result,
        ?CarbonImmutable $observedAt = null,
    ): void {
        $candidate = null;
        $outcome = $result->outcome;
        $failureCode = $result->failureCode;
        $evidenceHash = null;

        if ($result->identity !== null) {
            $match = $this->identityMatcher->match($scope, $result->identity);
            if ($match->decision === 'excluded') {
                $outcome = 'excluded';
                $failureCode = $match->reasons[0];
            } elseif ($match->decision === 'rejected') {
                $outcome = 'unresolved';
                $failureCode = $match->reasons[0];
            } else {
                $match = $this->annotateChange($match, $result->identity->evidence());
                $candidate = $this->candidates->record($run, $result->identity, $match);
                $evidenceHash = $result->identity->evidenceHash();
            }
        }

        DiscoveryResult::query()
            ->whereKey($stored->id)
            ->where('outcome', 'pending')
            ->update([
                'discovery_candidate_id' => $candidate?->id,
                'outcome' => $outcome,
                'failure_code' => $failureCode,
                'evidence_hash' => $evidenceHash,
                'observed_at' => $observedAt ?? now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  list<array{type: string, value: string, weight: int, reason: string, immutable: bool}>  $evidence
     */
    private function annotateChange(IdentityMatchResult $match, array $evidence): IdentityMatchResult
    {
        if ($match->deviceId === null) {
            return $match;
        }
        $hashes = collect($evidence)
            ->map(fn (array $item): string => DeviceIdentityEvidence::hashValue($item['type'], $item['value']))
            ->unique()
            ->values()
            ->all();
        if (array_diff($hashes, $match->matchedEvidenceHashes) === []) {
            return $match;
        }

        return new IdentityMatchResult(
            decision: $match->decision,
            deviceId: $match->deviceId,
            confidence: $match->confidence,
            reasons: array_values(array_unique([...$match->reasons, 'identity_change_detected'])),
            matchedEvidenceHashes: $match->matchedEvidenceHashes,
        );
    }

    /** @return array<string, int> */
    private function summary(DiscoveryRun $run): array
    {
        $results = DiscoveryResult::query()->where('discovery_run_id', $run->id)->get(['outcome']);
        $candidates = DiscoveryCandidate::query()
            ->where('discovery_run_id', $run->id)
            ->get(['decision', 'canonical_device_id', 'reasons']);

        return [
            'found_count' => $results->where('outcome', 'found')->count(),
            'matched_count' => $candidates->where('decision', 'matched')->count(),
            'proposed_count' => $candidates->where('decision', 'proposed')->count(),
            'changed_count' => $candidates->filter(
                fn (DiscoveryCandidate $candidate): bool => in_array('identity_change_detected', $candidate->reasons ?? [], true),
            )->count(),
            'excluded_count' => $results->where('outcome', 'excluded')->count(),
            'failed_count' => $results->where('outcome', 'failed')->count(),
            'unresolved_count' => $results->where('outcome', 'unresolved')->count()
                + $candidates->filter(
                    fn (DiscoveryCandidate $candidate): bool => $candidate->decision === 'review'
                        && $candidate->canonical_device_id === null,
                )->count(),
        ];
    }

    private function failureSummary(DiscoveryRun $run): ?string
    {
        $parts = DiscoveryResult::query()
            ->where('discovery_run_id', $run->id)
            ->where('outcome', 'failed')
            ->whereNotNull('failure_code')
            ->selectRaw('failure_code, COUNT(*) AS aggregate_count')
            ->groupBy('failure_code')
            ->orderBy('failure_code')
            ->get()
            ->map(fn (DiscoveryResult $result): string => "{$result->failure_code}:{$result->aggregate_count}")
            ->all();

        return $parts === [] ? null : Str::limit(implode(',', $parts), 500, '');
    }

    private function failRun(DiscoveryRun $run, string $code): DiscoveryRun
    {
        return DB::transaction(function () use ($run, $code): DiscoveryRun {
            $locked = DiscoveryRun::query()->lockForUpdate()->findOrFail($run->id);
            if (in_array($locked->status, self::TERMINAL_STATUSES, true)) {
                return $locked;
            }

            DiscoveryResult::query()
                ->where('discovery_run_id', $locked->id)
                ->where('outcome', 'pending')
                ->update([
                    'outcome' => 'failed',
                    'failure_code' => $code,
                    'observed_at' => now(),
                    'updated_at' => now(),
                ]);
            $locked->forceFill([
                ...$this->summary($locked),
                'status' => 'failed',
                'completed_at' => now(),
                'failure_summary' => $code,
            ])->save();

            return $locked->fresh();
        }, 3);
    }

    /** @param array<string, mixed> $payload */
    private function collectorProbeResult(array $payload): DiscoveryProbeResult
    {
        $outcome = $payload['outcome'] ?? null;
        $failureCode = $payload['failure_code'] ?? null;
        if (! is_string($outcome) || ! in_array($outcome, ['found', 'failed', 'unresolved'], true)) {
            throw new RuntimePayloadInvalid('Collector discovery outcome is invalid.');
        }
        if ($outcome !== 'found') {
            if (! is_string($failureCode) || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $failureCode) !== 1) {
                throw new RuntimePayloadInvalid('Collector discovery failure code is invalid.');
            }

            return $outcome === 'failed'
                ? DiscoveryProbeResult::failed($failureCode)
                : DiscoveryProbeResult::unresolved($failureCode);
        }

        $identity = $payload['identity'] ?? null;
        $allowed = ['mac_addresses', 'certificate_fingerprint', 'hostname', 'addresses', 'fingerprint'];
        if (! is_array($identity) || array_is_list($identity)
            || array_diff(array_keys($identity), $allowed) !== []) {
            throw new RuntimePayloadInvalid('Collector discovery evidence is invalid.');
        }
        foreach (['mac_addresses', 'addresses'] as $key) {
            $values = $identity[$key] ?? [];
            if (! is_array($values) || ! array_is_list($values) || count($values) > 64
                || array_any($values, fn (mixed $value): bool => ! is_string($value)
                    || $value === '' || strlen($value) > 2048)) {
                throw new RuntimePayloadInvalid('Collector discovery evidence is invalid.');
            }
        }
        foreach (['certificate_fingerprint', 'hostname', 'fingerprint'] as $key) {
            $value = $identity[$key] ?? null;
            if ($value !== null && (! is_string($value) || $value === '' || strlen($value) > 2048)) {
                throw new RuntimePayloadInvalid('Collector discovery evidence is invalid.');
            }
        }
        try {
            $discovered = new DiscoveredIdentity(
                provider: null,
                providerId: null,
                serialNumber: null,
                hardwareId: null,
                macAddresses: array_values($identity['mac_addresses'] ?? []),
                certificateFingerprint: $identity['certificate_fingerprint'] ?? null,
                hostname: $identity['hostname'] ?? null,
                addresses: array_values($identity['addresses'] ?? []),
                fingerprint: $identity['fingerprint'] ?? null,
            );
        } catch (Throwable) {
            throw new RuntimePayloadInvalid('Collector discovery evidence is invalid.');
        }
        if ($discovered->evidence() === []) {
            throw new RuntimePayloadInvalid('Collector discovery evidence is empty.');
        }

        return DiscoveryProbeResult::found($discovered);
    }

    private function scopeFromSnapshot(DiscoveryRun $run): DiscoveryScope
    {
        $scope = new DiscoveryScope;
        $scope->forceFill([
            'id' => $run->discovery_scope_id,
            ...$run->scope_snapshot,
            'deleted_at' => null,
        ]);
        $scope->exists = true;

        return $scope;
    }

    private function targetReference(DiscoveryRun $run, DiscoveryTarget $target): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new UnexpectedValueException('Discovery target hashing is unavailable.');
        }

        return hash_hmac('sha256', "{$run->run_uuid}\0{$target->key()}", $key);
    }
}
