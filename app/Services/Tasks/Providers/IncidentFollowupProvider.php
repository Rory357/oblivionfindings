<?php

namespace App\Services\Tasks\Providers;

use App\Models\IncidentFollowup;
use App\Models\User;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;

class IncidentFollowupProvider implements TaskProvider
{
    public function sourceKey(): string
    {
        return 'followup';
    }

    public function label(): string
    {
        return 'Incident Follow-ups';
    }

    public function canView(User $user): bool
    {
        return $user->canDo('incidents.view');
    }

    public function tasks(User $user, array $filters = []): array
    {
        $query = IncidentFollowup::query()
            ->with([
                'incident:id,reference_number,title,client_id',
                'incident.client:id,first_name,last_name',
                'assignedTo:id,name',
            ])
            ->orderByDesc('created_at')
            ->limit(300);

        if (empty($filters['include_done'])) {
            $query->whereNull('completed_at');
        }

        return $query->get()->map(function (IncidentFollowup $followup) {
            $incident = $followup->incident;
            $client = $incident?->client;

            return new TaskItem(
                id: 'followup-'.$followup->id,
                source: $this->sourceKey(),
                sourceLabel: $this->label(),
                ref: $incident?->reference_number,
                title: $incident?->title
                    ? 'Follow-up — '.$incident->title
                    : 'Incident follow-up',
                status: $followup->completed_at ? 'completed' : 'open',
                bucket: $followup->completed_at ? TaskItem::BUCKET_DONE : TaskItem::BUCKET_OPEN,
                severity: 'medium',
                assignee: $followup->assignedTo
                    ? ['id' => $followup->assignedTo->id, 'name' => $followup->assignedTo->name]
                    : null,
                client: $client
                    ? ['id' => $client->id, 'name' => trim($client->first_name.' '.$client->last_name)]
                    : null,
                dueAt: optional($followup->due_at)->toIso8601String(),
                createdAt: optional($followup->created_at)->toIso8601String(),
                link: "/incidents/{$followup->client_incident_id}",
                type: 'Follow-up',
                description: $followup->notes ? str($followup->notes)->limit(140)->toString() : null,
            );
        })->all();
    }
}
