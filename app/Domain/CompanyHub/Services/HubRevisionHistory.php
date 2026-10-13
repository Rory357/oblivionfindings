<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Models\HubRevision;
use App\Models\User;

class HubRevisionHistory
{
    public function __construct(private readonly HubAccess $access) {}

    public function assertView(User $user, HubPage $page, HubRevision $revision): void
    {
        $this->access->assertEdit($user, $page);
        abort_unless((int) $revision->hub_page_id === (int) $page->id && $this->access->canAuthorAudience($user, $revision->content), 404);
    }

    public function listing(User $user, HubPage $page, ?int $before = null): array
    {
        $this->access->assertEdit($user, $page);
        // Filter before pagination so hidden versions expose neither their metadata nor their count.
        $page->loadMissing('currentRelease');
        $visible = $page->revisions()->when($before, fn ($q) => $q->where('id', '<', $before))->orderByDesc('id')->lazy(50)
            ->filter(fn ($revision) => $this->access->canAuthorAudience($user, $revision->content))->take(26)->collect();
        $items = $visible->take(25)->map(fn ($revision) => $this->summary($user, $page, $revision))->values();

        return ['items' => $items->all(), 'next_cursor' => $visible->count() > 25 ? $items->last()['id'] : null];
    }

    public function show(User $user, HubPage $page, HubRevision $revision): array
    {
        $this->assertView($user, $page, $revision);

        return [...$this->summary($user, $page, $revision), 'content' => $revision->content, 'page_version' => $page->draft_version];
    }

    private function summary(User $user, HubPage $page, HubRevision $revision): array
    {
        $current = (int) $revision->draft_version === (int) $page->draft_version;
        $submitted = $revision->state === 'submitted' && $current;
        $canApprove = $submitted && $user->canDo('company_hub.publish')
            && (! ($revision->content['requires_approval'] ?? false) || (int) $revision->created_by !== (int) $user->id);
        $names = User::whereIn('id', array_filter([$revision->created_by, $revision->approved_by, $revision->reviewed_by]))->pluck('name', 'id');

        return [
            ...$revision->only(['id', 'state', 'draft_version', 'created_at', 'publish_at', 'expires_at', 'approved_at', 'reviewed_at', 'review_note']),
            'title' => $revision->content['title'], 'author' => $names[$revision->created_by] ?? 'Former staff member',
            'approved_by_name' => $names[$revision->approved_by] ?? null, 'reviewed_by_name' => $names[$revision->reviewed_by] ?? null,
            'is_current_draft' => $current, 'is_live' => $page->liveRevision()?->id === $revision->id,
            'can_approve' => $canApprove, 'can_return' => $submitted && $user->canDo('company_hub.publish'),
            'can_withdraw' => $submitted && ($user->canDo('company_hub.edit_all') || (int) $page->owner_id === (int) $user->id || (int) $revision->created_by === (int) $user->id),
            'can_cancel_schedule' => $revision->state === 'approved' && $revision->publish_at?->isFuture() && $user->canDo('company_hub.publish'),
        ];
    }
}
