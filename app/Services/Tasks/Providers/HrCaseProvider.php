<?php

namespace App\Services\Tasks\Providers;

use App\Domain\Hr\Models\HrCase;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Models\User;
use App\Services\Tasks\Contracts\AssignableTaskProvider;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;
use Illuminate\Validation\ValidationException;

/**
 * HR cases are confidential by default: the query replicates
 * HrCaseController::applyCaseVisibilityScope() (manage-perm bypass, otherwise
 * non-confidential OR creator/reporter/assignee/access-list membership) plus
 * the controller's tenant scoping, and the title/description never expose the
 * case title or narrative — only the humanised case type.
 */
class HrCaseProvider implements TaskProvider, HasModelClass, AssignableTaskProvider
{
    use ResolvesHrTenant;

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
        // Re-fetch under the same tenant + confidentiality scoping tasks()
        // applies, so an out-of-scope case reads as "not found".
        $tenantId = $this->resolveHrTenantIdForUser($actor);

        $case = HrCase::forTenant($tenantId)
            ->tap(fn ($q) => $this->applyCaseVisibilityScope($q, $actor))
            ->find($id);

        if (! $case) {
            throw ValidationException::withMessages([
                'assignee_id' => 'HR case not found or outside your visibility.',
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
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $query = HrCase::forTenant($tenantId)
            ->with('assignedTo:id,name')
            ->tap(fn ($q) => $this->applyCaseVisibilityScope($q, $user))
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

    /**
     * Mirror of HrCaseController::applyCaseVisibilityScope() — keep in sync.
     */
    protected function applyCaseVisibilityScope($query, User $viewer)
    {
        if ($viewer->canDo('hr.cases.manage')) {
            return $query;
        }

        return $query->where(function ($inner) use ($viewer) {
            $inner->where('is_confidential', false)
                ->orWhereNull('is_confidential')
                ->orWhere('created_by', $viewer->id)
                ->orWhere('reported_by', $viewer->id)
                ->orWhere('assigned_to', $viewer->id)
                // access_list entries may be stored as ints or strings.
                ->orWhereJsonContains('access_list', $viewer->id)
                ->orWhereJsonContains('access_list', (string) $viewer->id);
        });
    }
}
