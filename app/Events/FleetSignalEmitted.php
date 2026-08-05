<?php

namespace App\Events;

use App\Models\FleetSignal;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched whenever a fleet signal is emitted (geofence breach, SOS, tamper, etc.).
 * This is an internal domain event. Raw signal payloads must not be broadcast.
 */
class FleetSignalEmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(public FleetSignal $signal) {}
}
