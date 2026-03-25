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

        $type = $request->input('type');
        $status = $request->input('status', 'all');
        $region = $request->input('region');
        $risk = $request->input('risk');
        $managerId = $request->input('manager_id');
        $allowedTypes = $this->allowedSiteTypes($request);

        if ($type && !in_array($type, $allowedTypes, true)) {
            abort(403);
        }

        $sites = Site::query()
            ->whereIn('type', $allowedTypes)
            ->when(in_array($status, ['active', 'inactive']), fn($q) => $q->where('is_active', $status === 'active'))
            ->when($type && in_array($type, ['head_office', 'house', 'facility', 'residential']), fn($q) => $q->where('type', $type))
            ->when($region, fn($q) => $q->where('region', $region))
            ->when($risk === 'high_risk', fn($q) => $q->where('is_high_risk', true))
            ->when($risk === 'high_needs', fn($q) => $q->where('is_high_needs', true))
            ->when($risk === 'both', fn($q) => $q->where('is_high_risk', true)->where('is_high_needs', true))
            ->when($managerId, fn($q) => $q->where('primary_contact_user_id', $managerId))
            ->with('primaryContact:id,name')
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'type',
                'region',
                'address_line_1',
                'address_line_2',
                'suburb',
                'city',
                'postcode',
                'country',
                'is_active',
                'is_high_risk',
                'is_high_needs',
                'primary_contact_user_id',
            ]);

        // Get filter options
        $regions = Site::distinct()->pluck('region')->filter()->values();
        $managers = \App\Models\User::whereHas('sitesAsPrimaryContact')
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get();

        return inertia('sites/index', [
            'sites' => $sites,
            'filters' => [
                'type' => $type,
                'status' => $status,
                'region' => $region,
                'risk' => $risk,
                'manager_id' => $managerId,
            ],
            'filterOptions' => [
                'regions' => $regions,
                'managers' => $managers,
                'types' => [
                    ['value' => 'head_office', 'label' => 'Head Office'],
                    ['value' => 'house', 'label' => 'House'],
                    ['value' => 'facility', 'label' => 'Facilities'],
                    ['value' => 'residential', 'label' => 'Residential'],
                ],
                'risks' => [
                    ['value' => 'high_risk', 'label' => 'High Risk'],
                    ['value' => 'high_needs', 'label' => 'High Needs'],
                    ['value' => 'both', 'label' => 'Both'],
                ],
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
            'primaryContact:id,name',
            'houseRooms' => fn($q) => $q->active()->orderBy('sort_order'),
            'hoResources' => fn($q) => $q->active()->orderBy('name'),
            'facilityZones' => fn($q) => $q->active()->orderBy('name'),
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

        // Build setup completeness checklist based on ACTUAL data
        // (not onboarding progress - that tracks wizard completion separately)
        $checklist = [
            [
                'key' => 'contact_phone',
                'label' => 'Primary contact phone recorded',
                'done' => filled($site->phone),
            ],
            [
                'key' => 'after_hours',
                'label' => 'After-hours phone recorded',
                'done' => filled($site->after_hours_phone),
            ],
            [
                'key' => 'emergency_plan_location',
                'label' => 'Emergency plan location recorded',
                'done' => filled($site->emergency_plan_location),
            ],
            [
                'key' => 'med_storage',
                'label' => 'Medication storage location recorded',
                'done' => filled($site->medication_storage_location),
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
        
        // Add type-specific checklist items
        if (in_array($site->type, ['house', 'residential'], true)) {
            $checklist[] = [
                'key' => 'has_rooms',
                'label' => 'At least one bedroom configured',
                'done' => $site->houseRooms()->count() > 0,
            ];
        } elseif ($site->type === 'head_office') {
            $checklist[] = [
                'key' => 'has_resources',
                'label' => 'At least one room/resource configured',
                'done' => $site->hoResources()->count() > 0,
            ];
        } elseif ($site->type === 'facility') {
            $checklist[] = [
                'key' => 'has_zones',
                'label' => 'At least one zone configured',
                'done' => $site->facilityZones()->count() > 0,
            ];
        }

        // Type-specific data
        $typeSpecificData = match ($site->type) {
            'house' => [
                'rooms' => $site->houseRooms->map(fn($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'assigned_client' => $r->assignedClient ? [
                        'id' => $r->assignedClient->id,
                        'name' => $r->assignedClient->first_name . ' ' . $r->assignedClient->last_name,
                    ] : null,
                ]),
            ],
            'head_office' => [
                'resources' => $site->hoResources->map(fn($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'type' => $r->resource_type,
                    'capacity' => $r->capacity,
                ]),
            ],
            'facility' => [
                'zones' => $site->facilityZones->map(fn($z) => [
                    'id' => $z->id,
                    'name' => $z->name,
                    'type' => $z->zone_type,
                ]),
            ],
            'residential' => [
                'rooms' => $site->houseRooms->map(fn($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'assigned_client' => $r->assignedClient ? [
                        'id' => $r->assignedClient->id,
                        'name' => $r->assignedClient->first_name . ' ' . $r->assignedClient->last_name,
                    ] : null,
                ]),
            ],
            default => [],
        };

        return inertia('sites/show', [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'type' => $site->type,
                'display_type' => $site->display_type,
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
                'region' => $site->region,
                'latitude' => $site->latitude,
                'longitude' => $site->longitude,
                'access_instructions' => $site->access_instructions,
                'is_high_risk' => (bool) $site->is_high_risk,
                'is_high_needs' => (bool) $site->is_high_needs,
                'risk_notes' => $site->risk_notes,
                'risk_review_date' => $site->risk_review_date?->toDateString(),
                'primary_contact' => $site->primaryContact ? [
                    'id' => $site->primaryContact->id,
                    'name' => $site->primaryContact->name,
                ] : null,
                'onboarding_completed_at' => $site->onboarding_completed_at?->toDateTimeString(),
                'onboarding_progress' => $site->onboarding_progress,
            ],
            'typeSpecificData' => $typeSpecificData,
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
            'vendors' => \App\Models\SiteVendor::where('site_id', $site->id)
                ->where('is_active', true)
                ->orderBy('service_type')
                ->get()
                ->map(fn($v) => [
                    'id' => $v->id,
                    'company_name' => $v->company_name,
                    'service_type' => $v->service_type,
                    'phone' => $v->phone,
                    'is_preferred' => (bool) $v->is_preferred,
                ]),
            'credentialCount' => \App\Models\SiteCredential::where('site_id', $site->id)->count(),
            'hardwareCount' => \App\Models\LocationHardware::where('site_id', $site->id)->count(),
            'integrationStatus' => \App\Models\Integration\IntegrationSiteConfig::where('site_id', $site->id)
                ->where('is_active', true)
                ->get()
                ->map(fn($c) => [
                    'provider' => $c->provider,
                    'status' => $c->status,
                ])
                ->values()
                ->all(),
            'can_edit' => (bool) ($user && $user->canDo('sites.update') && $user->can('update', $site)),
            'can' => [
                'createAsset' => (bool) ($user && $user->canDo('assets.create')),
            ],
        ]);
    }

    public function create()
    {
        $this->authorize('create', Site::class);

        $users = \App\Models\User::staff()
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get();

        return inertia('sites/create', [
            'users' => $users,
        ]);
    }

    public function store(StoreSiteRequest $request)
    {
        $this->authorize('create', Site::class);

        $validated = $request->validated();
        
        $site = Site::create($validated);

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

        $users = \App\Models\User::staff()
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get();

        $documents = \App\Models\SiteDocument::where('site_id', $site->id)
            ->orderByDesc('created_at')
            ->get(['id', 'title', 'category', 'expiry_date', 'notes', 'original_name', 'size_bytes']);

        return inertia('sites/edit', [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'type' => $site->type,
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
                'region' => $site->region,
                'latitude' => $site->latitude,
                'longitude' => $site->longitude,
                'access_instructions' => $site->access_instructions,
                'is_active' => $site->is_active,
                'is_high_risk' => $site->is_high_risk,
                'is_high_needs' => $site->is_high_needs,
                'risk_notes' => $site->risk_notes,
                'risk_review_date' => $site->risk_review_date?->toDateString(),
                'primary_contact_user_id' => $site->primary_contact_user_id,
            ],
            'users' => $users,
            'documents' => $documents,
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

    private function allowedSiteTypes(Request $request): array
    {
        $user = $request->user();
        $map = [
            'head_office' => 'sites.type.head_office.view',
            'house' => 'sites.type.house.view',
            'facility' => 'sites.type.facility.view',
            'residential' => 'sites.type.house.view',
        ];

        $allowed = collect($map)
            ->filter(fn (string $permission) => $user?->canDo($permission))
            ->keys()
            ->values()
            ->all();

        return $allowed !== [] ? $allowed : array_keys($map);
    }
}
