<?php

namespace App\Observers;

use App\Models\RestraintEvent;
use App\Services\HealthSafety\RestraintSourceDeliveryService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Preserve legacy after-commit triggers; source commands stage intent atomically and save quietly. */
class RestraintEventObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly RestraintSourceDeliveryService $delivery) {}

    public function created(RestraintEvent $event): void
    {
        $this->stage($event);
    }

    public function updated(RestraintEvent $event): void
    {
        if ($event->wasChanged(RestraintEvent::LEGACY_FACTUAL_FIELDS)) {
            $changes = [];
            foreach (array_intersect(array_keys($event->getChanges()), RestraintEvent::LEGACY_FACTUAL_FIELDS) as $field) {
                $changes[$field] = $event->getAttribute($field);
            }
            $this->stage($event, $changes, (string) Str::uuid());
        }
    }

    private function stage(RestraintEvent $event, array $changes = [], ?string $handoffKey = null): void
    {
        try {
            $this->delivery->stageObserved($event, $changes, $handoffKey);
        } catch (Throwable $error) {
            Log::error('Restraint delivery staging failed', ['restraint_event_id' => $event->id, 'exception_type' => $error::class]);
            report($error);
        }
    }
}
