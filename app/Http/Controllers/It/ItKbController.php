<?php

namespace App\Http\Controllers\It;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Http\Requests\It\KbHelpfulRequest;
use App\Http\Requests\It\StoreKbArticleRequest;
use App\Http\Requests\It\UpdateKbArticleRequest;
use App\Models\ItKbArticle;
use Illuminate\Http\Request;

/**
 * Knowledge-base authoring (§I). Agents create, edit and publish/unpublish
 * articles; requesters read the published ones (browse/vote lands with 14c).
 * Every write is tenant-scoped and `it.manage`-gated (the FormRequests own
 * the authorize; tenancy is asserted here).
 */
class ItKbController extends Controller
{
    use ResolvesHrTenant;

    public function store(StoreKbArticleRequest $request)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $data = $request->validated();

        $article = ItKbArticle::query()->create([
            'tenant_id' => $tenantId,
            'title' => $data['title'],
            'slug' => ItKbArticle::uniqueSlug($tenantId, $data['title']),
            'category' => $data['category'],
            'body' => $data['body'],
            'status' => $data['status'],
            'author_user_id' => $user->id,
        ]);

        return redirect()->back()
            ->with('success', "Article “{$article->title}” ".($article->status === 'published' ? 'published.' : 'saved as a draft.'))
            ->with('it_kb', ['id' => $article->id, 'slug' => $article->slug]);
    }

    public function update(UpdateKbArticleRequest $request, ItKbArticle $article)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, (int) $article->tenant_id);

        // Slug stays stable across edits (a title tweak shouldn't churn a
        // published article's URL); it is only ever generated at create time.
        $article->fill($request->validated());
        $article->save();

        return redirect()->back()->with('success', 'Article updated.');
    }

    public function destroy(Request $request, ItKbArticle $article)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('it.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, (int) $article->tenant_id);

        $article->delete();

        return redirect()->back()->with('success', 'Article deleted.');
    }

    /* ================================================================== */
    /*  Requester-reachable — browse published articles (§I) */
    /* ================================================================== */

    /**
     * Count a read of a published article. Drafts 404 (never leak an
     * unpublished article's existence); cross-tenant 404s the same way.
     */
    public function view(Request $request, ItKbArticle $article)
    {
        $this->assertPublishedInTenant($request, $article);

        $article->increment('view_count');

        return redirect()->back();
    }

    /** "Was this helpful?" — tally a yes/no on a published article. */
    public function helpful(KbHelpfulRequest $request, ItKbArticle $article)
    {
        $this->assertPublishedInTenant($request, $article);

        $article->increment($request->boolean('helpful') ? 'helpful_yes' : 'helpful_no');

        return redirect()->back()->with('success', 'Thanks — that helps us tune the knowledge base.');
    }

    /** Shared guard for the requester-facing reads: same tenant + published. */
    private function assertPublishedInTenant(Request $request, ItKbArticle $article): void
    {
        $tenantId = $this->resolveHrTenantIdForUser($request->user());
        $this->assertHrTenantAccess($tenantId, (int) $article->tenant_id);
        abort_unless($article->status === 'published', 404);
    }
}
