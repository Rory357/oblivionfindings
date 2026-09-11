<?php

namespace App\Domain\It\Services;

use App\Models\ControlRoom\Signal;
use App\Models\FleetSignal;

/** Canonical source records for technical availability work; no synthetic DeviceEvent. */
final readonly class ItFleetAvailability
{
    public function __construct(
        public FleetSignal $event,
        public FleetSignal $offline,
        public Signal $signal,
        public int $siteId,
    ) {}
}
