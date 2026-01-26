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

    public function show(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        $site->load([
            'clients:id,site_id,first_name,last_name,status',
            'contacts',
            'documents' => fn ($q) => $q->orderByDesc('created_at')->with(['uploadedBy:id,name,email']),
        ]);

        $checklist = [
            [
                'key' => 'address',
                'label' => 'Address recorded',
                'done' => trim((string) $site->address) !== '',
            ],
            [
                'key' => 'primary_contact',
                'label' => 'Primary site contact added',
                'done' => $site->contacts()->where('is_primary', true)->exists(),
            ],
            [
                'key' => 'emergency_plan',
                'label' => 'Emergency plan location recorded',
                'done' => (bool) ($site->emergency_plan_location && trim($site->emergency_plan_location) !== ''),
            ],
            [
                'key' => 'med_storage',
                'label' => 'Medication storage location recorded',
                'done' => (bool) ($site->medication_storage_location && trim($site->medication_storage_location) !== ''),
            ],
            [
                'key' => 'docs',
                'label' => 'At least one compliance document uploaded',
                'done' => $site->documents()->exists(),
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
                'address_line_1' => $site->address_line_1,
                'address_line_2' => $site->address_line_2,
                'suburb' => $site->suburb,
                'city' => $site->city,
                'postcode' => $site->postcode,
                'country' => $site->country,
                'is_active' => (bool) $site->is_active,
                'address' => $site->address,
            ],
            'clients' => $site->clients->map(fn ($c) => [
                'id' => $c->id,
                'first_name' => $c->first_name,
                'last_name' => $c->last_name,
                'status' => $c->status,
            ])->values(),
            'contacts' => $site->contacts->sortByDesc('is_primary')->map(fn ($c) => [
                'id' => $c->id,
                'type' => $c->type,
                'name' => $c->name,
                'role' => $c->role,
                'phone' => $c->phone,
                'email' => $c->email,
                'is_primary' => (bool) $c->is_primary,
                'notes' => $c->notes,
            ])->values(),
            'documents' => $site->documents->map(fn ($d) => [
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
                'created_at' => optional($d->created_at)->toISOString(),
                'uploaded_by' => $d->uploadedBy ? [
                    'id' => $d->uploadedBy->id,
                    'name' => $d->uploadedBy->name,
                    'email' => $d->uploadedBy->email,
                ] : null,
            ])->values(),
            'checklist' => $checklist,
            'can_edit' => $request->user()?->canDo('sites.update') ?? false,
        ]);
    }
}
