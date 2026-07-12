<?php

namespace App\Services\Incidents;

use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;

final readonly class IncidentJourney
{
    public function __construct(
        public ClientIncident $incident,
        public ?ControlRoomAlert $alert,
        public ?HsEvent $hsEvent,
    ) {}
}
