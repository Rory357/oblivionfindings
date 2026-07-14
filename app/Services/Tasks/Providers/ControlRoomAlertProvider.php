<?php

namespace App\Services\Tasks\Providers;

use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Tasks\Contracts\AssignableTaskProvider;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ControlRoomAlertProvider implements AssignableTaskProvider, HasModelClass, TaskProvider
{
    private const TRANSACTION_ATTEMPTS = 3;

    /**
     * Same bypass list ControlRoomAlertController uses for its site scoping.
     *
     * @var array<int, string>
     */
    private const ALERT_BYPASS_PERMISSIONS = ['reports.viewAny'];

    public function sourceKey(): string
    {
        return 'alert';
    }

    public function label(): string
    {
        return 'Control Room Alerts';
    }

    public function modelClass(): string
    {
        return ControlRoomAlert::class;
    }

    public function canAssign(User $user): bool
    {
        // Mirrors routes/control-room.php: POST /alerts/{alert}/assign|unassign
        // → permission:controlRoom.alerts.assign.
        return $user->canDo('controlRoom.alerts.assign');
    }

    public function assign(User $actor, int $id, ?int $assigneeId): void
    {
        DB::transaction(function () use ($actor, $assigneeId, $id): void {
            $access = app(UserSiteAccessService::class);
            $freshActor = User::query()->whereKey($actor->id)->first();
            if (! $freshActor || ! $this->canAssign($freshActor)) {
                throw ValidationException::withMessages([
                    'assignee_id' => 'You do not have permission to assign this alert.',
                ]);
            }

            // Scope and lock the current database row together so a stale
            // queue item cannot bypass tenant access or terminal-state gates.
            $alert = ControlRoomAlert::query()
                ->whereKey($id)
                ->tap(fn ($query) => $access->applyAlertScope(
                    $query,
                    $freshActor,
                    self::ALERT_BYPASS_PERMISSIONS,
                ))
                ->lockForUpdate()
                ->first();

            if (! $alert) {
                throw ValidationException::withMessages([
                    'assignee_id' => 'Alert not found or outside your site access.',
                ]);
            }

            if (! $alert->isActionable()) {
                throw ValidationException::withMessages([
                    'assignee_id' => "Cannot assign an alert in '{$alert->status}' status.",
                ]);
            }

            $assignee = null;
            if ($assigneeId !== null) {
                $assignee = User::staff()
                    ->whereKey($assigneeId)
                    ->tap(fn ($query) => $access->applyControlRoomAssigneeScope(
                        $query,
                        $freshActor,
                        self::ALERT_BYPASS_PERMISSIONS,
                    ))
                    ->lockForUpdate()
                    ->first();

                if (! $assignee) {
                    throw ValidationException::withMessages([
                        'assignee_id' => 'You are not authorized to assign alerts to that staff member.',
                    ]);
                }
            }

            $at = now();
            $assignmentHistory = $alert->context['assignment_history'] ?? [];
            $assignmentHistory[] = [
                'action' => $assigneeId === null
                    ? 'unassigned'
                    : ($alert->assigned_to_user_id ? 'reassigned' : 'assigned'),
                'from_user_id' => $alert->assigned_to_user_id,
                'from_user_name' => $alert->assigned_to_user_id
                    ? User::query()->whereKey($alert->assigned_to_user_id)->value('name')
                    : null,
                'to_user_id' => $assigneeId,
                'to_user_name' => $assignee?->name,
                'by_user_id' => $freshActor->id,
                'by_user_name' => $freshActor->name,
                'reason' => null,
                'at' => $at->toISOString(),
            ];

            $alert->forceFill([
                'assigned_to_user_id' => $assigneeId,
                'assigned_at' => $assigneeId !== null ? $at : null,
                'assigned_by_user_id' => $assigneeId !== null ? $freshActor->id : null,
                'context' => array_merge($alert->context ?? [], [
                    'assignment_history' => $assignmentHistory,
                ]),
            ])->save();

            AuditLogger::logOrFail(
                $assigneeId === null ? 'controlRoom.alert.unassign' : 'controlRoom.alert.assign',
                $alert,
                [
                    'alert_id' => $alert->id,
                    'assigned_to' => $assigneeId,
                    'assigned_by' => $freshActor->id,
                    'actor_id' => $freshActor->id,
                ],
            );
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function canView(User $user): bool
    {
        return $user->canDo('controlRoom.viewAny')
            || $user->canDo('controlRoom.alerts.view');
    }

    public function tasks(User $user, array $filters = []): array
    {
        $query = ControlRoomAlert::query()
            ->with(['client:id,first_name,last_name', 'site:id,name', 'assignedTo:id,name'])
            // Same site scoping as the Control Room index (applyAlertScope) —
            // the queue must never show alerts the module itself would hide.
            ->tap(fn ($q) => app(UserSiteAccessService::class)->applyAlertScope($q, $user, self::ALERT_BYPASS_PERMISSIONS))
            ->orderByDesc('triggered_at')
            ->limit(300);

        if (empty($filters['include_done'])) {
            $query->actionable();
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
