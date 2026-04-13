<?php

namespace App\Domain\Clinical\Events;

use App\Domain\Clinical\Models\ClinicalObservation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ObservationRecorded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ClinicalObservation $observation,
    ) {}
}
