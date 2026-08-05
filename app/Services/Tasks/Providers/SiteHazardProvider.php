<?php

namespace App\Services\Tasks\Providers;

use App\Models\SiteHazard;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Tasks\Contracts\AssignableTaskProvider;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;
use Illuminate\Validation\ValidationException;

class SiteHazardProvider implements AssignableTaskProvider, HasModelClass, TaskProvider
{
    public function sourceKey(): string
    {
        return 'hazard';
    }

    public function label(): string
    {
        return 'Hazards';
    }

    public function modelClass(): string
    {
        return SiteHazard::class;
    }

    public function canAssign(User $user): bool
    {
        // Mirrors routes/sites.php: POST /hazards/{hazard}/assign → permission:hazards.assign.
        return $user->canDo('hazards.assign');
    }

    public function assign(User $actor, int $id, ?int $assigneeId): void
    {
        $hazard = SiteHazard::query()->with('site')->find($id);

        if (! $hazard) {
            throw ValidationException::withMessages([
                'assignee_id' => 'Hazard not found.',
            ]);
        }

        if ($assigneeId === null) {
            // The module's assign action requires an assignee — there is no unassign flow.
            throw ValidationException::withMessages([
                'assignee_id' => 'Hazards must be assigned to a staff member.',
            ]);
        }

        // SiteHazardController::assign authorizes view on the hazard's site.
        if ($hazard->site && ! $actor->can('view', $hazard->site)) {
            throw ValidationException::withMessages([
                'assignee_id' => 'You are not authorized to assign hazards for this site.',
            ]);
        }

        // Persist quietly: the SiteHazardObserver would notify the assignee on
        // an observed update, and the queue endpoint sends its own assignment
        // notification — quiet save keeps exactly one ping. assigned_at is the
        // stamp the observer would have set.
        $hazard->forceFill([
            'assigned_to_user_id' => $assigneeId,
            'assigned_at' => now(),
        ])->saveQuietly();

        $assignee = User::query()->find($assigneeId);
        AuditLogger::log('hazard.assigned', $hazard, [
            'assignee_id' => $assigneeId,
            'assignee_name' => $assignee?->name,
        ]);
    }

    public function canView(User $user): bool
    {
        return $user->canDo('hazards.view');
    }

    public function tasks(User $user, array $filters = []): array
    {
        $query = SiteHazard::query()
            ->with(['site:id,name', 'assignedTo:id,name'])
            ->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))
            ->orderByDesc('created_at')
            ->limit(300);

        if (empty($filters['include_done'])) {
            // Mirrors SiteHazard::scopeOpen() — 'mitigated' folds into closed.
            $query->whereIn('status', ['open', 'in_progress']);
        }

        return $query->get()->map(function (SiteHazard $hazard) {
            return new TaskItem(
                id: 'hazard-'.$hazard->id,
                source: $this->sourceKey(),
                sourceLabel: $this->label(),
                ref: $hazard->reference_number,
                title: $hazard->custom_hazard_type
                    ?: ucfirst(str_replace('_', ' ', (string) $hazard->hazard_type)).' hazard',
                status: (string) $hazard->status,
                bucket: match ($hazard->status) {
                    'open' => TaskItem::BUCKET_OPEN,
                    'in_progress' => TaskItem::BUCKET_IN_PROGRESS,
                    default => TaskItem::BUCKET_DONE,
                },
                severity: TaskItem::normaliseSeverity($hazard->risk_rating),
                assignee: $hazard->assignedTo
                    ? ['id' => $hazard->assignedTo->id, 'name' => $hazard->assignedTo->name]
                    : null,
                site: $hazard->site
                    ? ['id' => $hazard->site->id, 'name' => $hazard->site->name]
                    : null,
                dueAt: optional($hazard->due_date)->toIso8601String(),
                createdAt: optional($hazard->created_at)->toIso8601String(),
                link: "/hazards/{$hazard->id}",
                type: 'Hazard',
                description: $hazard->description ? str($hazard->description)->limit(140)->toString() : null,
            );
        })->all();
    }
}
