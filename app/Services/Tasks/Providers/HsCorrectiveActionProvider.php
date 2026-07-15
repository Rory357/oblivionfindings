<?php

namespace App\Services\Tasks\Providers;

use App\Models\HsCorrectiveAction;
use App\Models\User;
use App\Services\Tasks\Contracts\AssignableTaskProvider;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\IncidentJourneyTaskContext;
use App\Services\Tasks\TaskItem;
use App\Services\UserSiteAccessService;
use Illuminate\Validation\ValidationException;

class HsCorrectiveActionProvider implements AssignableTaskProvider, HasModelClass, TaskProvider
{
    private const SITE_BYPASS_PERMISSIONS = ['healthSafety.viewAllSites'];

    public function sourceKey(): string
    {
        return 'corrective_action';
    }

    public function label(): string
    {
        return 'Corrective Actions';
    }

    public function modelClass(): string
    {
        return HsCorrectiveAction::class;
    }

    public function canAssign(User $user): bool
    {
        // Mirrors routes/health-safety.php: all corrective-action writes sit
        // behind permission:hazards.manage.
        return $user->canDo('hazards.manage');
    }

    public function assign(User $actor, int $id, ?int $assigneeId): void
    {
        $access = app(UserSiteAccessService::class);
        $action = HsCorrectiveAction::query()
            ->with('hsEvent')
            ->whereHas('hsEvent', fn ($query) => $access->applyHsEventScope(
                $query,
                $actor,
                self::SITE_BYPASS_PERMISSIONS,
            ))
            ->find($id);

        if (! $action) {
            throw ValidationException::withMessages([
                'assignee_id' => 'Corrective action not found.',
            ]);
        }

        if ($action->status === HsCorrectiveAction::STATUS_CLOSED) {
            throw ValidationException::withMessages([
                'assignee_id' => 'A closed corrective action cannot be reassigned.',
            ]);
        }

        if ($assigneeId !== null) {
            $assignee = User::query()
                ->whereKey($assigneeId)
                ->tap(fn ($query) => $access->applyHsEventStaffScope(
                    $query,
                    $action->hsEvent,
                    $actor,
                    self::SITE_BYPASS_PERMISSIONS,
                ))
                ->first();

            if (! $assignee) {
                throw ValidationException::withMessages([
                    'assignee_id' => 'That staff member is not eligible for this event site.',
                ]);
            }
        }

        // Same side-effect columns HsCorrectiveActionService stamps on
        // create/start: assignee + who assigned it + when.
        $action->update([
            'assigned_to_user_id' => $assigneeId,
            'assigned_by_user_id' => $assigneeId !== null ? $actor->id : null,
            'assigned_at' => $assigneeId !== null ? now() : null,
            'updated_by' => $actor->id, // module service stamps this on every write
        ]);
    }

    public function canView(User $user): bool
    {
        return $user->canDo('hazards.view');
    }

    public function tasks(User $user, array $filters = []): array
    {
        $query = HsCorrectiveAction::query()
            ->with([
                'assignedTo:id,name',
                'hsEvent.client:id,first_name,last_name',
                'hsEvent.site:id,name',
                'hsEvent.controlRoomAlert:id,reference_number',
                'hsEvent.clientIncident:id,client_id,site_id,hs_event_id,control_room_alert_id,reference_number,source,occurred_at',
                'hsEvent.clientIncident.client:id,first_name,last_name',
                'hsEvent.clientIncident.site:id,name',
            ])
            ->whereHas('hsEvent', fn ($q) => app(UserSiteAccessService::class)->applyHsEventScope(
                $q,
                $user,
                self::SITE_BYPASS_PERMISSIONS,
            ))
            ->orderByDesc('created_at')
            ->limit(300);

        if (empty($filters['include_done'])) {
            $query->where('status', '!=', HsCorrectiveAction::STATUS_CLOSED);
        }

        return $query->get()->map(function (HsCorrectiveAction $action) {
            $event = $action->hsEvent;
            $journey = IncidentJourneyTaskContext::make($event?->clientIncident, $event?->controlRoomAlert, $event);
            $client = $journey['person'] ?? ($event?->client ? [
                'id' => $event->client->id,
                'name' => trim($event->client->first_name.' '.$event->client->last_name),
            ] : null);
            $site = $journey['site'] ?? ($event?->site ? [
                'id' => $event->site->id,
                'name' => $event->site->name,
            ] : null);

            return new TaskItem(
                id: 'corrective_action-'.$action->id,
                source: $this->sourceKey(),
                sourceLabel: $this->label(),
                ref: $action->reference_number,
                title: $action->title ?: 'Corrective action',
                status: (string) $action->status,
                bucket: match ($action->status) {
                    HsCorrectiveAction::STATUS_CLOSED => TaskItem::BUCKET_DONE,
                    HsCorrectiveAction::STATUS_IN_PROGRESS,
                    HsCorrectiveAction::STATUS_COMPLETED,
                    HsCorrectiveAction::STATUS_VERIFIED => TaskItem::BUCKET_IN_PROGRESS,
                    default => TaskItem::BUCKET_OPEN,
                },
                severity: TaskItem::normaliseSeverity($action->priority),
                assignee: $action->assignedTo
                    ? ['id' => $action->assignedTo->id, 'name' => $action->assignedTo->name]
                    : null,
                client: $client,
                site: $site,
                dueAt: optional($action->due_date)->toIso8601String(),
                createdAt: optional($action->created_at)->toIso8601String(),
                link: "/health-safety/corrective-actions?event={$action->hs_event_id}",
                type: 'Corrective action',
                description: $action->description ? str($action->description)->limit(140)->toString() : null,
                journey: $journey,
                sourceContext: 'Health & Safety',
                actionLabel: match ($action->status) {
                    HsCorrectiveAction::STATUS_OPEN => 'Start corrective action',
                    HsCorrectiveAction::STATUS_IN_PROGRESS => 'Complete corrective action',
                    HsCorrectiveAction::STATUS_COMPLETED => 'Verify corrective action',
                    HsCorrectiveAction::STATUS_VERIFIED => 'Close corrective action',
                    default => 'Review corrective action',
                },
            );
        })->all();
    }
}
