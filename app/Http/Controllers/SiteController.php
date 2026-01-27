<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSiteRequest;
use App\Http\Requests\UpdateSiteRequest;
use App\Models\Asset;
use App\Models\Client;
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

    public function show(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        $site->load([
            'clients:id,first_name,last_name,status,site_id',
            'contacts',
            'documents.uploadedBy:id,name,email',
        ]);

        $user = $request->user();

        // Assets linked to this site (includes both site-owned assets and client-owned assets stored at the site)
        $assets = Asset::query()
            ->where('site_id', $site->id)
            ->with([
                'client:id,first_name,last_name,site_id',
            ])
            ->orderBy('name')
            ->get([
                'id',
                'site_id',
                'client_id',
                'name',
                'asset_tag',
                'category',
                'status',
                'risk_level',
                'location',
                'updated_at',
            ]);

        $checklist = [
            [
                'key' => 'contact_phone',
                'label' => 'Primary contact phone recorded',
                'done' => (bool) $site->phone,
            ],
            [
                'key' => 'after_hours',
                'label' => 'After-hours phone recorded',
                'done' => (bool) $site->after_hours_phone,
            ],
            [
                'key' => 'emergency_plan_location',
                'label' => 'Emergency plan location recorded',
                'done' => (bool) $site->emergency_plan_location,
            ],
            [
                'key' => 'med_storage',
                'label' => 'Medication storage location recorded',
                'done' => (bool) $site->medication_storage_location,
            ],
            [
                'key' => 'has_emergency_contact',
                'label' => 'At least one site contact added',
                'done' => $site->contacts->count() > 0,
            ],
            [
                'key' => 'has_documents',
                'label' => 'At least one key document uploaded (e.g. evacuation plan)',
                'done' => $site->documents->count() > 0,
            ],
        ];

        return inertia('sites/show', [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'phone' => $site->phone,
                'email' => $site->email,
                'manager_name' => $site->manager_name,
                'manager_phone' => $site->manager_phone,
                'after_hours_phone' => $site->after_hours_phone,
                'emergency_plan_location' => $site->emergency_plan_location,
                'medication_storage_location' => $site->medication_storage_location,
                'notes' => $site->notes,
                'is_active' => (bool) $site->is_active,
                'address' => $site->address,
            ],
            'clients' => $site->clients->sortBy([['first_name', 'asc'], ['last_name', 'asc']])->values()->map(fn($c) => [
                'id' => $c->id,
                'first_name' => $c->first_name,
                'last_name' => $c->last_name,
                'status' => $c->status,
            ]),
            'contacts' => $site->contacts->sortByDesc('is_primary')->values()->map(fn($c) => [
                'id' => $c->id,
                'type' => $c->type,
                'name' => $c->name,
                'role' => $c->role,
                'phone' => $c->phone,
                'email' => $c->email,
                'is_primary' => (bool) $c->is_primary,
                'notes' => $c->notes,
            ]),
            'documents' => $site->documents->sortByDesc('created_at')->values()->map(fn($d) => [
                'id' => $d->id,
                'title' => $d->title,
                'category' => $d->category,
                'version' => $d->version,
                'effective_date' => optional($d->effective_date)->toDateString(),
                'expiry_date' => optional($d->expiry_date)->toDateString(),
                'notes' => $d->notes,
                'original_name' => $d->original_name,
                'mime_type' => $d->mime_type,
                'size_bytes' => $d->size_bytes,
                'created_at' => $d->created_at?->toDateTimeString(),
                'uploaded_by' => $d->uploadedBy?->only(['id','name','email']),
            ]),
            'assets' => $assets->map(fn($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'asset_tag' => $a->asset_tag,
                'category' => $a->category,
                'status' => $a->status,
                'risk_level' => $a->risk_level,
                'location' => $a->location,
                'owner' => $a->client ? [
                    'type' => 'client',
                    'label' => trim($a->client->first_name . ' ' . $a->client->last_name),
                    'id' => $a->client->id,
                ] : [
                    'type' => 'site',
                    'label' => $site->name,
                    'id' => $site->id,
                ],
                'updated_at' => $a->updated_at?->toDateTimeString(),
            ]),
            'checklist' => $checklist,
            'can_edit' => (bool) ($user && $user->canDo('sites.update') && $user->can('update', $site)),
            'can' => [
                'createAsset' => (bool) ($user && $user->canDo('assets.create')),
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
                'phone',
                'email',
                'manager_name',
                'manager_phone',
                'after_hours_phone',
                'emergency_plan_location',
                'medication_storage_location',
                'notes',
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
