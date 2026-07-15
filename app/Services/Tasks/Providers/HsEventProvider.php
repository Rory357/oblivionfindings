<?php

namespace App\Services\Tasks\Providers;

use App\Models\HsEvent;
use App\Models\User;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\IncidentJourneyTaskContext;
use App\Services\Tasks\TaskItem;
use App\Services\UserSiteAccessService;

class HsEventProvider implements HasModelClass, TaskProvider
{
    private const SITE_BYPASS_PERMISSIONS = ['healthSafety.viewAllSites'];

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
            ->with([
                'client:id,first_name,last_name',
                'site:id,name',
                'owner:id,name',
                'controlRoomAlert:id,reference_number',
                'clientIncident:id,client_id,site_id,hs_event_id,control_room_alert_id,reference_number,source,occurred_at',
                'clientIncident.client:id,first_name,last_name',
                'clientIncident.site:id,name',
                'clientIncident.controlRoomAlert:id,reference_number',
            ])
            ->tap(fn ($q) => app(UserSiteAccessService::class)->applyHsEventScope(
                $q,
                $user,
                self::SITE_BYPASS_PERMISSIONS,
            ))
            ->orderByDesc('occurred_at')
            ->limit(300);

        if (empty($filters['include_done'])) {
            $query->where('status', '!=', HsEvent::STATUS_CLOSED);
        }

        return $query->get()->map(function (HsEvent $event) {
            $journey = IncidentJourneyTaskContext::make($event->clientIncident, $event->controlRoomAlert, $event);
            $client = $journey['person'] ?? ($event->client ? [
                'id' => $event->client->id,
                'name' => trim($event->client->first_name.' '.$event->client->last_name),
            ] : null);
            $site = $journey['site'] ?? ($event->site ? [
                'id' => $event->site->id,
                'name' => $event->site->name,
            ] : null);

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
                assignee: $event->owner ? ['id' => $event->owner->id, 'name' => $event->owner->name] : null,
                client: $client,
                site: $site,
                dueAt: null,
                createdAt: optional($event->created_at)->toIso8601String(),
                link: "/health-safety/events/{$event->id}",
                type: 'H&S event',
                description: null,
                journey: $journey,
                sourceContext: str_replace('_', ' ', (string) $event->event_category),
                actionLabel: $event->handover_status === HsEvent::HANDOVER_AWAITING_ACCEPTANCE
                    ? 'Accept H&S handover'
                    : 'Continue H&S governance',
            );
        })->all();
    }
}
