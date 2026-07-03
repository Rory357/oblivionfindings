<?php

namespace App\Services\Tasks\Providers;

use App\Models\ClientIncident;
use App\Models\User;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;

/**
 * NOT assignable from the queue: `investigation_assigned_to` is a legacy
 * column no incidents-module endpoint writes any more (investigation
 * editing moved to the H&S register — see IncidentController::update()'s
 * Option B note), so there are no module assignment rules to mirror.
 */
class ClientIncidentProvider implements TaskProvider, HasModelClass
{
    public function sourceKey(): string
    {
        return 'incident';
    }

    public function label(): string
    {
        return 'Client Incidents';
    }

    public function modelClass(): string
    {
        return ClientIncident::class;
    }

    public function canView(User $user): bool
    {
        // Mirrors routes/incidents.php: permission:incidents.viewAny|incidents.viewAssigned.
        return $user->canDo('incidents.viewAny') || $user->canDo('incidents.viewAssigned');
    }

    public function tasks(User $user, array $filters = []): array
    {
        $query = ClientIncident::query()
            ->with(['client:id,first_name,last_name'])
            // viewAssigned-only staff see just their assigned clients' incidents,
            // exactly as IncidentController::index scopes the register.
            ->when(
                ! $user->canDo('incidents.viewAny') && $user->canDo('incidents.viewAssigned'),
                fn ($q) => $q->whereHas('client.supportWorkers', fn ($qq) => $qq->whereKey($user->id)),
            )
            ->orderByDesc('occurred_at')
            ->limit(300);

        if (empty($filters['include_done'])) {
            $query->whereNotIn('status', ['closed']);
        }

        $incidents = $query->get();

        // Resolve investigation assignees in one query — there is no
        // Eloquent relation for investigation_assigned_to.
        $assigneeNames = User::query()
            ->whereIn('id', $incidents->pluck('investigation_assigned_to')->filter()->unique())
            ->pluck('name', 'id');

        return $incidents->map(function (ClientIncident $incident) use ($assigneeNames) {
            $client = $incident->client;

            return new TaskItem(
                id: 'incident-'.$incident->id,
                source: $this->sourceKey(),
                sourceLabel: $this->label(),
                ref: $incident->reference_number,
                title: $incident->title
                    ?: ucfirst(str_replace('_', ' ', (string) $incident->type)).' incident',
                status: (string) $incident->status,
                bucket: match ($incident->status) {
                    'closed' => TaskItem::BUCKET_DONE,
                    'submitted', 'reviewed' => TaskItem::BUCKET_IN_PROGRESS,
                    default => TaskItem::BUCKET_OPEN,
                },
                severity: TaskItem::normaliseSeverity($incident->severity),
                assignee: $incident->investigation_assigned_to && $assigneeNames->has($incident->investigation_assigned_to)
                    ? [
                        'id' => (int) $incident->investigation_assigned_to,
                        'name' => (string) $assigneeNames[$incident->investigation_assigned_to],
                    ]
                    : null,
                client: $client
                    ? ['id' => $client->id, 'name' => trim($client->first_name.' '.$client->last_name)]
                    : null,
                dueAt: null,
                createdAt: optional($incident->created_at)->toIso8601String(),
                link: "/incidents/{$incident->id}",
                type: 'Incident',
                description: $incident->description ? str($incident->description)->limit(140)->toString() : null,
            );
        })->all();
    }
}
