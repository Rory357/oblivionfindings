<?php

namespace App\Domain\Clinical\Events;

use App\Domain\Clinical\Models\ClinicalEvent;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ClinicalEventRecorded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ClinicalEvent $clinicalEvent,
    ) {}
}
