<?php

namespace App\Services\Tasks\Providers;

use App\Models\ClientIncident;
use App\Models\User;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;

class ClientIncidentProvider implements TaskProvider
{
    public function sourceKey(): string
    {
        return 'incident';
    }

    public function label(): string
    {
        return 'Client Incidents';
    }

    public function canView(User $user): bool
    {
        return $user->canDo('incidents.view');
    }

    public function tasks(User $user, array $filters = []): array
    {
        $query = ClientIncident::query()
            ->with(['client:id,first_name,last_name', 'reporter:id,name'])
            ->orderByDesc('occurred_at')
            ->limit(300);

        if (empty($filters['include_done'])) {
            $query->whereNotIn('status', ['closed']);
        }

        return $query->get()->map(function (ClientIncident $incident) {
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
                assignee: $incident->investigation_assigned_to
                    ? ['id' => (int) $incident->investigation_assigned_to, 'name' => '']
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
