<?php

namespace App\Services\Queclink\Listener;

use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Queclink\QueclinkPendingCommand;
use App\Models\Queclink\QueclinkRawFrame;
use App\Services\Fleet\FleetTelemetryIngestService;
use App\Services\Queclink\AckBuilder;
use App\Services\Queclink\AtTrackFrame;
use App\Services\Queclink\AtTrackProtocolParser;
use App\Services\Queclink\Exceptions\IntakeRejected;
use App\Services\Queclink\GovernedCommandLifecycleService;
use App\Services\Queclink\SerialNumberAllocator;
use App\Support\SafeOperationalData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Routes a single parsed inbound frame:
 *   1. Validate its device-originated direction and bounded persisted fields.
 *   2. Resolve the QueclinkDevice (auto-add unknown IMEIs to pending tray).
 *   3. Persist the valid frame with immutable frame-time binding lineage.
 *   4. If paired, forward to FleetTelemetryIngestService for full ingest.
 *   5. Build outbound responses: server SACKs + any queued commands.
 *
 * Returns a list of raw bytes the caller should write back to the socket.
 */
class FrameRouter
{
    public function __construct(
        protected AtTrackProtocolParser $parser,
        protected AckBuilder $acks,
        protected FleetTelemetryIngestService $ingest,
        protected GovernedCommandLifecycleService $governedCommands,
        protected SerialNumberAllocator $serials,
        protected ListenerLimits $limits,
    ) {}

    /**
     * @return list<string> Raw frames to send back to the device.
     */
    /**
     * Cap on outbound commands dispatched per inbound frame. Prevents one
     * device from monopolising the listener if a large queue piles up — the
     * rest go on the next frame.
     */
    public const MAX_COMMANDS_PER_FRAME = 5;

    public function handleInbound(string $rawFrame, ConnectionState $state): array
    {
        if (strlen($rawFrame) > $this->limits->maxFrameBytes) {
            throw new IntakeRejected('frame_limit');
        }

        $receivedAt = now();
        $frame = $this->parser->parse($rawFrame);
        if (! $frame->isValid()) {
            throw new IntakeRejected('invalid_frame');
        }

        if (! in_array($frame->frameType, ['RESP', 'BUFF', 'ACK'], true)) {
            throw new IntakeRejected('invalid_direction');
        }

        // The first identified device owns this TCP connection for its full
        // lifetime. Reject identity hopping before activity counters, device
        // state, raw evidence, ACK correlation, telemetry, or command access.
        if ($state->imei !== null
            && $frame->imei !== null
            && ! $state->isBoundTo($frame->imei)) {
            throw new IntakeRejected('invalid_frame');
        }

        // Only a complete, protocol-valid, device-originated frame keeps a
        // connection alive. Partial bytes and empty delimiters remain bounded
        // by the connection's accepted-frame idle deadline.
        $state->touch();
        $state->framesIn++;

        $queclinkDevice = $this->resolveDevice($frame, $state);

        // Rejected devices are inert. A valid frame remains governed intake
        // evidence, but no SACK, telemetry ingest, or command is dispatched.
        if ($queclinkDevice && $queclinkDevice->status === QueclinkDevice::STATUS_REJECTED) {
            $this->logRaw($frame, $state, $queclinkDevice, 'inbound', $receivedAt);

            return [];
        }

        $storedFrame = $this->logRaw($frame, $state, $queclinkDevice, 'inbound', $receivedAt);

        if ($queclinkDevice && $queclinkDevice->isPaired()) {
            try {
                $this->governedCommands->fulfilFromReconnection(
                    $queclinkDevice,
                    (int) $storedFrame->id,
                    $state->sessionId,
                );
            } catch (\Throwable $e) {
                Log::warning('Queclink governed reconnection reconciliation failed.', SafeOperationalData::logContext([
                    'provider' => 'queclink',
                    'device_id' => $queclinkDevice->device_id,
                    'failure_category' => SafeOperationalData::failureCategory($e),
                    'items_errored' => 1,
                ]));
            }
        }

        if ($queclinkDevice
            && $queclinkDevice->isPaired()
            && $frame->commandWord === 'GTALM') {
            try {
                $this->governedCommands->fulfilFromConfiguration($queclinkDevice, (int) $storedFrame->id);
            } catch (\Throwable $e) {
                Log::warning('Queclink governed configuration reconciliation failed.', SafeOperationalData::logContext([
                    'provider' => 'queclink',
                    'device_id' => $queclinkDevice->device_id,
                    'failure_category' => SafeOperationalData::failureCategory($e),
                    'items_errored' => 1,
                ]));
            }
        }

        $outbound = [];

        // 1. Server ACK — send for pending + paired devices alike so the
        //    device keeps the connection alive while waiting for adoption.
        //    Rejected devices already returned above.
        $ack = $this->acks->serverAck($frame);
        if ($ack !== null) {
            $outbound[] = $ack;
            if ($queclinkDevice) {
                $this->logRawOutbound(
                    $ack,
                    $state,
                    $queclinkDevice,
                    $frame->isHeartbeat() ? 'GTHBD' : null,
                    $receivedAt,
                );
            } else {
                $this->logRaw($this->parser->parse($ack), $state, null, 'outbound', $receivedAt);
            }
        }

        // 2. Ingest telemetry into the fleet pipeline (only for paired devices).
        if ($queclinkDevice && $queclinkDevice->isPaired() && $frame->isReport()) {
            if ($queclinkDevice->device_id === null) {
                Log::warning('Queclink provider device has no canonical binding.', SafeOperationalData::logContext([
                    'provider' => 'queclink',
                    'status' => 'unbound',
                    'items_errored' => 1,
                ]));
            } else {
                try {
                    $result = $this->ingest->ingest(
                        'queclink',
                        $frame->payload,
                        (int) $queclinkDevice->device_id,
                    );
                    if (($result['ok'] ?? false) === true && is_numeric($result['id'] ?? null)) {
                        $this->governedCommands->fulfilFromTelemetry($queclinkDevice, (int) $result['id']);
                    } elseif (($result['ok'] ?? false) !== true) {
                        Log::warning('Queclink canonical telemetry intake rejected.', SafeOperationalData::logContext([
                            'provider' => 'queclink',
                            'device_id' => $queclinkDevice->device_id,
                            'status' => $result['status'] ?? null,
                            'failure_category' => 'telemetry_rejected',
                            'items_errored' => 1,
                        ]));
                    }
                } catch (\Throwable $e) {
                    Log::warning('Queclink telemetry ingest failed.', SafeOperationalData::logContext([
                        'provider' => 'queclink',
                        'device_id' => $queclinkDevice->device_id,
                        'failure_category' => SafeOperationalData::failureCategory($e),
                        'items_errored' => 1,
                    ]));
                }
            }
        }

        // 3. ACK correlation — if this is a device +ACK, mark the matching pending command.
        if ($frame->isAck() && $queclinkDevice) {
            $this->correlateAck($frame, $queclinkDevice);
        }

        // 4. Dispatch any queued commands for paired devices (capped per frame).
        if ($queclinkDevice && $queclinkDevice->isPaired()) {
            foreach ($this->popQueuedCommands($queclinkDevice) as $command) {
                $sent = $this->claimQueuedCommand(
                    (int) $command->id,
                    $queclinkDevice,
                    $state->sessionId,
                );
                if ($sent === null) {
                    continue;
                }
                $outbound[] = $sent->raw_command;
                $this->logRawOutbound(
                    $sent->raw_command,
                    $state,
                    $queclinkDevice,
                    $sent->command_word,
                    $receivedAt,
                );
            }
        }

        $state->framesOut += count($outbound);

        return $outbound;
    }

    public function handleDisconnect(ConnectionState $state): void
    {
        if ($state->queclinkDeviceId === null) {
            return;
        }
        QueclinkDevice::query()
            ->whereKey($state->queclinkDeviceId)
            ->update([
                'connection_state' => QueclinkDevice::CONN_DISCONNECTED,
                'current_session_id' => null,
            ]);
    }

    protected function resolveDevice(AtTrackFrame $frame, ConnectionState $state): ?QueclinkDevice
    {
        if ($frame->imei === null) {
            return null;
        }

        $device = QueclinkDevice::firstOrNew(['imei' => $frame->imei]);

        if (! $device->exists) {
            $device->fill([
                'status' => QueclinkDevice::STATUS_PENDING,
                'first_seen_at' => now(),
                'model_hint' => $frame->deviceName,
                'protocol_version' => $frame->protocolVersion,
            ]);
            // Fall through to the live-state update below so the very first
            // frame from a brand-new device records last_seen + connection.
        }

        // Bind the connection state even for rejected devices so caller can
        // log/drop deterministically. Do NOT update last_seen / connection
        // state for rejected devices — they should look "dormant" in the UI.
        if ($device->exists && $device->status === QueclinkDevice::STATUS_REJECTED) {
            $state->bind($frame->imei, $device->id);

            return $device;
        }

        // Pending + paired: refresh runtime state on every frame.
        $device->fill([
            'last_seen_at' => now(),
            'last_frame_at' => now(),
            'last_count_number' => $frame->countNumber,
            'connection_state' => QueclinkDevice::CONN_CONNECTED,
            'current_session_id' => $state->sessionId,
            'remote_address' => $state->remoteAddress,
        ]);
        if ($device->model_hint === null && $frame->deviceName !== null) {
            $device->model_hint = $frame->deviceName;
        }
        if ($frame->protocolVersion !== null) {
            $device->protocol_version = $frame->protocolVersion;
        }
        if ($device->isPaired()
            && is_numeric($device->device_id)
            && (! is_string($device->binding_uuid) || $device->binding_uuid === '')) {
            $device->binding_uuid = (string) Str::uuid();
        }
        $device->save();

        $state->bind($frame->imei, $device->id);

        return $device;
    }

    protected function logRaw(
        AtTrackFrame $frame,
        ConnectionState $state,
        ?QueclinkDevice $device,
        string $direction,
        ?Carbon $receivedAt = null,
    ): QueclinkRawFrame {
        $payload = $frame->isValid() ? $frame->payload : null;
        $protectedPayload = null;
        $sensitive = $frame->commandWord === 'GTALM';
        if ($sensitive && is_array($payload)) {
            $protectedPayload = Crypt::encryptString(json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
            unset($payload['config_text']);
            $payload['configuration_payload_protected'] = true;
        }
        $stored = $this->frameStorage($frame->rawFrame, $sensitive);
        $lineage = $this->frameLineage($device, $receivedAt ?? now());

        return QueclinkRawFrame::create([
            'queclink_device_id' => $device?->id,
            'canonical_device_id' => $lineage['canonical_device_id'],
            'device_assignment_id' => $lineage['device_assignment_id'],
            'binding_uuid' => $lineage['binding_uuid'],
            'imei' => $frame->imei,
            'direction' => $direction,
            'frame_type' => $frame->frameType,
            'command_word' => $frame->commandWord,
            ...$stored,
            'parsed_payload' => $payload,
            'encrypted_parsed_payload' => $protectedPayload,
            'parse_ok' => $frame->isValid(),
            'parse_error' => $frame->parseError,
            'session_id' => $state->sessionId,
            'remote_address' => $state->remoteAddress,
        ]);
    }

    protected function logRawOutbound(
        string $raw,
        ConnectionState $state,
        QueclinkDevice $device,
        ?string $commandWord = null,
        ?Carbon $receivedAt = null,
    ): void {
        $isCommand = ! str_starts_with($raw, '+SACK');
        $lineage = $this->frameLineage($device, $receivedAt ?? now());

        QueclinkRawFrame::create([
            'queclink_device_id' => $device->id,
            'canonical_device_id' => $lineage['canonical_device_id'],
            'device_assignment_id' => $lineage['device_assignment_id'],
            'binding_uuid' => $lineage['binding_uuid'],
            'imei' => $device->imei,
            'direction' => 'outbound',
            'frame_type' => str_starts_with($raw, '+SACK') ? 'SACK' : 'AT',
            'command_word' => $commandWord,
            ...$this->frameStorage($raw, $isCommand),
            'parsed_payload' => null,
            'parse_ok' => true,
            'session_id' => $state->sessionId,
            'remote_address' => $state->remoteAddress,
        ]);
    }

    /** @return array{canonical_device_id: ?int, device_assignment_id: ?int, binding_uuid: ?string} */
    private function frameLineage(?QueclinkDevice $device, Carbon $receivedAt): array
    {
        $canonicalDeviceId = is_numeric($device?->device_id)
            ? (int) $device->device_id
            : null;
        $assignmentId = null;

        if ($canonicalDeviceId !== null) {
            $assignmentId = DeviceAssignment::query()
                ->where('device_id', $canonicalDeviceId)
                ->where('assigned_at', '<=', $receivedAt)
                ->where(function ($query) use ($receivedAt): void {
                    $query->whereNull('released_at')
                        ->orWhere('released_at', '>=', $receivedAt);
                })
                ->orderByDesc('assigned_at')
                ->orderByDesc('id')
                ->value('id');
        }

        return [
            'canonical_device_id' => $canonicalDeviceId,
            'device_assignment_id' => is_numeric($assignmentId) ? (int) $assignmentId : null,
            'binding_uuid' => is_string($device?->binding_uuid) && $device->binding_uuid !== ''
                ? $device->binding_uuid
                : null,
        ];
    }

    protected function rawFrameForStorage(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        $printable = str_replace(["\r", "\n"], '', $raw);

        if ($printable !== '' && ctype_print($printable) && preg_match('//u', $raw) === 1) {
            return $raw;
        }

        return '0x'.strtoupper(bin2hex($raw));
    }

    /** @return array{raw_frame: string, encrypted_raw_frame: ?string} */
    private function frameStorage(string $raw, bool $sensitive): array
    {
        if (! $sensitive) {
            return [
                'raw_frame' => $this->rawFrameForStorage($raw),
                'encrypted_raw_frame' => null,
            ];
        }

        return [
            'raw_frame' => '[encrypted sensitive frame]',
            'encrypted_raw_frame' => Crypt::encryptString($raw),
        ];
    }

    protected function correlateAck(AtTrackFrame $frame, QueclinkDevice $device): void
    {
        // The serial number on an +ACK frame echoes the serial of the
        // command being acknowledged. The trailing "Count Number" is an
        // independent counter and does NOT correlate.
        if ($frame->serialNumber === null) {
            return;
        }
        DB::transaction(function () use ($frame, $device): void {
            QueclinkDevice::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();

            $matches = QueclinkPendingCommand::query()
                ->forDevice($device->id)
                ->where('serial_number', $frame->serialNumber)
                ->where(function (Builder $query): void {
                    $this->constrainTransmittedSerial($query);
                })
                ->orderBy('id')
                ->limit(2)
                ->lockForUpdate()
                ->get();

            $match = $matches->count() === 1 ? $matches->first() : null;
            if (! $match || $match->status !== QueclinkPendingCommand::STATUS_SENT) {
                if ($matches->count() > 1) {
                    Log::warning('Ambiguous Queclink command acknowledgement rejected.', SafeOperationalData::logContext([
                        'provider' => 'queclink',
                        'device_id' => $device->device_id,
                        'items_errored' => $matches->count(),
                    ]));
                }

                return;
            }

            $this->governedCommands->markAcknowledged($match, $frame->rawFrame);
        }, 3);
    }

    private function claimQueuedCommand(
        int $commandId,
        QueclinkDevice $device,
        ?string $sessionId,
    ): ?QueclinkPendingCommand {
        return DB::transaction(function () use ($commandId, $device, $sessionId): ?QueclinkPendingCommand {
            QueclinkDevice::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();

            $pending = QueclinkPendingCommand::query()->lockForUpdate()->find($commandId);
            if (! $pending
                || (int) $pending->queclink_device_id !== (int) $device->id
                || $pending->status !== QueclinkPendingCommand::STATUS_QUEUED
                || $pending->isExpired()) {
                return null;
            }

            // A row can be requeued by legacy repair or corrupted state while
            // still carrying proof that its bytes reached a device. Never
            // retransmit that row or let its own id evade the tombstone query.
            if ($pending->sent_at !== null
                || $pending->sent_session_id !== null
                || $pending->acked_at !== null
                || $pending->ack_response !== null) {
                Log::warning('Queclink requeued command with transmission provenance rejected.', SafeOperationalData::logContext([
                    'provider' => 'queclink',
                    'device_id' => $device->device_id,
                    'items_errored' => 1,
                ]));

                return null;
            }

            $rawCommand = $pending->raw_command;
            $storedSerial = (string) $pending->serial_number;
            if (! is_string($rawCommand)
                || preg_match('/^[0-9A-F]{4}$/', $storedSerial) !== 1
                || preg_match('/,([0-9A-F]{4})\$$/i', $rawCommand, $rawMatch) !== 1
                || strtoupper($rawMatch[1]) !== $storedSerial) {
                Log::warning('Queclink queued command serial binding rejected.', SafeOperationalData::logContext([
                    'provider' => 'queclink',
                    'device_id' => $device->device_id,
                    'items_errored' => 1,
                ]));

                return null;
            }

            $now = now();
            $reserved = QueclinkPendingCommand::query()
                ->forDevice($device->id)
                ->where('id', '<>', $pending->id)
                ->where(function (Builder $query) use ($now): void {
                    $query->where(function (Builder $queued) use ($now): void {
                        $queued->where('status', QueclinkPendingCommand::STATUS_QUEUED)
                            ->where(function (Builder $lifetime) use ($now): void {
                                $lifetime->whereNull('expires_at')->orWhere('expires_at', '>', $now);
                            });
                    })->orWhere(function (Builder $transmitted): void {
                        $this->constrainTransmittedSerial($transmitted);
                    });
                })
                ->lockForUpdate()
                ->pluck('serial_number')
                ->map(fn (mixed $serial): string => strtoupper(trim((string) $serial)))
                ->filter(fn (string $serial): bool => preg_match('/^[0-9A-F]{4}$/', $serial) === 1)
                ->unique()
                ->values()
                ->all();

            if (in_array($storedSerial, $reserved, true)) {
                try {
                    $newSerial = $this->serials->nextExcluding($reserved);
                } catch (RuntimeException) {
                    return null;
                }
                if (preg_match('/^[0-9A-F]{4}$/', $newSerial) !== 1
                    || in_array($newSerial, $reserved, true)) {
                    return null;
                }

                $rewritten = preg_replace(
                    '/,[0-9A-F]{4}\$$/i',
                    ','.$newSerial.'$',
                    $rawCommand,
                    1,
                    $replacementCount,
                );
                if (! is_string($rewritten) || $replacementCount !== 1) {
                    return null;
                }

                $pending->forceFill([
                    'serial_number' => $newSerial,
                    'raw_command' => $rewritten,
                ])->save();
            }

            return $this->governedCommands->markSent($pending, $sessionId);
        }, 3);
    }

    private function constrainTransmittedSerial(Builder $query): void
    {
        // Queclink's 16-bit serial has no repository-proven maximum ACK delay.
        // Once command bytes have been exposed to a device, retain that serial
        // as a permanent per-device tombstone and fail closed on exhaustion.
        $query->where(function (Builder $transmitted): void {
            $transmitted->whereNotNull('sent_at')
                ->orWhereNotNull('sent_session_id')
                ->orWhereNotNull('acked_at')
                ->orWhereNotNull('ack_response')
                ->orWhereIn('status', [
                    QueclinkPendingCommand::STATUS_SENT,
                    QueclinkPendingCommand::STATUS_ACKED,
                ]);
        });
    }

    /** @return iterable<QueclinkPendingCommand> */
    protected function popQueuedCommands(QueclinkDevice $device): iterable
    {
        return QueclinkPendingCommand::query()
            ->forDevice($device->id)
            ->queued()
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::MAX_COMMANDS_PER_FRAME * 5)
            ->get()
            ->filter(function (QueclinkPendingCommand $command): bool {
                if ($command->device_command_attempt_id === null || $command->governed_sequence <= 1) {
                    return true;
                }

                return ! QueclinkPendingCommand::query()
                    ->where('device_command_attempt_id', $command->device_command_attempt_id)
                    ->where('governed_sequence', '<', $command->governed_sequence)
                    ->where('status', '<>', QueclinkPendingCommand::STATUS_ACKED)
                    ->exists();
            })
            ->take(self::MAX_COMMANDS_PER_FRAME)
            ->values();
    }
}
