<?php

namespace App\Services\Tasks\Providers;

use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;

class ControlRoomAlertProvider implements TaskProvider
{
    public function sourceKey(): string
    {
        return 'alert';
    }

    public function label(): string
    {
        return 'Control Room Alerts';
    }

    public function canView(User $user): bool
    {
        return $user->canDo('controlRoom.viewAny');
    }

    public function tasks(User $user, array $filters = []): array
    {
        $query = ControlRoomAlert::query()
            ->with(['client:id,first_name,last_name', 'site:id,name', 'assignedTo:id,name'])
            ->orderByDesc('triggered_at')
            ->limit(300);

        if (empty($filters['include_done'])) {
            $query->whereNotIn('status', ['resolved', 'closed', 'dismissed']);
        }

        return $query->get()->map(function (ControlRoomAlert $alert) {
            $client = $alert->client;
            $site = $alert->site;

            $title = ucfirst(str_replace('_', ' ', (string) $alert->alert_type));

            if ($alert->category) {
                $title .= ' — '.str_replace('_', ' ', (string) $alert->category);
            }

            return new TaskItem(
                id: 'alert-'.$alert->id,
                source: $this->sourceKey(),
                sourceLabel: $this->label(),
                ref: $alert->reference_number,
                title: $title,
                status: (string) $alert->status,
                bucket: match ($alert->status) {
                    'resolved', 'closed', 'dismissed' => TaskItem::BUCKET_DONE,
                    'ack', 'triaging', 'confirmed' => TaskItem::BUCKET_IN_PROGRESS,
                    default => TaskItem::BUCKET_OPEN,
                },
                severity: TaskItem::normaliseSeverity($alert->severity),
                assignee: $alert->assignedTo
                    ? ['id' => $alert->assignedTo->id, 'name' => (string) $alert->assignedTo->name]
                    : null,
                client: $client
                    ? ['id' => $client->id, 'name' => trim($client->first_name.' '.$client->last_name)]
                    : null,
                site: $site
                    ? ['id' => $site->id, 'name' => (string) $site->name]
                    : null,
                dueAt: optional($alert->due_at)->toIso8601String(),
                createdAt: optional($alert->created_at)->toIso8601String(),
                link: "/control-room/alerts/{$alert->id}",
                type: 'Alert',
                description: $alert->notes ? str($alert->notes)->limit(140)->toString() : null,
            );
        })->all();
    }
}
