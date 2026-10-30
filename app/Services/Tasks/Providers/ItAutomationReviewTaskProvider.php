<?php

namespace App\Services\Tasks\Providers;

use App\Models\ItReplyTemplate;
use App\Models\User;
use App\Services\Tasks\Contracts\ExplicitlyGlobalTaskProvider;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;
use App\Services\Tasks\TaskProviderAuthorization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * W20 reminders: a reply template past (or within a week of) its review
 * date becomes its owner's task instead of silently going stale.
 *
 * Reply templates carry no Site: IT setup lists every template to it.manage,
 * so that is the explicit global permission and ownership narrows the queue.
 */
final class ItAutomationReviewTaskProvider implements ExplicitlyGlobalTaskProvider, HasModelClass, TaskProvider
{
    public function sourceKey(): string
    {
        return 'it_automation_review';
    }

    public function label(): string
    {
        return 'IT automation reviews';
    }

    public function modelClass(): string
    {
        return ItReplyTemplate::class;
    }

    public function canView(User $user): bool
    {
        return (bool) $user->canDo('it.manage');
    }

    public function globalViewPermissions(): array
    {
        return ['it.manage'];
    }

    public function authorizedTasks(User $user, array $filters = []): array
    {
        if (! $this->canView($user) || ! Schema::hasTable('it_reply_templates')) {
            return [];
        }
        $timezone = config('app.worker_timezone', 'Pacific/Auckland');
        $today = Carbon::today($timezone);

        return app(TaskProviderAuthorization::class)->explicitlyGlobal($user, $this->globalViewPermissions(),
            ItReplyTemplate::query()
                ->select(['id', 'name', 'audience', 'owner_user_id', 'review_due_at', 'created_at'])
                ->with('owner:id,name')
                ->where('is_active', true)
                ->where('owner_user_id', $user->id)
                ->whereNotNull('review_due_at')
                ->whereDate('review_due_at', '<=', $today->copy()->addDays(7))
                ->when(isset($filters['id']), fn ($query) => $query->whereKey((int) $filters['id']))
                ->orderBy('review_due_at')->orderBy('id'),
            function (ItReplyTemplate $template) use ($timezone, $today): TaskItem {
                $overdue = $template->review_due_at->toDateString() < $today->toDateString();

                return new TaskItem(
                    id: 'it_automation_review-'.$template->id, source: $this->sourceKey(), sourceLabel: $this->label(),
                    ref: 'TPL-'.$template->id, title: 'Review reply template "'.$template->name.'"', status: 'review_due',
                    bucket: TaskItem::BUCKET_OPEN, severity: $overdue ? 'medium' : 'low',
                    assignee: ['id' => (int) $template->owner_user_id, 'name' => $template->owner?->name ?? 'Template owner'],
                    dueAt: Carbon::createFromFormat('!Y-m-d', $template->review_due_at->toDateString(), $timezone)->endOfDay()->toIso8601String(),
                    createdAt: $template->created_at?->toIso8601String(),
                    link: '/it/setup?tab=automation', type: 'Template review', restricted: true,
                    actionLabel: 'Review template', displayState: $overdue ? 'Review overdue' : 'Review due soon',
                    actionHelp: 'Check the wording is still correct, then save it with a new review date (or archive it).',
                );
            });
    }
}
