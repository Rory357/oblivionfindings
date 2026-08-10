<?php

namespace App\Domain\Monitoring\Enums;

enum RuntimeMessageType: string
{
    case Observation = 'observation';
    case Event = 'event';
    case Configuration = 'configuration';
    case Projection = 'projection';
}
