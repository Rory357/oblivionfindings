<?php

namespace App\Services\Tasks\Providers;

use App\Models\SiteChecklistRun;
use App\Models\User;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;

class SiteChecklistRunProvider implements HasModelClass, TaskProvider
{
    public function sourceKey(): string
    {
        return 'checklist_run';
    }

    public function label(): string
    {
        return 'Checklist Runs';
    }

    public function modelClass(): string
    {
        return SiteChecklistRun::class;
    }

    public function canView(User $user): bool
    {
        // Mirrors routes/sites.php: checklist run views → permission:checklists.view.
        return $user->canDo('checklists.view');
    }

    public function tasks(User $user, array $filters = []): array
    {
        $query = SiteChecklistRun::query()
            ->with(['site:id,name', 'template:id,name', 'assignedTo:id,name'])
            ->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))
            ->orderByDesc('scheduled_date')
            ->limit(300);

        if (empty($filters['include_done'])) {
            // Mirrors SiteChecklistRun::scopeAwaitingCompletion().
            $query->whereIn('status', ['scheduled', 'in_progress', 'overdue']);
        }

        return $query->get()->map(function (SiteChecklistRun $run) {
            return new TaskItem(
                id: 'checklist_run-'.$run->id,
                source: $this->sourceKey(),
                sourceLabel: $this->label(),
                ref: null,
                title: $run->template?->name ?: 'Checklist run',
                status: (string) $run->status,
                bucket: match ($run->status) {
                    'completed', 'skipped' => TaskItem::BUCKET_DONE,
                    'in_progress' => TaskItem::BUCKET_IN_PROGRESS,
                    default => TaskItem::BUCKET_OPEN, // scheduled | overdue
                },
                severity: 'low',
                assignee: $run->assignedTo
                    ? ['id' => $run->assignedTo->id, 'name' => (string) $run->assignedTo->name]
                    : null,
                site: $run->site
                    ? ['id' => $run->site->id, 'name' => (string) $run->site->name]
                    : null,
                dueAt: optional($run->scheduled_date)->toIso8601String(),
                createdAt: optional($run->created_at)->toIso8601String(),
                // Same target sites.checklists.showRun redirects to.
                link: "/sites/{$run->site_id}/checklists?run={$run->id}",
                type: 'Checklist run',
                description: $run->overall_notes ? str($run->overall_notes)->limit(140)->toString() : null,
            );
        })->all();
    }
}
