<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSiteRequest;
use App\Http\Requests\UpdateSiteRequest;
use App\Models\Asset;
use App\Models\AssetGeofence;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\SiteChecklistAssignment;
use App\Models\SiteChecklistTemplate;
use App\Models\SiteCoverageRequirement;
use App\Models\SiteDocument;
use App\Models\SiteDocumentFolder;
use App\Models\SiteFacilityZone;
use App\Models\SiteHoResource;
use App\Models\SiteHouseRoom;
use App\Models\SiteStaffRequirement;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use App\Services\Sites\SiteContactService;
use App\Services\Sites\SitePhysicalRoomService;
use App\Services\Sites\SiteReadinessService;
use App\Services\UserSiteAccessService;
use App\Support\NzRegions;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class SiteController extends Controller
{
    private const SITE_BYPASS_PERMISSIONS = ['sites.viewAll'];

    public function __construct(
        private readonly SiteContactService $siteContacts,
        private readonly SitePhysicalRoomService $physicalRooms,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Site::class);

        $user = $request->user();
        $search = trim((string) $request->input('q', ''));
        $type = $request->input('type');
        // Default to active-only: the index leads with the live roster; inactive
        // and archived sites live behind their own tabs.
        $status = $request->input('status', 'active');
        $region = $request->input('region');
        $risk = $request->input('risk');
        $managerId = $request->input('manager_id');
        $audit = $request->input('audit');
        $hazards = $request->input('hazards');
        $maintenance = $request->input('maintenance');
        $readiness = $request->input('readiness');
        $service = $request->input('service');
        $showArchived = $request->boolean('show_archived');
        // The Archived tab is only reachable when the "Show archived" toggle is on.
        $archivedView = $showArchived && $request->boolean('archived');
        $allowedTypes = $this->allowedSiteTypes($request);
        $canViewAllSites = $this->siteAccess()->canBypass($user, self::SITE_BYPASS_PERMISSIONS);
        $accessibleSiteIds = $canViewAllSites ? [] : $this->siteAccess()->accessibleSiteIds($user);
        $readinessService = app(SiteReadinessService::class);

        if ($type && ! in_array($type, $allowedTypes, true)) {
            abort(403);
        }

        $visibleSitesQuery = Site::query()
            ->whereIn('type', $allowedTypes)
            ->when(! $canViewAllSites, fn ($q) => $accessibleSiteIds === []
                ? $q->whereRaw('1 = 0')
                : $q->whereIn('id', $accessibleSiteIds));

        $sites = (clone $visibleSitesQuery)
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%")
                        ->orWhere('suburb', 'like', "%{$search}%")
                        ->orWhere('address_line_1', 'like', "%{$search}%");
                });
            })
            // Archived sites surface only in the dedicated Archived view; every
            // other view excludes them entirely.
            ->when($archivedView, fn ($q) => $q->where('archived', true))
            ->when(! $archivedView, fn ($q) => $q->where('archived', false))
            // Status (active/inactive) does not apply inside the Archived view.
            ->when(! $archivedView && in_array($status, ['active', 'inactive']), fn ($q) => $q->where('is_active', $status === 'active'))
            ->when($type && in_array($type, ['head_office', 'house', 'facility', 'residential']), fn ($q) => $q->where('type', $type))
            ->when($region, fn ($q) => $q->where(function ($query) use ($region) {
                $query->where('region', $region)
                    ->orWhere(function ($derivedQuery) use ($region) {
                        $derivedQuery->where(function ($emptyRegion) {
                            $emptyRegion->whereNull('region')->orWhere('region', '');
                        })->whereIn('city', $this->citiesForRegion($region));
                    });
            }))
            // The "At risk" saved view rolls up both elevated-attention flags;
            // its filter must OR them to match the savedViewCounts['at_risk']
            // badge (which counts high-risk OR high-needs). Keep the grouped
            // closure so the OR doesn't leak across the surrounding wheres.
            ->when($risk === 'at_risk', fn ($q) => $q->where(fn ($risky) => $risky
                ->where('is_high_risk', true)
                ->orWhere('is_high_needs', true)
            ))
            ->when($risk === 'high_risk', fn ($q) => $q->where('is_high_risk', true))
            ->when($risk === 'high_needs', fn ($q) => $q->where('is_high_needs', true))
            ->when($risk === 'both', fn ($q) => $q->where('is_high_risk', true)->where('is_high_needs', true))
            ->when($managerId, fn ($q) => $q->where('primary_contact_user_id', $managerId))
            ->when($audit === 'overdue', fn ($q) => $q->whereHas('checklistRuns', fn ($run) => $run->overdue()))
            ->when($hazards === 'open', fn ($q) => $q->whereHas('hazards', fn ($hazard) => $hazard->open()))
            ->when($maintenance === 'open', fn ($q) => $q->whereHas('assets', fn ($asset) => $this->openMaintenanceQuery($asset)))
            ->when($service === 'respite', fn ($q) => $q->whereHas('serviceContexts', fn ($context) => $context
                ->whereIn('type', ['planned_respite', 'emergency_respite', 'community_respite'])
                ->where('is_active', true)
            ))
            ->select([
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
                'archived',
                'is_high_risk',
                'is_high_needs',
                'primary_contact_user_id',
            ])
            ->with([
                'primaryContact:id,name',
                'primarySiteContact:id,site_id,name',
            ])
            ->withCount($this->siteOperationalCounts())
            ->orderBy('name')
            ->get()
            ->map(fn (Site $site) => $this->siteIndexPayload($site, $readinessService))
            ->when($readiness === 'incomplete', fn ($collection) => $collection
                ->filter(fn (array $site) => $site['readiness']['is_active_but_incomplete'])
                ->values()
            );

        $visibleSites = (clone $visibleSitesQuery)
            ->select([
                'id',
                'name',
                'type',
                'region',
                'city',
                'suburb',
                'is_active',
                'archived',
                'is_high_risk',
                'is_high_needs',
                'primary_contact_user_id',
            ])
            ->withCount($this->siteOperationalCounts())
            ->get();

        // Headline counts, saved-view counts and the hero summary all describe
        // the *live* roster (archived excluded); the Archived tab is counted on
        // its own. This keeps the hero numbers stable regardless of which view
        // or filter is active.
        $liveSites = $visibleSites->filter(fn (Site $site) => ! $site->archived)->values();
        $archivedCount = $visibleSites->count() - $liveSites->count();

        // Get filter options
        $regions = $visibleSites
            ->map(fn (Site $site) => $site->resolved_region)
            ->filter()
            ->unique()
            ->sort()
            ->values();
        $managerIds = $visibleSites
            ->pluck('primary_contact_user_id')
            ->filter()
            ->unique()
            ->values();
        $managers = User::whereIn('id', $managerIds)
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get();
        $savedViewCounts = $this->savedViewCounts($liveSites, $readinessService);
        $savedViewCounts['archived'] = $archivedCount;

        $bedsTotal = (int) $liveSites->sum(fn (Site $site) => (int) ($site->rooms_total ?? 0));
        $bedsOccupied = (int) $liveSites->sum(fn (Site $site) => (int) ($site->rooms_occupied ?? 0));

        $summary = [
            'total' => $liveSites->count(),
            'active' => $liveSites->where('is_active', true)->count(),
            'inactive' => $liveSites->where('is_active', false)->count(),
            'incomplete' => $savedViewCounts['active_incomplete'],
            'hazards' => (int) $liveSites->sum(fn (Site $site) => (int) ($site->open_hazards_count ?? 0)),
            'overdue' => (int) $liveSites->sum(fn (Site $site) => (int) ($site->overdue_checklists_count ?? 0) + (int) ($site->open_maintenance_count ?? 0)),
            'regions' => $liveSites->map(fn (Site $site) => $site->resolved_region)->filter()->unique()->count(),
            'beds_total' => $bedsTotal,
            'beds_occupied' => $bedsOccupied,
            'occupancy_percent' => $bedsTotal > 0 ? (int) round(($bedsOccupied / $bedsTotal) * 100) : 0,
            'clients' => (int) $liveSites->sum(fn (Site $site) => (int) ($site->active_clients_count ?? 0)),
            'archived' => $archivedCount,
        ];

        // Reference data for the Add Site modal (mounted on this index). Only
        // computed for users who can create sites; everyone else gets empties.
        $addSite = ($user?->canDo('sites.create') ?? false)
            ? $this->addSiteReferenceData(
                $user,
                $allowedTypes,
                $accessibleSiteIds,
                $canViewAllSites,
            )
            : $this->emptyAddSiteReferenceData();

        return inertia('sites/index', [
            'sites' => $sites,
            'addSite' => $addSite,
            'filters' => [
                'q' => $search !== '' ? $search : null,
                'type' => $type,
                'status' => $status,
                'region' => $region,
                'risk' => $risk,
                'manager_id' => $managerId,
                'audit' => $audit,
                'hazards' => $hazards,
                'maintenance' => $maintenance,
                'readiness' => $readiness,
                'service' => $service,
                'show_archived' => $showArchived,
                'archived' => $archivedView,
            ],
            'summary' => $summary,
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
            'savedViewCounts' => $savedViewCounts,
        ]);
    }

    public function updateContactInfo(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        $site->update($data);

        AuditLogger::log('site.contact_info.update', $site, [
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

        AuditLogger::log('site.location.update', $site, [
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

        AuditLogger::log('site.safety.update', $site, [
            'site_id' => $site->id,
            'fields' => array_keys($data),
        ]);

        return back()->with('success', 'Safety information updated.');
    }

    /**
     * Toggle (or explicitly set) a site's active flag from the index quick
     * actions. `is_active` may be passed to force a state; otherwise it flips.
     */
    public function toggleActive(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $target = $request->has('is_active')
            ? $request->boolean('is_active')
            : ! $site->is_active;

        $site->update(['is_active' => $target]);

        AuditLogger::log('site.active.update', $site, [
            'site_id' => $site->id,
            'is_active' => $target,
        ]);

        return back()->with('success', $target ? 'Site marked active.' : 'Site marked inactive.');
    }

    public function archive(Request $request, Site $site)
    {
        $this->authorize('archive', $site);

        if (! $site->archived) {
            $site->update([
                'archived' => true,
                'archived_at' => now(),
                // Archiving takes a site out of operation.
                'is_active' => false,
            ]);

            AuditLogger::log('site.archive', $site, ['site_id' => $site->id]);
        }

        return back()->with('success', 'Site archived.');
    }

    public function unarchive(Request $request, Site $site)
    {
        $this->authorize('archive', $site);

        if ($site->archived) {
            $site->update([
                'archived' => false,
                'archived_at' => null,
            ]);

            AuditLogger::log('site.unarchive', $site, ['site_id' => $site->id]);
        }

        return back()->with('success', 'Site restored.');
    }

    /**
     * Archive several sites at once from the index bulk-action bar. Each site
     * is authorised individually so a partial selection still archives what the
     * user is allowed to touch.
     */
    public function bulkArchive(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $sites = Site::query()
            ->whereIn('id', $validated['ids'])
            ->where('archived', false)
            ->get();

        $archived = 0;
        foreach ($sites as $site) {
            if (! $request->user()?->can('archive', $site)) {
                continue;
            }

            $site->update([
                'archived' => true,
                'archived_at' => now(),
                'is_active' => false,
            ]);

            AuditLogger::log('site.archive', $site, [
                'site_id' => $site->id,
                'bulk' => true,
            ]);

            $archived++;
        }

        return back()->with('success', $archived === 1 ? '1 site archived.' : "{$archived} sites archived.");
    }

    public function create(Request $request)
    {
        $this->authorize('create', Site::class);

        $users = $this->staffPicker($request->user());

        return inertia('sites/create', [
            'users' => $users,
            'regionOptions' => NzRegions::REGIONS,
            'checklistTemplates' => $this->checklistTemplatesPayload(),
            'availableAssets' => $this->availableAssetsPayload(null),
        ]);
    }

    public function store(StoreSiteRequest $request)
    {
        $this->authorize('create', Site::class);

        $validated = $request->validated();
        $user = $request->user();
        $this->assertResponsibleStaff($validated['primary_contact_user_id'] ?? null, $user);

        $contacts = $validated['contacts'] ?? [];
        $rooms = $validated['rooms'] ?? [];
        $resources = $validated['resources'] ?? [];
        $zones = $validated['zones'] ?? [];
        $assets = $validated['assets'] ?? [];
        $checklists = $validated['checklists'] ?? [];
        // Rostering + geofence sub-payloads fanned out to their own models
        // after the Site exists (see persist* helpers below).
        $coverage = $validated['coverage'] ?? [];
        $credentials = $validated['credentials'] ?? [];
        $geofence = $validated['geofence'] ?? null;
        // Documents come from the multipart request with UploadedFile
        // instances; saveDocuments() reads them directly from $request.

        // Finance: the UI collects weekly food budget in dollars; the column
        // stores integer cents.
        $validated['weekly_food_budget_cents'] = $this->dollarsToCents($validated['weekly_food_budget'] ?? null);

        unset(
            $validated['contacts'],
            $validated['rooms'],
            $validated['resources'],
            $validated['zones'],
            $validated['assets'],
            $validated['checklists'],
            $validated['documents'],
            $validated['coverage'],
            $validated['credentials'],
            $validated['geofence'],
            $validated['weekly_food_budget'],
        );

        if ($validated['weekly_food_budget_cents'] === null) {
            unset($validated['weekly_food_budget_cents']);
        }

        $site = DB::transaction(function () use ($validated, $contacts, $rooms, $resources, $zones, $assets, $checklists, $coverage, $credentials, $geofence, $request, $user) {
            $site = Site::create($validated);

            $this->siteContacts->sync($site, $contacts);
            $this->syncRooms($site, $rooms, $user);
            $this->syncResources($site, $resources);
            $this->syncZones($site, $zones);
            $this->assignAssets($site, $assets, $user?->id);
            $this->syncChecklists($site, $checklists);

            // Rostering + geofence fan-out (all reuse existing models).
            $this->persistCoverageRequirements($site, $coverage, $user);
            $this->persistStaffRequirements($site, $credentials, $user);
            $this->persistSiteGeofence($site, $geofence);

            // Documents last so disk writes only happen once every DB op succeeds.
            $this->saveDocuments($site, $request, $user?->id);

            return $site;
        });

        app(NotificationService::class)->notifyCrud($user, 'created', 'site', $site, null, [
            'title' => "Site created: {$site->name}",
            'url' => url('/sites'),
        ]);

        // The Add Site modal submits with `_modal` and stays open to show its
        // success pane (linking to the new profile), so return back with the new
        // id flashed. The full-page wizard keeps the redirect-to-profile flow.
        if ($request->boolean('_modal')) {
            return back()
                ->with('created_site_id', $site->id)
                ->with('success', 'Site created.');
        }

        return redirect()
            ->route('sites.show', $site)
            ->with('success', 'Site created.');
    }

    /**
     * Convert a dollar amount (string|numeric|null) to integer cents, or null
     * when no value was provided.
     */
    private function dollarsToCents($dollars): ?int
    {
        if ($dollars === null || $dollars === '') {
            return null;
        }

        return (int) round(((float) $dollars) * 100);
    }

    /**
     * Coverage cards arrive with a multi-day `days[]`; the table stores one
     * row per day (single `day_of_week`), matching the existing post-create
     * path. So each card fans out to N rows. The role-mix map
     * {caregiver,driver,med_competent} is converted to the [{key,minimum}]
     * shape SiteCoverageRequirement expects, dropping zero counts.
     *
     * @param  array<int, array<string, mixed>>  $coverage
     */
    private function persistCoverageRequirements(Site $site, array $coverage, ?User $user): void
    {
        foreach ($coverage as $rule) {
            $roleRequirements = $this->rolesMapToArray($rule['roles'] ?? []);

            foreach ($rule['days'] ?? [] as $day) {
                SiteCoverageRequirement::create([
                    'site_id' => $site->id,
                    'service_context_id' => $rule['service_context_id'] ?? null,
                    'name' => $rule['name'],
                    'coverage_type' => $rule['coverage_type'],
                    'day_of_week' => $day,
                    'starts_time' => $rule['starts_time'],
                    'ends_time' => $rule['ends_time'],
                    'minimum_staff' => (int) $rule['minimum_staff'],
                    'role_requirements' => $roleRequirements,
                    'allow_overstaffing' => (bool) ($rule['allow_overstaffing'] ?? true),
                    'shift_type' => $rule['shift_type'] ?? null,
                    'is_active' => true,
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $roles
     * @return array<int, array{key: string, minimum: int}>
     */
    private function rolesMapToArray(array $roles): array
    {
        $out = [];

        foreach (['caregiver', 'driver', 'med_competent'] as $key) {
            $count = (int) ($roles[$key] ?? 0);
            if ($count > 0) {
                $out[] = ['key' => $key, 'minimum' => $count];
            }
        }

        return $out;
    }

    /**
     * Required staff credentials → SiteStaffRequirement. updateOrCreate honours
     * the unique(site_id, requirement_name) constraint when the same credential
     * is selected twice.
     *
     * @param  array<int, array<string, mixed>>  $credentials
     */
    private function persistStaffRequirements(Site $site, array $credentials, ?User $user): void
    {
        foreach ($credentials as $cred) {
            $expiry = $cred['expiry_period_months'] ?? null;
            $expiry = ($expiry !== null && (int) $expiry > 0) ? (int) $expiry : null;

            SiteStaffRequirement::updateOrCreate(
                ['site_id' => $site->id, 'requirement_name' => $cred['name']],
                [
                    'category' => $cred['category'],
                    'certification_required' => ($cred['category'] ?? null) === 'mandatory',
                    'expiry_period_months' => $expiry,
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * Seed a circle geofence into the shared AssetGeofence (reusing the exact
     * shape + scope contract from SiteGeofenceController). Skipped silently when
     * the site has no coordinates. Asset assignment stays a post-create concern.
     *
     * @param  array<string, mixed>|null  $geofence
     */
    private function persistSiteGeofence(Site $site, ?array $geofence): void
    {
        if (! $geofence) {
            return;
        }

        $lat = $site->latitude;
        $lng = $site->longitude;
        if ($lat === null || $lng === null) {
            return;
        }

        AssetGeofence::create([
            'asset_id' => null,
            'site_id' => $site->id,
            'name' => "{$site->name} Geofence",
            'type' => 'circle',
            'scope' => match ($site->type) {
                'house', 'residential' => 'house',
                'facility' => 'asset',
                default => 'site',
            },
            'shape' => [
                'center' => ['lat' => (float) $lat, 'lng' => (float) $lng],
                'radius_m' => (int) ($geofence['radius_m'] ?? 120),
            ],
            'breach_type' => $geofence['breach_type'] ?? 'both',
            'alert_config' => null,
            'time_rules' => null,
            'is_active' => (bool) ($geofence['is_active'] ?? true),
        ]);
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
            if (! $file instanceof UploadedFile) {
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

    public function edit(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $users = $this->staffPicker($request->user());

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
                'brand_colour' => $site->brand_colour,
                'phone' => $site->phone,
                'email' => $site->email,
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
            'regionOptions' => NzRegions::REGIONS,
            'checklistTemplates' => $this->checklistTemplatesPayload(),
            'availableAssets' => $this->availableAssetsPayload($site->id),
        ]);
    }

    public function update(UpdateSiteRequest $request, Site $site)
    {
        $this->authorize('update', $site);

        $validated = $request->validated();
        $this->assertResponsibleStaff(
            $validated['primary_contact_user_id'] ?? null,
            $request->user(),
        );

        $contacts = $validated['contacts'] ?? [];
        $rooms = $validated['rooms'] ?? [];
        $resources = $validated['resources'] ?? [];
        $zones = $validated['zones'] ?? [];
        $assets = $validated['assets'] ?? [];
        $checklists = $validated['checklists'] ?? [];

        // Finance: keep the dollars→cents conversion symmetric with store() so an
        // edited weekly food budget actually persists.
        $cents = $this->dollarsToCents($validated['weekly_food_budget'] ?? null);
        if ($cents !== null) {
            $validated['weekly_food_budget_cents'] = $cents;
        }

        // UpdateSiteRequest accepts the same rostering/geofence arrays as the
        // store request (so the edit path can share the modal), but the
        // edit-via-modal fan-out is a follow-up — drop them here explicitly
        // rather than letting Eloquent silently discard the non-fillable keys.
        unset(
            $validated['contacts'],
            $validated['rooms'],
            $validated['resources'],
            $validated['zones'],
            $validated['assets'],
            $validated['checklists'],
            $validated['coverage'],
            $validated['credentials'],
            $validated['geofence'],
            $validated['weekly_food_budget'],
        );

        DB::transaction(function () use (
            $assets,
            $checklists,
            $contacts,
            $request,
            $resources,
            $rooms,
            $site,
            $validated,
            $zones,
        ): void {
            $site->update($validated);

            $this->siteContacts->sync($site, $contacts);
            $this->syncRooms($site, $rooms, $request->user());
            $this->syncResources($site, $resources);
            $this->syncZones($site, $zones);
            $this->assignAssets($site, $assets, $request->user()?->id);
            $this->syncChecklists($site, $checklists);
        });

        app(NotificationService::class)->notifyCrud($request->user(), 'updated', 'site', $site, null, [
            'title' => "Site updated: {$site->name}",
            'url' => url('/sites'),
        ]);

        return redirect()
            ->route('sites.show', $site)
            ->with('success', 'Site updated.');
    }

    public function storeOnboardingStep(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'step' => ['required', 'string', 'in:contacts,assets'],
            'data' => ['nullable', 'array'],
            'data.contacts' => ['nullable', 'array'],
            'data.contacts.*.type' => ['nullable', 'string', 'max:60'],
            'data.contacts.*.name' => ['required_with:data.contacts', 'string', 'max:160'],
            'data.contacts.*.role' => ['nullable', 'string', 'max:120'],
            'data.contacts.*.phone' => ['nullable', 'string', 'max:60'],
            'data.contacts.*.email' => ['nullable', 'email', 'max:160'],
            'data.contacts.*.is_primary' => ['nullable', 'boolean'],
            'data.assets' => ['nullable', 'array'],
            'data.assets.*.name' => ['required_with:data.assets', 'string', 'max:160'],
            'data.assets.*.category' => ['nullable', 'string', 'max:120'],
            'data.assets.*.quantity' => ['nullable', 'integer', 'min:1', 'max:50'],
            'data.assets.*.notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $payload = $validated['data'] ?? [];

        if ($validated['step'] === 'contacts') {
            $this->storeOnboardingContacts($site, $payload['contacts'] ?? []);
        }

        if ($validated['step'] === 'assets') {
            $this->storeOnboardingAssets($site, $payload['assets'] ?? [], $request->user()?->id);
        }

        return response()->json(['ok' => true]);
    }

    private function checklistTemplatesPayload(): array
    {
        return SiteChecklistTemplate::active()
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'applicable_to_type', 'frequency'])
            ->all();
    }

    /**
     * Reference data the Add Site modal needs: site leads, checklist templates,
     * assignable assets, NZ regions, service contexts, copyable sites (with
     * their coverage + credential rows for the "copy a pattern" clone), the
     * staff-credential catalogue and the coverage role keys.
     *
     * @param  array<int, string>  $allowedTypes
     * @param  array<int, int>  $accessibleSiteIds
     * @return array<string, mixed>
     */
    private function addSiteReferenceData(
        User $user,
        array $allowedTypes,
        array $accessibleSiteIds,
        bool $canViewAllSites,
    ): array {
        $referenceSiteIds = $canViewAllSites
            ? Site::query()
                ->whereIn('type', $allowedTypes)
                ->where('archived', false)
                ->pluck('id')
                ->map(fn ($siteId) => (int) $siteId)
                ->all()
            : $accessibleSiteIds;

        $copyableSites = Site::query()
            ->whereIn('type', $allowedTypes)
            ->when(! $canViewAllSites, fn ($q) => $accessibleSiteIds === []
                ? $q->whereRaw('1 = 0')
                : $q->whereIn('id', $accessibleSiteIds))
            ->where('archived', false)
            ->with([
                'coverageRequirements' => fn ($q) => $q->where('is_active', true)
                    ->orderByRaw("FIELD(day_of_week, 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun')")
                    ->orderBy('starts_time'),
                'staffRequirements' => fn ($q) => $q->where('is_active', true),
            ])
            ->orderBy('name')
            ->get(['id', 'name', 'type'])
            ->map(fn (Site $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'type' => $s->type,
                'coverage' => $s->coverageRequirements->map(fn ($r) => [
                    'name' => $r->name,
                    'coverage_type' => $r->coverage_type,
                    'day_of_week' => $r->day_of_week,
                    'starts_time' => $r->starts_time,
                    'ends_time' => $r->ends_time,
                    'minimum_staff' => (int) $r->minimum_staff,
                    'role_requirements' => $r->role_requirements ?? [],
                    'allow_overstaffing' => (bool) $r->allow_overstaffing,
                    'shift_type' => $r->shift_type,
                    'service_context_id' => $r->service_context_id,
                ])->values(),
                'credentials' => $s->staffRequirements->map(fn ($r) => [
                    'name' => $r->requirement_name,
                    'category' => $r->category,
                    'expiry_period_months' => $r->expiry_period_months,
                ])->values(),
            ])
            ->values();

        $serviceContexts = ServiceContext::query()
            ->where('is_active', true)
            ->availableToSites($referenceSiteIds)
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'type', 'site_id'])
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'type' => $c->type,
            ])
            ->values();

        return [
            'users' => $this->staffPicker($user),
            'regionOptions' => NzRegions::REGIONS,
            'serviceContexts' => $serviceContexts,
            'copyableSites' => $copyableSites,
            'credentialCatalogue' => config('site_credentials.catalogue', []),
            'coverageRoleKeys' => config('site_credentials.coverage_role_keys', []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyAddSiteReferenceData(): array
    {
        return [
            'users' => [],
            'regionOptions' => [],
            'serviceContexts' => [],
            'copyableSites' => [],
            'credentialCatalogue' => [],
            'coverageRoleKeys' => [],
        ];
    }

    private function storeOnboardingContacts(Site $site, array $contacts): void
    {
        $this->siteContacts->upsertBatch($site, $contacts);
    }

    private function storeOnboardingAssets(Site $site, array $assets, ?int $userId): void
    {
        foreach ($assets as $asset) {
            $name = trim((string) ($asset['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $quantity = max(1, (int) ($asset['quantity'] ?? 1));

            for ($index = 1; $index <= $quantity; $index++) {
                Asset::create([
                    'site_id' => $site->id,
                    'created_by_user_id' => $userId,
                    'updated_by_user_id' => $userId,
                    'name' => $quantity > 1 ? "{$name} ({$index})" : $name,
                    'category' => $asset['category'] ?? null,
                    'status' => 'active',
                    'risk_level' => 'medium',
                    'notes' => $asset['notes'] ?? null,
                ]);
            }
        }
    }

    private function syncRooms(Site $site, array $rooms, User $actor): void
    {
        if (! in_array($site->type, ['house', 'residential'], true)) {
            return;
        }

        $this->physicalRooms->syncResidentialRooms($site, $rooms, $actor);
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

    private function siteOperationalCounts(): array
    {
        return [
            'clients as active_clients_count' => fn ($q) => $q->where('status', 'active'),
            'contacts',
            'contacts as emergency_contacts_count' => fn ($q) => $q->whereIn('type', ['emergency', 'maintenance', 'manager']),
            'contacts as site_lead_contacts_count' => fn ($q) => $q->whereIn('type', ['site_lead', 'manager']),
            'contacts as after_hours_contacts_count' => fn ($q) => $q->where('type', 'emergency'),
            'documents',
            'houseRooms as rooms_total' => fn ($q) => $q->active()->where('is_assignable', true),
            'houseRooms as rooms_occupied' => fn ($q) => $q->active()->where('is_assignable', true)->whereNotNull('assigned_client_id'),
            'hazards as open_hazards_count' => fn ($q) => $q->open(),
            'hazards as recent_hazards_count' => fn ($q) => $q->where('updated_at', '>=', now()->subDays(90)),
            'checklistRuns as overdue_checklists_count' => fn ($q) => $q->overdue(),
            'checklistAssignments as checklist_assignments_count' => fn ($q) => $q->active(),
            'assets as open_maintenance_count' => fn ($q) => $this->openMaintenanceQuery($q),
            'geofences as active_geofences_count' => fn ($q) => $q->where('is_active', true),
            'hoResources as ho_resources_count' => fn ($q) => $q->active(),
            'facilityZones as facility_zones_count' => fn ($q) => $q->active(),
            'serviceContexts as respite_service_contexts_count' => fn ($q) => $q
                ->whereIn('type', ['planned_respite', 'emergency_respite', 'community_respite'])
                ->where('is_active', true),
        ];
    }

    private function siteIndexPayload(Site $site, SiteReadinessService $readinessService): array
    {
        $roomsTotal = (int) ($site->rooms_total ?? 0);
        $roomsOccupied = (int) ($site->rooms_occupied ?? 0);

        return [
            'id' => $site->id,
            'name' => $site->name,
            'type' => $site->type,
            'region' => $site->resolved_region,
            'address_line_1' => $site->address_line_1,
            'address_line_2' => $site->address_line_2,
            'suburb' => $site->suburb,
            'city' => $site->city,
            'postcode' => $site->postcode,
            'country' => $site->country,
            'is_active' => (bool) $site->is_active,
            'archived' => (bool) $site->archived,
            'is_high_risk' => (bool) $site->is_high_risk,
            'is_high_needs' => (bool) $site->is_high_needs,
            'primary_contact' => $site->primaryContact ? [
                'id' => $site->primaryContact->id,
                'name' => $site->primaryContact->name,
            ] : ($site->primarySiteContact ? [
                'id' => null,
                'name' => $site->primarySiteContact->name,
            ] : null),
            'active_clients_count' => (int) ($site->active_clients_count ?? 0),
            'contacts_count' => (int) ($site->contacts_count ?? 0),
            'documents_count' => (int) ($site->documents_count ?? 0),
            'rooms_total' => $roomsTotal,
            'rooms_occupied' => $roomsOccupied,
            'vacancies' => max(0, $roomsTotal - $roomsOccupied),
            'open_hazards_count' => (int) ($site->open_hazards_count ?? 0),
            'overdue_checklists_count' => (int) ($site->overdue_checklists_count ?? 0),
            'open_maintenance_count' => (int) ($site->open_maintenance_count ?? 0),
            'readiness' => $readinessService->slim($site),
            'geofence_status' => $this->resolveGeofenceStatus($site),
        ];
    }

    private function savedViewCounts($visibleSites, SiteReadinessService $readinessService): array
    {
        return [
            'at_risk' => $visibleSites->filter(fn (Site $site) => $site->is_high_risk || $site->is_high_needs)->count(),
            'audit_overdue' => $visibleSites->filter(fn (Site $site) => (int) ($site->overdue_checklists_count ?? 0) > 0)->count(),
            'open_hazards' => $visibleSites->filter(fn (Site $site) => (int) ($site->open_hazards_count ?? 0) > 0)->count(),
            'open_maintenance' => $visibleSites->filter(fn (Site $site) => (int) ($site->open_maintenance_count ?? 0) > 0)->count(),
            'active_incomplete' => $visibleSites->filter(fn (Site $site) => $readinessService->slim($site)['is_active_but_incomplete'])->count(),
            'respite' => $visibleSites->filter(fn (Site $site) => (int) ($site->respite_service_contexts_count ?? 0) > 0)->count(),
            'inactive' => $visibleSites->where('is_active', false)->count(),
        ];
    }

    /**
     * Categorise a site's geofence state for the index list pill:
     *   'active'    — at least one active site-scoped AssetGeofence.
     *   'inactive'  — geofences exist but are all disabled.
     *   'missing'   — type=house/facility with tracked residents but no fence.
     *   'na'        — head office or otherwise not expected to have one.
     */
    private function resolveGeofenceStatus(Site $site): string
    {
        if (! Schema::hasTable('asset_geofences')) {
            return 'na';
        }

        $geofences = AssetGeofence::query()
            ->where('site_id', $site->id)
            ->whereNull('asset_id')
            ->get(['id', 'is_active']);

        if ($geofences->isNotEmpty()) {
            return $geofences->contains(fn ($g) => (bool) $g->is_active) ? 'active' : 'inactive';
        }

        $expected = in_array($site->type, ['house', 'residential', 'facility'], true);

        return $expected ? 'missing' : 'na';
    }

    private function openMaintenanceQuery($query)
    {
        return $query->where(function ($maintenance) {
            $maintenance->where('requires_maintenance', true)
                ->orWhereDate('maintenance_due_at', '<=', now()->toDateString());
        });
    }

    private function citiesForRegion(string $region): array
    {
        return [
            'Northland' => ['Whangarei', 'Kerikeri', 'Kaitaia'],
            'Auckland' => ['Auckland', 'Manukau', 'North Shore', 'Waitakere', 'Papakura', 'Devonport', 'Grey Lynn', 'Ponsonby', 'Mt Eden', 'Henderson', 'Takapuna', 'Albany'],
            'Waikato' => ['Hamilton', 'Cambridge', 'Te Awamutu', 'Huntly', 'Thames', 'Tokoroa'],
            'Bay of Plenty' => ['Tauranga', 'Rotorua', 'Whakatane', 'Mount Maunganui'],
            'Gisborne' => ['Gisborne'],
            "Hawke's Bay" => ['Napier', 'Hastings'],
            'Taranaki' => ['New Plymouth'],
            'Manawatū-Whanganui' => ['Palmerston North', 'Whanganui'],
            'Wellington' => ['Wellington', 'Lower Hutt', 'Porirua', 'Upper Hutt', 'Kapiti', 'Te Aro'],
            'Nelson' => ['Nelson'],
            'Marlborough' => ['Blenheim'],
            'West Coast' => ['Greymouth', 'Westport'],
            'Canterbury' => ['Christchurch', 'Rangiora', 'Ashburton', 'Timaru'],
            'Otago' => ['Dunedin', 'Queenstown', 'Oamaru'],
            'Southland' => ['Invercargill', 'Gore'],
        ][$region] ?? [];
    }

    private function allowedSiteTypes(Request $request): array
    {
        $user = $request->user();
        $map = [
            'head_office' => 'sites.type.head_office.view',
            'house' => 'sites.type.house.view',
            'residential' => 'sites.type.house.view',
            'facility' => 'sites.type.facility.view',
        ];

        $allowed = collect($map)
            ->filter(fn (string $permission) => $user?->canDo($permission))
            ->keys()
            ->values()
            ->all();

        return $allowed !== [] ? $allowed : array_keys($map);
    }

    private function staffPicker(?User $viewer)
    {
        $query = User::query();
        $this->siteAccess()->applyStaffScope($query, $viewer, self::SITE_BYPASS_PERMISSIONS);

        return $query->select(['id', 'name'])->orderBy('name')->get();
    }

    private function assertResponsibleStaff(mixed $userId, ?User $actor): void
    {
        if (! filled($userId)) {
            return;
        }

        $query = User::query()->whereKey((int) $userId);
        $this->siteAccess()->applyStaffScope($query, $actor, self::SITE_BYPASS_PERMISSIONS);

        if (! $query->exists()) {
            throw ValidationException::withMessages([
                'primary_contact_user_id' => 'Choose a current approved staff member visible from your approved Sites.',
            ]);
        }
    }

    private function siteAccess(): UserSiteAccessService
    {
        return app(UserSiteAccessService::class);
    }
}
