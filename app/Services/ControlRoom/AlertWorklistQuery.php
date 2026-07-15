<?php

namespace App\Services\ControlRoom;

use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;

class AlertWorklistQuery
{
    /** @var list<string> */
    private const BYPASS_PERMISSIONS = ['reports.viewAny'];

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly AlertPriorityService $priority,
    ) {}

    /** @param array<string, mixed> $filters */
    public function forUser(User $user, array $filters = []): Builder
    {
        $query = ControlRoomAlert::query()
            ->select('control_room_alerts.*')
            ->with([
                'site:id,name',
                'client:id,first_name,last_name,site_id,organization_id',
                'client.site:id,name,tenant_id',
                'assignedTo' => function ($assignee) use ($user): void {
                    $query = $assignee->getQuery();
                    $query->select(['users.id', 'users.name', 'users.email']);
                    $this->siteAccess->applyControlRoomAssigneeScope($query, $user, self::BYPASS_PERMISSIONS);
                },
                'queue:id,name,tier',
                'sla',
                'playbookRun:id,playbook_id,status,current_step,completed_steps,total_steps',
                'playbookRun.playbook:id,name,code',
                'clientIncident:id,reference_number,control_room_alert_id,hs_event_id,status,severity,site_id',
                'hsEvent:id,reference_number,control_room_alert_id,handover_status,owner_user_id,status,severity,worksafe_notifiable,worksafe_status',
            ]);

        $this->siteAccess->applyAlertScope($query, $user, self::BYPASS_PERMISSIONS);
        $this->applyLens($query, (string) ($filters['lens'] ?? 'active'), $user);

        $query
            ->when(filled($filters['site_id'] ?? null), fn (Builder $q) => $q->where('control_room_alerts.site_id', (int) $filters['site_id']))
            ->when(filled($filters['severity'] ?? null), fn (Builder $q) => $q->where('control_room_alerts.severity', $filters['severity']))
            ->when(filled($filters['source'] ?? null), fn (Builder $q) => $q->where('control_room_alerts.source', $filters['source']))
            ->when(filled($filters['queue_id'] ?? null), fn (Builder $q) => $q->where('control_room_alerts.queue_id', (int) $filters['queue_id']))
            ->when(filled($filters['assigned_to'] ?? null), fn (Builder $q) => $q->where('control_room_alerts.assigned_to_user_id', (int) $filters['assigned_to']))
            ->when(filled($filters['escalation_level'] ?? null), fn (Builder $q) => $q->where('control_room_alerts.escalation_level', '>=', (int) $filters['escalation_level']))
            ->when(filled($filters['date_from'] ?? null), fn (Builder $q) => $q->whereDate('control_room_alerts.triggered_at', '>=', $filters['date_from']))
            ->when(filled($filters['date_to'] ?? null), fn (Builder $q) => $q->whereDate('control_room_alerts.triggered_at', '<=', $filters['date_to']))
            ->when(filled($filters['q'] ?? null), function (Builder $q) use ($filters): void {
                $term = '%'.trim((string) $filters['q']).'%';
                $q->where(function (Builder $search) use ($term): void {
                    $search->where('control_room_alerts.reference_number', 'like', $term)
                        ->orWhere('control_room_alerts.alert_type', 'like', $term)
                        ->orWhere('control_room_alerts.notes', 'like', $term)
                        ->orWhereHas('clientIncident', fn (Builder $incident) => $incident->where('reference_number', 'like', $term))
                        ->orWhereHas('hsEvent', fn (Builder $event) => $event->where('reference_number', 'like', $term));
                });
            });

        return $this->priority->apply($query);
    }

    private function applyLens(Builder $query, string $lens, User $user): void
    {
        match ($lens) {
            'history' => $query->whereIn('control_room_alerts.status', ControlRoomAlert::TERMINAL_STATUSES),
            'snoozed' => $query->snoozed(),
            'my_queue' => $query->actionable()->notSnoozed()->where('control_room_alerts.assigned_to_user_id', $user->id),
            'safety_handover' => $query->actionable()->notSnoozed()->whereHas(
                'hsEvent',
                fn (Builder $event) => $event->where('handover_status', 'awaiting_acceptance'),
            ),
            default => $query->actionable()->notSnoozed(),
        };
    }
}
