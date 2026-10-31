<?php

namespace App\Services\Incidents;

use App\Models\ClientIncident;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Database\Eloquent\Builder;

/** Detached current footprint only; the canonical parent and policy owners still establish authority. */
final class IncidentRecordingContext
{
    public const COLUMNS = ['id', 'client_id', 'site_id', 'occurred_at', 'type', 'source', 'control_room_alert_id',
        'hs_event_id', 'fleet_incident_id', 'shift_id', 'respite_stay_id', 'service_context_id'];

    public static function capture(Builder $originalParent, int $incidentId, CurrentAuthorizationReads $reads): array
    {
        $reads->assertActive();
        if (! $originalParent->getModel() instanceof ClientIncident
            || $originalParent->getQuery()->from !== 'client_incidents') {
            throw new \LogicException('Capture recording context only from the canonical incident parent.');
        }
        // Exactly IncidentAccessService's historical Site precedence, with current locking subqueries.
        $review = "(SELECT r.site_id FROM hs_events r WHERE r.id=client_incidents.hs_event_id
            AND r.source_type=? AND r.source_id=client_incidents.id AND r.deleted_at IS NULL
            AND r.site_id IS NOT NULL LIMIT 1 FOR SHARE NOWAIT)";
        $shift = '(SELECT s.site_id FROM shifts s WHERE s.id=client_incidents.shift_id
            AND s.client_id=client_incidents.client_id LIMIT 1 FOR SHARE NOWAIT)';
        $client = '(SELECT c.site_id FROM clients c WHERE c.id=client_incidents.client_id
            AND c.deleted_at IS NULL LIMIT 1 FOR SHARE NOWAIT)';
        $site = "COALESCE(client_incidents.site_id,$review,
            CASE WHEN client_incidents.shift_id IS NOT NULL THEN $shift ELSE $client END)";
        $journey = IncidentJourneyService::incidentReadOwnershipExpression($reads);
        $row = $reads->query((clone $originalParent)->whereKey($incidentId)->reorder()->setEagerLoads([])
            ->select(array_map(static fn (string $column): string => 'client_incidents.'.$column, self::COLUMNS)))
            ->selectRaw("$site as recording_effective_site_id", [ClientIncident::class])
            ->selectRaw("SHA2(CAST($journey AS CHAR CHARACTER SET utf8mb4),256) as recording_journey_sha256")
            ->toBase()->first();
        abort_unless($row !== null, 404);
        $incident = (new ClientIncident)->setRawAttributes((array) $row, true);
        $anchor = ['incident_id' => (int) $row->id,
            'client_id' => $row->client_id === null ? null : (int) $row->client_id,
            'raw_site_id' => $row->site_id === null ? null : (int) $row->site_id,
            'effective_site_id' => $row->recording_effective_site_id === null ? null : (int) $row->recording_effective_site_id,
            'occurred_at' => $incident->occurred_at?->copy()->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'incident_type' => $row->type, 'source' => $row->source, 'journey_sha256' => $row->recording_journey_sha256];
        foreach (['control_room_alert_id', 'hs_event_id', 'fleet_incident_id', 'shift_id', 'respite_stay_id', 'service_context_id'] as $key) {
            $anchor[$key] = $row->{$key} === null ? null : (int) $row->{$key};
        }
        return IncidentRecordingReconstructionValues::contextAnchor($anchor);
    }
}
