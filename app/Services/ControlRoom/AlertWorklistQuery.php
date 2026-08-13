<?php

namespace App\Services\ControlRoom;

use App\Models\Client;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class AlertWorklistQuery
{
    /** @var list<string> */
    private const BYPASS_PERMISSIONS = ['reports.viewAny'];

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly AlertPriorityService $priority,
        private readonly ControlRoomAlertAccessService $alertAccess,
    ) {}

    /** @param array<string, mixed> $filters */
    public function forUser(User $user, array $filters = []): Builder
    {
        $query = $this->visibleAlerts($user)
            ->select('control_room_alerts.*')
            ->with([
                'site:id,name,tenant_id',
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

        $this->applyLens($query, (string) ($filters['lens'] ?? 'active'), $user);

        if (filled($filters['site_id'] ?? null)) {
            $this->siteAccess->applyAlertSiteScopeForSiteIds($query, [(int) $filters['site_id']]);
        }

        if (array_key_exists('ids', $filters)) {
            $ids = collect(is_array($filters['ids']) ? $filters['ids'] : [])
                ->filter(fn ($id): bool => is_numeric($id))
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
            $query->whereIn('control_room_alerts.id', $ids);
        }

        $query
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

    /**
     * Build every adjacent alert-list dataset from the same actor and Site
     * boundary as {@see forUser()}. Creation datasets are deliberately absent
     * unless the actor can create alerts; an empty picker is still a dataset.
     *
     * @return array<string, mixed>
     */
    public function viewContextFor(User $user): array
    {
        $statsBase = $this->visibleAlerts($user);

        // These counts intentionally mirror the existing worklist lenses.
        // The five live tabs exclude currently-snoozed alerts, while snoozed
        // and history retain their dedicated counts.
        $stats = [
            'total' => (clone $statsBase)->actionable()->notSnoozed()->count(),
            'open' => (clone $statsBase)->notSnoozed()->where('status', 'open')->count(),
            'critical' => (clone $statsBase)->notSnoozed()->where('severity', 'critical')->actionable()->count(),
            'in_triage' => (clone $statsBase)->where('status', 'triaging')->count(),
            'assigned_to_me' => (clone $statsBase)->notSnoozed()->where('assigned_to_user_id', $user->id)->actionable()->count(),
            'unassigned' => (clone $statsBase)->notSnoozed()->whereNull('assigned_to_user_id')->actionable()->count(),
            'snoozed' => (clone $statsBase)->snoozed()->count(),
            'history' => (clone $statsBase)->whereIn('status', ControlRoomAlert::TERMINAL_STATUSES)->count(),
            'sla_breached' => (clone $statsBase)->actionable()
                ->whereHas('sla', fn ($query) => $query->breached())
                ->count(),
        ];

        $queues = TriageQueue::active()
            ->withCount(['alerts as active_alert_count' => function (Builder $query) use ($user): void {
                $query->actionable();
                $this->applyVisibleAlertScope($query, $user);
            }])
            ->orderBy('tier')
            ->get(['id', 'name', 'tier', 'code'])
            ->map(fn (TriageQueue $queue): array => [
                'id' => $queue->id,
                'name' => $queue->name,
                'tier' => $queue->tier,
                'active_alerts' => $queue->active_alert_count,
            ]);

        $can = [
            'manage' => $user->canDo('controlRoom.alerts.manage'),
            'assign' => $user->canDo('controlRoom.alerts.assign'),
            'create' => $user->canDo('controlRoom.alerts.create'),
        ];

        $latestAlert = $this->visibleAlerts($user)->actionable();
        $context = [
            'stats' => $stats,
            'queues' => $queues,
            'staff' => $this->assignableStaff($user),
            'can' => $can,
            'latest_alert_at' => $latestAlert->max('updated_at'),
        ];

        if (! $can['create']) {
            return $context;
        }

        $clients = Client::query()->orderBy('first_name');
        $this->siteAccess->applyClientScope($clients, $user, self::BYPASS_PERMISSIONS);

        $sites = Site::query()->orderBy('name');
        $this->siteAccess->applySiteScope($sites, $user, self::BYPASS_PERMISSIONS);

        return array_merge($context, [
            'clients' => $clients
                ->get(['id', 'first_name', 'last_name', 'site_id'])
                ->map(fn (Client $client): array => [
                    'id' => $client->id,
                    'name' => trim($client->first_name.' '.$client->last_name),
                    'site_id' => $client->site_id,
                ]),
            'sites' => $sites->get(['id', 'name']),
        ]);
    }

    private function visibleAlerts(User $user): Builder
    {
        return $this->applyVisibleAlertScope(ControlRoomAlert::query(), $user);
    }

    private function applyVisibleAlertScope(Builder $query, User $user): Builder
    {
        return $this->alertAccess->applyVisibleScope($query, $user);
    }

    private function assignableStaff(User $user): Collection
    {
        if (! $user->canDo('controlRoom.alerts.assign') && ! $user->canDo('controlRoom.alerts.manage')) {
            return collect();
        }

        $staff = User::query()->staff()->orderBy('name');
        $this->siteAccess->applyControlRoomAssigneeScope(
            $staff,
            $user,
            self::BYPASS_PERMISSIONS,
        );

        return $staff->get(['id', 'name', 'email']);
    }

    private function applyLens(Builder $query, string $lens, User $user): void
    {
        match ($lens) {
            'history' => $query->whereIn('control_room_alerts.status', ControlRoomAlert::TERMINAL_STATUSES),
            'snoozed' => $query->snoozed(),
            'all_active' => $query->actionable(),
            'all_records' => $query,
            'my_queue' => $query->actionable()->notSnoozed()->where('control_room_alerts.assigned_to_user_id', $user->id),
            'safety_handover' => $query->actionable()->notSnoozed()->whereHas(
                'hsEvent',
                fn (Builder $event) => $event->where('handover_status', 'awaiting_acceptance'),
            ),
            default => $query->actionable()->notSnoozed(),
        };
    }
}
