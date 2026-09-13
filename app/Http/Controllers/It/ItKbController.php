<?php

namespace App\Http\Controllers\It;

use App\Domain\It\Exceptions\ItKnowledgeRelationshipUnavailable;
use App\Domain\It\Services\ItKbAccessService;
use App\Domain\It\Services\ItKbLifecycleService;
use App\Domain\It\Services\ItKbRevisionService;
use App\Domain\It\Services\ItKnowledgeWorkspace;
use App\Domain\It\Services\ItTicketVersionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\It\DeleteKbArticleRequest;
use App\Http\Requests\It\KbHelpfulRequest;
use App\Http\Requests\It\RetireKbArticleRequest;
use App\Http\Requests\It\StoreKbArticleRequest;
use App\Http\Requests\It\UpdateKbArticleRequest;
use App\Models\ItKbArticle;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Knowledge-base authoring (§I). Agents create, edit and publish/unpublish
 * articles; requesters read the published ones (browse/vote lands with 14c).
 * Authoring and publication have independent grants; Site audiences are bounded by
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
        try {
            $article = $this->lifecycle->create($user, $data);
        } catch (DomainException $exception) {
            if ($exception instanceof ItKnowledgeRelationshipUnavailable) {
                return redirect()->back()->withErrors(['knowledge_relationship_access' => $exception->getMessage()]);
            }

            return redirect()->back()->with('error', $exception->getMessage());
        }

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
            if ($exception instanceof ItKnowledgeRelationshipUnavailable) {
                return redirect()->back()->withErrors(['knowledge_relationship_access' => $exception->getMessage()]);
            }

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
            $retained = $this->lifecycle->deleteDraft(
                $article,
                $request->user(),
                (string) $request->validated('reason'),
                $request->safe()->only(['lock_version']),
            );
        } catch (DomainException $exception) {
            if ($exception instanceof ItKnowledgeRelationshipUnavailable) {
                return redirect()->back()->withErrors(['knowledge_relationship_access' => $exception->getMessage()]);
            }

            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', $retained ? 'Draft archived. Its files and revisions are retained.' : 'Draft deleted.');
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

    public function history(Request $request, ItKbArticle $article)
    {
        $this->assertBrowserActor($request);
        $data = $request->validate(['before_revision' => ['nullable', 'integer', 'min:1']]);
        $payload = DB::transaction(function () use ($request, $article, $data): array {
            $locked = ItKbArticle::query()->whereKey($article->id)->lockForUpdate()->firstOrFail();
            $actor = app(ItTicketVersionService::class)->currentActor($request->user());
            abort_unless(app(ItKbAccessService::class)->canManage($actor, $locked), 404);

            return [
                'actor_user_id' => (int) $actor->id,
                'article_id' => (int) $locked->id,
                'lock_version' => (int) $locked->lock_version,
                ...app(ItKbRevisionService::class)->presentation($locked, $actor, isset($data['before_revision']) ? (int) $data['before_revision'] : null),
            ];
        });

        return response()->json($payload)->header('Cache-Control', 'private, no-store');
    }

    /** Read one current editor independently of library filters or pagination. */
    public function editorContext(Request $request, ItKbArticle $article)
    {
        $request->validate(['actor_user_id' => ['required', 'integer', 'min:1']]);
        $this->assertBrowserActor($request);
        $payload = DB::transaction(function () use ($article, $request): array {
            $locked = ItKbArticle::query()->whereKey($article->id)->lockForUpdate()->firstOrFail();
            $actor = app(ItTicketVersionService::class)->currentActor($request->user());
            abort_unless(app(ItKbAccessService::class)->canAuthor($actor, $locked), 404);
            $revisions = app(ItKbRevisionService::class);
            $copy = $revisions->workingCopy($locked);
            abort_if($copy && ! $revisions->canAccessScope($actor, $copy->audience, $copy->site_scope), 404);
            $workspace = app(ItKnowledgeWorkspace::class);
            $locked->load(['author:id,name', 'owner:id,name', 'service:id,name']);

            return [
                'actor_user_id' => (int) $actor->id,
                'editable' => $locked->status === 'draft' || ($revisions->ready() && $locked->status === 'published' && $copy?->status !== 'in_review'),
                'article' => $workspace->rows(new Collection([$locked]), $actor, true, $revisions->ready())[0],
                'options' => $workspace->authoringOptions($actor),
            ];
        });

        return response()->json($payload)->header('Cache-Control', 'private, no-store');
    }

    public function restoreRevision(Request $request, ItKbArticle $article)
    {
        $this->assertBrowserActor($request);
        abort_unless(app(ItKbAccessService::class)->canAuthor($request->user(), $article), 404);
        $data = $request->validate(['revision_id' => ['required', 'integer', 'min:1'], 'lock_version' => ['required', 'integer', 'min:1']]);
        try {
            $this->lifecycle->restoreRevision($article, $request->user(), $data['revision_id'], $data['lock_version']);
        } catch (DomainException $exception) {
            if ($exception instanceof ItKnowledgeRelationshipUnavailable) {
                return redirect()->back()->withErrors(['knowledge_relationship_access' => $exception->getMessage()]);
            }

            return redirect()->back()->withErrors(['knowledge' => $exception->getMessage()]);
        }

        return redirect()->back()->with('success', 'Revision restored as a draft for review.');
    }

    public function discardRevision(Request $request, ItKbArticle $article)
    {
        $this->assertBrowserActor($request);
        abort_unless(app(ItKbAccessService::class)->canAuthor($request->user(), $article), 404);
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000']]);
        try {
            $this->lifecycle->discardWorkingCopy($article, $request->user(), $data['lock_version'], $data['reason']);
        } catch (DomainException $exception) {
            if ($exception instanceof ItKnowledgeRelationshipUnavailable) {
                return redirect()->back()->withErrors(['knowledge_relationship_access' => $exception->getMessage()]);
            }

            return redirect()->back()->withErrors(['knowledge' => $exception->getMessage()]);
        }

        return redirect()->back()->with('success', 'Proposed revision discarded. The publication is unchanged.');
    }

    /** "Was this helpful?" — tally a yes/no on a published article. */
    public function helpful(KbHelpfulRequest $request, ItKbArticle $article)
    {
        $this->assertBrowserActor($request);
        $helpful = $request->boolean('helpful');
        $recorded = $this->lifecycle->recordHelpful(
            $article, $request->user(), $helpful, $request->boolean('solved'),
            $request->has('lock_version') ? $request->integer('lock_version') : null,
        );

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
        $this->assertBrowserActor($request);
        $capability = in_array($method, ['publish', 'retire'], true)
            ? ItKbAccessService::REVIEW : ItKbAccessService::AUTHOR;
        abort_unless($request->user()?->canDo($capability), 403);
        abort_unless(app(ItKbAccessService::class)->canManage($request->user(), $article), 404);
        $revisions = app(ItKbRevisionService::class);
        $copy = $revisions->workingCopy($article);
        abort_if($copy && ! $revisions->canAccessScope($request->user(), $copy->audience, $copy->site_scope), 404);
        $version = $request->validate([
            'lock_version' => [Rule::requiredIf(fn () => app(ItKbRevisionService::class)->ready()), 'integer', 'min:1'],
        ]);
        try {
            $reason === null
                ? $this->lifecycle->{$method}($article, $request->user(), $version)
                : $this->lifecycle->{$method}($article, $request->user(), $reason, $version);
        } catch (DomainException $exception) {
            if ($exception instanceof ItKnowledgeRelationshipUnavailable) {
                return redirect()->back()->withErrors(['knowledge_relationship_access' => $exception->getMessage()]);
            }

            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', $success);
    }

    private function assertBrowserActor(Request $request): void
    {
        if (! $request->exists('actor_user_id')) {
            return;
        }
        $value = $request->input('actor_user_id');
        abort_unless((is_int($value) || is_string($value))
            && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false
            && (int) $value === (int) $request->user()?->id, 403);
    }
}
