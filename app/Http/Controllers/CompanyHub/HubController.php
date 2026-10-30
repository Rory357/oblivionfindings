<?php

namespace App\Http\Controllers\CompanyHub;

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Services\HubAccess;
use App\Domain\CompanyHub\Services\HubBlocks;
use App\Domain\CompanyHub\Services\HubCampaign;
use App\Domain\CompanyHub\Services\HubCommunity;
use App\Domain\CompanyHub\Services\HubContent;
use App\Domain\CompanyHub\Services\HubDepartment;
use App\Domain\CompanyHub\Services\HubDirectory;
use App\Domain\CompanyHub\Services\HubDraft;
use App\Domain\CompanyHub\Services\HubEditorial;
use App\Domain\CompanyHub\Services\HubFeatured;
use App\Domain\CompanyHub\Services\HubFeed;
use App\Domain\CompanyHub\Services\HubIdeas;
use App\Domain\CompanyHub\Services\HubKnowledge;
use App\Domain\CompanyHub\Services\HubParticipation;
use App\Domain\CompanyHub\Services\HubRecognition;
use App\Domain\CompanyHub\Services\HubResourceReports;
use App\Domain\CompanyHub\Services\HubSearchInsights;
use App\Domain\CompanyHub\Services\HubServices;
use App\Domain\CompanyHub\Services\HubShowcase;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class HubController extends Controller
{
    public function showcase(Request $request, HubAccess $access)
    {
        $access->assertEnter($request->user());
        abort_unless($request->user()->canDo('company_hub.layout'), 403);
        $data = $request->validate(['story' => 'nullable|string|max:100', 'search' => 'nullable|string|max:150', 'view' => ['nullable', Rule::in(['home', 'news', 'shoutouts', 'events', 'resources', 'guides', 'pages', 'people', 'ideas', 'teams', 'campaigns'])]]);
        if (($data['view'] ?? '') === 'people') {
            return redirect()->route('company-hub.index', ['view' => 'people']);
        }
        $showcase = HubShowcase::data($data['story'] ?? null, (int) $request->user()->getAuthIdentifier());
        $search = trim($data['search'] ?? '');
        if ($search !== '') {
            $showcase['pages'] = collect($showcase['pages'])->filter(fn ($page) => str_contains(mb_strtolower($page['title'].' '.$page['summary']), mb_strtolower($search)))->values()->all();
        }

        return Inertia::render('company-hub/index', [...$showcase, 'search' => $search, 'view' => $data['view'] ?? 'home']);
    }

    public function showcaseEditor(Request $request, HubAccess $access)
    {
        $access->assertEnter($request->user());
        abort_unless($request->user()->canDo('company_hub.layout'), 403);
        $data = $request->validate(['story' => ['nullable', Rule::in(['sample-campaign'])]]);
        $showcase = HubShowcase::data($data['story'] ?? null);
        $selected = $showcase['selectedPage'];
        $kind = $selected['kind'] ?? 'home';

        return Inertia::render('company-hub/editor', [
            'demo' => true,
            'page' => ['id' => $selected['id'] ?? 0, 'slug' => $selected['slug'] ?? 'showcase', 'kind' => $kind, 'owner_id' => 0, 'draft' => $selected['content'] ?? $showcase['home'], 'draft_version' => 1, 'archived_at' => null],
            'capabilities' => ['layout' => true, 'write' => true, 'publish' => true, 'edit_all' => false],
            'revisions' => [], 'owners' => [], 'sites' => [], 'departments' => [], 'previewData' => $showcase,
            'starterLayouts' => HubDraft::starterLayouts($kind),
        ]);
    }

    public function index(Request $request, HubFeed $feed)
    {
        $data = $request->validate([
            'announcement' => 'nullable|integer|min:1',
            'idea' => 'nullable|integer|min:1|max:2147483647',
            'recognition' => 'nullable|integer|min:1|max:2147483647',
            'search' => 'nullable|string|max:150',
            'view' => ['nullable', Rule::in(['home', 'news', 'shoutouts', 'events', 'resources', 'guides', 'pages', 'saved', 'people', 'teams', 'campaigns', 'ideas', 'following', 'updates', 'services', 'publishing'])],
        ]);

        $search = trim($data['search'] ?? '');
        $globalSearch = $search !== '' && ($data['view'] ?? 'home') === 'home';
        $ideaOptions = ($data['view'] ?? '') === 'ideas' ? app(HubCommunity::class)->ideaOptions($request->user()) : ['ready' => false, 'actor_id' => null, 'teams' => [], 'owners' => []];
        $requestedIdea = ($data['view'] ?? '') === 'ideas' && isset($data['idea']);
        $selectedIdea = $requestedIdea ? app(HubIdeas::class)->selected($request->user(), (int) $data['idea']) : null;
        $requestedRecognition = ($data['view'] ?? '') === 'shoutouts' && isset($data['recognition']);
        $selectedRecognition = $requestedRecognition ? app(HubRecognition::class)->selected($request->user(), (int) $data['recognition']) : null;
        $ideaBrowse = $globalSearch || ($data['view'] ?? '') === 'ideas' ? app(HubIdeas::class)->browse($request->user(), ['search' => $search]) : null;

        $payload = [
            ...$feed->forUser($request->user(), $search),
            'view' => $data['view'] ?? 'home', 'selectedPage' => null,
            'announcementId' => isset($data['announcement']) ? (int) $data['announcement'] : null,
            'people' => $globalSearch || ($data['view'] ?? '') === 'people' ? app(HubDirectory::class)->people($request->user(), $search) : [],
            'canViewOrg' => $request->user()->canDo('hr.orgchart.view') || $request->user()->canDo('hr.employees.viewAny'),
            'ideas' => $ideaBrowse['data'] ?? [], 'ideaBrowse' => $ideaBrowse,
            'ideaProgressReady' => $ideaOptions['ready'], 'ideaActorId' => $ideaOptions['actor_id'], 'ideaTeams' => $ideaOptions['teams'], 'ideaOwners' => $ideaOptions['owners'],
            'selectedIdea' => $selectedIdea, 'ideaId' => $selectedIdea['id'] ?? null, 'ideaUnavailable' => $requestedIdea && $selectedIdea === null,
            'selectedRecognition' => $selectedRecognition, 'recognitionId' => $selectedRecognition['id'] ?? null,
            'recognitionUnavailable' => $requestedRecognition && $selectedRecognition === null,
            'searchServices' => $globalSearch ? app(HubServices::class)->search($request->user(), $search) : [],
            'services' => ($data['view'] ?? '') === 'services' ? app(HubServices::class)->forUser($request->user()) : null,
            'editorial' => ($data['view'] ?? '') === 'publishing' ? app(HubEditorial::class)->workspace($request->user()) : [],
        ];
        if ($globalSearch) {
            $matched = collect(['pages', 'resourceItems', 'people', 'ideas', 'searchServices', 'events', 'kudos', 'announcements'])
                ->contains(fn ($key) => count($payload[$key] ?? []) > 0);
            app(HubSearchInsights::class)->record($request->user(), $search, $matched);
        }

        return Inertia::render('company-hub/index', $payload);
    }

    public function show(Request $request, string $slug, HubAccess $access, HubFeed $feed)
    {
        $page = HubPage::where('slug', $slug)->firstOrFail();
        $revision = $access->assertRead($request->user(), $page);
        // All reader components use the same authorised snapshot for this response.
        $page->setRelation('currentRelease', $revision);
        $data = $feed->forUser($request->user());
        $resourceSummary = collect($data['pages'])->firstWhere('id', $page->id);
        $collectionFields = in_array($page->kind, ['page', 'knowledge'], true)
            ? ['collection_id' => $resourceSummary['collection_id'] ?? null, 'collection' => $resourceSummary['collection'] ?? null] : [];
        $hasPeople = HubContent::visibleBlocks($revision->content)->contains('type', 'people');
        $people = $hasPeople || in_array($page->kind, ['department', 'campaign'], true) || HubKnowledge::supports($page, $revision->content) ? app(HubDirectory::class)->people($request->user()) : [];
        $campaign = app(HubCampaign::class)->read($request->user(), $page, $data, $people);
        $blockData = app(HubBlocks::class)->forUser($request->user(), $revision->content, $data, $people, sourceTeamIds: $page->kind === 'department' ? ($revision->content['team_ids'] ?? []) : null);

        return Inertia::render('company-hub/index', [
            ...$data, 'view' => match ($page->kind) {
                'news' => 'news', 'department' => 'teams', 'campaign' => 'campaigns', default => 'resources'
            },
            'department' => app(HubDepartment::class)->read($request->user(), $page, $data, $people),
            'campaign' => $campaign,
            'knowledge' => app(HubKnowledge::class)->read($request->user(), $page, $data, $people),
            'resourceReports' => in_array($page->kind, ['page', 'knowledge'], true) ? app(HubResourceReports::class)->read($request->user(), $page) : null,
            'participation' => app(HubParticipation::class)->read($request->user(), $page),
            'people' => $hasPeople ? collect($blockData)->pluck('people')->filter()->flatten(1)->unique('id')->values()->all() : [],
            'blockData' => $blockData,
            'selectedPage' => [
                'id' => $page->id, 'slug' => $page->slug, 'kind' => $page->kind, 'content' => HubCampaign::readerContent(HubFeatured::readerContent(
                    [...HubContent::readerContent($revision->content, array_column($data['interests']['available_teams'], 'id')),
                        ...$collectionFields],
                    $data['pages'], $page->kind === 'department' ? array_values(array_intersect($revision->content['team_ids'] ?? [], array_column($data['interests']['available_teams'], 'id'))) : null,
                ), $campaign),
                'published_at' => $revision->publish_at->toIso8601String(),
                'revision_id' => $revision->id,
                'can_edit' => $access->canEdit($request->user(), $page),
            ],
        ]);
    }
}
