<?php

namespace App\Http\Controllers\It;

use App\Domain\It\ItStaffDirectory;
use App\Domain\It\Services\ItKbLifecycleService;
use App\Domain\It\Services\ItWorkAccessService;
use App\Http\Controllers\Controller;
use App\Http\Requests\It\KbHelpfulRequest;
use App\Http\Requests\It\StoreKbArticleRequest;
use App\Http\Requests\It\UpdateKbArticleRequest;
use App\Models\ItKbArticle;
use App\Models\ItKbInteraction;
use App\Models\User;
use App\Support\LegacyStorageContext;
use DomainException;
use Illuminate\Http\Request;

/**
 * Knowledge-base authoring (§I). Agents create, edit and publish/unpublish
 * articles; requesters read the published ones (browse/vote lands with 14c).
 * Every write is `it.manage`-gated; Site-specific audiences are bounded by
 * canonical approved-Site assignments.
 */
class ItKbController extends Controller
{
    public function __construct(
        private readonly ItKbLifecycleService $lifecycle,
        private readonly ItWorkAccessService $workAccess,
    ) {}

    public function store(StoreKbArticleRequest $request)
    {
        $user = $request->user();
        $data = $request->validated();
        $this->assertGovernedOptions($user, $data);

        $article = ItKbArticle::query()->create([
            'tenant_id' => LegacyStorageContext::id(),
            'title' => $data['title'],
            'slug' => ItKbArticle::uniqueSlug($data['title']),
            'category' => $data['category'],
            'body' => $data['body'],
            'status' => 'draft',
            'audience' => $data['audience'] ?? 'all_staff',
            'site_scope' => ($data['audience'] ?? 'all_staff') === 'specific_sites'
                ? array_values(array_unique(array_map('intval', $data['site_scope'] ?? [])))
                : null,
            'author_user_id' => $user->id,
            'owner_user_id' => $data['owner_user_id'] ?? $user->id,
            'related_service_id' => $data['related_service_id'] ?? null,
            'review_due_at' => $data['review_due_at'] ?? null,
            'published_at' => null,
            'reviewed_by_user_id' => null,
        ]);

        return redirect()->back()
            ->with('success', "Article “{$article->title}” saved as a draft.")
            ->with('it_kb', ['id' => $article->id, 'slug' => $article->slug]);
    }

    public function update(UpdateKbArticleRequest $request, ItKbArticle $article)
    {
        $user = $request->user();

        // Slug stays stable across edits (a title tweak shouldn't churn a
        // published article's URL); it is only ever generated at create time.
        $data = $request->validated();
        $this->assertGovernedOptions($user, [
            'audience' => $data['audience'] ?? $article->audience,
            'site_scope' => $data['site_scope'] ?? $article->site_scope,
            'owner_user_id' => $data['owner_user_id'] ?? $article->owner_user_id,
        ]);
        if (($data['audience'] ?? $article->audience) !== 'specific_sites') {
            $data['site_scope'] = null;
        }
        try {
            $this->lifecycle->update($article, $user, $data);
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', 'Article updated.');
    }

    public function submitReview(Request $request, ItKbArticle $article)
    {
        return $this->lifecycleAction($request, $article, 'submitForReview', 'Article sent for review.');
    }

    public function publish(Request $request, ItKbArticle $article)
    {
        return $this->lifecycleAction($request, $article, 'publish', 'Article published.');
    }

    public function retire(Request $request, ItKbArticle $article)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->lifecycleAction($request, $article, 'retire', 'Article retired.', $data['reason']);
    }

    public function restore(Request $request, ItKbArticle $article)
    {
        return $this->lifecycleAction($request, $article, 'restore', 'Article restored as a draft.');
    }

    public function destroy(Request $request, ItKbArticle $article)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('it.manage'), 403);

        $article->delete();

        return redirect()->back()->with('success', 'Article deleted.');
    }

    /* ================================================================== */
    /*  Requester-reachable — browse published articles (§I) */
    /* ================================================================== */

    /**
     * Count a read of a published article. Drafts 404 (never leak an
     * unpublished article's existence); inaccessible Site audiences 404 too.
     */
    public function view(Request $request, ItKbArticle $article)
    {
        $this->assertPublishedAndVisible($request, $article);

        $article->increment('view_count');
        $this->recordInteraction($request, $article, 'viewed');

        return redirect()->back();
    }

    /** "Was this helpful?" — tally a yes/no on a published article. */
    public function helpful(KbHelpfulRequest $request, ItKbArticle $article)
    {
        $this->assertPublishedAndVisible($request, $article);

        $helpful = $request->boolean('helpful');
        $article->increment($helpful ? 'helpful_yes' : 'helpful_no');
        if ($helpful) {
            $article->increment('deflection_count');
        }
        $this->recordInteraction($request, $article, $helpful ? 'helpful' : 'not_helpful');

        return redirect()->back()->with('success', 'Thanks — that helps us tune the knowledge base.');
    }

    /** Shared guard for requester-facing reads: published and audience-visible. */
    private function assertPublishedAndVisible(Request $request, ItKbArticle $article): void
    {
        abort_unless($article->status === 'published', 404);
        abort_unless($this->visibleTo($request, $article), 404);
    }

    private function visibleTo(Request $request, ItKbArticle $article): bool
    {
        $user = $request->user();
        if ($article->audience === 'all_staff') {
            return true;
        }
        if ($article->audience === 'it_agents') {
            return (bool) ($user?->canDo('it.view') || $user?->canDo('it.manage'));
        }
        if ($article->audience !== 'specific_sites') {
            return false;
        }
        if (! $user) {
            return false;
        }
        $userSites = $this->workAccess->approvedSiteIds($user);

        return array_intersect(array_map('intval', $article->site_scope ?? []), array_map('intval', $userSites)) !== [];
    }

    private function recordInteraction(Request $request, ItKbArticle $article, string $event): void
    {
        ItKbInteraction::query()->create([
            'tenant_id' => LegacyStorageContext::id(),
            'it_kb_article_id' => $article->id,
            'user_id' => $request->user()?->id,
            'event_type' => $event,
            'source' => 'help_centre',
            'occurred_at' => now(),
        ]);
    }

    private function lifecycleAction(
        Request $request,
        ItKbArticle $article,
        string $method,
        string $success,
        ?string $reason = null,
    ) {
        abort_unless($request->user()?->canDo('it.manage'), 403);
        try {
            $reason === null
                ? $this->lifecycle->{$method}($article, $request->user())
                : $this->lifecycle->{$method}($article, $request->user(), $reason);
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', $success);
    }

    /** @param array<string, mixed> $data */
    private function assertGovernedOptions(User $actor, array $data): void
    {
        $siteIds = ($data['audience'] ?? 'all_staff') === 'specific_sites'
            ? array_values(array_unique(array_map('intval', (array) ($data['site_scope'] ?? []))))
            : [];
        if ($siteIds !== [] && ! $actor->canDo('it.organisationWide')) {
            abort_unless(
                array_diff($siteIds, $this->workAccess->approvedSiteIds($actor)) === [],
                403,
            );
        }

        $ownerId = (int) ($data['owner_user_id'] ?? $actor->id);
        $owner = ItStaffDirectory::agents()->firstWhere('id', $ownerId);
        abort_unless($owner instanceof User, 403);
        if ($siteIds !== [] && ! $owner->canDo('it.organisationWide')) {
            abort_unless(
                array_diff($siteIds, $this->workAccess->approvedSiteIds($owner)) === [],
                403,
            );
        }
    }
}
