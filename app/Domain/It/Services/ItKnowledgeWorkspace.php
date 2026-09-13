<?php

namespace App\Domain\It\Services;

use App\Models\ItKbArticle;
use App\Models\ItKbWorkingCopy;
use App\Models\ItService;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/** Read the Knowledge workspace independently of ticket and provisioning queues. */
final class ItKnowledgeWorkspace
{
    public function __construct(private readonly ItKbAccessService $access, private readonly ItKbRevisionService $revisions) {}

    public function authoringOptions(User $actor): array
    {
        $canAuthor = $this->access->canAuthorRecords($actor);

        return [
            'organisation_wide' => $actor->canDo('it.organisationWide'),
            'revisions_ready' => $this->revisions->ready(),
            'tags_ready' => Schema::hasColumn('it_kb_articles', 'tags'),
            'document_templates' => app(ItKnowledgeDocumentDefinition::class)->templates(),
            'owners' => $this->access->ownerOptions($actor),
            'sites' => $canAuthor ? Site::query()->when(! $actor->canDo('it.organisationWide'), fn ($query) => $query->whereIn('id', app(ItWorkAccessService::class)->approvedSiteIds($actor)))
                ->where('is_active', true)->where('archived', false)->whereNull('archived_at')->orderBy('name')->get(['id', 'name']) : [],
            'services' => $canAuthor ? ItService::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']) : [],
        ];
    }

    public function forUser(User $actor, Request $request): array
    {
        $agent = $actor->canDo('it.view') || $actor->canDo('it.manage') || $this->access->hasKnowledgeCapability($actor);
        $ready = $this->revisions->ready();
        $tagsReady = Schema::hasColumn('it_kb_articles', 'tags');
        if (! Schema::hasTable('it_kb_articles')) {
            return ['kbArticles' => [], 'kbPublished' => [], 'selectedKbArticle' => null, 'knowledgeSummary' => [], 'knowledgePage' => null];
        }
        $base = $this->access->applyViewScope(ItKbArticle::query(), $actor);
        $awaiting = $this->revisions->applyOriginalScope(clone $base, $actor)
            ->where(function ($query) use ($ready, $actor): void {
                $query->where('status', 'in_review');
                if ($ready) {
                    $query->orWhereHas('workingCopy', fn ($copy) => $this->revisions->applyOriginalScope($copy, $actor)->where('status', 'in_review'));
                }
            });
        if (! $this->access->hasKnowledgeCapability($actor)) {
            $awaiting->whereRaw('1 = 0');
        }
        $summary = [
            'total' => (clone $base)->count(),
            'published' => (clone $base)->where('status', 'published')->count(),
            'in_review' => (clone $base)->where('status', 'in_review')->count(),
            'draft' => (clone $base)->where('status', 'draft')->count(),
            'overdue' => (clone $base)->where('status', 'published')->whereDate('review_due_at', '<', today())->count(),
            'awaiting_review' => (clone $awaiting)->count(),
        ];
        $query = clone $base;
        $context = null;
        if ($request->filled('related_type') && $request->filled('related_id')) {
            $type = (string) $request->query('related_type');
            $id = (int) $request->query('related_id');
            $context = app(ItKnowledgeRelationships::class)->resolve($actor, [['type' => $type, 'id' => $id]])[0] ?? null;
            abort_unless($context && ($ready || $type === 'service'), 404);
            $query->where(function ($query) use ($ready, $type, $id): void {
                if ($ready) {
                    $query->whereJsonContains('related_records', ['type' => $type, 'id' => $id]);
                }
                if ($type === 'service') {
                    $query->orWhere('related_service_id', $id);
                }
            });
        }
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 200);
        if ($search !== '') {
            // Only the currently visible article version is searched. Encrypted
            // proposals and restricted file contents never enter library search.
            $query->where(function ($query) use ($search, $ready, $tagsReady): void {
                $term = '%'.$search.'%';
                $query->where('title', 'like', $term)->orWhere('body', 'like', $term);
                if ($ready) {
                    $query->orWhereRaw("JSON_SEARCH(structured_content, 'one', ?, NULL, '$.*') IS NOT NULL", [$term]);
                }
                if ($tagsReady) {
                    $query->orWhereRaw("JSON_SEARCH(tags, 'one', ?) IS NOT NULL", [$term]);
                }
            });
        }
        if ($request->filled('tag')) {
            $tagsReady
                ? $query->whereJsonContains('tags', trim((string) $request->query('tag')))
                : $query->whereRaw('1 = 0');
        }
        foreach (['category' => ItKbArticle::CATEGORIES, 'status' => ItKbArticle::STATUSES] as $key => $values) {
            if (in_array($request->query($key), $values, true)) {
                $query->where($key, $request->query($key));
            }
        }
        if ($ready && in_array($request->query('document_type'), ItKbArticle::DOCUMENT_TYPES, true)) {
            $query->where('document_type', $request->query('document_type'));
        } elseif (! $ready && $request->filled('document_type') && $request->query('document_type') !== 'guide') {
            $query->whereRaw('1 = 0');
        }
        if ($request->query('review') === 'overdue') {
            $query->where('status', 'published')->whereDate('review_due_at', '<', today());
        } elseif ($request->query('review') === 'awaiting') {
            $query->whereIn('id', $awaiting->select('it_kb_articles.id'));
        }
        if ($request->query('owner') === 'me') {
            $query->where('owner_user_id', $actor->id);
        } elseif ($request->query('owner') === 'unassigned') {
            $query->whereNull('owner_user_id');
        }
        $sort = in_array($request->query('sort'), ['title', 'updated_at', 'review_due_at'], true) ? $request->query('sort') : 'updated_at';
        $direction = $request->query('dir') === 'asc' ? 'asc' : 'desc';
        // The library needs metadata only. Large document/proposal bodies are
        // loaded for the selected reader, editor or revision history instead.
        $columns = ['id', 'title', 'slug', 'category', 'status', 'audience', 'site_scope',
            'author_user_id', 'owner_user_id', 'related_service_id', 'review_due_at',
            'review_started_at', 'published_at', 'retired_at', 'updated_at',
            'view_count', 'helpful_yes', 'helpful_no', 'deflection_count'];
        if ($ready) {
            $columns = [...$columns, 'document_type', 'lock_version'];
        }
        if ($tagsReady) {
            $columns[] = 'tags';
        }
        $page = $query->with(['author:id,name', 'owner:id,name', 'service:id,name'])
            ->orderBy($sort, $direction)->orderBy('id')->paginate(24, $columns)->appends($request->except('article'));
        $rows = $this->rows($page->getCollection(), $actor, $agent, $ready, false);
        // A saved link remains resolvable beyond the current page or filters.
        // An inaccessible link has the same response as a missing article.
        $selected = null;
        if ($request->filled('article')) {
            abort_unless(ctype_digit((string) $request->query('article')), 404);
            $article = (clone $base)->with(['author:id,name', 'owner:id,name', 'service:id,name'])->findOrFail((int) $request->query('article'));
            $selected = $this->rows(new Collection([$article]), $actor, $agent, $ready)[0];
        }

        return [
            'kbArticles' => $agent ? $rows : [],
            'kbPublished' => $agent ? [] : $rows,
            'selectedKbArticle' => $selected,
            'knowledgeSummary' => $summary,
            'knowledgeContext' => $context,
            'knowledgePage' => [
                'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem(),
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'links' => $page->linkCollection()->all(),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function rows(Collection $articles, User $actor, bool $agent, bool $ready, bool $withContent = true): array
    {
        $mediaReady = app(ItKnowledgeMedia::class)->ready();
        $capabilities = $this->access->capabilities($actor, $articles->modelKeys());
        $copies = $ready && $this->access->hasKnowledgeCapability($actor)
            ? ItKbWorkingCopy::query()->whereIn('article_id', $articles->modelKeys())
                ->get($withContent ? ['*'] : ['id', 'article_id', 'status', 'audience', 'site_scope'])->keyBy('article_id') : collect();
        $articles->load(['interactions' => fn ($query) => $query->where('user_id', $actor->id)->whereIn('event_type', ['helpful', 'not_helpful', 'solved'])->oldest('id')]);
        $articles->loadCount(['interactions as confirmed_solved' => fn ($query) => $query->where('event_type', 'solved')]);
        $contents = [];
        foreach ($articles as $article) {
            $contents['article:'.$article->id] = ['related_records' => $withContent ? ($article->related_records ?? []) : []];
            $copy = $copies->get($article->id);
            $can = $capabilities[$article->id] ?? ['author' => false, 'review' => false];
            if (($can['author'] || $can['review']) && $copy && $this->revisions->canAccessScope($actor, $copy->audience, $copy->site_scope)) {
                $contents['copy:'.$article->id] = $withContent ? $this->revisions->normaliseContentDates($copy->snapshot) : [];
            }
        }
        if ($withContent) {
            $contents = app(ItKnowledgeRelationships::class)->contentsFor($actor, $contents);
        }

        return $articles->map(function (ItKbArticle $article) use ($agent, $ready, $capabilities, $copies, $contents, $withContent, $mediaReady): array {
            $can = $capabilities[$article->id] ?? ['author' => false, 'review' => false];
            $manage = $can['author'] || $can['review'];
            $copy = $copies->get($article->id);
            $vote = $article->interactions->first(fn ($interaction) => in_array($interaction->event_type, ['helpful', 'not_helpful'], true))?->event_type;
            $issues = $withContent && $manage
                ? app(ItKnowledgeDocumentDefinition::class)->issues(app(ItKbRevisionService::class)->content($article), today()->toDateString()) : [];
            if ($withContent && $manage) {
                foreach ($contents['article:'.$article->id]['related_records'] as $record) {
                    if ($record['inactive'] ?? false) {
                        $issues[] = ['code' => 'inactive_link', 'field' => $record['type'].':'.$record['id'], 'section' => 'relationships', 'message' => 'Remove or replace the inactive link to '.$record['label'].'.'];
                    }
                }
            }

            return [
                'id' => $article->id, 'title' => $article->title, 'slug' => $article->slug,
                'category' => $article->category, 'status' => $article->status, 'body' => $withContent ? $article->body : null,
                'content_loaded' => $withContent,
                'media_ready' => $mediaReady,
                'diagrams' => $withContent ? ($article->diagrams ?? []) : [],
                'file_ids' => $withContent ? ($article->file_ids ?? []) : [],
                'audience' => $article->audience, 'site_scope' => $agent ? ($article->site_scope ?? []) : [],
                'document_type' => $article->document_type ?? 'guide', 'structured_content' => $withContent ? $article->structured_content : null,
                'tags' => $article->tags ?? [],
                'document_issues' => $issues,
                'related_records' => $contents['article:'.$article->id]['related_records'],
                'lock_version' => (int) ($article->lock_version ?? 1), 'revision_ready' => $ready,
                'can' => [...$can, 'manage' => $manage,
                    'edit' => $can['author'] && ($article->status === 'draft' || ($ready && $article->status === 'published'
                        && (! $copy || (isset($contents['copy:'.$article->id]) && $copy->status === 'draft')))),
                    'retire' => $can['review'] && $article->status === 'published' && ! $copy,
                ],
                'working_copy' => isset($contents['copy:'.$article->id])
                    ? ['status' => $copy->status, 'content' => $contents['copy:'.$article->id]] : null,
                'views' => (int) $article->view_count, 'helpful_yes' => (int) $article->helpful_yes,
                'helpful_no' => (int) $article->helpful_no, 'helpful_percent' => $article->helpfulPercent(),
                'user_vote' => match ($vote) {
                    'helpful' => true, 'not_helpful' => false, default => null
                },
                'user_solved' => $article->interactions->contains('event_type', 'solved'),
                'confirmed_solved' => $manage ? (int) $article->confirmed_solved : null,
                'deflections' => 0, // Compatibility only: historical helpful votes do not prove avoided tickets.
                'author' => $agent ? $article->author?->name : null,
                'owner_user_id' => $agent ? $article->owner_user_id : null, 'owner' => $agent ? $article->owner?->name : null,
                'related_service_id' => $agent ? $article->related_service_id : null, 'related_service' => $article->service?->name,
                'review_due_at' => $article->review_due_at?->toDateString(),
                'review_started_at' => $agent ? $article->review_started_at?->toIso8601String() : null,
                'published_at' => $article->published_at?->toIso8601String(), 'retired_at' => $article->retired_at?->toIso8601String(),
                'updated' => $article->updated_at?->diffForHumans(short: true),
                'review_overdue' => $article->status === 'published' && $article->review_due_at?->startOfDay()->lt(today()),
            ];
        })->values()->all();
    }
}
