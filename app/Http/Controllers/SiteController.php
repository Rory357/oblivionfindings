<?php

namespace App\Http\Controllers;

use App\Domain\SecurityDevices\Services\DeviceRegistryService;
use App\Http\Requests\StoreSiteRequest;
use App\Http\Requests\UpdateSiteRequest;
use App\Models\Asset;
use App\Models\Client;
use App\Models\FleetFuelLog;
use App\Models\FleetIncident;
use App\Models\FleetOuting;
use App\Models\FleetTrip;
use App\Models\FleetVehicleBooking;
use App\Models\Site;
use App\Models\SiteChecklistAssignment;
use App\Models\SiteChecklistTemplate;
use App\Models\SiteContact;
use App\Models\SiteDocument;
use App\Models\SiteDocumentFolder;
use App\Models\SiteFacilityZone;
use App\Models\SiteHoResource;
use App\Models\SiteHouseRoom;
use App\Services\HealthSafety\HsModuleSummaryService;
use App\Services\NotificationService;
use App\Services\ShiftCoverageService;
use App\Services\Sites\HouseLedgerPresenter;
use App\Services\Sites\HouseLedgerService;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SiteController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Site::class);

        $user = $request->user();
        $type = $request->input('type');
        $status = $request->input('status', 'all');
        $region = $request->input('region');
        $risk = $request->input('risk');
        $managerId = $request->input('manager_id');
        $allowedTypes = $this->allowedSiteTypes($request);
        $accessibleSiteIds = $this->siteAccess()->accessibleSiteIds($user);

        if ($type && ! in_array($type, $allowedTypes, true)) {
            abort(403);
        }

        $visibleSitesQuery = Site::query()
            ->whereIn('type', $allowedTypes)
            ->when($accessibleSiteIds !== [], fn ($q) => $q->whereIn('id', $accessibleSiteIds));

        $sites = (clone $visibleSitesQuery)
            ->when(in_array($status, ['active', 'inactive']), fn ($q) => $q->where('is_active', $status === 'active'))
            ->when($type && in_array($type, ['head_office', 'house', 'facility']), fn ($q) => $q->where('type', $type))
            ->when($region, fn ($q) => $q->where('region', $region))
            ->when($risk === 'high_risk', fn ($q) => $q->where('is_high_risk', true))
            ->when($risk === 'high_needs', fn ($q) => $q->where('is_high_needs', true))
            ->when($risk === 'both', fn ($q) => $q->where('is_high_risk', true)->where('is_high_needs', true))
            ->when($managerId, fn ($q) => $q->where('primary_contact_user_id', $managerId))
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
        $regions = (clone $visibleSitesQuery)
            ->distinct()
            ->pluck('region')
            ->filter()
            ->values();
        $managerIds = (clone $visibleSitesQuery)
            ->whereNotNull('primary_contact_user_id')
            ->distinct()
            ->pluck('primary_contact_user_id')
            ->filter()
            ->values();
        $managers = \App\Models\User::whereIn('id', $managerIds)
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
            'serviceContexts',
            'houseRooms' => fn ($q) => $q->active()->orderBy('sort_order'),
            'hoResources' => fn ($q) => $q->active()->orderBy('name'),
            'facilityZones' => fn ($q) => $q->active()->orderBy('name'),
            'siteNotes' => fn ($q) => $q->with('createdBy:id,name')->orderByDesc('created_at'),
            'geofences' => fn ($q) => $q->where('is_active', true),
        ]);

        $user = $request->user();
        $tenantId = $site->tenant_id ?? $user?->tenant_id ?? $user?->organization_id ?? 1;
        $siteDevices = app(DeviceRegistryService::class)->forSite($tenantId, $site->id);
        $houseLedger = $this->buildHouseLedgerData($site, $user);

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
        if ($site->type === 'house') {
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
                'rooms' => $site->houseRooms->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'assigned_client' => $r->assignedClient ? [
                        'id' => $r->assignedClient->id,
                        'name' => $r->assignedClient->first_name.' '.$r->assignedClient->last_name,
                    ] : null,
                ]),
            ],
            'head_office' => [
                'resources' => $site->hoResources->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'type' => $r->resource_type,
                    'capacity' => $r->capacity,
                ]),
            ],
            'facility' => [
                'zones' => $site->facilityZones->map(fn ($z) => [
                    'id' => $z->id,
                    'name' => $z->name,
                    'type' => $z->zone_type,
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
                'is_high_risk' => (bool) $site->is_high_risk,
                'is_high_needs' => (bool) $site->is_high_needs,
                'risk_notes' => $site->risk_notes,
                'risk_review_date' => $site->risk_review_date?->toDateString(),
                'primary_contact' => $site->primaryContact ? [
                    'id' => $site->primaryContact->id,
                    'name' => $site->primaryContact->name,
                ] : null,
                'service_contexts' => $site->serviceContexts->map(fn ($context) => [
                    'id' => $context->id,
                    'name' => $context->name,
                    'type' => $context->type,
                    'is_active' => (bool) $context->is_active,
                    'description' => $context->description,
                ])->values(),
            ],
            'typeSpecificData' => $typeSpecificData,
            'clients' => $site->clients->sortBy([['first_name', 'asc'], ['last_name', 'asc']])->values()->map(fn ($c) => [
                'id' => $c->id,
                'first_name' => $c->first_name,
                'last_name' => $c->last_name,
                'status' => $c->status,
            ]),
            'contacts' => $site->contacts->sortByDesc('is_primary')->values()->map(fn ($c) => [
                'id' => $c->id,
                'type' => $c->type,
                'name' => $c->name,
                'role' => $c->role,
                'phone' => $c->phone,
                'email' => $c->email,
                'is_primary' => (bool) $c->is_primary,
                'notes' => $c->notes,
            ]),
            'documents' => $site->documents->sortByDesc('created_at')->values()->map(fn ($d) => [
                'id' => $d->id,
                'title' => $d->title,
                'category' => $d->category,
                'folder' => $d->folder,
                'version' => $d->version,
                'effective_date' => optional($d->effective_date)->toDateString(),
                'expiry_date' => optional($d->expiry_date)->toDateString(),
                'notes' => $d->notes,
                'original_name' => $d->original_name,
                'mime_type' => $d->mime_type,
                'size_bytes' => $d->size_bytes,
                'created_at' => $d->created_at?->toDateTimeString(),
                'uploaded_by' => $d->uploadedBy?->only(['id', 'name', 'email']),
            ]),
            'assets' => $assets->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'asset_tag' => $a->asset_tag,
                'category' => $a->category,
                'status' => $a->status,
                'risk_level' => $a->risk_level,
                'location' => $a->location,
                'owner' => $a->client ? [
                    'type' => 'client',
                    'label' => trim($a->client->first_name.' '.$a->client->last_name),
                    'id' => $a->client->id,
                ] : [
                    'type' => 'site',
                    'label' => $site->name,
                    'id' => $site->id,
                ],
                'updated_at' => $a->updated_at?->toDateTimeString(),
            ]),
            'checklist' => $checklist,
            'houseLedger' => $houseLedger,
            // Vendors and credentials are scoped by the viewing user's
            // per-permission rights so the in-tab dialogs only ever see
            // data the user is allowed to see.
            'vendors' => ($user?->canDo('vendors.view') ?? false)
                ? \App\Models\SiteVendor::where('site_id', $site->id)
                    ->where('is_active', true)
                    ->orderBy('service_type')
                    ->orderBy('company_name')
                    ->get()
                    ->map(fn ($v) => [
                        'id' => $v->id,
                        'company_name' => $v->company_name,
                        'service_type' => $v->service_type,
                        'contact_name' => $v->contact_name,
                        'phone' => $v->phone,
                        'after_hours_phone' => $v->after_hours_phone,
                        'email' => $v->email,
                        'account_number' => $v->account_number,
                        'notes' => $v->notes,
                        'preferred_contact_method' => $v->preferred_contact_method,
                        'is_preferred' => (bool) $v->is_preferred,
                        'is_active' => (bool) $v->is_active,
                    ])
                    ->values()
                    ->all()
                : [],
            'credentials' => ($user?->canDo('credentials.view') ?? false)
                ? \App\Models\SiteCredential::where('site_id', $site->id)
                    ->with('vendor:id,company_name,service_type')
                    ->orderBy('label')
                    ->get()
                    ->map(fn ($c) => [
                        'id' => $c->id,
                        'label' => $c->label,
                        'username' => $c->username,
                        'url' => $c->url,
                        'credential_type' => $c->credential_type,
                        'vendor_id' => $c->vendor_id,
                        'vendor_name' => $c->vendor?->company_name,
                        'notes' => $c->notes,
                        'requires_reauth' => (bool) $c->requires_reauth,
                        'is_shareable' => (bool) $c->is_shareable,
                        'password_strength' => $c->password_strength,
                        'has_totp' => $c->hasTotp(),
                        'last_rotated_at' => $c->last_rotated_at?->toDateTimeString(),
                    ])
                    ->values()
                    ->all()
                : [],
            'credentialCount' => ($user?->canDo('credentials.view') ?? false)
                ? \App\Models\SiteCredential::where('site_id', $site->id)->count()
                : 0,
            'hardwareCount' => (clone $siteDevices)->count(),
            'integrationStatus' => \App\Models\Integration\IntegrationSiteConfig::where('site_id', $site->id)
                ->where('is_active', true)
                ->get()
                ->map(fn ($c) => [
                    'provider' => $c->provider,
                    'status' => $c->status,
                ])
                ->values()
                ->all(),
            'can_edit' => (bool) ($user && $user->canDo('sites.update') && $user->can('update', $site)),
            'staffRequirements' => \App\Models\SiteStaffRequirement::where('site_id', $site->id)
                ->where('is_active', true)
                ->orderByRaw("FIELD(category, 'mandatory', 'recommended', 'specialist')")
                ->get()
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'requirement_name' => $r->requirement_name,
                    'category' => $r->category,
                    'description' => $r->description,
                    'certification_required' => (bool) $r->certification_required,
                    'expiry_period_months' => $r->expiry_period_months,
                ]),
            'coverageRequirements' => \App\Models\SiteCoverageRequirement::where('site_id', $site->id)
                ->where('is_active', true)
                ->with(['serviceContext:id,name,type', 'preferredClient:id,first_name,last_name,site_id'])
                ->orderByRaw("FIELD(day_of_week, 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun')")
                ->orderBy('starts_time')
                ->get()
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'coverage_type' => $r->coverage_type,
                    'day_of_week' => $r->day_of_week,
                    'starts_time' => $r->starts_time,
                    'ends_time' => $r->ends_time,
                    'minimum_staff' => (int) $r->minimum_staff,
                    'preferred_client' => $r->preferredClient ? [
                        'id' => $r->preferredClient->id,
                        'name' => trim($r->preferredClient->first_name.' '.$r->preferredClient->last_name),
                    ] : null,
                    'role_requirements' => $r->role_requirements ?? [],
                    'allow_overstaffing' => (bool) $r->allow_overstaffing,
                    'shift_type' => $r->shift_type,
                    'notes' => $r->notes,
                    'service_context' => $r->serviceContext ? [
                        'id' => $r->serviceContext->id,
                        'name' => $r->serviceContext->name,
                        'type' => $r->serviceContext->type,
                    ] : null,
                ]),
            'coveragePreview' => app(ShiftCoverageService::class)
                ->buildSiteSummaries(now()->startOfWeek(), now()->addWeek()->endOfWeek(), $site->id),
            'checklistsSummary' => $this->buildSiteChecklistsSummary($site, $user),
            'can' => [
                'createAsset' => (bool) ($user && $user->canDo('assets.create')),
            ],
            'fleet' => \Inertia\Inertia::optional(fn () => $this->buildSiteFleetData($site)),
            'hs_summary' => \Inertia\Inertia::optional(fn () => app(HsModuleSummaryService::class)->forSite($site->id)),
            'siteNotes' => $site->siteNotes->map(fn ($n) => [
                'id' => $n->id,
                'body' => $n->body,
                'created_at' => $n->created_at?->toIso8601String(),
                'created_by' => $n->createdBy?->only(['id', 'name']),
            ])->values(),
            'geofences' => $site->geofences->map(fn ($g) => [
                'id' => $g->id,
                'name' => $g->name,
                'type' => $g->type,
                'shape' => $g->shape,
                'breach_type' => $g->breach_type,
            ])->values(),
        ]);
    }

    public function updateContactInfo(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'manager_name' => ['nullable', 'string', 'max:255'],
            'manager_phone' => ['nullable', 'string', 'max:50'],
            'after_hours_phone' => ['nullable', 'string', 'max:50'],
        ]);

        $site->update($data);

        \App\Services\AuditLogger::log('site.contact_info.update', $site, [
            'site_id' => $site->id,
            'fields' => array_keys($data),
        ]);

        return back()->with('success', 'Contact information updated.');
    }

    public function updateLocation(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $data = $request->validate([
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'suburb' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
            'region' => ['nullable', 'string', 'max:100'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'access_instructions' => ['nullable', 'string', 'max:2000'],
        ]);

        $site->update($data);

        \App\Services\AuditLogger::log('site.location.update', $site, [
            'site_id' => $site->id,
            'fields' => array_keys($data),
        ]);

        return back()->with('success', 'Location updated.');
    }

    public function updateSafety(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $data = $request->validate([
            'emergency_plan_location' => ['nullable', 'string', 'max:1000'],
            'medication_storage_location' => ['nullable', 'string', 'max:1000'],
        ]);

        $site->update($data);

        \App\Services\AuditLogger::log('site.safety.update', $site, [
            'site_id' => $site->id,
            'fields' => array_keys($data),
        ]);

        return back()->with('success', 'Safety information updated.');
    }

    private function buildHouseLedgerData(Site $site, ?\App\Models\User $user): ?array
    {
        if (! in_array($site->type, ['house', 'residential'], true)) {
            return null;
        }

        if (! $user?->canDo('sites.ledger.view')) {
            return null;
        }

        $tenantId = $user->organization_id;
        if ($site->tenant_id && $tenantId && (int) $site->tenant_id !== (int) $tenantId) {
            return null;
        }

        $ledger = app(HouseLedgerService::class)->getOrCreateLedger($site);
        $entries = $ledger->entries()
            ->with(['recordedBy:id,name', 'approvedBy:id,name'])
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate(10);
        $entries->setPath(url("/sites/{$site->id}/ledger"));

        return HouseLedgerPresenter::payload($site, $ledger, $entries, $user);
    }

    private function buildSiteChecklistsSummary(Site $site, $user): array
    {
        $today = now()->toDateString();

        $assignments = SiteChecklistAssignment::query()
            ->where('site_id', $site->id)
            ->where('is_active', true)
            ->with(['template:id,name,frequency,description', 'assignedTo:id,name'])
            ->orderBy('start_date', 'desc')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'frequency' => $a->frequency,
                'start_date' => $a->start_date?->toDateString(),
                'template' => $a->template ? [
                    'id' => $a->template->id,
                    'name' => $a->template->name,
                    'description' => $a->template->description,
                    'frequency' => $a->template->frequency,
                ] : null,
                'assigned_to' => $a->assignedTo?->only(['id', 'name']),
            ])
            ->all();

        $recentRuns = $site->checklistRuns()
            ->with(['template:id,name', 'completedBy:id,name'])
            ->orderByDesc('scheduled_date')
            ->limit(5)
            ->get()
            ->map(fn ($run) => [
                'id' => $run->id,
                'status' => $run->status,
                'scheduled_date' => $run->scheduled_date?->toDateString(),
                'completed_at' => $run->completed_at?->toDateTimeString(),
                'completion_percentage' => (float) $run->completion_percentage,
                'items_passed' => (int) $run->items_passed,
                'items_failed' => (int) $run->items_failed,
                'is_overdue' => $run->scheduled_date
                    && $run->scheduled_date->lt($today)
                    && in_array($run->status, ['scheduled', 'in_progress']),
                'template' => $run->template ? [
                    'id' => $run->template->id,
                    'name' => $run->template->name,
                ] : null,
                'completed_by' => $run->completedBy?->only(['id', 'name']),
            ])
            ->all();

        $availableTemplates = SiteChecklistTemplate::active()
            ->forType($site->type)
            ->withCount('items')
            ->orderBy('name')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'description' => $t->description,
                'frequency' => $t->frequency,
                'items_count' => (int) $t->items_count,
            ])
            ->all();

        $stats = [
            'active_assignments' => $site->checklistAssignments()->where('is_active', true)->count(),
            'scheduled' => $site->checklistRuns()->where('status', 'scheduled')->count(),
            'in_progress' => $site->checklistRuns()->where('status', 'in_progress')->count(),
            'overdue' => $site->checklistRuns()
                ->where('scheduled_date', '<', $today)
                ->whereIn('status', ['scheduled', 'in_progress'])
                ->count(),
            'completed_30d' => $site->checklistRuns()
                ->where('status', 'completed')
                ->where('completed_at', '>=', now()->subDays(30))
                ->count(),
        ];

        return [
            'stats' => $stats,
            'assignments' => $assignments,
            'recentRuns' => $recentRuns,
            'availableTemplates' => $availableTemplates,
            'can' => [
                'view' => (bool) $user?->canDo('checklists.view'),
                'schedule' => (bool) $user?->canDo('checklists.schedule'),
                'run' => (bool) $user?->canDo('checklists.run'),
            ],
        ];
    }

    private function buildSiteFleetData(Site $site): array
    {
        $hasFleetFields = Schema::hasColumn('assets', 'home_site_id');
        $hasTrips = Schema::hasTable('fleet_trips');
        $hasFuel = Schema::hasTable('fleet_fuel_logs');
        $hasIncidents = Schema::hasTable('fleet_incidents');
        $hasBookings = Schema::hasTable('fleet_vehicle_bookings');
        $hasOutings = Schema::hasTable('fleet_outings');

        // Vehicles at this site (home_site_id) with fleet state
        $vehicles = $hasFleetFields
            ? Asset::query()
                ->where('home_site_id', $site->id)
                ->where('category', 'vehicle')
                ->with('fleetState')
                ->orderBy('name')
                ->get()
                ->map(fn ($v) => [
                    'id' => $v->id,
                    'name' => $v->name,
                    'asset_tag' => $v->asset_tag,
                    'status' => $v->status,
                    'fleet_status' => $v->fleetState?->status,
                    'speed_kph' => $v->fleetState?->speed_kph,
                    'last_seen_at' => optional($v->fleetState?->last_seen_at)->toISOString(),
                    'consent_blocked' => (bool) ($v->fleetState?->consent_blocked ?? false),
                    'wof_expires_at' => optional($v->wof_expires_at)->toDateString(),
                    'registration_expires_at' => optional($v->registration_expires_at)->toDateString(),
                ])
                ->values()
            : collect();

        $vehicleIds = $vehicles->pluck('id')->all();

        // Today's bookings for this site
        $todayBookings = $hasBookings && $vehicleIds
            ? FleetVehicleBooking::query()
                ->where(fn ($q) => $q->where('pickup_site_id', $site->id)->orWhere('return_site_id', $site->id))
                ->whereDate('starts_at', '<=', today())
                ->whereDate('ends_at', '>=', today())
                ->whereIn('status', ['approved', 'checked_out'])
                ->with(['asset:id,name', 'user:id,name'])
                ->limit(10)
                ->get()
                ->map(fn ($b) => [
                    'id' => $b->id,
                    'vehicle' => $b->asset ? ['id' => $b->asset->id, 'name' => $b->asset->name] : null,
                    'booked_by' => $b->user?->name,
                    'purpose' => $b->purpose,
                    'status' => $b->status,
                    'starts_at' => optional($b->starts_at)->toISOString(),
                    'ends_at' => optional($b->ends_at)->toISOString(),
                ])
                ->values()
            : collect();

        // Active outings for site vehicles
        $activeOutings = $hasOutings && $vehicleIds
            ? FleetOuting::query()
                ->whereIn('asset_id', $vehicleIds)
                ->whereIn('status', ['planned', 'active'])
                ->where('planned_departure', '>=', today()->subDay())
                ->with(['asset:id,name', 'driver:id,name'])
                ->withCount('residents')
                ->limit(10)
                ->get()
                ->map(fn ($o) => [
                    'id' => $o->id,
                    'title' => $o->title,
                    'destination' => $o->destination,
                    'status' => $o->status,
                    'planned_departure' => optional($o->planned_departure)->toISOString(),
                    'vehicle' => $o->asset ? ['id' => $o->asset->id, 'name' => $o->asset->name] : null,
                    'driver' => $o->driver ? ['id' => $o->driver->id, 'name' => $o->driver->name] : null,
                    'residents_count' => $o->residents_count,
                ])
                ->values()
            : collect();

        // Monthly stats
        $monthStart = now()->startOfMonth();

        $tripsThisMonth = $hasTrips && $vehicleIds
            ? FleetTrip::whereIn('asset_id', $vehicleIds)->where('started_at', '>=', $monthStart)->count()
            : 0;

        $distanceThisMonth = $hasTrips && $vehicleIds
            ? round((float) FleetTrip::whereIn('asset_id', $vehicleIds)->where('started_at', '>=', $monthStart)->sum('distance_km'), 1)
            : 0;

        $fuelCostThisMonth = $hasFuel && $vehicleIds
            ? round((float) FleetFuelLog::whereIn('asset_id', $vehicleIds)->where('logged_at', '>=', $monthStart)->sum('total_cost'), 2)
            : 0;

        $incidentsThisMonth = $hasIncidents && $vehicleIds
            ? FleetIncident::whereIn('asset_id', $vehicleIds)->where('occurred_at', '>=', $monthStart)->count()
            : 0;

        // Compliance: vehicles with expiring WOF/Rego
        $compliance = $vehicles->filter(function ($v) {
            foreach (['wof_expires_at', 'registration_expires_at'] as $field) {
                if ($v[$field] && now()->diffInDays(\Carbon\Carbon::parse($v[$field]), false) <= 90) {
                    return true;
                }
            }

            return false;
        })->map(function ($v) {
            $items = [];
            foreach (['wof_expires_at' => 'WOF', 'registration_expires_at' => 'Registration'] as $field => $label) {
                if ($v[$field]) {
                    $days = now()->diffInDays(\Carbon\Carbon::parse($v[$field]), false);
                    if ($days <= 90) {
                        $items[] = [
                            'type' => $label,
                            'expires_at' => $v[$field],
                            'days_remaining' => $days,
                            'status' => $days < 0 ? 'expired' : ($days <= 30 ? 'critical' : 'warning'),
                        ];
                    }
                }
            }

            return ['vehicle_name' => $v['name'], 'vehicle_id' => $v['id'], 'items' => $items];
        })->filter(fn ($v) => count($v['items']) > 0)->values();

        return [
            'vehicles' => $vehicles,
            'today_bookings' => $todayBookings,
            'active_outings' => $activeOutings,
            'stats' => [
                'trips_this_month' => $tripsThisMonth,
                'distance_this_month' => $distanceThisMonth,
                'fuel_cost_this_month' => $fuelCostThisMonth,
                'incidents_this_month' => $incidentsThisMonth,
            ],
            'compliance' => $compliance,
        ];
    }

    public function create()
    {
        $this->authorize('create', Site::class);

        $users = \App\Models\User::select(['id', 'name'])->orderBy('name')->get();

        return inertia('sites/create', [
            'users' => $users,
            'checklistTemplates' => $this->checklistTemplatesPayload(),
            'availableAssets' => $this->availableAssetsPayload(null),
        ]);
    }

    public function store(StoreSiteRequest $request)
    {
        $this->authorize('create', Site::class);

        $validated = $request->validated();

        $contacts = $validated['contacts'] ?? [];
        $rooms = $validated['rooms'] ?? [];
        $resources = $validated['resources'] ?? [];
        $zones = $validated['zones'] ?? [];
        $assets = $validated['assets'] ?? [];
        $checklists = $validated['checklists'] ?? [];
        // Documents come from the multipart request with UploadedFile
        // instances; saveDocuments() reads them directly from $request.

        unset(
            $validated['contacts'],
            $validated['rooms'],
            $validated['resources'],
            $validated['zones'],
            $validated['assets'],
            $validated['checklists'],
            $validated['documents'],
        );

        $site = Site::create($validated);

        $this->syncContacts($site, $contacts);
        $this->syncRooms($site, $rooms);
        $this->syncResources($site, $resources);
        $this->syncZones($site, $zones);
        $this->assignAssets($site, $assets, $request->user()?->id);
        $this->syncChecklists($site, $checklists);
        $this->saveDocuments($site, $request, $request->user()?->id);

        app(NotificationService::class)->notifyCrud($request->user(), 'created', 'site', $site, null, [
            'title' => "Site created: {$site->name}",
            'url' => url('/sites'),
        ]);

        return redirect()
            ->route('sites.show', $site)
            ->with('success', 'Site created.');
    }

    private function saveDocuments(Site $site, Request $request, ?int $userId): void
    {
        // Files and metadata arrive in different superglobals — collect indices
        // from both and iterate that union, so a doc with only a file (no
        // metadata) or only metadata (no file, ignored) is handled cleanly.
        $files = (array) ($request->file('documents') ?? []);
        $meta = (array) $request->input('documents', []);
        $indices = array_unique(array_merge(array_keys($files), array_keys($meta)));

        Log::info('SiteController::saveDocuments', [
            'site_id' => $site->id,
            'file_indices' => array_keys($files),
            'meta_indices' => array_keys($meta),
            'has_documents_field' => $request->hasFile('documents'),
        ]);

        foreach ($indices as $index) {
            $file = $request->file("documents.$index.file");
            if (! $file instanceof \Illuminate\Http\UploadedFile) {
                Log::info('saveDocuments: skipped index — no file', [
                    'index' => $index,
                    'meta' => $meta[$index] ?? null,
                ]);
                continue;
            }

            $entry = $meta[$index] ?? [];
            $stored = $file->store("site_documents/{$site->id}", 'local');
            $folder = $this->ensureDocumentFolder($site, $entry['folder'] ?? null);

            SiteDocument::create([
                'tenant_id' => $site->tenant_id,
                'site_id' => $site->id,
                'uploaded_by_user_id' => $userId,
                'title' => $entry['title'] ?? null,
                'category' => $entry['category'] ?? null,
                'folder' => $folder,
                'version' => $entry['version'] ?? null,
                'effective_date' => $entry['effective_date'] ?? null,
                'expiry_date' => $entry['expiry_date'] ?? null,
                'notes' => $entry['notes'] ?? null,
                'storage_disk' => 'local',
                'storage_path' => $stored,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size_bytes' => $file->getSize(),
            ]);
        }
    }

    private function ensureDocumentFolder(Site $site, ?string $folder): ?string
    {
        $folder = trim((string) $folder);
        if ($folder === '') {
            return null;
        }

        SiteDocumentFolder::query()->firstOrCreate([
            'site_id' => $site->id,
            'name' => $folder,
        ]);

        return $folder;
    }

    public function edit(Site $site)
    {
        $this->authorize('update', $site);

        $users = \App\Models\User::select(['id', 'name'])->orderBy('name')->get();

        $site->load('contacts');

        $rooms = SiteHouseRoom::where('site_id', $site->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'notes']);

        $resources = SiteHoResource::where('site_id', $site->id)
            ->orderBy('id')
            ->get(['id', 'name', 'resource_type', 'capacity']);

        $zones = SiteFacilityZone::where('site_id', $site->id)
            ->orderBy('id')
            ->get(['id', 'name', 'zone_type']);

        $checklistAssignments = SiteChecklistAssignment::where('site_id', $site->id)
            ->where('is_active', true)
            ->get(['id', 'template_id', 'frequency', 'assigned_to_user_id']);

        $documents = SiteDocument::where('site_id', $site->id)
            ->orderByDesc('created_at')
            ->get(['id', 'title', 'category', 'folder', 'version', 'effective_date', 'expiry_date', 'notes', 'original_name', 'mime_type', 'size_bytes']);

        $assignedAssetIds = Asset::where('site_id', $site->id)->pluck('id')->all();

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
                'contacts' => $site->contacts->sortByDesc('is_primary')->values()->map(fn ($c) => [
                    'id' => $c->id,
                    'type' => $c->type,
                    'name' => $c->name,
                    'role' => $c->role,
                    'phone' => $c->phone,
                    'email' => $c->email,
                    'is_primary' => (bool) $c->is_primary,
                    'notes' => $c->notes,
                ])->all(),
                'rooms' => $rooms->all(),
                'resources' => $resources->all(),
                'zones' => $zones->all(),
                'checklist_assignments' => $checklistAssignments->all(),
                'documents' => $documents->all(),
                'assigned_asset_ids' => $assignedAssetIds,
            ],
            'users' => $users,
            'checklistTemplates' => $this->checklistTemplatesPayload(),
            'availableAssets' => $this->availableAssetsPayload($site->id),
        ]);
    }

    public function update(UpdateSiteRequest $request, Site $site)
    {
        $this->authorize('update', $site);

        $validated = $request->validated();

        $contacts = $validated['contacts'] ?? [];
        $rooms = $validated['rooms'] ?? [];
        $resources = $validated['resources'] ?? [];
        $zones = $validated['zones'] ?? [];
        $assets = $validated['assets'] ?? [];
        $checklists = $validated['checklists'] ?? [];

        unset(
            $validated['contacts'],
            $validated['rooms'],
            $validated['resources'],
            $validated['zones'],
            $validated['assets'],
            $validated['checklists'],
        );

        $site->update($validated);

        $this->syncContacts($site, $contacts);
        $this->syncRooms($site, $rooms);
        $this->syncResources($site, $resources);
        $this->syncZones($site, $zones);
        $this->assignAssets($site, $assets, $request->user()?->id);
        $this->syncChecklists($site, $checklists);

        app(NotificationService::class)->notifyCrud($request->user(), 'updated', 'site', $site, null, [
            'title' => "Site updated: {$site->name}",
            'url' => url('/sites'),
        ]);

        return redirect()
            ->route('sites.show', $site)
            ->with('success', 'Site updated.');
    }

    private function checklistTemplatesPayload(): array
    {
        return SiteChecklistTemplate::active()
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'applicable_to_type', 'frequency'])
            ->all();
    }

    private function syncContacts(Site $site, array $contacts): void
    {
        $keepIds = [];

        foreach ($contacts as $contact) {
            $name = trim((string) ($contact['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $payload = [
                'site_id' => $site->id,
                'tenant_id' => $site->tenant_id,
                'type' => $contact['type'] ?? 'general',
                'name' => $name,
                'role' => $contact['role'] ?? null,
                'phone' => $contact['phone'] ?? null,
                'email' => $contact['email'] ?? null,
                'is_primary' => (bool) ($contact['is_primary'] ?? false),
                'notes' => $contact['notes'] ?? null,
            ];

            if (! empty($contact['id'])) {
                $existing = SiteContact::where('id', $contact['id'])
                    ->where('site_id', $site->id)
                    ->first();
                if ($existing) {
                    $existing->update($payload);
                    $keepIds[] = $existing->id;
                    continue;
                }
            }

            $created = SiteContact::create($payload);
            $keepIds[] = $created->id;
        }

        SiteContact::where('site_id', $site->id)
            ->whereNotIn('id', $keepIds)
            ->delete();
    }

    private function syncRooms(Site $site, array $rooms): void
    {
        if ($site->type !== 'house') {
            return;
        }

        $keepIds = [];
        foreach ($rooms as $room) {
            $name = trim((string) ($room['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $payload = [
                'tenant_id' => $site->tenant_id,
                'notes' => $room['notes'] ?? null,
                'is_active' => true,
            ];

            if (! empty($room['id'])) {
                $existing = SiteHouseRoom::where('id', $room['id'])
                    ->where('site_id', $site->id)
                    ->first();
                if ($existing) {
                    $existing->update($payload + ['name' => $name]);
                    $keepIds[] = $existing->id;
                    continue;
                }
            }

            // Dedupe by (site_id, name) — prevents unique-constraint crashes
            // when the user submits the same room name twice in one form.
            $row = SiteHouseRoom::updateOrCreate(
                ['site_id' => $site->id, 'name' => $name],
                $payload,
            );
            if (! in_array($row->id, $keepIds, true)) {
                $keepIds[] = $row->id;
            }
        }

        SiteHouseRoom::where('site_id', $site->id)
            ->whereNotIn('id', $keepIds)
            ->delete();
    }

    private function syncResources(Site $site, array $resources): void
    {
        if ($site->type !== 'head_office') {
            return;
        }

        $keepIds = [];
        foreach ($resources as $resource) {
            $name = trim((string) ($resource['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $payload = [
                'tenant_id' => $site->tenant_id,
                'resource_type' => $resource['resource_type'] ?? 'meeting_room',
                'capacity' => isset($resource['capacity']) ? (int) $resource['capacity'] : null,
                'is_active' => true,
            ];

            if (! empty($resource['id'])) {
                $existing = SiteHoResource::where('id', $resource['id'])
                    ->where('site_id', $site->id)
                    ->first();
                if ($existing) {
                    $existing->update($payload + ['name' => $name]);
                    $keepIds[] = $existing->id;
                    continue;
                }
            }

            $row = SiteHoResource::updateOrCreate(
                ['site_id' => $site->id, 'name' => $name],
                $payload,
            );
            if (! in_array($row->id, $keepIds, true)) {
                $keepIds[] = $row->id;
            }
        }

        SiteHoResource::where('site_id', $site->id)
            ->whereNotIn('id', $keepIds)
            ->delete();
    }

    private function syncZones(Site $site, array $zones): void
    {
        if ($site->type !== 'facility') {
            return;
        }

        $keepIds = [];
        foreach ($zones as $zone) {
            $name = trim((string) ($zone['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $payload = [
                'tenant_id' => $site->tenant_id,
                'zone_type' => $zone['zone_type'] ?? null,
                'is_active' => true,
            ];

            if (! empty($zone['id'])) {
                $existing = SiteFacilityZone::where('id', $zone['id'])
                    ->where('site_id', $site->id)
                    ->first();
                if ($existing) {
                    $existing->update($payload + ['name' => $name]);
                    $keepIds[] = $existing->id;
                    continue;
                }
            }

            $row = SiteFacilityZone::updateOrCreate(
                ['site_id' => $site->id, 'name' => $name],
                $payload,
            );
            if (! in_array($row->id, $keepIds, true)) {
                $keepIds[] = $row->id;
            }
        }

        SiteFacilityZone::where('site_id', $site->id)
            ->whereNotIn('id', $keepIds)
            ->delete();
    }

    /**
     * Sync the set of assets attached to a site. The wizard sends a list of
     * asset IDs; each is updated to point at this site, and any asset that
     * was previously assigned here but is missing from the new list is
     * released back to the pool (site_id = null).
     */
    private function assignAssets(Site $site, array $assetIds, ?int $userId): void
    {
        $ids = array_values(array_unique(array_map('intval', $assetIds)));

        // Release previously-assigned assets that were de-selected.
        Asset::where('site_id', $site->id)
            ->when(! empty($ids), fn ($q) => $q->whereNotIn('id', $ids))
            ->update([
                'site_id' => null,
                'updated_by_user_id' => $userId,
            ]);

        // Claim the selected assets that aren't already pointing at another
        // site (the form only offers unassigned + this-site's assets, but be
        // defensive in case the pool changed between page-load and submit).
        if (! empty($ids)) {
            Asset::whereIn('id', $ids)
                ->where(function ($q) use ($site) {
                    $q->whereNull('site_id')->orWhere('site_id', $site->id);
                })
                ->update([
                    'site_id' => $site->id,
                    'updated_by_user_id' => $userId,
                ]);
        }
    }

    private function availableAssetsPayload(?int $currentSiteId): array
    {
        return Asset::query()
            ->select(['id', 'name', 'asset_tag', 'category', 'serial_number', 'site_id'])
            ->where(function ($q) use ($currentSiteId) {
                $q->whereNull('site_id');
                if ($currentSiteId !== null) {
                    $q->orWhere('site_id', $currentSiteId);
                }
            })
            ->orderBy('name')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'asset_tag' => $a->asset_tag,
                'category' => $a->category,
                'serial_number' => $a->serial_number,
                'is_assigned_here' => $currentSiteId !== null && $a->site_id === $currentSiteId,
            ])
            ->all();
    }

    private function syncChecklists(Site $site, array $checklists): void
    {
        $keepIds = [];

        foreach ($checklists as $assignment) {
            if (! ($assignment['enabled'] ?? false)) {
                continue;
            }

            $templateId = (int) ($assignment['template_id'] ?? 0);
            if ($templateId <= 0) {
                continue;
            }

            $row = SiteChecklistAssignment::updateOrCreate(
                [
                    'site_id' => $site->id,
                    'template_id' => $templateId,
                ],
                [
                    'tenant_id' => $site->tenant_id,
                    'frequency' => $assignment['frequency'] ?? 'monthly',
                    'start_date' => now()->toDateString(),
                    'assigned_to_user_id' => $assignment['assigned_to_user_id'] ?? null,
                    'is_active' => true,
                ]
            );
            $keepIds[] = $row->id;
        }

        SiteChecklistAssignment::where('site_id', $site->id)
            ->whereNotIn('id', $keepIds)
            ->update(['is_active' => false]);
    }

    private function allowedSiteTypes(Request $request): array
    {
        $user = $request->user();
        $map = [
            'head_office' => 'sites.type.head_office.view',
            'house' => 'sites.type.house.view',
            'facility' => 'sites.type.facility.view',
        ];

        $allowed = collect($map)
            ->filter(fn (string $permission) => $user?->canDo($permission))
            ->keys()
            ->values()
            ->all();

        return $allowed !== [] ? $allowed : array_keys($map);
    }

    private function siteAccess(): UserSiteAccessService
    {
        return app(UserSiteAccessService::class);
    }
}
