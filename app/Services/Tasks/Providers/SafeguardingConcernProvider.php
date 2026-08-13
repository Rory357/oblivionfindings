<?php

namespace App\Services\Tasks\Providers;

use App\Models\Client;
use App\Models\SafeguardingActionPlan;
use App\Models\SafeguardingConcern;
use App\Models\User;
use App\Policies\SafeguardingConcernPolicy;
use App\Services\Tasks\Contracts\AssignableTaskProvider;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\SiteScopedTaskProvider;
use App\Services\Tasks\Contracts\SplittableTaskProvider;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;
use App\Services\Tasks\TaskProviderAuthorization;
use Illuminate\Validation\ValidationException;

class SafeguardingConcernProvider implements AssignableTaskProvider, HasModelClass, SiteScopedTaskProvider, SplittableTaskProvider, TaskProvider
{
    public function sourceKey(): string
    {
        return 'safeguarding';
    }

    public function label(): string
    {
        return 'Safeguarding Concerns';
    }

    public function modelClass(): string
    {
        return SafeguardingConcern::class;
    }

    public function canAssign(User $user): bool
    {
        // SafeguardingConcernController::assign authorizes 'update' —
        // globally that is the safeguarding.update permission (the policy's
        // per-record assignee branch is re-checked in assign()).
        return $user->canDo('safeguarding.update');
    }

    public function assign(User $actor, int $id, ?int $assigneeId): void
    {
        $concern = app(SafeguardingConcernPolicy::class)
            ->applyVisibleScope(SafeguardingConcern::query(), $actor)
            ->find($id);

        if (! $concern) {
            throw ValidationException::withMessages([
                'assignee_id' => 'Safeguarding concern not found.',
            ]);
        }

        if ($assigneeId === null) {
            // The module's assign action requires an assignee — no unassign flow.
            throw ValidationException::withMessages([
                'assignee_id' => 'Safeguarding concerns must be assigned to a staff member.',
            ]);
        }

        // Mirror of SafeguardingConcernPolicy::update.
        if (! $actor->can('update', $concern)) {
            throw ValidationException::withMessages([
                'assignee_id' => 'You are not authorized to assign this concern.',
            ]);
        }

        // Need-to-know: a sensitive concern is restricted unless the actor has
        // viewSensitive or is already the assignee/reporter — restricted viewers
        // must not be able to (re)allocate it either.
        $restricted = $concern->is_sensitive
            && ! $actor->can('viewSensitive', SafeguardingConcern::class)
            && $concern->assigned_to_user_id !== $actor->id
            && $concern->reported_by_user_id !== $actor->id;

        if ($restricted) {
            throw ValidationException::withMessages([
                'assignee_id' => 'This concern is restricted — you cannot assign it.',
            ]);
        }

        $concern->update([
            'assigned_to_user_id' => $assigneeId,
            'assigned_at' => now(),
            'updated_by' => $actor->id,
        ]);
    }

    public function canView(User $user): bool
    {
        // Mirrors routes/safeguarding.php: the register is gated by safeguarding.viewAny.
        return $user->canDo('safeguarding.viewAny');
    }

    public function authorizedTasks(User $user, array $filters = []): array
    {
        $query = SafeguardingConcern::query()
            ->with(['assignedTo:id,name', 'site:id,name'])
            ->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))
            ->orderByDesc('reported_at')
            ->limit(300);

        if (empty($filters['include_done'])) {
            $query->whereNotIn('status', SafeguardingConcern::TERMINAL_STATUSES);
        }

        $canSensitive = $user->can('viewSensitive', SafeguardingConcern::class);

        return app(TaskProviderAuthorization::class)->siteScoped(
            $user,
            $this->canView($user),
            $query,
            fn ($scoped, User $actor) => app(SafeguardingConcernPolicy::class)
                ->applyVisibleScope($scoped, $actor),
            function (SafeguardingConcern $concern) use ($user, $canSensitive) {
                // Need-to-know parity with SafeguardingConcernController::isConcernRestricted():
                // a sensitive concern is restricted unless the viewer has viewSensitive
                // or is the assignee/reporter. Restricted rows expose no free-text,
                // no subject, and no site (a site name narrows the subject pool).
                $restricted = $concern->is_sensitive
                    && ! $canSensitive
                    && $concern->assigned_to_user_id !== $user->id
                    && $concern->reported_by_user_id !== $user->id;

                return new TaskItem(
                    id: 'safeguarding-'.$concern->id,
                    source: $this->sourceKey(),
                    sourceLabel: $this->label(),
                    ref: $concern->reference_number,
                    title: ucfirst(str_replace('_', ' ', (string) $concern->concern_type)).' concern',
                    status: (string) $concern->status,
                    bucket: match (true) {
                        in_array($concern->status, SafeguardingConcern::TERMINAL_STATUSES, true) => TaskItem::BUCKET_DONE,
                        $concern->status === 'reported' => TaskItem::BUCKET_OPEN,
                        default => TaskItem::BUCKET_IN_PROGRESS,
                    },
                    severity: TaskItem::normaliseSeverity($concern->severity),
                    assignee: $concern->assignedTo
                        ? ['id' => $concern->assignedTo->id, 'name' => $concern->assignedTo->name]
                        : null,
                    client: (! $restricted && $concern->subject_type === Client::class && $concern->subject_id)
                        ? ['id' => (int) $concern->subject_id, 'name' => (string) $concern->subject_name]
                        : null,
                    site: (! $restricted && $concern->site)
                        ? ['id' => $concern->site->id, 'name' => $concern->site->name]
                        : null,
                    dueAt: null,
                    createdAt: optional($concern->created_at)->toIso8601String(),
                    link: "/safeguarding?concern={$concern->id}",
                    type: 'Concern',
                    description: (! $restricted && $concern->description)
                        ? str($concern->description)->limit(140)->toString()
                        : null,
                    restricted: $restricted,
                );
            },
        );
    }

    public function childLabel(): string
    {
        return 'action';
    }

    public function createChild(User $actor, int $id, array $data): ?string
    {
        // Mirror SafeguardingActionPlanController::store()'s write gate. The
        // controller authorizes 'update'; the module's investigate permission
        // is the key that governs opening action plans on a concern.
        if (! $actor->canDo('safeguarding.investigate')) {
            throw ValidationException::withMessages([
                'title' => 'You do not have permission to add an action to this concern.',
            ]);
        }

        $concern = app(SafeguardingConcernPolicy::class)
            ->applyVisibleScope(SafeguardingConcern::query(), $actor)
            ->find($id);

        if (! $concern) {
            throw ValidationException::withMessages([
                'title' => 'Safeguarding concern not found.',
            ]);
        }

        // Re-apply the exact need-to-know redaction this provider enforces
        // (parity with SafeguardingConcernController::isConcernRestricted()):
        // a restricted viewer of a sensitive concern cannot see its subject and
        // therefore must not be able to fork actions off it.
        $restricted = $concern->is_sensitive
            && ! $actor->can('viewSensitive', SafeguardingConcern::class)
            && $concern->assigned_to_user_id !== $actor->id
            && $concern->reported_by_user_id !== $actor->id;

        if ($restricted) {
            throw ValidationException::withMessages([
                'title' => 'This concern is restricted — you cannot split it into an action.',
            ]);
        }

        // Map the cross-cutting split shape onto the action-plan columns exactly
        // as SafeguardingActionPlanController::store() writes them. The module
        // has no separate free-text column beyond action_description, so the
        // queue title (+ description) become the action description.
        $title = trim((string) ($data['title'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $actionDescription = $description !== '' ? $title."\n\n".$description : $title;

        SafeguardingActionPlan::create([
            'safeguarding_concern_id' => $concern->id,
            'action_description' => $actionDescription,
            'assigned_to_user_id' => $data['assignee_id'] ?? null,
            'due_date' => $data['due_at'] ?? null,
            'status' => 'pending',
            // priority is an int column; the module defaults null → 3 on create.
            'priority' => 3,
            'created_by' => $actor->id,
        ]);

        // No module notification — the /tasks split controller sends the
        // assignment FYI, and the module's own store() fires nothing anyway.

        return "/safeguarding?concern={$concern->id}";
    }
}
