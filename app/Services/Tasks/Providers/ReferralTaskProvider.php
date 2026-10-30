<?php

namespace App\Services\Tasks\Providers;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Domain\Referrals\Services\ReferralWorkQueue;
use App\Models\User;
use App\Services\Tasks\Contracts\ProvidesTaskSourceAliases;
use App\Services\Tasks\Contracts\SiteScopedTaskProvider;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;
use App\Services\Tasks\TaskProviderAuthorization;

final class ReferralTaskProvider implements ProvidesTaskSourceAliases, SiteScopedTaskProvider, TaskProvider
{
    public function sourceKey(): string
    {
        return 'referrals';
    }

    public function label(): string
    {
        return 'Referrals';
    }

    public function sourceAliases(): array
    {
        return ['referral_work', 'referral_next'];
    }

    public function legacySourceAliasForId(User $user, int $id): ?string
    {
        return null;
    } // New source: never guess between colliding model IDs.

    public function canView(User $user): bool
    {
        return app(ReferralWorkQueue::class)->canView($user);
    }

    public function authorizedTasks(User $user, array $filters = []): array
    {
        $queue = app(ReferralWorkQueue::class);
        $boundary = app(TaskProviderAuthorization::class);
        $work = ReferralWorkItem::query()->with(['owner:id,name', 'referral.site:id,name'])
            ->when(isset($filters['id']), fn ($q) => $q->whereKey($filters['id']))
            ->when(empty($filters['include_done']), fn ($q) => $q->where('status', 'open')->whereHas('referral', fn ($r) => $r->where('stage', '!=', 'closed')));
        $items = $boundary->siteScoped($user, $this->canView($user), $work,
            fn ($q, User $actor) => $q->whereIn('id', $queue->work($actor)->select('care_referral_work_items.id')),
            fn ($item) => $this->project('referral_work-'.$item->id, $item->details['title'] ?? 'Referral follow-up', $item->status,
                $item->owner, $item->referral, $item->due_at, $item->created_at, '/operations/referrals/'.$item->referral->reference.'?work='.$item->id,
                $item->audience !== 'intake'));
        $next = $boundary->siteScoped($user, $this->canView($user), CareReferral::query()->with(['owner:id,name', 'site:id,name'])->when(isset($filters['id']), fn ($q) => $q->whereKey($filters['id'])),
            fn ($q, User $actor) => $q->whereIn('id', $queue->nextActions($actor)->select('care_referrals.id')),
            fn ($referral) => $this->project('referral_next-'.$referral->id, $referral->next_action, 'open', $referral->owner, $referral,
                $referral->next_due_at, $referral->created_at, '/operations/referrals/'.$referral->reference.'?tab=work', false));

        return [...$items, ...$next];
    }

    private function project($id, $title, $status, $owner, $referral, $due, $created, $link, bool $private): TaskItem
    {
        return new TaskItem(id: $id, source: $this->sourceKey(), sourceLabel: $this->label(), ref: $referral->reference,
            title: $title, status: $status, bucket: $status === 'open' ? TaskItem::BUCKET_OPEN : TaskItem::BUCKET_DONE,
            severity: TaskItem::normaliseSeverity($referral->priority), assignee: $owner ? ['id' => $owner->id, 'name' => $owner->name] : null,
            site: $referral->site ? ['id' => $referral->site->id, 'name' => $referral->site->name] : null,
            dueAt: $due->toISOString(), createdAt: $created?->toISOString(), link: $link,
            type: 'Referral follow-up', restricted: $private || $referral->restricted, actionLabel: 'Open referral',
            actionHelp: 'Review safe contact and record changes in the referral workflow.');
    }
}
