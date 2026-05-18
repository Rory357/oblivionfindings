<?php

namespace App\Services\Queclink;

/**
 * Builds server-originated +SACK frames sent back to devices.
 *
 * The most important one is +SACK:GTHBD — heartbeats have their own shape.
 * When server acknowledgement is enabled on the device, every other device
 * message should receive a generic +SACK:<count_number>$ response.
 *
 * Format (per protocol PDF section 4.10.2):
 *   +SACK:GTHBD,<full_protocol_version>,<count_number>$
 *
 * Example:
 *   +SACK:GTHBD,8020090501,11F0$
 *
 * The count_number must match the count from the inbound heartbeat.
 */
class AckBuilder
{
    public function serverAck(AtTrackFrame $frame): ?string
    {
        if ($frame->isHeartbeat()) {
            return $this->heartbeatAck($frame);
        }

        if (! in_array($frame->frameType, ['RESP', 'ACK', 'BUFF'], true)) {
            return null;
        }

        if ($frame->countNumber === null) {
            return null;
        }

        return sprintf('+SACK:%s$', $frame->countNumber);
    }

    public function heartbeatAck(AtTrackFrame $heartbeat): ?string
    {
        if (! $heartbeat->isHeartbeat()) {
            return null;
        }
        if ($heartbeat->protocolVersion === null || $heartbeat->countNumber === null) {
            return null;
        }

        return sprintf(
            '+SACK:GTHBD,%s,%s$',
            $heartbeat->protocolVersion,
            $heartbeat->countNumber,
        );
    }
}
