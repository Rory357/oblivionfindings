<?php

namespace App\Http\Controllers\CompanyHub;

use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Models\HubRevision;
use App\Domain\CompanyHub\Services\HubAccess;
use App\Domain\CompanyHub\Services\HubBlocks;
use App\Domain\CompanyHub\Services\HubContent;
use App\Domain\CompanyHub\Services\HubDirectory;
use App\Domain\CompanyHub\Services\HubDraft;
use App\Domain\CompanyHub\Services\HubFeed;
use App\Domain\CompanyHub\Services\HubPageCopy;
use App\Domain\CompanyHub\Services\HubPublishing;
use App\Domain\CompanyHub\Services\HubReadiness;
use App\Domain\CompanyHub\Services\HubRevisionHistory;
use App\Domain\CompanyHub\Services\HubServices;
use App\Domain\CompanyHub\Services\HubTemplates;
use App\Domain\Hr\Services\HrCalendarAccessService;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class HubPageController extends Controller
{
    public function __construct(private readonly HubAccess $access, private readonly HubPublishing $publishing) {}

    public function store(Request $request)
    {
        $data = $request->validate(['kind' => ['required', Rule::in(['home', 'page', 'news', 'department', 'knowledge', 'campaign'])], 'title' => 'required|string|max:160', 'template' => ['nullable', Rule::in(HubTemplates::KEYS)], 'source_page_id' => 'sometimes|integer|min:1', 'source_revision_id' => 'required_with:source_page_id|integer|min:1']);
        abort_if(isset($data['source_page_id']) && $data['kind'] !== 'news', 422, 'Team updates start as news stories.');
        if ($data['kind'] === 'home' && $page = HubPage::where('slug', 'home')->first()) {
            $this->access->assertEdit($request->user(), $page);
        } else {
            $page = $this->publishing->create($request->user(), $data['kind'], $data['title'], $data['template'] ?? 'story', isset($data['source_page_id']) ? ['page_id' => (int) $data['source_page_id'], 'revision_id' => (int) $data['source_revision_id']] : null);
        }

        return redirect('/company-hub/manage/'.$page->id.'/edit');
    }

    public function edit(Request $request, HubPage $page)
    {
        $this->access->assertEdit($request->user(), $page);
        $calendar = app(HrCalendarAccessService::class);
        $history = app(HubRevisionHistory::class)->listing($request->user(), $page);
        $feed = app(HubFeed::class)->forUser($request->user());
        $people = app(HubDirectory::class)->people($request->user());
        $services = collect(app(HubServices::class)->search($request->user(), ''))->map(fn ($service) => [...$service,
            'url' => $service['url'] ?? '/company-hub?view=services&search='.rawurlencode($service['name']),
        ])->all();
        $preview = [...$feed, 'people' => $people, 'searchServices' => $services, 'view' => 'home', 'selectedPage' => null];
        $preview['blockData'] = app(HubBlocks::class)->forUser($request->user(), $page->draft, $feed, $people, includeHidden: true, sourceTeamIds: $page->kind === 'department' ? ($page->draft['team_ids'] ?? []) : null);
        $preview['featuredMedia'] = HubMedia::where('hub_page_id', $page->id)->whereIn('mime', ['image/jpeg', 'image/png', 'image/webp', 'image/gif'])->get(['id', 'alt'])
            ->map(fn ($media) => ['id' => (int) $media->id, 'url' => '/company-hub/media/'.$media->id, 'alt' => $media->alt ?? ''])->all();

        return Inertia::render('company-hub/editor', [
            'page' => $page->only(['id', 'slug', 'kind', 'owner_id', 'draft', 'draft_version', 'archived_at']),
            'draftRecovery' => app(HubDraft::class)->read($request->user(), $page),
            'readiness' => app(HubReadiness::class)->forPage($request->user(), $page),
            'starterLayouts' => HubDraft::starterLayouts($page->kind),
            'capabilities' => $this->access->capabilities($request->user()),
            'revisions' => $history['items'], 'revisionCursor' => $history['next_cursor'],
            'blockTypes' => HubContent::BLOCKS,
            'owners' => $request->user()->canDo('company_hub.edit_all') ? app(HrCurrentStaffService::class)->currentUsersQuery()->orderBy('name')->get()->filter(function ($candidate) use ($page) {
                $copy = clone $page;
                $copy->owner_id = $candidate->id;

                return $this->access->canEdit($candidate, $copy);
            })->map(fn ($person) => ['id' => $person->id, 'name' => $person->name])->values()->all() : [],
            'previewData' => $preview,
            'sites' => $calendar->visibleSitesQuery($request->user())->get(['id', 'name']),
            'departments' => $calendar->visibleDepartmentsQuery($request->user())->get(['id', 'name']),
        ]);
    }

    public function duplicateOptions(Request $request, HubPage $page, HubPageCopy $copies)
    {
        $data = $request->validate(['source' => ['nullable', Rule::in(['draft', 'published'])]]);

        return response()->json($copies->read($request->user(), $page, $data['source'] ?? null))->header('Cache-Control', 'private, no-store');
    }

    public function duplicate(Request $request, HubPage $page, HubPageCopy $copies)
    {
        $data = $request->validate([
            'actor_id' => 'required|integer|min:1', 'version' => 'required|integer|min:1',
            'source' => ['required', Rule::in(['draft', 'published'])],
            'revision_id' => 'present|nullable|integer|min:1|required_if:source,published|prohibited_if:source,draft',
            'media_versions' => 'present|array|max:4000', 'media_versions.*' => 'required|integer|min:1',
            'user_id' => 'prohibited', 'owner_id' => 'prohibited', 'kind' => 'prohibited', 'content' => 'prohibited',
        ]);
        $copy = $copies->duplicate($request->user(), $page, $data);

        return response()->json(['page_id' => (int) $copy->id, 'version' => (int) $copy->draft_version, 'edit_url' => '/company-hub/manage/'.$copy->id.'/edit'], 201)
            ->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, HubPage $page)
    {
        $data = $request->validate(['content' => 'required|array', 'version' => 'required|integer|min:1']);
        $page = $this->publishing->save($request->user(), $page, $data['content'], $data['version']);

        return response()->json(['version' => $page->draft_version, 'saved_at' => $page->updated_at->toIso8601String(),
            'readiness' => app(HubReadiness::class)->forPage($request->user(), $page),
        ]);
    }

    public function history(Request $request, HubPage $page, HubRevisionHistory $history)
    {
        $data = $request->validate(['before' => 'nullable|integer|min:1']);

        return response()->json($history->listing($request->user(), $page, isset($data['before']) ? (int) $data['before'] : null))->header('Cache-Control', 'private, no-store');
    }

    public function revision(Request $request, HubPage $page, HubRevision $revision, HubRevisionHistory $history)
    {
        return response()->json($history->show($request->user(), $page, $revision))->header('Cache-Control', 'private, no-store');
    }

    public function review(Request $request, HubPage $page, HubRevision $revision)
    {
        $data = $request->validate(['action' => ['required', Rule::in(['request_changes', 'withdraw', 'cancel_schedule'])], 'version' => 'required|integer|min:1', 'note' => 'nullable|string|max:2000']);
        $this->publishing->reviewDecision($request->user(), $page, $revision, $data['action'], (int) $data['version'], $data['note'] ?? null);

        return response()->json(['saved' => true]);
    }

    public function submit(Request $request, HubPage $page)
    {
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $this->publishing->submit($request->user(), $page, $data['version']);

        return back()->with('success', 'Submitted for publication.');
    }

    public function publish(Request $request, HubPage $page)
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'publish_at' => 'nullable|date', 'expires_at' => 'nullable|date']);
        $this->publishing->publish($request->user(), $page, $data['version'], $data['publish_at'] ?? null, $data['expires_at'] ?? null);

        return back()->with('success', 'Publication saved.');
    }

    public function approve(Request $request, HubPage $page, HubRevision $revision)
    {
        $data = $request->validate(['publish_at' => 'nullable|date', 'expires_at' => 'nullable|date']);
        $this->publishing->approve($request->user(), $page, $revision, $data['publish_at'] ?? null, $data['expires_at'] ?? null);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['saved' => true]);
        }

        return back()->with('success', 'Revision approved for publication.');
    }

    public function restore(Request $request, HubPage $page, HubRevision $revision)
    {
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $this->publishing->restore($request->user(), $page, $revision, $data['version']);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['saved' => true]);
        }

        return back()->with('success', 'Version restored into your draft.');
    }

    public function archive(Request $request, HubPage $page)
    {
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $this->publishing->archive($request->user(), $page, $data['version']);

        return redirect('/company-hub')->with('success', 'Page archived.');
    }

    public function owner(Request $request, HubPage $page)
    {
        $data = $request->validate(['owner_id' => 'required|integer', 'version' => 'required|integer|min:1']);
        $this->publishing->changeOwner($request->user(), $page, User::findOrFail($data['owner_id']), $data['version']);

        return back()->with('success', 'Page owner updated.');
    }
}
