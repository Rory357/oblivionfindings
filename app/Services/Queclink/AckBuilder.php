<?php

namespace App\Services\Queclink;

/**
 * Builds server-originated +SACK frames sent back to devices.
 *
 * The most important one is +SACK:GTHBD — when SACK Mode != 0 on the device,
 * heartbeats must be acknowledged or the device will resend up to 4 times
 * before treating the link as dead.
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
