<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\Monitoring\Exceptions\RuntimePayloadInvalid;
use App\Domain\Monitoring\Exceptions\RuntimeScopeViolation;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Management\Contracts\CollectorCommandDescriptor;
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
    /** Hard ceiling for any attachment, under the hub's 2 MB spool frame. */
    public const int MAX_ATTACHMENT_ENCODED_BYTES = 1_572_864;

    public function __construct(
        private readonly CollectorCommandContract $contracts,
        private readonly DeviceCommandAuditService $audit,
        private readonly CollectorCommandDescriptorRegistry $descriptors,
    ) {}

    /** @param array<string, mixed> $payload */
    public function record(MonitoringCollector $collector, int $sequence, array $payload): void
    {
        $validated = $this->validate($payload);
        $descriptor = $this->descriptors->for($validated['capability']);
        if (! $descriptor instanceof CollectorCommandDescriptor) {
            throw new RuntimeScopeViolation('Collector command result has no declared hub job.');
        }
        $attachment = $this->attachment($payload['attachment'] ?? null, $descriptor, $validated['execution_status']);

        DB::transaction(function () use ($collector, $sequence, $validated, $descriptor, $attachment): void {
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
                // The hub that covers the Site ran it: its home Site or one it covers over SD-WAN.
                || ! in_array((int) $request->site_id, $collector->authorisedSiteIds(), true)
                || (int) $request->site_id !== $validated['site_id']
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

            if ($attempt->status->isTerminal()) {
                if (! $descriptor->sameEvidence(
                    $attempt,
                    $validated['execution_status'],
                    $validated['safe_result'],
                    $validated['safe_failure_reason'],
                )) {
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

            $outcome = $descriptor->interpret(
                $request,
                $collector,
                $validated['execution_status'],
                $validated['safe_result'],
                $validated['provider_request_reference'],
                $validated['safe_failure_reason'],
                $validated['reconciliation'],
                $attachment,
            );
            $safeResult = $this->safeMap($outcome->safeResult, 16);

            $attemptStatus = CommandAttemptStatus::from($outcome->executionStatus);
            $attempt->status = $attemptStatus;
            $attempt->provider_request_reference = $outcome->providerReference;
            $attempt->safe_result_summary = $safeResult;
            $attempt->safe_failure_reason = $outcome->failure;
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
            $request->safe_result_summary = $safeResult === []
                ? null
                : json_encode($safeResult, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $request->safe_failure_reason = $outcome->failure;
            $request->save();
            $this->audit->append($request, null, 'collector_execution_'.$attemptStatus->value, [
                'attempt_number' => $attempt->attempt_number,
                'collector_id' => (int) $collector->id,
                'source_sequence' => $sequence,
                'status' => $request->status->value,
                'safe_result' => $safeResult,
            ]);

            if ($outcome->reconciliation !== null && $attemptStatus !== CommandAttemptStatus::Failed) {
                $this->recordReconciliation(
                    $request,
                    $attempt,
                    $collector,
                    $sequence,
                    $outcome->reconciliation,
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
        $keys = array_keys($payload);
        if (array_diff($keys, [...$allowed, 'attachment']) !== []
            || array_diff($allowed, $keys) !== []
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
        } elseif ($reconciliation !== null) {
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

    /**
     * One attachment, only for jobs that declare one, only on success: gzip, then base64, with the
     * sha256 and length of the original bytes so a damaged upload is refused.
     */
    private function attachment(mixed $value, CollectorCommandDescriptor $descriptor, string $status): ?string
    {
        if ($value === null) {
            return null;
        }
        $limit = min(self::MAX_ATTACHMENT_ENCODED_BYTES, $descriptor->maxAttachmentBytes());
        if ($limit < 1 || $status !== 'succeeded' || ! is_array($value)
            || array_keys($value) !== ['kind', 'encoding', 'bytes', 'sha256', 'data']
            || ! is_string($value['kind']) || preg_match('/\A[a-z][a-z0-9_]{0,31}\z/', $value['kind']) !== 1
            || $value['encoding'] !== 'gzip+base64'
            || ! is_int($value['bytes']) || $value['bytes'] < 1 || $value['bytes'] > 2_097_152
            || ! is_string($value['sha256']) || preg_match('/\A[0-9a-f]{64}\z/', $value['sha256']) !== 1
            || ! is_string($value['data']) || strlen($value['data']) > $limit) {
            throw new RuntimePayloadInvalid('Collector command attachment is invalid.');
        }
        $compressed = base64_decode($value['data'], true);
        $raw = is_string($compressed) ? @gzdecode($compressed, 2_097_152) : false;
        if (! is_string($raw) || strlen($raw) !== $value['bytes']
            || ! hash_equals($value['sha256'], hash('sha256', $raw))) {
            throw new RuntimePayloadInvalid('Collector command attachment failed its integrity check.');
        }

        return $raw;
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

        return [
            'outcome' => $value['outcome'],
            'observed_state' => $observed,
            'observation_reference' => $reference,
            'safe_evidence_summary' => trim($summary),
            'observed_at' => $observedAt,
        ];
    }

    /** @return array<string, scalar|null> */
    private function safeMap(mixed $value, int $maximum): array
    {
        if ($value === []) {
            return [];
        }
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
