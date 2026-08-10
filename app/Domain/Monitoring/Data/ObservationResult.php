<?php

namespace App\Domain\Monitoring\Data;

use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\SecurityDevices\Models\DeviceEvent;

final readonly class ObservationResult
{
    public function __construct(
        public MonitorObservation $observation,
        public bool $duplicate,
        public bool $stateChanged,
        public MonitorState $from,
        public MonitorState $to,
        public ?DeviceEvent $deviceEvent,
    ) {}
}
