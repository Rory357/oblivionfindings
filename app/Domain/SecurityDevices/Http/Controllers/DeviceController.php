<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\SecurityDevices\Config\DeviceTaxonomy;
use App\Domain\SecurityDevices\Enums\DeviceDomain;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Enums\RelationshipType;
use App\Domain\SecurityDevices\Http\Controllers\Concerns\MapsDevicesForList;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceDocument;
use App\Domain\SecurityDevices\Models\DeviceRelationship;
use App\Domain\SecurityDevices\Presenters\DeviceProfilePresenter;
use App\Domain\SecurityDevices\Services\DeviceLinkService;
use App\Domain\SecurityDevices\Services\DeviceFieldOwnershipService;
use App\Domain\SecurityDevices\Services\DeviceRegistryService;
use App\Domain\SecurityDevices\Services\DeviceRelationshipLifecycleService;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class DeviceController extends Controller
{
    use MapsDevicesForList;

    public function __construct(
        private readonly DeviceRegistryService $registry,
        private readonly DeviceLinkService $linkService,
        private readonly DeviceFieldOwnershipService $fieldOwnership,
        private readonly DeviceRelationshipLifecycleService $relationshipLifecycle,
        private readonly SecurityDevicesAccessService $access,
        private readonly DeviceProfilePresenter $profilePresenter,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.devices.view'), 403);

        $baseQuery = $this->access->visibleDevices($user);
        $scopeLabel = null;
        if ($request->filled('site_id')) {
            $siteId = $request->integer('site_id');
            abort_unless(
                $siteId > 0 && in_array($siteId, $this->access->accessibleSiteIds($user), true),
                404,
            );
            $site = Site::query()
                ->where('is_active', true)
                ->where('archived', false)
                ->whereNull('archived_at')
                ->findOrFail($siteId);
            $baseQuery = $this->access->visibleDevicesForSite($user, $siteId);
            $scopeLabel = $site->name;
        }
        $query = (clone $baseQuery)
            ->with([
                'assignments' => fn ($q) => $q->active(),
            ])
            ->withCount([
                'monitors as enabled_monitors_count' => fn ($monitor) => $monitor->where('is_enabled', true),
                'monitors as failing_monitors_count' => fn ($monitor) => $monitor
                    ->where('is_enabled', true)
                    ->whereIn('current_state', ['failed', 'degraded']),
                'monitors as uncertain_monitors_count' => fn ($monitor) => $monitor
                    ->where('is_enabled', true)
                    ->whereIn('current_state', ['unknown', 'stale', 'pending']),
            ]);

        $view = $request->string('view', 'all')->toString();
        if (! in_array($view, ['all', 'needs_attention', 'offline', 'unmonitored', 'unassigned', 'stale'], true)) {
            $view = 'all';
        }
        $this->applyInventoryView($query, $view);

        // ── Domain/category filters (index-specific, not in trait) ──

        if ($request->filled('domain') && $request->input('domain') !== 'all') {
            $query->byDomain($request->input('domain'));
        }

        if ($request->filled('category') && $request->input('category') !== 'all') {
            $query->byCategory($request->input('category'));
        }

        // ── Common filters, search, sort (shared via trait) ───────

        $this->applyCommonFilters($request, $query);
        $this->applyCommonSort($request, $query);

        $devices = $query->paginate(30)->withQueryString();

        // ── Stats ─────────────────────────────────────────────────

        $stats = [
            'total' => (clone $baseQuery)->count(),
            'active' => (clone $baseQuery)->where('status', DeviceStatus::Active->value)->count(),
            'offline' => (clone $baseQuery)->where('status', DeviceStatus::Offline->value)->count(),
            'attention' => (clone $baseQuery)->needingAttention()->count(),
        ];

        // ── Filter options ────────────────────────────────────────

        $providers = (clone $baseQuery)->whereNotNull('provider')
            ->select('provider')
            ->distinct()
            ->orderBy('provider')
            ->pluck('provider');

        return Inertia::render('security-devices/devices/index', [
            'devices' => [
                'data' => $devices->getCollection()->map(fn (Device $d) => $this->mapDeviceForList($d)),
                'links' => $devices->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $devices->currentPage(),
                    'last_page' => $devices->lastPage(),
                    'total' => $devices->total(),
                ],
            ],
            'stats' => $stats,
            'savedViews' => $this->savedViews($baseQuery),
            'filters' => [
                ...$request->only(['site_id', 'domain', 'category', 'status', 'health', 'provider', 'assigned', 'search', 'sort', 'direction']),
                'view' => $view,
            ],
            'scopeLabel' => $scopeLabel,
            'filterOptions' => [
                'domains' => collect(DeviceDomain::cases())->map(fn ($d) => ['value' => $d->value, 'label' => $d->label()]),
                'statuses' => collect(DeviceStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
                'healthStatuses' => collect(HealthStatus::cases())->map(fn ($h) => ['value' => $h->value, 'label' => $h->label()]),
                'providers' => $providers,
            ],
            'can' => [
                'create' => $user->canDo('securityDevices.devices.create'),
                'export' => $user->canDo('securityDevices.reports.view'),
                'bulk_select' => $user->canDo('securityDevices.reports.view'),
            ],
            'exportHref' => '/security-devices/reports/devices.csv',
        ]);
    }

    public function show(Request $request, Device $device)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.devices.view'), 403);
        $this->access->assertCanViewDevice($user, $device);

        $canViewEvents = $user->canDo('securityDevices.events.view');
        $canViewMaintenance = $user->canDo('securityDevices.maintenance.view');
        $relations = [
            'assignments' => fn ($q) => $q->with(['assignedBy:id,name', 'releasedBy:id,name'])->latest('assigned_at')->limit(20),
            'activeAssetLinks.asset',
            'activeAssetLinks.asset.categoryRef:id,slug',
            'documents',
            'documentHistory' => fn ($query) => $query
                ->with([
                    'uploadedBy:id,name',
                    'removedBy:id,name',
                    'removalRequestedBy:id,name',
                ])
                ->latest('updated_at')
                ->limit(50),
            'parentRelationships.parent',
            'childRelationships.child',
            'relationshipHistoryAsChild' => fn ($query) => $query
                ->with(['parent:id,name', 'createdBy:id,name', 'unlinkedBy:id,name'])
                ->latest('unlinked_at')
                ->limit(50),
            'relationshipHistoryAsParent' => fn ($query) => $query
                ->with(['child:id,name', 'createdBy:id,name', 'unlinkedBy:id,name'])
                ->latest('unlinked_at')
                ->limit(50),
            'groups',
            'createdBy',
        ];
        if ($canViewEvents) {
            $relations['events'] = fn ($q) => $this->access
                ->applyTemporalEventCustodyScope($q, $user)
                ->latest('occurred_at')
                ->limit(20);
            $relations['monitors'] = fn ($q) => $q
                ->with([
                    'profile' => fn ($profile) => $profile
                        ->select('id', 'name', 'interval_seconds', 'stale_after_seconds'),
                    'collector' => fn ($collector) => $collector
                        ->select('id', 'name', 'status', 'last_seen_at'),
                ])
                ->orderBy('name');
        }
        if ($canViewMaintenance) {
            $relations['maintenanceRecords'] = fn ($q) => $q->latest()->limit(10);
        }
        $device->load($relations);

        $device->setRelation('assignments', $device->assignments
            ->filter(fn ($assignment): bool => $assignment->released_at === null
                ? $this->access->canAccessCurrentAssignment($user, $assignment)
                : $this->access->canAccessHistoricalAssignment($user, $assignment))
            ->values());
        $accessibleAssetIds = $this->access->accessibleAssetIds($user);
        $device->setRelation('activeAssetLinks', $device->activeAssetLinks
            ->filter(fn ($link): bool => in_array((int) $link->asset_id, $accessibleAssetIds, true))
            ->values());
        $relatedIds = $device->parentRelationships->pluck('parent_device_id')
            ->merge($device->childRelationships->pluck('child_device_id'))
            ->merge($device->relationshipHistoryAsChild->pluck('parent_device_id'))
            ->merge($device->relationshipHistoryAsParent->pluck('child_device_id'))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
        $visibleRelatedIds = $relatedIds->isEmpty()
            ? collect()
            : $this->access->visibleDevices($user)
                ->whereIn('id', $relatedIds)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id);
        $device->setRelation('parentRelationships', $device->parentRelationships
            ->filter(fn ($relationship): bool => $visibleRelatedIds->contains((int) $relationship->parent_device_id))
            ->values());
        $device->setRelation('childRelationships', $device->childRelationships
            ->filter(fn ($relationship): bool => $visibleRelatedIds->contains((int) $relationship->child_device_id))
            ->values());
        $device->setRelation('relationshipHistoryAsChild', $device->relationshipHistoryAsChild
            ->filter(fn ($relationship): bool => $visibleRelatedIds->contains((int) $relationship->parent_device_id))
            ->values());
        $device->setRelation('relationshipHistoryAsParent', $device->relationshipHistoryAsParent
            ->filter(fn ($relationship): bool => $visibleRelatedIds->contains((int) $relationship->child_device_id))
            ->values());

        $activeAssignment = $device->assignments->first(fn ($a) => $a->released_at === null);
        $siteIds = $this->access->accessibleSiteIds($user);
        $canAssign = $user->canDo('securityDevices.devices.assign');
        $canUpdate = $user->canDo('securityDevices.devices.update');

        // Assignment target options for the assign dialog.
        $assignmentTargets = $canAssign ? [
            'sites' => Site::query()
                ->whereIn('id', $siteIds)
                ->where('is_active', true)
                ->where('archived', false)
                ->whereNull('archived_at')
                ->orderBy('name')
                ->get(['id', 'name']),
            'rooms' => SiteRoom::query()
                ->whereIn('site_id', $siteIds)
                ->orderBy('name')
                ->get(['id', 'site_id', 'name']),
            'staff' => $this->access->assignableStaff($user)
                ->orderBy('name')
                ->get(['id', 'name']),
            'clients' => $this->access->assignableClients($user),
            'vehicles' => $this->access->assignableVehicles($user),
        ] : [
            'sites' => [],
            'rooms' => [],
            'staff' => [],
            'clients' => [],
            'vehicles' => [],
        ];

        $availableAssets = $canUpdate
            ? $this->access->assignableAssets($user)
                ->map(fn (Asset $asset) => [
                    'id' => $asset->id,
                    'name' => $asset->name,
                    'asset_tag' => $asset->asset_tag,
                ])
            : collect();

        $otherDevices = $canUpdate
            ? $this->access->visibleDevices($user)
                ->where('id', '!=', $device->id)
                ->orderBy('name')
                ->limit(500)
                ->get(['id', 'name', 'device_uid', 'category'])
                ->map(fn (Device $otherDevice) => [
                    'id' => $otherDevice->id,
                    'name' => $otherDevice->name,
                    'device_uid' => $otherDevice->device_uid,
                    'category' => $otherDevice->category,
                ])
            : collect();

        $profile = $this->profilePresenter->present($user, $device, $activeAssignment);
        $passwordConfirmedAt = $request->session()->get('auth.password_confirmed_at');
        $profile['management']['stepUpCurrent'] = is_numeric($passwordConfirmedAt)
            && (int) $passwordConfirmedAt >= now()->subSeconds(
                max(60, (int) config('security_devices.step_up_max_age_seconds', 900)),
            )->timestamp;

        $accessibleAssetIds = $this->access->accessibleAssetIds($user);

        return Inertia::render('security-devices/devices/show', [
            'device' => [
                'id' => $device->id,
                'name' => $device->name,
                'status' => $device->status?->value,
            ],
            'profile' => $profile,
            'activeAssignment' => $activeAssignment ? [
                'id' => $activeAssignment->id,
                'assignable_type' => $activeAssignment->assignable_type,
                'assignable_id' => $activeAssignment->assignable_id,
                'assignment_type' => $activeAssignment->assignment_type?->value,
                'assigned_at' => $activeAssignment->assigned_at?->toISOString(),
                'expected_return_at' => $activeAssignment->expected_return_at?->toISOString(),
                'assignable_name' => $this->resolveAssignableName($activeAssignment),
            ] : null,
            'assignmentHistory' => $device->assignments->map(fn ($a) => [
                'id' => $a->id,
                'assignable_type' => $a->assignable_type,
                'assignable_name' => $this->resolveAssignableName($a),
                'assignment_type' => $a->assignment_type?->value,
                'assigned_at' => $a->assigned_at?->toISOString(),
                'expected_return_at' => $a->expected_return_at?->toISOString(),
                'released_at' => $a->released_at?->toISOString(),
                'assigned_by' => $a->assignedBy ? $a->assignedBy->name : null,
                'released_by' => $a->releasedBy ? $a->releasedBy->name : null,
                'is_active' => $a->isActive(),
                'is_overdue' => $a->isOverdue(),
                'notes' => $a->notes,
            ]),
            'assignmentTargets' => $assignmentTargets,
            'assetLinks' => $device->activeAssetLinks->map(function ($link) use ($user, $accessibleAssetIds): array {
                $destination = $link->asset && in_array((int) $link->asset_id, $accessibleAssetIds, true)
                    ? $this->assetTechnologyDestination($user, $link->asset)
                    : [
                        'href' => null,
                        'access' => [
                            'state' => 'restricted',
                            'label' => 'Fleet & Assets technology access required',
                        ],
                    ];

                return [
                    'id' => $link->id,
                    'asset_id' => $link->asset_id,
                    'asset_name' => $link->asset?->name,
                    'asset_tag' => $link->asset?->asset_tag,
                    ...$destination,
                    'link_type' => $link->link_type?->value,
                    'linked_at' => $link->linked_at?->toISOString(),
                    'notes' => $link->notes,
                ];
            }),
            'availableAssets' => $availableAssets,
            'linkTypes' => collect(LinkType::cases())->map(fn ($t) => [
                'value' => $t->value,
                'label' => $t->label(),
            ]),
            'relationshipTypes' => collect(RelationshipType::cases())->map(fn ($t) => [
                'value' => $t->value,
                'label' => $t->label(),
            ]),
            // Other visible devices, excluding this one. Capped at 500
            // to keep the page payload small; picker becomes search-driven
            // past that volume (future PR).
            'otherDevices' => $otherDevices,
            'documents' => $device->documents->map(fn ($doc) => [
                'id' => $doc->id,
                'title' => $doc->title,
                'category' => $doc->category,
                'version' => $doc->version,
                'effective_date' => $doc->effective_date?->toDateString(),
                'expiry_date' => $doc->expiry_date?->toDateString(),
                'original_name' => $doc->original_name,
                'mime_type' => $doc->mime_type,
                'size_bytes' => $doc->size_bytes,
                'notes' => $doc->notes,
                'uploaded_at' => $doc->created_at?->toISOString(),
                'download_url' => "/security-devices/devices/{$device->id}/documents/{$doc->id}",
            ]),
            'documentHistory' => $device->documentHistory->map(fn (DeviceDocument $doc) => [
                'id' => $doc->id,
                'title' => $doc->title,
                'category' => $doc->category,
                'version' => $doc->version,
                'original_name' => $doc->original_name,
                'size_bytes' => $doc->size_bytes,
                'uploaded_at' => $doc->created_at?->toISOString(),
                'uploaded_by' => $doc->uploadedBy?->name,
                'state' => $doc->lifecycle_state,
                'status_label' => $this->documentLifecycleLabel($doc),
                'needs_attention' => $doc->lifecycle_error_code !== null,
                'storage_verified_at' => $doc->storage_verified_at?->toISOString(),
                'integrity_sha256' => $doc->content_sha256,
                'removal_requested_at' => $doc->removal_requested_at?->toISOString(),
                'removed_at' => $doc->removed_at?->toISOString(),
                'removed_by' => $doc->removedBy?->name ?? $doc->removalRequestedBy?->name,
                'removal_reason' => $doc->removal_reason ?? $doc->removal_request_reason,
                'storage_deleted_at' => $doc->storage_deleted_at?->toISOString(),
            ])->values(),
            'documentCategories' => [
                ['value' => 'manual', 'label' => 'Manual'],
                ['value' => 'install_photo', 'label' => 'Install photo'],
                ['value' => 'compliance_cert', 'label' => 'Compliance cert'],
                ['value' => 'firmware_notes', 'label' => 'Firmware notes'],
                ['value' => 'configuration', 'label' => 'Configuration'],
                ['value' => 'network_diagram', 'label' => 'Network diagram'],
                ['value' => 'other', 'label' => 'Other'],
            ],
            'recentEvents' => ! $canViewEvents ? [] : $device->events->map(fn ($e) => [
                'id' => $e->id,
                'event_type' => $e->event_type,
                'severity' => $e->severity,
                'occurred_at' => $e->occurred_at?->toISOString(),
                'source' => $e->source,
            ]),
            'maintenanceRecords' => ! $canViewMaintenance ? [] : $device->maintenanceRecords->map(fn ($m) => [
                'id' => $m->id,
                'type' => $m->type,
                'status' => $m->status,
                'description' => $m->description,
                'scheduled_for' => $m->scheduled_for?->toISOString(),
                'completed_at' => $m->completed_at?->toISOString(),
            ]),
            'relationships' => [
                'parents' => $device->parentRelationships->map(fn ($r) => [
                    'id' => $r->id,
                    'device_id' => $r->parent_device_id,
                    'device_name' => $r->parent?->name,
                    'type' => $r->relationship_type?->value,
                    'port' => $r->port,
                ]),
                'children' => $device->childRelationships->map(fn ($r) => [
                    'id' => $r->id,
                    'device_id' => $r->child_device_id,
                    'device_name' => $r->child?->name,
                    'type' => $r->relationship_type?->value,
                    'port' => $r->port,
                ]),
            ],
            'relationshipHistory' => [
                'parents' => $device->relationshipHistoryAsChild->map(fn (DeviceRelationship $relationship) => [
                    'id' => $relationship->id,
                    'device_id' => $relationship->parent_device_id,
                    'device_name' => $relationship->parent?->name,
                    'type' => $relationship->relationship_type?->value,
                    'port' => $relationship->port,
                    'created_at' => $relationship->created_at?->toISOString(),
                    'created_by' => $relationship->createdBy?->name,
                    'unlinked_at' => $relationship->unlinked_at?->toISOString(),
                    'unlinked_by' => $relationship->unlinkedBy?->name,
                    'unlink_reason' => $relationship->unlink_reason,
                ]),
                'children' => $device->relationshipHistoryAsParent->map(fn (DeviceRelationship $relationship) => [
                    'id' => $relationship->id,
                    'device_id' => $relationship->child_device_id,
                    'device_name' => $relationship->child?->name,
                    'type' => $relationship->relationship_type?->value,
                    'port' => $relationship->port,
                    'created_at' => $relationship->created_at?->toISOString(),
                    'created_by' => $relationship->createdBy?->name,
                    'unlinked_at' => $relationship->unlinked_at?->toISOString(),
                    'unlinked_by' => $relationship->unlinkedBy?->name,
                    'unlink_reason' => $relationship->unlink_reason,
                ]),
            ],
            'can' => [
                'update' => $profile['capabilities']['registry']['available'],
                'delete' => $user->canDo('securityDevices.devices.delete'),
                'assign' => $profile['capabilities']['assignment']['available'],
                'viewEvents' => $canViewEvents,
                'manageMaintenance' => $profile['capabilities']['maintenance']['available'],
            ],
        ]);
    }

    /** @return array{href: string|null, access: array{state: string, label: string}} */
    private function assetTechnologyDestination(User $viewer, Asset $asset): array
    {
        $isVehicle = strcasecmp((string) $asset->category, 'vehicle') === 0
            || $asset->categoryRef?->slug === 'vehicle';

        if ($isVehicle) {
            $href = $viewer->canDo('fleet.viewAny')
                ? "/fleet-assets/vehicles/{$asset->id}?tab=technology"
                : null;

            return [
                'href' => $href,
                'access' => [
                    'state' => $href ? 'available' : 'restricted',
                    'label' => $href
                        ? 'Open Fleet vehicle technology'
                        : 'Fleet vehicle technology access required',
                ],
            ];
        }

        return [
            'href' => "/fleet-assets/assets/{$asset->id}?tab=technology",
            'access' => [
                'state' => 'available',
                'label' => 'Open Asset technology',
            ],
        ];
    }

    private function documentLifecycleLabel(DeviceDocument $document): string
    {
        if ($document->lifecycle_error_code !== null) {
            return match ($document->lifecycle_state) {
                DeviceDocument::STATE_UPLOAD_STAGED => 'Upload needs storage recovery',
                DeviceDocument::STATE_REMOVAL_PENDING => 'Removal needs storage recovery',
                DeviceDocument::STATE_REMOVED => 'Private cleanup needs recovery',
                default => 'Storage verification needs recovery',
            };
        }

        return match ($document->lifecycle_state) {
            DeviceDocument::STATE_UPLOAD_STAGED => 'Upload recovery pending',
            DeviceDocument::STATE_REMOVAL_PENDING => 'Removal pending',
            DeviceDocument::STATE_REMOVED => $document->storage_deleted_at === null
                ? 'Removed · private cleanup pending'
                : 'Removed',
            default => 'Storage verification pending',
        };
    }

    /** @return array<int, array{key: string, label: string, count: int}> */
    private function savedViews(Builder $baseQuery): array
    {
        return [
            ['key' => 'all', 'label' => 'All devices', 'count' => (clone $baseQuery)->count()],
            ['key' => 'needs_attention', 'label' => 'Needs attention', 'count' => (clone $baseQuery)->needingAttention()->count()],
            ['key' => 'offline', 'label' => 'Offline', 'count' => (clone $baseQuery)->where('status', DeviceStatus::Offline->value)->count()],
            [
                'key' => 'unmonitored',
                'label' => 'Without monitoring',
                'count' => (clone $baseQuery)->whereDoesntHave('monitors', fn ($monitor) => $monitor->where('is_enabled', true))->count(),
            ],
            [
                'key' => 'unassigned',
                'label' => 'Unassigned',
                'count' => (clone $baseQuery)->whereDoesntHave('assignments', fn ($assignment) => $assignment->active())->count(),
            ],
            [
                'key' => 'stale',
                'label' => 'Stale or never seen',
                'count' => (clone $baseQuery)
                    ->whereNotIn('status', [DeviceStatus::Decommissioned->value, DeviceStatus::InStock->value, DeviceStatus::Lost->value])
                    ->where(fn (Builder $stale) => $stale
                        ->whereNull('last_seen_at')
                        ->orWhere('last_seen_at', '<', now()->subMinutes(15)))
                    ->count(),
            ],
        ];
    }

    private function applyInventoryView(Builder $query, string $view): void
    {
        match ($view) {
            'needs_attention' => $query->needingAttention(),
            'offline' => $query->where('status', DeviceStatus::Offline->value),
            'unmonitored' => $query->whereDoesntHave('monitors', fn ($monitor) => $monitor->where('is_enabled', true)),
            'unassigned' => $query->whereDoesntHave('assignments', fn ($assignment) => $assignment->active()),
            'stale' => $query
                ->whereNotIn('status', [DeviceStatus::Decommissioned->value, DeviceStatus::InStock->value, DeviceStatus::Lost->value])
                ->where(fn (Builder $stale) => $stale
                    ->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<', now()->subMinutes(15))),
            default => null,
        };
    }

    /**
     * Link a device to an asset. POST /security-devices/devices/{device}/asset-links
     */
    public function linkAsset(Request $request, Device $device)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.devices.update'), 403);
        $this->access->assertCanViewDevice($user, $device);

        $validated = $request->validate([
            'asset_id' => ['required', 'integer', 'exists:assets,id'],
            'link_type' => ['required', 'string', 'in:primary,installed_in,accessory'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $asset = Asset::findOrFail($validated['asset_id']);
        $this->access->assertCanUseAsset($user, $device, $asset->id);

        try {
            $this->linkService->link(
                device: $device,
                asset: $asset,
                linkedByUserId: $user->id,
                linkType: LinkType::from($validated['link_type']),
                notes: $validated['notes'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', "Device linked to asset {$asset->name}.");
    }

    /**
     * Link a device to another device (topology relationship).
     * POST /security-devices/devices/{device}/relationships
     *
     * `direction` = 'downstream' → this device is the parent, other is child.
     * `direction` = 'upstream'   → other device is the parent, this is child.
     */
    public function linkRelated(Request $request, Device $device)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.devices.update'), 403);
        $this->access->assertCanViewDevice($user, $device);

        $validated = $request->validate([
            'other_device_id' => ['required', 'integer', Rule::notIn([(int) $device->id]), 'exists:devices,id'],
            'relationship_type' => ['required', 'string', 'in:records_to,powered_by,connected_to,mounted_in,controls,uplinks_to,backs_up_to'],
            'direction' => ['required', 'string', 'in:upstream,downstream'],
            'port' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $other = Device::findOrFail($validated['other_device_id']);
        $this->access->assertCanViewDevice($user, $other);

        $this->relationshipLifecycle->create($user, $device, $other, $validated);

        return redirect()->back()->with('success', 'Relationship added.');
    }

    /**
     * Remove a topology relationship.
     * DELETE /security-devices/devices/{device}/relationships/{relationship}
     */
    public function unlinkRelated(Request $request, Device $device, DeviceRelationship $relationship)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.devices.update'), 403);
        $this->access->assertCanViewDevice($user, $device);

        abort_unless(in_array((int) $device->id, [
            (int) $relationship->parent_device_id,
            (int) $relationship->child_device_id,
        ], true), 404, 'This relationship does not involve this Device.');
        $otherDeviceId = (int) $relationship->parent_device_id === (int) $device->id
            ? (int) $relationship->child_device_id
            : (int) $relationship->parent_device_id;
        $otherDevice = Device::query()->findOrFail($otherDeviceId);
        $this->access->assertCanViewDevice($user, $otherDevice);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000', 'regex:/\S/'],
        ]);
        $this->relationshipLifecycle->unlink($user, $device, $relationship, trim($validated['reason']));

        return redirect()->back()->with('success', 'Relationship removed.');
    }

    /**
     * Unlink a device from an asset. DELETE /security-devices/devices/{device}/asset-links/{link}
     *
     * History is preserved (sets `unlinked_at`, does not delete the row).
     */
    public function unlinkAsset(Request $request, Device $device, DeviceAssetLink $link)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.devices.update'), 403);
        $this->access->assertCanViewDevice($user, $device);

        abort_unless(
            $link->device_id === $device->id,
            404,
            'This asset link does not belong to this device.',
        );
        $this->access->assertCanUseAsset($user, $device, (int) $link->asset_id);

        try {
            $this->linkService->unlink($link);
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Asset unlinked.');
    }

    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.devices.create'), 403);

        $prefillDomain = $request->string('domain')->toString();
        if (! in_array($prefillDomain, DeviceTaxonomy::domains(), true)) {
            $prefillDomain = '';
        }

        if ($request->expectsJson()) {
            return response()->json($this->deviceFormOptions());
        }

        return redirect()->route('security-devices.devices.index', array_filter([
            'dialog' => 'add-device',
            'domain' => $prefillDomain,
        ]));
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.devices.create'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['required', 'string', 'in:'.implode(',', DeviceTaxonomy::domains())],
            'category' => ['required', 'string', 'max:100'],
            'subcategory' => ['nullable', 'string', 'max:100'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'mac_address' => ['nullable', 'string', 'max:17'],
            'imei' => ['nullable', 'string', 'max:20'],
            'asset_tag' => ['nullable', 'string', 'max:100'],
            'firmware_version' => ['nullable', 'string', 'max:100'],
            'ip_address' => ['nullable', 'string', 'max:45'],
            'status' => ['nullable', 'string'],
            'provider' => ['nullable', 'string', 'max:100'],
            'location_description' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'site_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $siteId = isset($validated['site_id']) ? (int) $validated['site_id'] : null;
        unset($validated['site_id']);

        $device = $this->registry->registerDevice($validated, $user, $siteId);

        if ($request->boolean('_modal')) {
            return redirect()->back()
                ->with('success', "Device '{$device->name}' registered.");
        }

        return redirect()->route('security-devices.devices.show', $device)
            ->with('success', "Device '{$device->name}' registered.");
    }

    public function edit(Request $request, Device $device)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.devices.update'), 403);
        $this->access->assertCanViewDevice($user, $device);

        $payload = [
            'device' => $this->mapDeviceForDetail($device),
            ...$this->deviceFormOptions(),
        ];

        if ($request->expectsJson()) {
            return response()->json($payload);
        }

        return redirect()->route('security-devices.devices.show', [
            'device' => $device,
            'dialog' => 'edit-device',
        ]);
    }

    /**
     * PATCH a narrow set of OF-editable fields from the Device Detail
     * Overview tab. Separate from update() because update() enforces the
     * full taxonomy set as required; this endpoint is for quick inline
     * edits of notes / asset_tag / location_description that don't belong
     * in a full edit-page round trip.
     */
    public function patchFields(Request $request, Device $device)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.devices.update'), 403);
        $this->access->assertCanViewDevice($user, $device);

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:5000'],
            'asset_tag' => ['nullable', 'string', 'max:100'],
            'location_description' => ['nullable', 'string', 'max:255'],
            'next_service_due' => ['nullable', 'date'],
        ]);

        $this->fieldOwnership->updateFromLocal(
            $device,
            $validated,
            $user,
            null,
            null,
        );

        return redirect()->back()->with('success', 'Device updated.');
    }

    public function update(Request $request, Device $device)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.devices.update'), 403);
        $this->access->assertCanViewDevice($user, $device);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['required', 'string', 'in:'.implode(',', DeviceTaxonomy::domains())],
            'category' => ['required', 'string', 'max:100'],
            'subcategory' => ['nullable', 'string', 'max:100'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'mac_address' => ['nullable', 'string', 'max:17'],
            'imei' => ['nullable', 'string', 'max:20'],
            'asset_tag' => ['nullable', 'string', 'max:100'],
            'firmware_version' => ['nullable', 'string', 'max:100'],
            'ip_address' => ['nullable', 'string', 'max:45'],
            'status' => ['nullable', Rule::enum(DeviceStatus::class)],
            'health_status' => ['nullable', Rule::enum(HealthStatus::class)],
            'provider' => ['nullable', 'string', 'max:100'],
            'location_description' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'override_reason' => ['nullable', 'string', 'min:10', 'max:1000'],
            'override_expires_at' => [
                'nullable',
                'date',
                'after:now',
                'before_or_equal:'.now()->addYear()->toDateTimeString(),
            ],
        ]);

        $overrideReason = $validated['override_reason'] ?? null;
        $overrideExpiresAt = filled($validated['override_expires_at'] ?? null)
            ? CarbonImmutable::parse((string) $validated['override_expires_at'])
            : null;
        unset($validated['override_reason'], $validated['override_expires_at']);

        $device = $this->fieldOwnership->updateFromLocal(
            $device,
            $validated,
            $user,
            $overrideReason,
            $overrideExpiresAt,
        );

        if ($request->boolean('_modal')) {
            return redirect()->back()
                ->with('success', "Device '{$device->name}' updated.");
        }

        return redirect()->route('security-devices.devices.show', $device)
            ->with('success', "Device '{$device->name}' updated.");
    }

    public function destroy(Request $request, Device $device)
    {
        $user = $request->user();
        abort_unless($user?->canDo('securityDevices.devices.delete'), 403);
        $this->access->assertCanViewDevice($user, $device);

        $this->registry->decommission($device, $user);

        return redirect()->route('security-devices.devices.index')
            ->with('success', "Device '{$device->name}' decommissioned.");
    }

    // ── Mapping helpers ───────────────────────────────────────────
    // mapDeviceForList() and resolveAssignableName() are in MapsDevicesForList trait.

    /** @return array{taxonomy: array<string, mixed>, domains: Collection<int, array{value: string, label: string}>, statuses: Collection<int, array{value: string, label: string}>} */
    private function deviceFormOptions(): array
    {
        return [
            'taxonomy' => DeviceTaxonomy::all(),
            'domains' => collect(DeviceDomain::cases())->map(fn ($domain) => [
                'value' => $domain->value,
                'label' => $domain->label(),
            ]),
            'statuses' => collect(DeviceStatus::cases())->map(fn ($status) => [
                'value' => $status->value,
                'label' => $status->label(),
            ]),
        ];
    }

    private function mapDeviceForDetail(Device $d): array
    {
        return [
            'id' => $d->id,
            'device_uid' => $d->device_uid,
            'name' => $d->name,
            'domain' => $d->domain,
            'category' => $d->category,
            'subcategory' => $d->subcategory,
            'manufacturer' => $d->manufacturer,
            'model' => $d->model,
            'serial_number' => $d->serial_number,
            'mac_address' => $d->mac_address,
            'imei' => $d->imei,
            'asset_tag' => $d->asset_tag,
            'firmware_version' => $d->firmware_version,
            'ip_address' => $d->ip_address,
            'status' => $d->status?->value,
            'health_status' => $d->health_status?->value,
            'last_seen_at' => $d->last_seen_at?->toISOString(),
            'last_signal_at' => $d->last_signal_at?->toISOString(),
            'battery_level' => $d->battery_level,
            'battery_updated_at' => $d->battery_updated_at?->toISOString(),
            'commissioned_at' => $d->commissioned_at?->toDateString(),
            'warranty_expires_at' => $d->warranty_expires_at?->toDateString(),
            'next_service_due' => $d->next_service_due?->toDateString(),
            'expected_lifespan_months' => $d->expected_lifespan_months,
            'purchase_price' => $d->purchase_price,
            'provider' => $d->provider,
            'location_description' => $d->location_description,
            'notes' => $d->notes,
            'field_ownership' => $this->fieldOwnership->snapshot($d),
            'created_at' => $d->created_at?->toISOString(),
            'created_by' => $d->createdBy ? ['id' => $d->createdBy->id, 'name' => $d->createdBy->name] : null,
        ];
    }
}
