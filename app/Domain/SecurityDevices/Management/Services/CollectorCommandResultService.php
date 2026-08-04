<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\Monitoring\Exceptions\RuntimePayloadInvalid;
use App\Domain\Monitoring\Exceptions\RuntimeScopeViolation;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
use App\Domain\SecurityDevices\Management\Enums\CommandReconciliationOutcome;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandAttempt;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandReconciliation;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class CollectorCommandResultService
{
    public function __construct(
        private readonly CollectorCommandContract $contracts,
        private readonly DeviceCommandAuditService $audit,
    ) {}

    /** @param array<string, mixed> $payload */
    public function record(MonitoringCollector $collector, int $sequence, array $payload): void
    {
        $validated = $this->validate($payload);

        DB::transaction(function () use ($collector, $sequence, $validated): void {
            $request = DeviceCommandRequest::query()
                ->with('device')
                ->where('command_uuid', $validated['command_uuid'])
                ->lockForUpdate()
                ->firstOrFail();
            $attempt = DeviceCommandAttempt::query()
                ->where('attempt_uuid', $validated['attempt_uuid'])
                ->lockForUpdate()
                ->firstOrFail();
            if ((int) $request->collector_id !== (int) $collector->id
                || (int) $request->site_id !== (int) $collector->site_id
                || (int) $request->device_id !== $validated['device_id']
                || (int) $attempt->device_command_request_id !== (int) $request->id
                || (int) $attempt->attempt_number !== $validated['attempt_number']
                || $attempt->runtime !== 'collector'
                || ! hash_equals($request->capability, $validated['capability'])
                || ! hash_equals(
                    $this->contracts->hash($request, $attempt, $collector),
                    $validated['contract_hash'],
                )) {
                throw new RuntimeScopeViolation('Collector command result is outside its immutable request scope.');
            }

            $attemptStatus = CommandAttemptStatus::from($validated['execution_status']);
            if ($attempt->status->isTerminal()) {
                if ($attempt->status !== $attemptStatus
                    || ($attempt->safe_result_summary ?? []) !== $validated['safe_result']
                    || $attempt->safe_failure_reason !== $validated['safe_failure_reason']) {
                    throw new RuntimePayloadInvalid('Collector command result replay conflicts with immutable evidence.');
                }

                return;
            }
            if (! in_array($request->status, [
                CommandStatus::Dispatching,
                CommandStatus::Accepted,
                CommandStatus::Running,
            ], true)) {
                throw new RuntimePayloadInvalid('Collector command lifecycle changed before result ingestion.');
            }

            $attempt->status = $attemptStatus;
            $attempt->provider_request_reference = $validated['provider_request_reference'];
            $attempt->safe_result_summary = $validated['safe_result'];
            $attempt->safe_failure_reason = $validated['safe_failure_reason'];
            $attempt->evidence_reference = "collector:{$collector->collector_uuid}:sequence:{$sequence}";
            $attempt->accepted_at = $validated['accepted_at'];
            $attempt->started_at = $validated['started_at'];
            $attempt->completed_at = $validated['completed_at'];
            $attempt->save();

            $request->status = match ($attemptStatus) {
                CommandAttemptStatus::Succeeded => CommandStatus::Reconciling,
                CommandAttemptStatus::Failed => CommandStatus::Failed,
                CommandAttemptStatus::Uncertain => CommandStatus::Uncertain,
                default => throw new RuntimePayloadInvalid('Collector command result status is invalid.'),
            };
            $request->execution_route = 'collector';
            $request->execution_completed_at = $validated['completed_at'];
            $request->safe_result_summary = $validated['safe_result'] === []
                ? null
                : json_encode($validated['safe_result'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $request->safe_failure_reason = $validated['safe_failure_reason'];
            $request->save();
            $this->audit->append($request, null, 'collector_execution_'.$attemptStatus->value, [
                'attempt_number' => $attempt->attempt_number,
                'collector_id' => (int) $collector->id,
                'source_sequence' => $sequence,
                'status' => $request->status->value,
                'safe_result' => $validated['safe_result'],
            ]);

            if ($validated['reconciliation'] !== null) {
                $this->recordReconciliation(
                    $request,
                    $attempt,
                    $collector,
                    $sequence,
                    $validated['reconciliation'],
                );
            }
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *   command_uuid: string, attempt_uuid: string, attempt_number: int, site_id: int,
     *   device_id: int, capability: string, contract_hash: string, execution_status: string,
     *   safe_result: array<string, scalar|null>, provider_request_reference: ?string,
     *   safe_failure_reason: ?string, accepted_at: CarbonImmutable, started_at: CarbonImmutable,
     *   completed_at: CarbonImmutable, reconciliation: ?array<string, mixed>
     * }
     */
    private function validate(array $payload): array
    {
        $allowed = [
            'item_type', 'command_uuid', 'attempt_uuid', 'attempt_number', 'site_id',
            'device_id', 'capability', 'contract_hash', 'execution_status', 'safe_result',
            'provider_request_reference', 'safe_failure_reason', 'accepted_at', 'started_at',
            'completed_at', 'reconciliation',
        ];
        if (array_diff(array_keys($payload), $allowed) !== []
            || array_diff($allowed, array_keys($payload)) !== []
            || ($payload['item_type'] ?? null) !== 'command_result') {
            throw new RuntimePayloadInvalid('Collector command result fields are invalid.');
        }
        $uuid = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i';
        if (! is_string($payload['command_uuid']) || preg_match($uuid, $payload['command_uuid']) !== 1
            || ! is_string($payload['attempt_uuid']) || preg_match($uuid, $payload['attempt_uuid']) !== 1
            || ! is_int($payload['attempt_number']) || $payload['attempt_number'] < 1
            || ! is_int($payload['site_id']) || $payload['site_id'] < 1
            || ! is_string($payload['device_id']) || preg_match('/\A[1-9][0-9]{0,18}\z/', $payload['device_id']) !== 1
            || ! is_string($payload['capability']) || preg_match('/\A[a-z][a-z0-9._]{1,119}\z/', $payload['capability']) !== 1
            || ! is_string($payload['contract_hash']) || preg_match('/\A[0-9a-f]{64}\z/', $payload['contract_hash']) !== 1
            || ! in_array($payload['execution_status'], ['succeeded', 'failed', 'uncertain'], true)) {
            throw new RuntimePayloadInvalid('Collector command result identity is invalid.');
        }
        $safeResult = $this->safeMap($payload['safe_result'], 16);
        $providerReference = $payload['provider_request_reference'];
        $failure = $payload['safe_failure_reason'];
        if (($providerReference !== null && (! is_string($providerReference)
                || preg_match('/\A[A-Za-z0-9._:-]{1,160}\z/', $providerReference) !== 1))
            || ($failure !== null && (! is_string($failure) || trim($failure) === '' || mb_strlen($failure) > 2000))) {
            throw new RuntimePayloadInvalid('Collector command result summary is invalid.');
        }
        $this->assertTypedUnifiResult(
            $payload['execution_status'],
            $safeResult,
            $providerReference,
            $failure,
            $payload['command_uuid'],
        );
        try {
            $acceptedAt = CarbonImmutable::parse($payload['accepted_at'])->utc();
            $startedAt = CarbonImmutable::parse($payload['started_at'])->utc();
            $completedAt = CarbonImmutable::parse($payload['completed_at'])->utc();
        } catch (\Throwable) {
            throw new RuntimePayloadInvalid('Collector command result timestamps are invalid.');
        }
        if ($acceptedAt->gt($startedAt) || $startedAt->gt($completedAt)
            || $completedAt->gt(CarbonImmutable::now('UTC')->addMinutes(5))) {
            throw new RuntimePayloadInvalid('Collector command result chronology is invalid.');
        }
        $reconciliation = $payload['reconciliation'];
        if ($payload['execution_status'] === 'failed') {
            if ($reconciliation !== null) {
                throw new RuntimePayloadInvalid('A failed pre-execution command cannot claim reconciliation evidence.');
            }
        } else {
            $reconciliation = $this->reconciliation($reconciliation);
        }

        return [
            'command_uuid' => strtolower($payload['command_uuid']),
            'attempt_uuid' => strtolower($payload['attempt_uuid']),
            'attempt_number' => $payload['attempt_number'],
            'site_id' => $payload['site_id'],
            'device_id' => (int) $payload['device_id'],
            'capability' => $payload['capability'],
            'contract_hash' => $payload['contract_hash'],
            'execution_status' => $payload['execution_status'],
            'safe_result' => $safeResult,
            'provider_request_reference' => $providerReference,
            'safe_failure_reason' => $failure === null ? null : trim($failure),
            'accepted_at' => $acceptedAt,
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'reconciliation' => $reconciliation,
        ];
    }

    /** @return array<string, mixed> */
    private function reconciliation(mixed $value): array
    {
        $allowed = [
            'outcome', 'observed_state', 'observation_reference',
            'safe_evidence_summary', 'observed_at',
        ];
        if (! is_array($value) || array_diff(array_keys($value), $allowed) !== []
            || array_diff($allowed, array_keys($value)) !== []
            || ! in_array($value['outcome'], ['matched', 'mismatch', 'uncertain'], true)) {
            throw new RuntimePayloadInvalid('Collector command reconciliation is invalid.');
        }
        $observed = $value['observed_state'];
        if ($value['outcome'] === 'uncertain') {
            if ($observed !== null) {
                throw new RuntimePayloadInvalid('Uncertain collector reconciliation cannot claim observed state.');
            }
        } else {
            $observed = $this->safeMap($observed, 16);
            if (array_keys($observed) !== ['locked'] || ! is_bool($observed['locked'])) {
                throw new RuntimePayloadInvalid('Collector command observed state is invalid.');
            }
        }
        $reference = $value['observation_reference'];
        $summary = $value['safe_evidence_summary'];
        if (($reference !== null && (! is_string($reference)
                || preg_match('/\A[A-Za-z0-9._:-]{1,255}\z/', $reference) !== 1))
            || ! is_string($summary) || trim($summary) === '' || mb_strlen($summary) > 2000) {
            throw new RuntimePayloadInvalid('Collector command reconciliation evidence is invalid.');
        }
        try {
            $observedAt = CarbonImmutable::parse($value['observed_at'])->utc();
        } catch (\Throwable) {
            throw new RuntimePayloadInvalid('Collector command reconciliation time is invalid.');
        }
        $allowedSummaries = [
            'The remote Site collector freshly confirmed that the door relay returned to locked.',
            'The remote Site collector freshly reported that the door relay remains unlocked.',
            'The remote Site collector could not freshly confirm the final door state. Do not retry until actual state is known.',
            'Fresh state is required before any new attempt.',
        ];
        if (! in_array(trim($summary), $allowedSummaries, true)
            || ($value['outcome'] === 'matched' && $observed !== ['locked' => true])
            || ($value['outcome'] === 'mismatch' && $observed !== ['locked' => false])) {
            throw new RuntimePayloadInvalid('Collector command reconciliation evidence is not a declared typed outcome.');
        }

        return [
            'outcome' => $value['outcome'],
            'observed_state' => $observed,
            'observation_reference' => $reference,
            'safe_evidence_summary' => trim($summary),
            'observed_at' => $observedAt,
        ];
    }

    /** @param array<string, scalar|null> $safeResult */
    private function assertTypedUnifiResult(
        string $status,
        array $safeResult,
        ?string $providerReference,
        ?string $failure,
        string $commandUuid,
    ): void {
        if ($status === 'succeeded') {
            if (array_keys($safeResult) !== [
                'provider_state', 'previous_lock_state', 'unlock_duration_seconds',
            ]
                || $safeResult['provider_state'] !== 'accepted'
                || $safeResult['previous_lock_state'] !== 'locked'
                || ! is_int($safeResult['unlock_duration_seconds'])
                || $safeResult['unlock_duration_seconds'] < 5
                || $safeResult['unlock_duration_seconds'] > 60
                || $providerReference !== 'unifi-access:'.$commandUuid
                || $failure !== null) {
                throw new RuntimePayloadInvalid('Collector UniFi success evidence is invalid.');
            }

            return;
        }
        $allowedFailures = [
            'UniFi Access reports that the mapped door is not bound to an access hub.',
            'The door was not in the approved locked state immediately before execution.',
            'UniFi Access rejected the credential or required API permission.',
            'The mapped UniFi Access door is unavailable.',
            'UniFi Access rejected the door action in its current state.',
            'UniFi Access rate-limited the door action.',
            'UniFi Access did not accept the remote door action.',
            'The collector did not receive a confirmed final provider response. Actual state was checked before any retry.',
            'The collector could not complete the approved pre-execution state and credential checks.',
            'The collector restarted after persisting execution intent without a durable final result. The action was not repeated.',
        ];
        if ($safeResult !== [] || $providerReference !== null
            || ! is_string($failure) || ! in_array(trim($failure), $allowedFailures, true)) {
            throw new RuntimePayloadInvalid('Collector UniFi failure evidence is invalid.');
        }
    }

    /** @return array<string, scalar|null> */
    private function safeMap(mixed $value, int $maximum): array
    {
        if (! is_array($value) || array_is_list($value) || count($value) > $maximum) {
            throw new RuntimePayloadInvalid('Collector command safe evidence is invalid.');
        }
        foreach ($value as $key => $item) {
            if (! is_string($key) || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $key) !== 1
                || preg_match('/body|authorization|cookie|credential|password|secret|token|certificate|raw/i', $key) === 1
                || (! is_scalar($item) && $item !== null)
                || (is_string($item) && mb_strlen($item) > 256)) {
                throw new RuntimePayloadInvalid('Collector command safe evidence is invalid.');
            }
        }
        $this->audit->assertSafeContext($value);

        return $value;
    }

    /** @param array<string, mixed> $evidence */
    private function recordReconciliation(
        DeviceCommandRequest $request,
        DeviceCommandAttempt $attempt,
        MonitoringCollector $collector,
        int $sequence,
        array $evidence,
    ): void {
        $outcome = CommandReconciliationOutcome::from($evidence['outcome']);
        $reconciliation = DeviceCommandReconciliation::query()->create([
            'device_command_request_id' => $request->id,
            'device_command_attempt_id' => $attempt->id,
            'outcome' => $outcome,
            'expected_state' => $request->expected_state,
            'observed_state' => $evidence['observed_state'],
            'observation_reference' => $evidence['observation_reference'],
            'safe_evidence_summary' => $evidence['safe_evidence_summary'],
            'observed_at' => $evidence['observed_at'],
        ]);
        $request->status = match ($outcome) {
            CommandReconciliationOutcome::Matched => CommandStatus::Reconciled,
            CommandReconciliationOutcome::Mismatch => CommandStatus::Mismatch,
            CommandReconciliationOutcome::Uncertain => CommandStatus::Uncertain,
        };
        $request->reconciled_at = $outcome === CommandReconciliationOutcome::Matched
            ? $evidence['observed_at']
            : null;
        $request->save();
        $this->audit->append($request, null, 'collector_reconciliation_'.$outcome->value, [
            'attempt_number' => $attempt->attempt_number,
            'collector_id' => (int) $collector->id,
            'source_sequence' => $sequence,
            'outcome' => $outcome->value,
            'status' => $request->status->value,
        ]);
        if ($outcome === CommandReconciliationOutcome::Matched) {
            DeviceEvent::query()->create([
                'device_id' => $request->device_id,
                'event_type' => 'management_command_reconciled',
                'severity' => 'info',
                'source' => 'oblivion_command_plane',
                'occurred_at' => $reconciliation->observed_at,
                'payload' => [
                    'command_uuid' => $request->command_uuid,
                    'capability' => $request->capability,
                    'site_id' => (int) $request->site_id,
                    'attempt_number' => (int) $attempt->attempt_number,
                    'outcome' => $outcome->value,
                    'execution_route' => 'collector',
                ],
            ]);
        }
    }
}
