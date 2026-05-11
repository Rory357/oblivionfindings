<?php

namespace App\Services\Queclink;

/**
 * Allocates the 4-hex-digit serial numbers used in AT commands.
 *
 * The serial appears in the corresponding +ACK from the device and is the
 * only reliable way to correlate command → ack.
 *
 * Random 4-hex-digit selection rather than a counter: 65,536-slot space is
 * larger than any realistic queue depth per device (typically <5 outstanding),
 * and avoiding shared counter state means the controller process (where
 * commands are queued from the UI) and the listener process (which dispatches
 * them) don't need to coordinate. Collision risk for the device-scoped
 * correlation lookup is negligible at our scale.
 */
class SerialNumberAllocator
{
    public function next(): string
    {
        $value = random_int(0, 0xFFFF);
        return strtoupper(str_pad(dechex($value), 4, '0', STR_PAD_LEFT));
    }
}
