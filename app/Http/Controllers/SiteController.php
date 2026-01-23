<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSiteRequest;
use App\Http\Requests\UpdateSiteRequest;
use App\Models\Site;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class SiteController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Site::class);

        $status = $request->string('status')->toString();
        $status = in_array($status, ['active', 'inactive'], true) ? $status : 'all';

        $sites = Site::query()
            ->when($status === 'active', fn($q) => $q->where('is_active', true))
            ->when($status === 'inactive', fn($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'address_line_1',
                'address_line_2',
                'suburb',
                'city',
                'postcode',
                'country',
                'is_active',
            ]);

        return inertia('sites/index', [
            'sites' => $sites,
            'filters' => [
                'status' => $status,
            ],
        ]);
    }

    public function create()
    {
        $this->authorize('create', Site::class);

        return inertia('sites/create');
    }

    public function store(StoreSiteRequest $request)
    {
        $this->authorize('create', Site::class);

        $site = Site::create($request->validated());

        app(NotificationService::class)->notifyCrud($request->user(), "created", "site", $site, null, [
            "title" => "Site created: {$site->name}",
            "url" => url("/sites"),
        ]);

        return redirect()
            ->route('sites.index')
            ->with('success', 'Site created.');
    }

    public function edit(Site $site)
    {
        $this->authorize('update', $site);

        return inertia('sites/edit', [
            'site' => $site->only([
                'id',
                'name',
                'address_line_1',
                'address_line_2',
                'suburb',
                'city',
                'postcode',
                'country',
                'is_active',
            ]),
        ]);
    }

    public function update(UpdateSiteRequest $request, Site $site)
    {
        $this->authorize('update', $site);

        $site->update($request->validated());

        app(NotificationService::class)->notifyCrud($request->user(), "updated", "site", $site, null, [
            "title" => "Site updated: {$site->name}",
            "url" => url("/sites"),
        ]);

        return redirect()
            ->route('sites.index')
            ->with('success', 'Site updated.');
    }
}
