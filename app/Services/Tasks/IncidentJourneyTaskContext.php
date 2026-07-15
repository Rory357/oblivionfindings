<?php

namespace App\Services\Tasks;

use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;

final class IncidentJourneyTaskContext
{
    /**
     * @return array<string, mixed>|null
     */
    public static function make(
        ?ClientIncident $incident,
        ?ControlRoomAlert $alert = null,
        ?HsEvent $event = null,
    ): ?array {
        if ($incident === null) {
            return null;
        }

        $incident->loadMissing([
            'client:id,first_name,last_name',
            'site:id,name',
            'controlRoomAlert:id,reference_number',
            'hsEvent:id,reference_number',
        ]);

        $alert ??= $incident->controlRoomAlert;
        $event ??= $incident->hsEvent;

        return [
            'key' => 'incident-'.$incident->id,
            'source' => $incident->source ?: 'incident',
            'occurred_at' => $incident->occurred_at?->toIso8601String(),
            'references' => [
                'control_room' => $alert?->reference_number ?: null,
                'incident' => $incident->reference_number ?: null,
                'health_safety' => $event?->reference_number ?: null,
            ],
            'person' => $incident->client ? [
                'id' => $incident->client->id,
                'name' => trim($incident->client->first_name.' '.$incident->client->last_name),
            ] : null,
            'site' => $incident->site ? [
                'id' => $incident->site->id,
                'name' => $incident->site->name,
            ] : null,
        ];
    }
}
