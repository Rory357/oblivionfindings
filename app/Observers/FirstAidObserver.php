<?php

namespace App\Observers;

use App\Models\FirstAidRecord;
use App\Models\HsEvent;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\HealthSafety\FirstAidDeliveryService;
use Illuminate\Support\Facades\DB;

/** Retained direct-model callers stage real review intent; commands stage it inside their own transaction. */
class FirstAidObserver
{
    public function __construct(private readonly FirstAidDeliveryService $delivery) {}

    public function created(FirstAidRecord $record): void
    {
        $this->stage($record, $record->created_by);
    }

    public function updated(FirstAidRecord $record): void
    {
        if ($record->wasChanged('related_incident_id') && $record->related_incident_id) {
            $event = HsEvent::whereIn('source_type', array_unique([FirstAidRecord::class, $record->getMorphClass()]))->where('source_id', $record->id)->where('event_category', HsEvent::CATEGORY_INJURY)->first();
            if ($event) {
                AuditLogger::logOrFail('healthSafety.event.firstAidSupersessionRecorded', $event, ['first_aid_record_id' => $record->id, 'linked_incident_id' => $record->related_incident_id, 'closure_required' => $event->status !== HsEvent::STATUS_CLOSED]);
            }
        }
        if ($record->wasChanged(['ambulance_called', 'treatment_outcome'])) {
            $this->stage($record, $record->updated_by ?: $record->created_by);
        }
    }

    private function stage(FirstAidRecord $record, ?int $actorId): void
    {
        $actor = $actorId ? User::find($actorId) : null;
        if ($actor) {
            DB::transaction(fn () => $this->delivery->stageReview($record->fresh(), $actor));
        }
    }
}
