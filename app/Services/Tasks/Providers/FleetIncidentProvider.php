<?php

namespace App\Services\Tasks\Providers;

use App\Models\FleetIncident;
use App\Models\User;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;

class FleetIncidentProvider implements TaskProvider, HasModelClass
{
    public function sourceKey(): string
    {
        return 'fleet_incident';
    }

    public function label(): string
    {
        return 'Fleet & Asset Incidents';
    }

    public function modelClass(): string
    {
        return FleetIncident::class;
    }

    public function canView(User $user): bool
    {
        return $user->canDo('fleet.viewAny') || $user->canDo('assets.viewAny');
    }

    public function tasks(User $user, array $filters = []): array
    {
        $query = FleetIncident::query()
            ->with(['asset:id,name', 'assignedTo:id,name'])
            ->orderByDesc('occurred_at')
            ->limit(300);

        if (empty($filters['include_done'])) {
            $query->whereIn('status', ['reported', 'investigating']);
        }

        return $query->get()->map(function (FleetIncident $incident) {
            $title = ucfirst(str_replace('_', ' ', (string) $incident->incident_type));

            if ($incident->asset) {
                $title .= ' — '.$incident->asset->name;
            }

            return new TaskItem(
                id: 'fleet_incident-'.$incident->id,
                source: $this->sourceKey(),
                sourceLabel: $this->label(),
                ref: $incident->reference_number,
                title: $title,
                status: (string) $incident->status,
                bucket: match ($incident->status) {
                    'resolved', 'closed' => TaskItem::BUCKET_DONE,
                    'investigating' => TaskItem::BUCKET_IN_PROGRESS,
                    default => TaskItem::BUCKET_OPEN,
                },
                severity: TaskItem::normaliseSeverity($incident->severity),
                assignee: $incident->assignedTo
                    ? ['id' => $incident->assignedTo->id, 'name' => (string) $incident->assignedTo->name]
                    : null,
                dueAt: null,
                createdAt: optional($incident->created_at)->toIso8601String(),
                link: "/fleet-assets/incidents/{$incident->id}",
                type: 'Fleet incident',
                description: $incident->description ? str($incident->description)->limit(140)->toString() : null,
            );
        })->all();
    }
}
