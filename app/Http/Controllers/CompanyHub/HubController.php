<?php

namespace App\Http\Controllers\CompanyHub;

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Services\HubAccess;
use App\Domain\CompanyHub\Services\HubCommunity;
use App\Domain\CompanyHub\Services\HubDepartment;
use App\Domain\CompanyHub\Services\HubDirectory;
use App\Domain\CompanyHub\Services\HubEditorial;
use App\Domain\CompanyHub\Services\HubFeed;
use App\Domain\CompanyHub\Services\HubKnowledge;
use App\Domain\CompanyHub\Services\HubParticipation;
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
        $data = $request->validate(['story' => 'nullable|string|max:100', 'search' => 'nullable|string|max:150', 'view' => ['nullable', Rule::in(['home', 'news', 'shoutouts', 'events', 'resources', 'pages'])]]);
        $showcase = HubShowcase::data($data['story'] ?? null);
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
        $showcase = HubShowcase::data();

        return Inertia::render('company-hub/editor', [
            'demo' => true,
            'page' => ['id' => 0, 'slug' => 'showcase', 'kind' => 'home', 'owner_id' => 0, 'draft' => $showcase['home'], 'draft_version' => 1, 'archived_at' => null],
            'capabilities' => ['layout' => true, 'write' => true, 'publish' => true, 'edit_all' => false],
            'revisions' => [], 'owners' => [], 'sites' => [], 'departments' => [], 'previewData' => $showcase,
        ]);
    }

    public function index(Request $request, HubFeed $feed)
    {
        $data = $request->validate([
            'announcement' => 'nullable|integer|min:1',
            'search' => 'nullable|string|max:150',
            'view' => ['nullable', Rule::in(['home', 'news', 'shoutouts', 'events', 'resources', 'pages', 'saved', 'people', 'teams', 'campaigns', 'ideas', 'following', 'updates', 'services', 'publishing'])],
        ]);

        $search = trim($data['search'] ?? '');
        $globalSearch = $search !== '' && ($data['view'] ?? 'home') === 'home';

        $payload = [
            ...$feed->forUser($request->user(), $search),
            'view' => $data['view'] ?? 'home', 'selectedPage' => null,
            'announcementId' => isset($data['announcement']) ? (int) $data['announcement'] : null,
            'people' => $globalSearch || ($data['view'] ?? '') === 'people' ? app(HubDirectory::class)->people($request->user(), $search) : [],
            'canViewOrg' => $request->user()->canDo('hr.orgchart.view') || $request->user()->canDo('hr.employees.viewAny'),
            'ideas' => $globalSearch || ($data['view'] ?? '') === 'ideas' ? app(HubCommunity::class)->ideas($request->user(), $search) : [],
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
        $hasPeople = collect($revision->content['sections'])->flatMap(fn ($section) => $section['blocks'])->contains('type', 'people');
        $people = $hasPeople || $page->kind === 'department' || HubKnowledge::supports($page, $revision->content) ? app(HubDirectory::class)->people($request->user()) : [];

        return Inertia::render('company-hub/index', [
            ...$data, 'view' => match ($page->kind) {
                'news' => 'news', 'department' => 'teams', 'campaign' => 'campaigns', default => 'resources'
            },
            'department' => app(HubDepartment::class)->read($request->user(), $page, $data, $people),
            'knowledge' => app(HubKnowledge::class)->read($request->user(), $page, $data, $people),
            'participation' => app(HubParticipation::class)->read($request->user(), $page),
            'people' => $hasPeople ? $people : [],
            'selectedPage' => [
                'id' => $page->id, 'slug' => $page->slug, 'kind' => $page->kind, 'content' => $revision->content,
                'published_at' => $revision->publish_at->toIso8601String(),
                'revision_id' => $revision->id,
                'can_edit' => $access->canEdit($request->user(), $page),
            ],
        ]);
    }
}
