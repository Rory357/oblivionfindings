<?php

namespace App\Services\Tasks\Providers;

use App\Domain\It\Services\ItKbAccessService;
use App\Domain\It\Services\ItKbRevisionService;
use App\Models\ItKbArticle;
use App\Models\User;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\SiteScopedTaskProvider;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;
use App\Services\Tasks\TaskProviderAuthorization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/** The publication owns its review deadline; reading or editing a proposal cannot clear it. */
final class ItKnowledgeReviewTaskProvider implements HasModelClass, SiteScopedTaskProvider, TaskProvider
{
    public function sourceKey(): string
    {
        return 'it_knowledge_review';
    }

    public function label(): string
    {
        return 'Knowledge reviews';
    }

    public function modelClass(): string
    {
        return ItKbArticle::class;
    }

    public function canView(User $user): bool
    {
        return app(ItKbAccessService::class)->hasKnowledgeCapability($user);
    }

    public function authorizedTasks(User $user, array $filters = []): array
    {
        if (! $this->canView($user) || ! Schema::hasTable('it_kb_articles')) {
            return [];
        }
        $timezone = config('app.worker_timezone', 'Pacific/Auckland');
        $today = Carbon::today($timezone);
        $access = app(ItKbAccessService::class);

        return app(TaskProviderAuthorization::class)->siteScoped($user, true,
            ItKbArticle::query()->select(['id', 'title', 'audience', 'site_scope', 'owner_user_id', 'review_due_at', 'created_at'])
                ->with('owner:id,name')->where('status', 'published')->where('owner_user_id', $user->id)
                ->whereDate('review_due_at', '<=', $today->copy()->addDays(7))
                ->when(isset($filters['id']), fn ($query) => $query->whereKey((int) $filters['id']))
                ->orderBy('review_due_at')->orderBy('id'),
            fn ($query, User $actor) => app(ItKbRevisionService::class)->applyOriginalScope($access->applyViewScope($query, $actor), $actor),
            function (ItKbArticle $article) use ($timezone, $today): TaskItem {
                $overdue = $article->review_due_at->toDateString() < $today->toDateString();

                return new TaskItem(
                    id: 'it_knowledge_review-'.$article->id, source: $this->sourceKey(), sourceLabel: $this->label(),
                    ref: 'KB-'.$article->id, title: 'Review '.$article->title, status: 'review_due',
                    bucket: TaskItem::BUCKET_OPEN, severity: $overdue ? 'medium' : 'low',
                    assignee: ['id' => (int) $article->owner_user_id, 'name' => $article->owner?->name ?? 'Document owner'],
                    dueAt: Carbon::createFromFormat('!Y-m-d', $article->review_due_at->toDateString(), $timezone)->endOfDay()->toIso8601String(),
                    createdAt: $article->created_at?->toIso8601String(),
                    link: '/it/knowledge/'.$article->id.'?section=ownership', type: 'Document review', restricted: true,
                    actionLabel: 'Review document', displayState: $overdue ? 'Review overdue' : 'Review due soon',
                    actionHelp: 'Check the document and submit its next review date for approval. Opening it does not complete the review.',
                );
            });
    }
}
