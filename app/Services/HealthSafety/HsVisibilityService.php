<?php

namespace App\Services\HealthSafety;

use App\Models\ControlRoomAlert;
use App\Models\HsEvent;

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
    public function forControlRoomAlert(ControlRoomAlert $alert): ?array
    {
        $hsEvent = HsEvent::where('control_room_alert_id', $alert->id)->first();

        if (! $hsEvent) {
            return null;
        }

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
            'investigation_required' => $hsEvent->investigation_required,
            'investigation' => $activeInvestigation ? [
                'reference_number' => $activeInvestigation->reference_number,
                'status' => $activeInvestigation->status,
                'lead_investigator_name' => $activeInvestigation->leadInvestigator?->name,
                'is_overdue' => $activeInvestigation->isOverdue(),
            ] : null,
            'total_corrective_actions' => $hsEvent->corrective_actions_count ?? 0,
            'open_corrective_actions' => $hsEvent->open_corrective_actions_count ?? 0,
            'href' => "/health-safety/events/{$hsEvent->id}",
        ];
    }
}
