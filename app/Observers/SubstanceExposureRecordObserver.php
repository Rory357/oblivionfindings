<?php

namespace App\Observers;

use App\Models\SubstanceExposureRecord;
use App\Services\HealthSafety\ChemicalExposureDeliveryService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/** New source commands retain revision-bound delivery intents. No current-incident fallback certifies historical context. */
final class SubstanceExposureRecordObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly ChemicalExposureDeliveryService $delivery) {}

    public function created(SubstanceExposureRecord $source): void
    {
        $this->delivery->sourceCommitted($source);
    }

    public function updated(SubstanceExposureRecord $source): void
    {
        $this->delivery->sourceCommitted($source);
    }
}