<?php

namespace App\Domain\It\Services;

use App\Domain\It\ItStaffDirectory;
use App\Models\ItKbArticle;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** One current audience boundary for the existing knowledge catalogue. */
final class ItKbAccessService
{
    public const AUTHOR = 'it.knowledge.author';

    public const REVIEW = 'it.knowledge.review';

    public function __construct(private readonly ItWorkAccessService $workAccess) {}

    public function canAuthorRecords(User $actor): bool
    {
        return $actor->approved_at !== null && $actor->canDo(self::AUTHOR);
    }

    public function canReviewRecords(User $actor): bool
    {
        return $actor->approved_at !== null && $actor->canDo(self::REVIEW);
    }

    public function hasKnowledgeCapability(User $actor): bool
    {
        return $this->canAuthorRecords($actor) || $this->canReviewRecords($actor);
    }

    /** @param Builder<ItKbArticle> $query @return Builder<ItKbArticle> */
    public function applyViewScope(Builder $query, User $actor, bool $publishedOnly = false): Builder
    {
        $isAgent = $actor->canDo('it.view') || $actor->canDo('it.manage') || $this->hasKnowledgeCapability($actor);
        if ($actor->approved_at === null || (! $isAgent && ! $actor->canDo('it.request'))) {
            return $query->whereRaw('1 = 0');
        }

        if ($publishedOnly || ! $isAgent) {
            $query->published();
        }

        $siteIds = $this->workAccess->approvedSiteIds($actor);
        $canGovernAllSites = $isAgent && $actor->canDo('it.organisationWide');

        return $query->where(function (Builder $audience) use ($isAgent, $siteIds, $canGovernAllSites): void {
            $audience->where('audience', 'all_staff');
            if ($isAgent) {
                $audience->orWhere('audience', 'it_agents');
            }
            if ($siteIds !== [] || $canGovernAllSites) {
                $audience->orWhere(function (Builder $sites) use ($siteIds, $canGovernAllSites): void {
                    $sites->where('audience', 'specific_sites')
                        ->whereJsonLength('site_scope', '>', 0);
                    if (! $canGovernAllSites) {
                        $sites->where(function (Builder $approved) use ($siteIds): void {
                            foreach ($siteIds as $siteId) {
                                $approved->orWhereJsonContains('site_scope', $siteId)
                                    ->orWhereJsonContains('site_scope', (string) $siteId);
                            }
                        });
                    }
                });
            }
        });
    }

    public function canReadPublished(User $actor, ItKbArticle $article): bool
    {
        return $this->applyViewScope(ItKbArticle::query(), $actor, publishedOnly: true)
            ->whereKey($article->getKey())
            ->exists();
    }

    public function canManage(User $actor, ItKbArticle $article): bool
    {
        return $this->manageCapabilities($actor, [$article->getKey()])[$article->getKey()] ?? false;
    }

    public function canAuthor(User $actor, ItKbArticle $article): bool
    {
        return $this->canAuthorRecords($actor) && $this->canManage($actor, $article);
    }

    public function canReview(User $actor, ItKbArticle $article): bool
    {
        return $this->canReviewRecords($actor) && $this->canManage($actor, $article);
    }

    /** @param list<int> $articleIds @return array<int, array{author: bool, review: bool}> */
    public function capabilities(User $actor, array $articleIds): array
    {
        $author = $this->canAuthorRecords($actor);
        $review = $this->canReviewRecords($actor);

        return collect($this->manageCapabilities($actor, $articleIds))
            ->map(fn (bool $withinScope): array => ['author' => $author && $withinScope, 'review' => $review && $withinScope])
            ->all();
    }

    /** @param list<int> $articleIds @return array<int, bool> */
    public function manageCapabilities(User $actor, array $articleIds): array
    {
        if (! $this->hasKnowledgeCapability($actor)) {
            return array_fill_keys($articleIds, false);
        }
        $approvedSiteIds = $this->workAccess->approvedSiteIds($actor);
        $canGovernAllSites = $actor->canDo('it.organisationWide');

        // Reload canonical audiences once for a list projection, instead of
        // repeating article and approved-Site queries for every visible row.
        return ItKbArticle::query()->whereKey($articleIds)->get(['id', 'audience', 'site_scope'])
            ->mapWithKeys(function (ItKbArticle $article) use ($approvedSiteIds, $canGovernAllSites): array {
                $siteIds = array_map('intval', $article->site_scope ?? []);
                $canManage = in_array($article->audience, ['all_staff', 'it_agents'], true)
                    || ($article->audience === 'specific_sites' && $siteIds !== []
                        && ($canGovernAllSites || array_diff($siteIds, $approvedSiteIds) === []));

                return [$article->id => $canManage];
            })->all();
    }

    /** @return Collection<int, User> */
    public function owners(): Collection
    {
        return ItStaffDirectory::holdingPermission(self::AUTHOR)
            ->merge(ItStaffDirectory::holdingPermission(self::REVIEW))->unique('id')->values();
    }

    /** @return list<array{id: int, name: string}> */
    public function ownerOptions(User $actor): array
    {
        if (! $this->hasKnowledgeCapability($actor)) {
            return [];
        }
        $siteIds = $this->workAccess->approvedSiteIds($actor);

        return $this->owners()->filter(fn (User $owner): bool => $owner->id === $actor->id
            || $actor->canDo('it.organisationWide')
            || array_intersect($siteIds, $this->workAccess->approvedSiteIds($owner)) !== [])
            ->map(fn (User $owner): array => ['id' => (int) $owner->id, 'name' => $owner->name])
            ->values()->all();
    }
}
