<?php

namespace App\Services\Tasks\Providers;

use App\Models\Client;
use App\Models\SafeguardingConcern;
use App\Models\User;
use App\Services\Tasks\Contracts\AssignableTaskProvider;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;
use Illuminate\Validation\ValidationException;

class SafeguardingConcernProvider implements TaskProvider, HasModelClass, AssignableTaskProvider
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
        $concern = SafeguardingConcern::query()->find($id);

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

    public function tasks(User $user, array $filters = []): array
    {
        $query = SafeguardingConcern::query()
            ->with(['assignedTo:id,name', 'site:id,name'])
            ->orderByDesc('reported_at')
            ->limit(300);

        if (empty($filters['include_done'])) {
            $query->whereNotIn('status', SafeguardingConcern::TERMINAL_STATUSES);
        }

        $canSensitive = $user->can('viewSensitive', SafeguardingConcern::class);

        return $query->get()->map(function (SafeguardingConcern $concern) use ($user, $canSensitive) {
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
            );
        })->all();
    }
}
