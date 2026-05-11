<?php

namespace App\Services\Queclink\Listener;

use App\Models\Queclink\QueclinkDevice;
use App\Models\Queclink\QueclinkPendingCommand;
use App\Models\Queclink\QueclinkRawFrame;
use App\Services\Fleet\FleetTelemetryIngestService;
use App\Services\Queclink\AckBuilder;
use App\Services\Queclink\AtTrackFrame;
use App\Services\Queclink\AtTrackProtocolParser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Routes a single parsed inbound frame:
 *   1. Persist raw frame for the debug console.
 *   2. Upsert QueclinkDevice (auto-add unknown IMEIs to pending tray).
 *   3. If paired, forward to FleetTelemetryIngestService for full ingest.
 *   4. Build outbound responses: heartbeat SACKs + any queued commands.
 *
 * Returns a list of raw bytes the caller should write back to the socket.
 */
class FrameRouter
{
    public function __construct(
        protected AtTrackProtocolParser $parser,
        protected AckBuilder $acks,
        protected FleetTelemetryIngestService $ingest,
    ) {
    }

    /**
     * @return list<string>  Raw frames to send back to the device.
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

        $this->logRaw($frame, $state, $queclinkDevice, 'inbound');

        if (! $frame->isValid()) {
            return [];
        }

        $outbound = [];

        // 1. Heartbeat ACK — send for pending + paired devices alike so the
        //    device keeps the connection alive while waiting for adoption.
        //    Rejected devices already returned above.
        if ($frame->isHeartbeat()) {
            $ack = $this->acks->heartbeatAck($frame);
            if ($ack !== null) {
                $outbound[] = $ack;
                $this->logRaw($this->parser->parse($ack), $state, $queclinkDevice, 'outbound');
            }
        }

        // 2. Ingest telemetry into the fleet pipeline (only for paired devices).
        if ($queclinkDevice && $queclinkDevice->isPaired() && $frame->isReport()) {
            try {
                $this->ingest->ingest('queclink', $frame->payload);
            } catch (\Throwable $e) {
                Log::warning('queclink: ingest failed', [
                    'imei' => $frame->imei,
                    'command' => $frame->commandWord,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 3. ACK correlation — if this is a device +ACK, mark the matching pending command.
        if ($frame->isAck() && $queclinkDevice) {
            $this->correlateAck($frame, $queclinkDevice);
        }

        // 4. Dispatch any queued commands for paired devices (capped per frame).
        if ($queclinkDevice && $queclinkDevice->isPaired()) {
            foreach ($this->popQueuedCommands($queclinkDevice) as $command) {
                $outbound[] = $command->raw_command;
                $this->logRawOutbound($command->raw_command, $state, $queclinkDevice, $command->command_word);
                $command->update([
                    'status' => QueclinkPendingCommand::STATUS_SENT,
                    'sent_at' => now(),
                ]);
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

    protected function logRaw(AtTrackFrame $frame, ConnectionState $state, ?QueclinkDevice $device, string $direction): void
    {
        QueclinkRawFrame::create([
            'queclink_device_id' => $device?->id,
            'imei' => $frame->imei,
            'tenant_id' => $device?->tenant_id,
            'direction' => $direction,
            'frame_type' => $frame->frameType,
            'command_word' => $frame->commandWord,
            'raw_frame' => $frame->rawFrame,
            'parsed_payload' => $frame->isValid() ? $frame->payload : null,
            'parse_ok' => $frame->isValid(),
            'parse_error' => $frame->parseError,
            'session_id' => $state->sessionId,
            'remote_address' => $state->remoteAddress,
        ]);
    }

    protected function logRawOutbound(string $raw, ConnectionState $state, QueclinkDevice $device, ?string $commandWord = null): void
    {
        QueclinkRawFrame::create([
            'queclink_device_id' => $device->id,
            'imei' => $device->imei,
            'tenant_id' => $device->tenant_id,
            'direction' => 'outbound',
            'frame_type' => str_starts_with($raw, '+SACK') ? 'SACK' : 'AT',
            'command_word' => $commandWord,
            'raw_frame' => $raw,
            'parsed_payload' => null,
            'parse_ok' => true,
            'session_id' => $state->sessionId,
            'remote_address' => $state->remoteAddress,
        ]);
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
            ->update([
                'status' => QueclinkPendingCommand::STATUS_ACKED,
                'acked_at' => now(),
                'ack_response' => $frame->rawFrame,
            ]);
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
            ->limit(self::MAX_COMMANDS_PER_FRAME)
            ->get();
    }
}
