<?php

namespace App\Domain\Monitoring\Contracts;

use App\Domain\Monitoring\Data\AuthorisedProbeContext;
use App\Domain\Monitoring\Data\ProtocolObservation;
use App\Domain\Monitoring\Enums\MonitorKind;

interface ProbeAdapter
{
    public function kind(): MonitorKind;

    public function probe(AuthorisedProbeContext $context): ProtocolObservation;
}
