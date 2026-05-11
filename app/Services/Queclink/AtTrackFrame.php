<?php

namespace App\Services\Queclink;

/**
 * Parsed @Track frame.
 *
 * Frame grammar (ASCII):
 *   <header>:<command>,<field1>,<field2>,...,<send_time>,<count>$
 *
 * Where header is one of:
 *   +RESP   device-originated report
 *   +ACK    device-originated acknowledgement of a server command
 *   +SACK   server-originated acknowledgement of a device heartbeat
 *   +BUFF   buffered report sent after reconnect (same payload as +RESP)
 *   AT+GT   server-to-device command
 */
final class AtTrackFrame
{
    public function __construct(
        public readonly string $rawFrame,
        public readonly string $frameType,           // RESP | ACK | SACK | BUFF | AT | unknown
        public readonly ?string $commandWord,        // e.g. GTFRI, GTHBD, GTSOS
        public readonly ?string $protocolVersion,    // 6 or 10 hex chars
        public readonly ?string $imei,
        public readonly ?string $deviceName,
        public readonly ?string $countNumber,        // 4 hex chars, used to echo in +SACK
        public readonly ?string $serialNumber,       // 4 hex chars on +ACK frames; matches the command's serial
        public readonly array $fields,               // raw comma-split field array (without trailing $)
        public readonly array $payload = [],         // normalised payload keyed by semantic name
        public readonly ?string $parseError = null,
    ) {
    }

    public function isValid(): bool
    {
        return $this->parseError === null && $this->commandWord !== null;
    }

    public function isHeartbeat(): bool
    {
        return $this->commandWord === 'GTHBD';
    }

    public function isReport(): bool
    {
        return in_array($this->frameType, ['RESP', 'BUFF'], true);
    }

    public function isAck(): bool
    {
        return $this->frameType === 'ACK';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'frame_type' => $this->frameType,
            'command_word' => $this->commandWord,
            'protocol_version' => $this->protocolVersion,
            'imei' => $this->imei,
            'device_name' => $this->deviceName,
            'count_number' => $this->countNumber,
            'serial_number' => $this->serialNumber,
            'payload' => $this->payload,
            'fields_count' => count($this->fields),
        ];
    }
}
