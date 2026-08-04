<?php

namespace App\Services\Queclink\Listener;

use App\Models\Queclink\QueclinkDevice;
use App\Models\Queclink\QueclinkPendingCommand;
use App\Models\Queclink\QueclinkRawFrame;
use App\Services\Fleet\FleetTelemetryIngestService;
use App\Services\Queclink\AckBuilder;
use App\Services\Queclink\AtTrackFrame;
use App\Services\Queclink\AtTrackProtocolParser;
use App\Services\Queclink\GovernedCommandLifecycleService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Routes a single parsed inbound frame:
 *   1. Persist raw frame for the debug console.
 *   2. Upsert QueclinkDevice (auto-add unknown IMEIs to pending tray).
 *   3. If paired, forward to FleetTelemetryIngestService for full ingest.
 *   4. Build outbound responses: server SACKs + any queued commands.
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
        $state->touch();
        $state->framesIn++;

        $frame = $this->parser->parse($rawFrame);
        $queclinkDevice = $this->resolveDevice($frame, $state);

        // Rejected devices are inert: log the raw frame for debug visibility
        // (so operators can see the rejection is working), then drop. No
        // SACK, no ingest, no command dispatch.
        if ($queclinkDevice && $queclinkDevice->status === QueclinkDevice::STATUS_REJECTED) {
            $this->logRaw($frame, $state, $queclinkDevice, 'inbound');

            return [];
        }

        $storedFrame = $this->logRaw($frame, $state, $queclinkDevice, 'inbound');

        if (! $frame->isValid()) {
            return [];
        }

        if ($queclinkDevice && $queclinkDevice->isPaired()) {
            try {
                $this->governedCommands->fulfilFromReconnection(
                    $queclinkDevice,
                    (int) $storedFrame->id,
                    $state->sessionId,
                );
            } catch (\Throwable $e) {
                Log::warning('queclink: governed reconnection reconciliation intake failed', [
                    'device_id' => $queclinkDevice->device_id,
                    'frame_id' => $storedFrame->id,
                    'error_type' => $e::class,
                ]);
            }
        }

        if ($queclinkDevice
            && $queclinkDevice->isPaired()
            && $frame->commandWord === 'GTALM') {
            try {
                $this->governedCommands->fulfilFromConfiguration($queclinkDevice, (int) $storedFrame->id);
            } catch (\Throwable $e) {
                Log::warning('queclink: governed configuration reconciliation intake failed', [
                    'device_id' => $queclinkDevice->device_id,
                    'frame_id' => $storedFrame->id,
                    'error_type' => $e::class,
                ]);
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
                $this->logRawOutbound($ack, $state, $queclinkDevice, $frame->isHeartbeat() ? 'GTHBD' : null);
            } else {
                $this->logRaw($this->parser->parse($ack), $state, null, 'outbound');
            }
        }

        // 2. Ingest telemetry into the fleet pipeline (only for paired devices).
        if ($queclinkDevice && $queclinkDevice->isPaired() && $frame->isReport()) {
            if ($queclinkDevice->device_id === null) {
                Log::warning('queclink: paired provider device has no canonical Device binding', [
                    'queclink_device_id' => $queclinkDevice->id,
                    'frame_id' => $storedFrame->id,
                ]);
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
                        Log::warning('queclink: canonical telemetry intake rejected', [
                            'queclink_device_id' => $queclinkDevice->id,
                            'device_id' => $queclinkDevice->device_id,
                            'frame_id' => $storedFrame->id,
                            'status' => $result['status'] ?? null,
                            'reason' => $result['error'] ?? 'unknown',
                        ]);
                    }
                } catch (\Throwable $e) {
                    Log::warning('queclink: ingest failed', [
                        'imei' => $frame->imei,
                        'command' => $frame->commandWord,
                        'error' => $e->getMessage(),
                    ]);
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
                $sent = $this->governedCommands->markSent($command, $state->sessionId);
                $outbound[] = $sent->raw_command;
                $this->logRawOutbound($sent->raw_command, $state, $queclinkDevice, $sent->command_word);
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
        $device->save();

        $state->bind($frame->imei, $device->id);

        return $device;
    }

    protected function logRaw(AtTrackFrame $frame, ConnectionState $state, ?QueclinkDevice $device, string $direction): QueclinkRawFrame
    {
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

        return QueclinkRawFrame::create([
            'queclink_device_id' => $device?->id,
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

    protected function logRawOutbound(string $raw, ConnectionState $state, QueclinkDevice $device, ?string $commandWord = null): void
    {
        $isCommand = ! str_starts_with($raw, '+SACK');

        QueclinkRawFrame::create([
            'queclink_device_id' => $device->id,
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
        QueclinkPendingCommand::query()
            ->forDevice($device->id)
            ->where('serial_number', $frame->serialNumber)
            ->where('status', QueclinkPendingCommand::STATUS_SENT)
            ->orderBy('id')
            ->get()
            ->each(fn (QueclinkPendingCommand $command) => $this->governedCommands->markAcknowledged(
                $command,
                $frame->rawFrame,
            ));
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
