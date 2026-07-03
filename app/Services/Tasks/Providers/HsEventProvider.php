<?php

namespace App\Services\Tasks\Providers;

use App\Models\HsEvent;
use App\Models\User;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;

class HsEventProvider implements TaskProvider, HasModelClass
{
    public function sourceKey(): string
    {
        return 'hs_event';
    }

    public function label(): string
    {
        return 'H&S Events';
    }

    public function modelClass(): string
    {
        return HsEvent::class;
    }

    public function canView(User $user): bool
    {
        return $user->canDo('hazards.view');
    }

    public function tasks(User $user, array $filters = []): array
    {
        $query = HsEvent::query()
            ->with(['client:id,first_name,last_name', 'site:id,name'])
            ->orderByDesc('occurred_at')
            ->limit(300);

        if (empty($filters['include_done'])) {
            $query->where('status', '!=', HsEvent::STATUS_CLOSED);
        }

        return $query->get()->map(function (HsEvent $event) {
            $client = $event->client;

            return new TaskItem(
                id: 'hs_event-'.$event->id,
                source: $this->sourceKey(),
                sourceLabel: $this->label(),
                ref: $event->reference_number,
                title: ucfirst(str_replace('_', ' ', (string) $event->event_category)).' event',
                status: (string) $event->status,
                bucket: match ($event->status) {
                    HsEvent::STATUS_CLOSED => TaskItem::BUCKET_DONE,
                    HsEvent::STATUS_INVESTIGATING,
                    HsEvent::STATUS_CORRECTIVE_ACTION,
                    HsEvent::STATUS_MONITORING => TaskItem::BUCKET_IN_PROGRESS,
                    default => TaskItem::BUCKET_OPEN,
                },
                severity: TaskItem::normaliseSeverity($event->severity),
                assignee: null,
                client: $client
                    ? ['id' => $client->id, 'name' => trim($client->first_name.' '.$client->last_name)]
                    : null,
                site: $event->site
                    ? ['id' => $event->site->id, 'name' => $event->site->name]
                    : null,
                dueAt: null,
                createdAt: optional($event->created_at)->toIso8601String(),
                link: "/health-safety/events/{$event->id}",
                type: 'H&S event',
                description: null,
            );
        })->all();
    }
}
