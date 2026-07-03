<?php

namespace App\Services\Tasks\Providers;

use App\Models\IncidentFollowup;
use App\Models\User;
use App\Services\Tasks\Contracts\AssignableTaskProvider;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;
use Illuminate\Validation\ValidationException;

class IncidentFollowupProvider implements TaskProvider, HasModelClass, AssignableTaskProvider
{
    public function sourceKey(): string
    {
        return 'followup';
    }

    public function label(): string
    {
        return 'Incident Follow-ups';
    }

    public function modelClass(): string
    {
        return IncidentFollowup::class;
    }

    public function canAssign(User $user): bool
    {
        // Mirrors routes/incidents.php: followup writes → permission:incidents.followups.manage
        // (IncidentFollowupPolicy::update).
        return $user->canDo('incidents.followups.manage');
    }

    public function assign(User $actor, int $id, ?int $assigneeId): void
    {
        // Re-fetch with the same viewAssigned client scoping tasks() applies.
        $followup = IncidentFollowup::query()
            ->when(
                ! $actor->canDo('incidents.viewAny') && $actor->canDo('incidents.viewAssigned'),
                fn ($q) => $q->whereHas('incident.client.supportWorkers', fn ($qq) => $qq->whereKey($actor->id)),
            )
            ->find($id);

        if (! $followup) {
            throw ValidationException::withMessages([
                'assignee_id' => 'Follow-up not found or outside your assigned clients.',
            ]);
        }

        // Audit guardrail mirrored from IncidentFollowupController::update().
        if (! empty($followup->completed_at)) {
            throw ValidationException::withMessages([
                'assignee_id' => 'A completed follow-up cannot be modified.',
            ]);
        }

        $followup->update(['assigned_to_user_id' => $assigneeId]);
    }

    public function canView(User $user): bool
    {
        // Mirrors routes/incidents.php: permission:incidents.viewAny|incidents.viewAssigned.
        return $user->canDo('incidents.viewAny') || $user->canDo('incidents.viewAssigned');
    }

    public function tasks(User $user, array $filters = []): array
    {
        $query = IncidentFollowup::query()
            ->with([
                'incident:id,reference_number,title,client_id',
                'incident.client:id,first_name,last_name',
                'assignedTo:id,name',
            ])
            // Same viewAssigned client scoping as the incidents register.
            ->when(
                ! $user->canDo('incidents.viewAny') && $user->canDo('incidents.viewAssigned'),
                fn ($q) => $q->whereHas('incident.client.supportWorkers', fn ($qq) => $qq->whereKey($user->id)),
            )
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
