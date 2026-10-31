<?php

namespace App\Observers;

use App\Models\EmergencyDrill;
use App\Services\HealthSafety\EmergencyDrillFailureDeliveryService;
use Illuminate\Support\Facades\DB;

/** Legacy model writers retain the same source-owned failure intent as canonical commands. */
class EmergencyDrillObserver
{
    public function __construct(private readonly EmergencyDrillFailureDeliveryService $delivery) {}

    public function updated(EmergencyDrill $drill): void
    {
        if (! $drill->wasChanged('status') || $drill->status !== 'completed' || ! in_array($drill->outcome, ['failed', 'passed_actions'], true) || ! $drill->completed_at) {
            return;
        }
        DB::transaction(fn () => $this->delivery->stage($drill));
    }
}
