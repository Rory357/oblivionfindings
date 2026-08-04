<?php

namespace App\Services\Tasks\Providers;

use App\Domain\Hr\Models\HrCase;
use App\Domain\Hr\Services\HrCaseAccessService;
use App\Models\User;
use App\Services\Tasks\Contracts\AssignableTaskProvider;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;
use App\Services\UserSiteAccessService;
use Illuminate\Validation\ValidationException;

/**
 * HR cases are confidential by default. HrCaseAccessService supplies retained
 * historical Site provenance and the confidential-case predicate. Assignment
 * eligibility remains current staff at an approved Site, and task text never
 * exposes the case title or narrative.
 */
class HrCaseProvider implements AssignableTaskProvider, HasModelClass, TaskProvider
{
    public function sourceKey(): string
    {
        return 'hr_case';
    }

    public function label(): string
    {
        return 'HR Cases';
    }

    public function modelClass(): string
    {
        return HrCase::class;
    }

    public function canAssign(User $user): bool
    {
        // Mirrors HrCaseController::update(): abort_unless hr.cases.manage.
        return $user->canDo('hr.cases.manage');
    }

    public function assign(User $actor, int $id, ?int $assigneeId): void
    {
        // Re-fetch with the same confidentiality scoping used by tasks()
        // applies, so an out-of-scope case reads as "not found".
        $case = app(HrCaseAccessService::class)
            ->applyVisibleCaseScope(HrCase::query(), $actor)
            ->find($id);

        if (! $case) {
            throw ValidationException::withMessages([
                'assignee_id' => 'HR case not found or outside your visibility.',
            ]);
        }

        if ($assigneeId !== null && ! $this->visibleStaffUserIds($actor)->whereKey($assigneeId)->exists()) {
            throw ValidationException::withMessages([
                'assignee_id' => 'The assignee must be current staff at an approved Site.',
            ]);
        }

        $case->update([
            'assigned_to' => $assigneeId,
            'updated_by' => $actor->id,
        ]);
    }

    public function canView(User $user): bool
    {
        return $user->canDo('hr.cases.view');
    }

    public function tasks(User $user, array $filters = []): array
    {
        $query = app(HrCaseAccessService::class)
            ->applyVisibleCaseScope(HrCase::query(), $user)
            ->with('assignedTo:id,name')
            ->orderByDesc('opened_at')
            ->limit(300);

        if (empty($filters['include_done'])) {
            $query->whereNotIn('status', ['resolved', 'closed']);
        }

        return $query->get()->map(function (HrCase $case) {
            return new TaskItem(
                id: 'hr_case-'.$case->id,
                source: $this->sourceKey(),
                sourceLabel: $this->label(),
                ref: $case->case_number,
                title: ucfirst(str_replace('_', ' ', (string) $case->case_type)).' case',
                status: (string) $case->status,
                bucket: match ($case->status) {
                    'resolved', 'closed' => TaskItem::BUCKET_DONE,
                    'under_investigation', 'awaiting_response' => TaskItem::BUCKET_IN_PROGRESS,
                    default => TaskItem::BUCKET_OPEN,
                },
                severity: TaskItem::normaliseSeverity($case->severity),
                assignee: $case->assignedTo
                    ? ['id' => $case->assignedTo->id, 'name' => (string) $case->assignedTo->name]
                    : null,
                dueAt: null,
                createdAt: optional($case->created_at)->toIso8601String(),
                link: "/hr/cases/{$case->id}",
                type: 'HR case',
            );
        })->all();
    }

    protected function visibleStaffUserIds(User $viewer)
    {
        $staff = User::query()->select('users.id');

        return app(UserSiteAccessService::class)->applyStaffScope($staff, $viewer);
    }
}
