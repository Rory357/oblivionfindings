<?php

namespace App\Http\Controllers\It;

use App\Domain\It\Services\ItKbAccessService;
use App\Domain\It\Services\ItKbRevisionService;
use App\Domain\It\Services\ItKnowledgeFiles;
use App\Domain\It\Services\ItKnowledgeNavigation;
use App\Domain\It\Services\ItKnowledgePublishedRevisions;
use App\Domain\It\Services\ItKnowledgeRelationships;
use App\Domain\It\Services\ItKnowledgeResolutionSources;
use App\Domain\It\Services\ItKnowledgeWorkspace;
use App\Http\Controllers\Controller;
use App\Models\ItKbArticle;
use App\Models\ItTicket;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class ItKnowledgeWorkspaceController extends Controller
{
    public function show(Request $request, ItKbArticle $article, ItKnowledgeWorkspace $workspace, ItKbAccessService $access)
    {
        $actor = $request->user();
        $article = $access->applyViewScope(ItKbArticle::query(), $actor)
            ->with(['author:id,name', 'owner:id,name', 'service:id,name'])->findOrFail($article->id);
        $agent = $actor->canDo('it.view') || $actor->canDo('it.manage') || $access->hasKnowledgeCapability($actor);
        $navigation = app(ItKnowledgeNavigation::class);
        $returnHref = $navigation->libraryHref($navigation->context($request));
        Inertia::encryptHistory();
        $request->validate(['revision' => ['nullable', 'integer', 'min:1']]);
        if ($request->filled('revision')) {
            $revision = app(ItKnowledgePublishedRevisions::class)->find($actor, $article, $request->integer('revision'));
            $historical = clone $article;
            $historical->fill(app(ItKbRevisionService::class)->normaliseContentDates($revision->snapshot));
            $historical->published_at = $revision->published_at;
            $historical->unsetRelation('owner')->load('owner:id,name');
            $row = $workspace->rows(new Collection([$historical]), $actor, $agent, true)[0];
            $row['can'] = ['author' => false, 'review' => false, 'manage' => false, 'edit' => false];
            $row['working_copy'] = null;
            $row['document_issues'] = [];

            return Inertia::render('it/knowledge/show', [
                'article' => $row, 'options' => $workspace->authoringOptions($actor),
                'files' => app(ItKnowledgePublishedRevisions::class)->files($actor, $article, $revision),
                'returnHref' => $returnHref,
                'viewingRevision' => ['id' => $revision->id, 'number' => $revision->revision_number, 'published_at' => $revision->published_at->toIso8601String()],
                'resolutionSources' => app(ItKnowledgeResolutionSources::class)->forArticle($actor, $article),
            ])->toResponse($request)->header('Cache-Control', 'private, no-store');
        }

        return Inertia::render('it/knowledge/show', [
            'article' => $workspace->rows(new Collection([$article]), $actor, $agent, app(ItKbRevisionService::class)->ready())[0],
            'options' => $workspace->authoringOptions($actor),
            'files' => app(ItKnowledgeFiles::class)->presentation($actor, $article),
            'fileHistory' => app(ItKnowledgeFiles::class)->history($actor, $article),
            'unfinishedUploads' => app(ItKnowledgeFiles::class)->unfinished($actor, $article),
            'returnHref' => $returnHref,
            'resolutionSources' => app(ItKnowledgeResolutionSources::class)->forArticle($actor, $article),
        ])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function fromResolution(Request $request, ItTicket $ticket, ItKnowledgeResolutionSources $sources, ItKnowledgeWorkspace $workspace)
    {
        $actor = $request->user();
        try {
            $draft = $sources->prepare($actor, $ticket);
        } catch (\DomainException $exception) {
            abort(409, $exception->getMessage());
        }
        Inertia::encryptHistory();

        return Inertia::render('it/knowledge/from-resolution', ['draft' => $draft, 'options' => $workspace->authoringOptions($actor), 'sourceActorId' => (int) $actor->id])
            ->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function resolutionDocuments(Request $request, ItTicket $ticket, ItKnowledgeResolutionSources $sources)
    {
        return response()->json($sources->forTicket($request->user(), $ticket))->header('Cache-Control', 'private, no-store');
    }

    public function __invoke(Request $request, ItKnowledgeWorkspace $workspace, ItKbAccessService $access)
    {
        if ($request->filled('article')) {
            abort_unless(ctype_digit((string) $request->query('article')), 404);
            $article = $access->applyViewScope(ItKbArticle::query(), $request->user())->findOrFail((int) $request->query('article'));
            $context = $request->except(['article', 'tab']);

            return redirect('/it/knowledge/'.$article->id.($context ? '?'.http_build_query(['library' => http_build_query($context)]) : ''));
        }
        $actor = $request->user();
        $agent = $actor->canDo('it.view') || $actor->canDo('it.manage');
        abort_unless($actor->approved_at && ($agent || $actor->canDo('it.request') || $access->hasKnowledgeCapability($actor)), 403);
        $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', 'string', 'max:20'],
            'document_type' => ['nullable', 'string', 'max:32'],
            'tag' => ['nullable', 'string', 'max:40'],
            'review' => ['nullable', 'string', 'max:20'],
            'owner' => ['nullable', 'string', 'max:20'],
            'sort' => ['nullable', 'string', 'max:30'],
            'dir' => ['nullable', 'string', 'max:4'],
            'page' => ['nullable', 'integer', 'min:1'],
            'related_type' => ['nullable', 'required_with:related_id', Rule::in(ItKnowledgeRelationships::TYPES)],
            'related_id' => ['nullable', 'required_with:related_type', 'integer', 'min:1'],
        ]);
        $canAuthor = $access->canAuthorRecords($actor);

        return Inertia::render('it/knowledge/index', [
            ...$workspace->forUser($actor, $request),
            'kbOptions' => $workspace->authoringOptions($actor),
            'myTickets' => [], 'catalogItems' => [], 'summary' => null,
            'can' => [
                'view' => $actor->canDo('it.view'), 'manage' => $actor->canDo('it.manage'), 'request' => $actor->canDo('it.request'),
                'knowledge_author' => $canAuthor, 'knowledge_review' => $access->canReviewRecords($actor),
            ],
        ]);
    }

    public function recordOptions(Request $request, ItKnowledgeRelationships $relationships, ItKbAccessService $access)
    {
        $actor = $request->user();
        abort_unless($actor->approved_at && ($actor->canDo('it.view') || $actor->canDo('it.manage') || $actor->canDo('it.request') || $access->hasKnowledgeCapability($actor)), 403);
        $data = $request->validate([
            'type' => ['required', Rule::in(ItKnowledgeRelationships::TYPES)],
            'q' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'article_id' => ['nullable', 'integer', 'min:1'],
        ]);
        if (isset($data['article_id'])) {
            abort_unless($access->applyViewScope(ItKbArticle::query(), $actor)->whereKey($data['article_id'])->exists(), 404);
        }

        return response()->json($relationships->options($actor, $data['type'], trim($data['q'] ?? ''), (int) ($data['page'] ?? 1), isset($data['article_id']) ? (int) $data['article_id'] : null))
            ->header('Cache-Control', 'private, no-store');
    }
}
