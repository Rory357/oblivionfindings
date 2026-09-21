<?php

namespace App\Services\Queclink;

use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Exceptions\CommandDispatchPreconditionException;
use App\Domain\SecurityDevices\Management\Jobs\ReconcileDeviceCommand;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandAttempt;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandAuditService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandContractVerifier;
use App\Domain\SecurityDevices\Management\Services\GovernedCommandDispatchService;
use App\Models\FleetTelemetryEvent;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Queclink\QueclinkPendingCommand;
use App\Models\Queclink\QueclinkRawFrame;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class GovernedCommandLifecycleService
{
    public function __construct(
        private readonly DeviceCommandAuditService $audit,
        private readonly ConfigurationSnapshotService $configurations,
    ) {}

    /** The caller holds provider Device + pending row through the atomic send claim. */
    public function authoriseDelivery(QueclinkPendingCommand $pending): bool
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Provider delivery authorization requires a transaction.');
        }
        $governed = $pending->was_governed || $pending->device_command_request_id !== null || $pending->device_command_attempt_id !== null;
        if (! $governed) {
            return true;
        }
        // Missing/mismatched links cannot identify a canonical lifecycle safely.
        // Fail this provider row without mutating a potentially unrelated request.
        try {
            $linked = $this->linkedLifecycle($pending);
        } catch (\UnexpectedValueException|ModelNotFoundException $error) {
            $pending->forceFill(['status' => QueclinkPendingCommand::STATUS_FAILED,
                'failed_reason' => 'The governed command linkage could not be verified.'])->save();

            return false;
        }
        if ($linked === null) {
            throw new \LogicException('A governed command cannot use legacy delivery.');
        }
        [$request, $attempt] = $linked;
        $expired = $request->expires_at->lessThanOrEqualTo(now()) || $pending->isExpired();
        $failure = $expired ? 'The location request expired before delivery.' : null;
        if (! app(DeviceCommandContractVerifier::class)->verify($request)) {
            $failure = 'The signed command contract could not be verified.';
        } elseif ($request->origin_context === null && str_starts_with(strtolower($request->idempotency_key), 'client-location')) {
            $failure = 'The client command context could not be verified.';
        } elseif ($failure === null && $request->origin_context !== null) {
            try {
                $requester = $request->requestedBy;
                abort_unless($requester, 403);
                app(GovernedCommandDispatchService::class)->assertClientProviderDelivery($request, $requester);
            } catch (CommandDispatchPreconditionException|AuthorizationException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface|ValidationException $error) {
                $failure = 'Current client location authorization is no longer valid. No command was sent.';
            }
        }
        if ($failure === null) {
            return true;
        }

        $pending->forceFill(['status' => $expired ? QueclinkPendingCommand::STATUS_EXPIRED : QueclinkPendingCommand::STATUS_FAILED,
            'failed_reason' => $failure])->save();
        $status = $expired ? CommandStatus::Expired : CommandStatus::Failed;
        $attemptStatus = $expired ? CommandAttemptStatus::Expired : CommandAttemptStatus::Failed;
        if (in_array($request->status, [CommandStatus::Dispatching, CommandStatus::Accepted, CommandStatus::Running], true)
            && in_array($attempt->status, [CommandAttemptStatus::Dispatching, CommandAttemptStatus::Accepted, CommandAttemptStatus::Running], true)) {
            $at = CarbonImmutable::now('UTC')->startOfSecond();
            $request->forceFill(['status' => $status, 'safe_failure_reason' => $failure, 'execution_completed_at' => $at])->save();
            $attempt->forceFill(['status' => $attemptStatus, 'safe_failure_reason' => $failure, 'completed_at' => $at])->save();
        }
        // An audit failure rolls back pending/request/attempt together. The
        // router has not read or returned any command bytes at this point.
        $this->audit->append($request, null, 'provider_delivery_denied', [
            'runtime' => 'queclink_native', 'attempt_number' => $attempt->attempt_number,
            'status' => $request->status->value, 'reason_code' => $expired ? 'command_expired' : 'delivery_authorisation_changed',
        ]);

        return false;
    }

    public function markSent(QueclinkPendingCommand $command, ?string $sessionId = null): QueclinkPendingCommand
    {
        return DB::transaction(function () use ($command, $sessionId): QueclinkPendingCommand {
            $pending = QueclinkPendingCommand::query()->lockForUpdate()->findOrFail($command->id);
            if ($pending->status !== QueclinkPendingCommand::STATUS_QUEUED || $pending->isExpired()) {
                throw new RuntimeException('Only a current queued Queclink command can be sent.');
            }

            $now = CarbonImmutable::now('UTC')->startOfSecond();
            $pending->forceFill([
                'status' => QueclinkPendingCommand::STATUS_SENT,
                'sent_at' => $now,
                'sent_session_id' => $sessionId,
            ])->save();

            $linked = $this->linkedLifecycle($pending);
            if ($linked !== null) {
                [$request, $attempt] = $linked;
                $starting = $attempt->status === CommandAttemptStatus::Accepted
                    && $request->status === CommandStatus::Accepted;
                $continuing = $attempt->status === CommandAttemptStatus::Running
                    && $request->status === CommandStatus::Running;
                if (! $starting && ! $continuing) {
                    throw new RuntimeException('The governed Queclink command is not awaiting provider delivery.');
                }
                if ($starting) {
                    $attempt->status = CommandAttemptStatus::Running;
                    $attempt->started_at = $now;
                    $attempt->save();
                    $request->status = CommandStatus::Running;
                    $request->started_at = $now;
                    $request->save();
                    $this->audit->append($request, null, 'provider_delivery_started', [
                        'runtime' => 'queclink_native',
                        'attempt_number' => $attempt->attempt_number,
                        'status' => CommandStatus::Running->value,
                    ]);
                }
            }

            return $pending->fresh();
        });
    }

    public function markAcknowledged(
        QueclinkPendingCommand $command,
        string $acknowledgement,
    ): QueclinkPendingCommand {
        return DB::transaction(function () use ($command, $acknowledgement): QueclinkPendingCommand {
            $pending = QueclinkPendingCommand::query()->lockForUpdate()->findOrFail($command->id);
            if ($pending->status !== QueclinkPendingCommand::STATUS_SENT) {
                return $pending;
            }

            $pending->forceFill([
                'status' => QueclinkPendingCommand::STATUS_ACKED,
                'acked_at' => CarbonImmutable::now('UTC')->startOfSecond(),
                'ack_response' => $acknowledgement,
            ])->save();

            $linked = $this->linkedLifecycle($pending);
            if ($linked !== null) {
                [$request, $attempt] = $linked;
                $this->audit->append($request, null, 'provider_acknowledged', [
                    'runtime' => 'queclink_native',
                    'attempt_number' => $attempt->attempt_number,
                    'status' => $request->status->value,
                ]);
            }

            return $pending->fresh();
        });
    }

    public function fulfilFromTelemetry(QueclinkDevice $device, int $telemetryEventId): int
    {
        $event = FleetTelemetryEvent::query()
            ->whereKey($telemetryEventId)
            ->where('device_id', $device->device_id)
            ->where('vendor', 'queclink')
            ->where('consent_blocked', false)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->first();
        if (! $event || $event->received_at === null) {
            return 0;
        }

        $ids = QueclinkPendingCommand::query()
            ->where('queclink_device_id', $device->id)
            ->whereNotNull('device_command_attempt_id')
            ->whereHas('governedRequest', fn ($query) => $query->where('capability', 'tracking.location_refresh'))
            ->whereNull('fulfilled_at')
            ->whereIn('status', [
                QueclinkPendingCommand::STATUS_SENT,
                QueclinkPendingCommand::STATUS_ACKED,
            ])
            ->where('sent_at', '<=', $event->received_at)
            ->where('expires_at', '>=', $event->received_at)
            ->orderBy('id')
            ->pluck('id');

        $fulfilled = 0;
        foreach ($ids as $id) {
            $reconcile = DB::transaction(function () use ($id, $event): ?array {
                $pending = QueclinkPendingCommand::query()->lockForUpdate()->find($id);
                if (! $pending
                    || $pending->fulfilled_at !== null
                    || ! in_array($pending->status, [
                        QueclinkPendingCommand::STATUS_SENT,
                        QueclinkPendingCommand::STATUS_ACKED,
                    ], true)
                    || $pending->sent_at === null
                    || $pending->sent_at->gt($event->received_at)
                    || $pending->expires_at === null
                    || $pending->expires_at->lt($event->received_at)) {
                    return null;
                }

                $linked = $this->linkedLifecycle($pending);
                if ($linked === null) {
                    return null;
                }
                [$request, $attempt] = $linked;
                if (! in_array($attempt->status, [CommandAttemptStatus::Accepted, CommandAttemptStatus::Running], true)
                    || ! in_array($request->status, [CommandStatus::Accepted, CommandStatus::Running], true)) {
                    return null;
                }

                $completedAt = CarbonImmutable::instance($event->received_at);
                $summary = [
                    'delivery_state' => 'fresh_location_received',
                    'reconciliation' => 'pending_exact_observation_check',
                ];
                $pending->forceFill([
                    'fulfilled_telemetry_event_id' => $event->id,
                    'fulfilled_at' => $completedAt,
                ])->save();
                $attempt->status = CommandAttemptStatus::Succeeded;
                $attempt->safe_result_summary = $summary;
                $attempt->evidence_reference = 'fleet-telemetry:'.$event->id;
                $attempt->completed_at = $completedAt;
                $attempt->save();
                $request->status = CommandStatus::Reconciling;
                $request->safe_result_summary = json_encode($summary, JSON_THROW_ON_ERROR);
                $request->execution_completed_at = $completedAt;
                $request->save();
                $this->audit->append($request, null, 'provider_observation_received', [
                    'runtime' => 'queclink_native',
                    'attempt_number' => $attempt->attempt_number,
                    'status' => CommandStatus::Reconciling->value,
                ]);

                return [
                    'command_id' => (int) $request->id,
                    'queue' => (string) config('security_devices.command_queue', 'monitoring-commands'),
                ];
            });
            if ($reconcile === null) {
                continue;
            }

            $this->dispatchReconciliation(
                pendingId: (int) $id,
                commandId: $reconcile['command_id'],
                queue: $reconcile['queue'],
            );
            $fulfilled++;
        }

        return $fulfilled;
    }

    public function fulfilFromConfiguration(QueclinkDevice $device, int $rawFrameId): int
    {
        $frame = QueclinkRawFrame::query()
            ->whereKey($rawFrameId)
            ->where('queclink_device_id', $device->id)
            ->where('direction', QueclinkRawFrame::DIRECTION_INBOUND)
            ->where('command_word', 'GTALM')
            ->where('parse_ok', true)
            ->first();
        if (! $frame || $frame->created_at === null) {
            return 0;
        }
        $snapshot = $this->configurations->latestForDevice($device);
        $receivedAt = is_string($snapshot['received_at'] ?? null)
            ? CarbonImmutable::parse($snapshot['received_at'])
            : null;
        if (($snapshot['available'] ?? false) !== true || $receivedAt === null) {
            return 0;
        }

        $ids = QueclinkPendingCommand::query()
            ->where('queclink_device_id', $device->id)
            ->whereNotNull('device_command_attempt_id')
            ->whereNull('fulfilled_at')
            ->whereHas('governedRequest', fn ($query) => $query->whereIn('capability', [
                'configuration.refresh',
                'configuration.apply',
            ]))
            ->where('governed_role', 'verification')
            ->whereIn('status', [
                QueclinkPendingCommand::STATUS_SENT,
                QueclinkPendingCommand::STATUS_ACKED,
            ])
            ->where('sent_at', '<=', $receivedAt)
            ->where('expires_at', '>=', $receivedAt)
            ->orderBy('id')
            ->pluck('id');

        $fulfilled = 0;
        foreach ($ids as $id) {
            $reconcile = DB::transaction(function () use ($id, $frame, $receivedAt, $snapshot): ?array {
                $pending = QueclinkPendingCommand::query()->lockForUpdate()->find($id);
                if (! $pending
                    || $pending->fulfilled_at !== null
                    || ! in_array($pending->status, [
                        QueclinkPendingCommand::STATUS_SENT,
                        QueclinkPendingCommand::STATUS_ACKED,
                    ], true)
                    || $pending->sent_at === null
                    || $pending->sent_at->gt($receivedAt)
                    || $pending->expires_at === null
                    || $pending->expires_at->lt($receivedAt)) {
                    return null;
                }

                $linked = $this->linkedLifecycle($pending);
                if ($linked === null) {
                    return null;
                }
                [$request, $attempt] = $linked;
                $section = strtoupper((string) data_get($request->encrypted_parameters, 'section', 'all'));
                $refreshInvalid = $request->capability === 'configuration.refresh'
                    && $section !== 'ALL'
                    && ! array_key_exists($section, (array) ($snapshot['sections'] ?? []));
                $applyInvalid = $request->capability === 'configuration.apply'
                    && QueclinkPendingCommand::query()
                        ->where('device_command_attempt_id', $attempt->id)
                        ->where('governed_sequence', '<', $pending->governed_sequence)
                        ->where('status', '<>', QueclinkPendingCommand::STATUS_ACKED)
                        ->exists();
                if (! in_array($request->capability, ['configuration.refresh', 'configuration.apply'], true)
                    || $refreshInvalid
                    || $applyInvalid
                    || ! in_array($attempt->status, [CommandAttemptStatus::Accepted, CommandAttemptStatus::Running], true)
                    || ! in_array($request->status, [CommandStatus::Accepted, CommandStatus::Running], true)) {
                    return null;
                }

                $summary = [
                    'delivery_state' => $request->capability === 'configuration.apply'
                        ? 'protected_post_change_configuration_received'
                        : 'fresh_protected_configuration_received',
                    'reconciliation' => 'pending_exact_observation_check',
                ];
                $pending->forceFill([
                    'fulfilled_raw_frame_id' => $frame->id,
                    'fulfilled_at' => $receivedAt,
                ])->save();
                $attempt->status = CommandAttemptStatus::Succeeded;
                $attempt->safe_result_summary = $summary;
                $attempt->evidence_reference = 'queclink-frame:'.$frame->id;
                $attempt->completed_at = $receivedAt;
                $attempt->save();
                $request->status = CommandStatus::Reconciling;
                $request->safe_result_summary = json_encode($summary, JSON_THROW_ON_ERROR);
                $request->execution_completed_at = $receivedAt;
                $request->save();
                $this->audit->append($request, null, 'provider_observation_received', [
                    'runtime' => 'queclink_native',
                    'attempt_number' => $attempt->attempt_number,
                    'status' => CommandStatus::Reconciling->value,
                ]);

                return [
                    'command_id' => (int) $request->id,
                    'queue' => (string) config('security_devices.command_queue', 'monitoring-commands'),
                ];
            });
            if ($reconcile === null) {
                continue;
            }

            $this->dispatchReconciliation(
                pendingId: (int) $id,
                commandId: $reconcile['command_id'],
                queue: $reconcile['queue'],
            );
            $fulfilled++;
        }

        return $fulfilled;
    }

    public function fulfilFromReconnection(
        QueclinkDevice $device,
        int $rawFrameId,
        string $sessionId,
    ): int {
        $frame = QueclinkRawFrame::query()
            ->whereKey($rawFrameId)
            ->where('queclink_device_id', $device->id)
            ->where('direction', QueclinkRawFrame::DIRECTION_INBOUND)
            ->where('session_id', $sessionId)
            ->where('parse_ok', true)
            ->first();
        if (! $frame || $frame->created_at === null) {
            return 0;
        }

        $ids = QueclinkPendingCommand::query()
            ->where('queclink_device_id', $device->id)
            ->whereNotNull('device_command_attempt_id')
            ->whereNull('fulfilled_at')
            ->whereNotNull('sent_session_id')
            ->where('sent_session_id', '<>', $sessionId)
            ->whereHas('governedRequest', fn ($query) => $query->where('capability', 'device.reboot'))
            ->whereIn('status', [
                QueclinkPendingCommand::STATUS_SENT,
                QueclinkPendingCommand::STATUS_ACKED,
            ])
            ->where('sent_at', '<=', $frame->created_at)
            ->where('expires_at', '>=', $frame->created_at)
            ->orderBy('id')
            ->pluck('id');

        $fulfilled = 0;
        foreach ($ids as $id) {
            $reconcile = DB::transaction(function () use ($id, $frame, $sessionId): ?array {
                $pending = QueclinkPendingCommand::query()->lockForUpdate()->find($id);
                if (! $pending
                    || $pending->fulfilled_at !== null
                    || ! in_array($pending->status, [
                        QueclinkPendingCommand::STATUS_SENT,
                        QueclinkPendingCommand::STATUS_ACKED,
                    ], true)
                    || ! is_string($pending->sent_session_id)
                    || $pending->sent_session_id === ''
                    || hash_equals($pending->sent_session_id, $sessionId)
                    || $pending->sent_at === null
                    || $pending->sent_at->gt($frame->created_at)
                    || $pending->expires_at === null
                    || $pending->expires_at->lt($frame->created_at)) {
                    return null;
                }

                $linked = $this->linkedLifecycle($pending);
                if ($linked === null) {
                    return null;
                }
                [$request, $attempt] = $linked;
                if ($request->capability !== 'device.reboot'
                    || ! in_array($attempt->status, [CommandAttemptStatus::Accepted, CommandAttemptStatus::Running], true)
                    || ! in_array($request->status, [CommandStatus::Accepted, CommandStatus::Running], true)) {
                    return null;
                }

                $completedAt = CarbonImmutable::instance($frame->created_at);
                $summary = [
                    'delivery_state' => 'fresh_listener_reconnection_received',
                    'reconciliation' => 'pending_exact_observation_check',
                ];
                $pending->forceFill([
                    'fulfilled_raw_frame_id' => $frame->id,
                    'fulfilled_at' => $completedAt,
                ])->save();
                $attempt->status = CommandAttemptStatus::Succeeded;
                $attempt->safe_result_summary = $summary;
                $attempt->evidence_reference = 'queclink-frame:'.$frame->id;
                $attempt->completed_at = $completedAt;
                $attempt->save();
                $request->status = CommandStatus::Reconciling;
                $request->safe_result_summary = json_encode($summary, JSON_THROW_ON_ERROR);
                $request->execution_completed_at = $completedAt;
                $request->save();
                $this->audit->append($request, null, 'provider_observation_received', [
                    'runtime' => 'queclink_native',
                    'attempt_number' => $attempt->attempt_number,
                    'status' => CommandStatus::Reconciling->value,
                ]);

                return [
                    'command_id' => (int) $request->id,
                    'queue' => (string) config('security_devices.command_queue', 'monitoring-commands'),
                ];
            });
            if ($reconcile === null) {
                continue;
            }

            $this->dispatchReconciliation(
                pendingId: (int) $id,
                commandId: $reconcile['command_id'],
                queue: $reconcile['queue'],
            );
            $fulfilled++;
        }

        return $fulfilled;
    }

    public function recoverReconciliations(int $limit = 500): int
    {
        $pending = QueclinkPendingCommand::query()
            ->whereNotNull('fulfilled_at')
            ->whereNotNull('device_command_request_id')
            ->where(function ($query): void {
                $query->whereNull('reconciliation_dispatched_at')
                    ->orWhere('reconciliation_dispatched_at', '<=', CarbonImmutable::now('UTC')->subMinutes(5));
            })
            ->whereHas('governedRequest', function ($query): void {
                $query->whereIn('status', [CommandStatus::Reconciling->value, CommandStatus::Uncertain->value])
                    ->whereDoesntHave('reconciliations');
            })
            ->orderBy('id')
            ->limit(max(1, min(1000, $limit)))
            ->get(['id', 'device_command_request_id']);

        $recovered = 0;
        foreach ($pending as $providerCommand) {
            $this->dispatchReconciliation(
                pendingId: (int) $providerCommand->id,
                commandId: (int) $providerCommand->device_command_request_id,
                queue: (string) config('security_devices.command_queue', 'monitoring-commands'),
            );
            $recovered++;
        }

        return $recovered;
    }

    public function expireStale(int $limit = 500): int
    {
        $ids = QueclinkPendingCommand::query()
            ->whereNotNull('device_command_attempt_id')
            ->whereNull('fulfilled_at')
            ->whereIn('status', [
                QueclinkPendingCommand::STATUS_QUEUED,
                QueclinkPendingCommand::STATUS_SENT,
                QueclinkPendingCommand::STATUS_ACKED,
            ])
            ->where('expires_at', '<=', CarbonImmutable::now('UTC')->startOfSecond())
            ->orderBy('id')
            ->limit(max(1, min(1000, $limit)))
            ->pluck('id');

        $expired = 0;
        foreach ($ids as $id) {
            $changed = DB::transaction(function () use ($id): bool {
                $pending = QueclinkPendingCommand::query()->lockForUpdate()->find($id);
                if (! $pending
                    || $pending->fulfilled_at !== null
                    || $pending->expires_at === null
                    || $pending->expires_at->isFuture()
                    || ! in_array($pending->status, [
                        QueclinkPendingCommand::STATUS_QUEUED,
                        QueclinkPendingCommand::STATUS_SENT,
                        QueclinkPendingCommand::STATUS_ACKED,
                    ], true)) {
                    return false;
                }

                $capability = $pending->governedRequest?->capability;
                $evidence = in_array($capability, ['configuration.refresh', 'configuration.apply'], true)
                    ? ($capability === 'configuration.apply'
                        ? 'all sequential acknowledgements and a matching protected post-change configuration snapshot'
                        : 'a fresh protected configuration snapshot')
                    : ($capability === 'device.reboot'
                        ? 'a fresh tracker reconnection'
                        : 'a fresh governed location observation');
                $pending->forceFill([
                    'status' => QueclinkPendingCommand::STATUS_EXPIRED,
                    'failed_reason' => "No {$evidence} arrived before expiry.",
                ])->save();
                $linked = $this->linkedLifecycle($pending);
                if ($linked === null) {
                    return true;
                }
                [$request, $attempt] = $linked;
                if (! in_array($attempt->status, [
                    CommandAttemptStatus::Dispatching,
                    CommandAttemptStatus::Accepted,
                    CommandAttemptStatus::Running,
                ], true)
                    || ! in_array($request->status, [
                        CommandStatus::Dispatching,
                        CommandStatus::Accepted,
                        CommandStatus::Running,
                    ], true)) {
                    return true;
                }

                $now = CarbonImmutable::now('UTC')->startOfSecond();
                $failure = "The tracker did not return {$evidence} before the request expired.";
                $attempt->status = CommandAttemptStatus::Expired;
                $attempt->safe_failure_reason = $failure;
                $attempt->completed_at = $now;
                $attempt->save();
                $request->status = CommandStatus::Expired;
                $request->safe_failure_reason = $failure;
                $request->execution_completed_at = $now;
                $request->save();
                $this->audit->append($request, null, 'provider_delivery_expired', [
                    'runtime' => 'queclink_native',
                    'attempt_number' => $attempt->attempt_number,
                    'status' => CommandStatus::Expired->value,
                ]);

                return true;
            });
            $expired += $changed ? 1 : 0;
        }

        return $expired;
    }

    /** @return array{DeviceCommandRequest, DeviceCommandAttempt}|null */
    private function linkedLifecycle(QueclinkPendingCommand $pending): ?array
    {
        if (! $pending->was_governed && $pending->device_command_request_id === null && $pending->device_command_attempt_id === null) {
            return null;
        }
        if ($pending->device_command_request_id === null || $pending->device_command_attempt_id === null) {
            throw new \UnexpectedValueException('The Queclink governed command linkage is incomplete.');
        }

        $request = DeviceCommandRequest::query()
            ->whereKey($pending->device_command_request_id)
            ->lockForUpdate()
            ->firstOrFail();
        $attempt = DeviceCommandAttempt::query()
            ->whereKey($pending->device_command_attempt_id)
            ->lockForUpdate()
            ->firstOrFail();
        if ((int) $attempt->device_command_request_id !== (int) $request->id
            || ! in_array($request->capability, ['tracking.location_refresh', 'configuration.refresh', 'configuration.apply', 'device.reboot'], true)
            || (int) $request->device_id !== (int) $pending->device?->device_id) {
            throw new \UnexpectedValueException('The Queclink governed command linkage does not match its canonical request.');
        }

        return [$request, $attempt];
    }

    private function dispatchReconciliation(int $pendingId, int $commandId, string $queue): void
    {
        ReconcileDeviceCommand::dispatch($commandId)->onQueue($queue);

        QueclinkPendingCommand::query()
            ->whereKey($pendingId)
            ->whereNotNull('fulfilled_at')
            ->update(['reconciliation_dispatched_at' => CarbonImmutable::now('UTC')->startOfSecond()]);
    }
}
