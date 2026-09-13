<?php

namespace App\Domain\It\Services;

use App\Models\ItKbArticle;
use App\Models\ItKbRevision;
use App\Models\ItKbWorkingCopy;
use App\Models\ItService;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Revision writes are called inside the existing canonical article transaction and parent lock. */
final class ItKbRevisionService
{
    public const CONTENT_FIELDS = ['title', 'category', 'body', 'audience', 'site_scope', 'owner_user_id', 'related_service_id', 'review_due_at', 'document_type', 'structured_content', 'related_records', 'diagrams', 'file_ids', 'tags'];

    public function ready(): bool
    {
        return Schema::hasTable('it_kb_revisions') && Schema::hasTable('it_kb_working_copies') && Schema::hasColumn('it_kb_articles', 'lock_version');
    }

    public function content(ItKbArticle $article): array
    {
        return $this->normaliseContentDates($article->only(array_filter(self::CONTENT_FIELDS, fn ($field) => array_key_exists($field, $article->getAttributes()))));
    }

    /** Calendar dates retain their recorded day, including older ISO snapshots. */
    public function normaliseContentDates(array $content): array
    {
        $date = $content['review_due_at'] ?? null;
        if ($date instanceof \DateTimeInterface) {
            $content['review_due_at'] = $date->format('Y-m-d');
        } elseif (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2}))?$/D', $date)) {
            $content['review_due_at'] = substr($date, 0, 10);
        }

        return $content;
    }

    public function record(ItKbArticle $article, User $actor, string $event): void
    {
        $this->recordSnapshot($article, $actor, $event, $this->content($article), $article->published_at);
    }

    public function recordSnapshot(ItKbArticle $article, User $actor, string $event, array $content, ?\DateTimeInterface $publishedAt = null): void
    {
        if (! $this->ready()) {
            return;
        }
        $revision = ItKbRevision::query()->create([
            'article_id' => $article->id,
            'revision_number' => (int) ItKbRevision::query()->where('article_id', $article->id)->max('revision_number') + 1,
            'audience' => $content['audience'],
            'site_scope' => $content['site_scope'] ?? null,
            'snapshot' => $content,
            'recorded_by_user_id' => $actor->id,
            'event' => $event,
            'published_at' => $publishedAt,
            'created_at' => now(),
        ]);
        if (! empty($content['file_ids']) && app(ItKnowledgeMedia::class)->ready()) {
            DB::table('it_kb_revision_files')->insert(array_map(fn ($id) => ['revision_id' => $revision->id, 'file_id' => (int) $id], array_unique($content['file_ids'])));
        }
        if ($event === 'published') {
            app(ItKnowledgeResolutionSources::class)->recordPublication($article, $revision);
        }
    }

    public function workingCopy(ItKbArticle $article): ?ItKbWorkingCopy
    {
        return $this->ready() ? ItKbWorkingCopy::query()->where('article_id', $article->id)->first() : null;
    }

    public function canAccessScope(User $actor, string $audience, ?array $sites): bool
    {
        if (! in_array($audience, ItKbArticle::AUDIENCES, true)) {
            return false;
        }

        return $audience !== 'specific_sites' || ($sites && ($actor->canDo('it.organisationWide')
            || array_diff(array_map('intval', $sites), app(ItWorkAccessService::class)->approvedSiteIds($actor)) === []));
    }

    /** Require every original Site, matching canAccessScope before loading content. */
    public function applyOriginalScope(Builder $query, User $actor): Builder
    {
        $sites = app(ItWorkAccessService::class)->approvedSiteIds($actor);
        $allowed = json_encode([...$sites, ...array_map('strval', $sites)], JSON_THROW_ON_ERROR);

        return $query->where(function (Builder $scope) use ($actor, $sites, $allowed): void {
            $scope->whereIn('audience', ['all_staff', 'it_agents']);
            if ($sites !== [] || $actor->canDo('it.organisationWide')) {
                $scope->orWhere(function (Builder $specific) use ($actor, $allowed): void {
                    $specific->where('audience', 'specific_sites')->whereJsonLength('site_scope', '>', 0);
                    if (! $actor->canDo('it.organisationWide')) {
                        $specific->whereRaw('JSON_CONTAINS(?, site_scope)', [$allowed]);
                    }
                });
            }
        });
    }

    /** Only currently eligible article authors/reviewers receive an unpublished proposal. */
    public function presentation(ItKbArticle $article, User $actor, ?int $beforeRevision = null): array
    {
        $access = app(ItKbAccessService::class);
        if (! $this->ready() || ! $access->canManage($actor, $article)) {
            return [];
        }
        $copy = $this->workingCopy($article);
        if ($copy && ! $this->canAccessScope($actor, $copy->audience, $copy->site_scope)) {
            $copy = null;
        }
        // Filter original audience metadata before selecting or decrypting any
        // snapshot. The cursor contains only a revision already visible here.
        $visible = $this->applyOriginalScope(ItKbRevision::query()->where('article_id', $article->id), $actor)
            ->when($beforeRevision !== null, fn ($query) => $query->where('revision_number', '<', $beforeRevision))
            ->latest('revision_number')->limit(9)->get();
        $hasMore = $visible->count() > 8;
        $history = $visible->take(8);
        $visible = $history;
        $contents = ['current' => $this->content($article)];
        if ($copy) {
            $contents['copy'] = $this->normaliseContentDates($copy->snapshot);
        }
        foreach ($history as $revision) {
            $contents['revision:'.$revision->id] = $this->normaliseContentDates($revision->snapshot);
        }
        $contents = app(ItKnowledgeRelationships::class)->contentsFor($actor, $contents);
        $ownerIds = array_filter(array_map(fn (array $content) => (int) ($content['owner_user_id'] ?? 0), $contents));
        $people = User::query()->whereIn('id', [...$ownerIds, ...$history->pluck('recorded_by_user_id')->all()])->pluck('name', 'id');
        $serviceIds = array_filter(array_map(fn (array $content) => (int) ($content['related_service_id'] ?? 0), $contents));
        $services = ItService::query()->whereIn('id', $serviceIds)->pluck('name', 'id');
        $siteIds = collect($contents)->flatMap(fn (array $content) => ($content['audience'] ?? null) === 'specific_sites' ? (array) ($content['site_scope'] ?? []) : [])->map(fn ($id) => (int) $id)->unique();
        $sites = Site::query()->whereIn('id', $siteIds)
            ->when(! $actor->canDo('it.organisationWide'), fn ($query) => $query->whereIn('id', app(ItWorkAccessService::class)->approvedSiteIds($actor)))
            ->pluck('name', 'id');
        foreach ($contents as &$content) {
            $content['metadata'] = [
                'owner' => $people->get((int) ($content['owner_user_id'] ?? 0)),
                'service' => $services->get((int) ($content['related_service_id'] ?? 0)),
                'sites' => ($content['audience'] ?? null) === 'specific_sites'
                    ? array_values(array_filter(array_map(fn ($id) => $sites->get((int) $id), (array) ($content['site_scope'] ?? [])))) : [],
            ];
        }
        unset($content);

        return [
            'current_content' => $contents['current'],
            'working_copy' => $copy ? ['status' => $copy->status, 'lock_version' => $copy->lock_version, 'content' => $contents['copy'], 'submitted_at' => $copy->submitted_at?->toIso8601String()] : null,
            'next_before_revision' => $hasMore ? $visible->last()->revision_number : null,
            'revisions' => $history->map(fn (ItKbRevision $revision): array => [
                'id' => $revision->id, 'number' => $revision->revision_number, 'event' => $revision->event,
                'recorded_at' => $revision->created_at->toIso8601String(), 'published_at' => $revision->published_at?->toIso8601String(),
                'recorded_by' => $people->get((int) $revision->recorded_by_user_id),
                'content' => $contents['revision:'.$revision->id],
            ])->values()->all(),
        ];
    }
}
