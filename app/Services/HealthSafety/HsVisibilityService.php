<?php

namespace App\Services\HealthSafety;

use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\User;

/**
 * Read-only service for surfacing H&S data in non-H&S contexts.
 *
 * Used by Control Room, module pages, and anywhere that needs a
 * lightweight, shaped H&S summary without depending on H&S internals.
 *
 * All methods are strictly read-only. No mutations. No side effects.
 */
class HsVisibilityService
{
    /**
     * Get linked HsEvent summary for a Control Room alert.
     *
     * Returns null if no linked HsEvent exists.
     * Designed for the CR alert detail panel — compact, read-only.
     */
    public function forControlRoomAlert(ControlRoomAlert $alert, ?User $viewer = null): ?array
    {
        $hsEvent = HsEvent::where('control_room_alert_id', $alert->id)->first();

        if (! $hsEvent) {
            return null;
        }

        $hsEvent->loadMissing(['owner:id,name', 'acceptedBy:id,name']);
        $hsEvent->loadCount([
            'investigations',
            'correctiveActions',
            'openCorrectiveActions',
        ]);

        $activeInvestigation = $hsEvent->activeInvestigation;

        return [
            'id' => $hsEvent->id,
            'reference_number' => $hsEvent->reference_number,
            'event_category' => $hsEvent->event_category,
            'severity' => $hsEvent->severity,
            'status' => $hsEvent->status,
            'reported_at' => $hsEvent->reported_at?->toIso8601String(),
            'worksafe_notifiable' => $hsEvent->worksafe_notifiable,
            'worksafe_status' => $hsEvent->worksafe_status,
            'worksafe_reference' => $hsEvent->worksafe_reference,
            'worksafe_notified_at' => $hsEvent->worksafe_notified_at?->toIso8601String(),
            'worksafe_acknowledged_at' => $hsEvent->worksafe_acknowledged_at?->toIso8601String(),
            'handover' => [
                'status' => $hsEvent->handover_status,
                'owner' => $hsEvent->owner ? [
                    'id' => $hsEvent->owner->id,
                    'name' => $hsEvent->owner->name,
                ] : null,
                'accepted_by' => $hsEvent->acceptedBy ? [
                    'id' => $hsEvent->acceptedBy->id,
                    'name' => $hsEvent->acceptedBy->name,
                ] : null,
                'accepted_at' => $hsEvent->accepted_at?->toIso8601String(),
                'notes' => $hsEvent->acceptance_notes,
            ],
            'investigation_required' => $hsEvent->investigation_required,
            'investigation' => $activeInvestigation ? [
                'reference_number' => $activeInvestigation->reference_number,
                'status' => $activeInvestigation->status,
                'lead_investigator_name' => $activeInvestigation->leadInvestigator?->name,
                'is_overdue' => $activeInvestigation->isOverdue(),
            ] : null,
            'total_corrective_actions' => $hsEvent->corrective_actions_count ?? 0,
            'open_corrective_actions' => $hsEvent->open_corrective_actions_count ?? 0,
            'href' => $viewer?->canDo('hazards.view')
                ? "/health-safety/events/{$hsEvent->id}"
                : null,
        ];
    }
}
