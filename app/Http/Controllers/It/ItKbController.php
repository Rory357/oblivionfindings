<?php

namespace App\Http\Controllers\It;

use App\Domain\It\Services\ItKbLifecycleService;
use App\Http\Controllers\Controller;
use App\Http\Requests\It\DeleteKbArticleRequest;
use App\Http\Requests\It\KbHelpfulRequest;
use App\Http\Requests\It\RetireKbArticleRequest;
use App\Http\Requests\It\StoreKbArticleRequest;
use App\Http\Requests\It\UpdateKbArticleRequest;
use App\Models\ItKbArticle;
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
    ) {}

    public function store(StoreKbArticleRequest $request)
    {
        $user = $request->user();
        $data = $request->validated();
        $article = $this->lifecycle->create($user, $data);

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

    public function retire(RetireKbArticleRequest $request, ItKbArticle $article)
    {
        return $this->lifecycleAction(
            $request,
            $article,
            'retire',
            'Article retired.',
            (string) $request->validated('reason'),
        );
    }

    public function restore(Request $request, ItKbArticle $article)
    {
        return $this->lifecycleAction($request, $article, 'restore', 'Article restored as a draft.');
    }

    public function destroy(DeleteKbArticleRequest $request, ItKbArticle $article)
    {
        try {
            $this->lifecycle->deleteDraft(
                $article,
                $request->user(),
                (string) $request->validated('reason'),
            );
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', 'Draft deleted.');
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
        $this->lifecycle->recordView($article, $request->user());

        return redirect()->back();
    }

    /** "Was this helpful?" — tally a yes/no on a published article. */
    public function helpful(KbHelpfulRequest $request, ItKbArticle $article)
    {
        $helpful = $request->boolean('helpful');
        $recorded = $this->lifecycle->recordHelpful($article, $request->user(), $helpful);

        return redirect()->back()->with(
            'success',
            $recorded
                ? 'Thanks — that helps us tune the knowledge base.'
                : 'Your feedback was already recorded.',
        );
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
}
