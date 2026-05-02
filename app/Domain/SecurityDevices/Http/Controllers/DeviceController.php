<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\SecurityDevices\Config\DeviceTaxonomy;
use App\Domain\SecurityDevices\Enums\DeviceDomain;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Enums\RelationshipType;
use App\Domain\SecurityDevices\Http\Controllers\Concerns\MapsDevicesForList;
use App\Domain\SecurityDevices\Http\Controllers\Concerns\ResolvesDeviceTenant;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceRelationship;
use App\Domain\SecurityDevices\Services\DeviceLinkService;
use App\Domain\SecurityDevices\Services\DeviceRegistryService;
use App\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DeviceController extends Controller
{
    use MapsDevicesForList;
    use ResolvesDeviceTenant;

    public function __construct(
        private readonly DeviceRegistryService $registry,
        private readonly DeviceLinkService $linkService,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.devices.view'), 403);

        $query = Device::query()
            ->with([
                'assignments' => fn ($q) => $q->active(),
            ]);

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
            'total' => Device::count(),
            'active' => Device::where('status', DeviceStatus::Active->value)->count(),
            'offline' => Device::where('status', DeviceStatus::Offline->value)->count(),
            'attention' => Device::needingAttention()->count(),
        ];

        // ── Filter options ────────────────────────────────────────

        $providers = Device::whereNotNull('provider')
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
            'filters' => $request->only(['domain', 'category', 'status', 'health', 'provider', 'assigned', 'search', 'sort', 'direction']),
            'filterOptions' => [
                'domains' => collect(DeviceDomain::cases())->map(fn ($d) => ['value' => $d->value, 'label' => $d->label()]),
                'statuses' => collect(DeviceStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
                'healthStatuses' => collect(HealthStatus::cases())->map(fn ($h) => ['value' => $h->value, 'label' => $h->label()]),
                'providers' => $providers,
            ],
        ]);
    }

    public function show(Request $request, Device $device)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.devices.view'), 403);

        $device->load([
            'assignments' => fn ($q) => $q->with(['assignedBy:id,name', 'releasedBy:id,name'])->latest('assigned_at')->limit(20),
            'activeAssetLinks.asset',
            'maintenanceRecords' => fn ($q) => $q->latest()->limit(10),
            'events' => fn ($q) => $q->latest('occurred_at')->limit(20),
            'documents',
            'parentRelationships.parent',
            'childRelationships.child',
            'groups',
            'createdBy',
        ]);

        $activeAssignment = $device->assignments->first(fn ($a) => $a->released_at === null);

        // Assignment target options for the assign dialog.
        $assignmentTargets = [
            'sites' => \App\Models\Site::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'rooms' => \App\Models\SiteRoom::query()->orderBy('name')->get(['id', 'site_id', 'name']),
            'staff' => \App\Models\User::query()->whereNotNull('approved_at')->orderBy('name')->get(['id', 'name']),
            'clients' => \App\Models\Client::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
            'vehicles' => \App\Models\Asset::query()->vehicles()->orderBy('name')->get(['id', 'name', 'registration_number']),
        ];

        return Inertia::render('security-devices/devices/show', [
            'device' => $this->mapDeviceForDetail($device),
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
            'assetLinks' => $device->activeAssetLinks->map(fn ($link) => [
                'id' => $link->id,
                'asset_id' => $link->asset_id,
                'asset_name' => $link->asset?->name,
                'asset_tag' => $link->asset?->asset_tag,
                'link_type' => $link->link_type?->value,
                'linked_at' => $link->linked_at?->toISOString(),
                'notes' => $link->notes,
            ]),
            'availableAssets' => Asset::query()
                ->orderBy('name')
                ->limit(500)
                ->get(['id', 'name', 'asset_tag'])
                ->map(fn (Asset $a) => [
                    'id' => $a->id,
                    'name' => $a->name,
                    'asset_tag' => $a->asset_tag,
                ]),
            'linkTypes' => collect(LinkType::cases())->map(fn ($t) => [
                'value' => $t->value,
                'label' => $t->label(),
            ]),
            'relationshipTypes' => collect(RelationshipType::cases())->map(fn ($t) => [
                'value' => $t->value,
                'label' => $t->label(),
            ]),
            // Other devices in this tenant, excluding this one. Capped at 500
            // to keep the page payload small; picker becomes search-driven
            // past that volume (future PR).
            'otherDevices' => Device::query()
                ->where('id', '!=', $device->id)
                ->when($device->tenant_id, fn ($q) => $q->where('tenant_id', $device->tenant_id))
                ->orderBy('name')
                ->limit(500)
                ->get(['id', 'name', 'device_uid', 'category'])
                ->map(fn (Device $d) => [
                    'id' => $d->id,
                    'name' => $d->name,
                    'device_uid' => $d->device_uid,
                    'category' => $d->category,
                ]),
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
            'documentCategories' => [
                ['value' => 'manual', 'label' => 'Manual'],
                ['value' => 'install_photo', 'label' => 'Install photo'],
                ['value' => 'compliance_cert', 'label' => 'Compliance cert'],
                ['value' => 'firmware_notes', 'label' => 'Firmware notes'],
                ['value' => 'configuration', 'label' => 'Configuration'],
                ['value' => 'network_diagram', 'label' => 'Network diagram'],
                ['value' => 'other', 'label' => 'Other'],
            ],
            'recentEvents' => $device->events->map(fn ($e) => [
                'id' => $e->id,
                'event_type' => $e->event_type,
                'severity' => $e->severity,
                'occurred_at' => $e->occurred_at?->toISOString(),
                'source' => $e->source,
            ]),
            'maintenanceRecords' => $device->maintenanceRecords->map(fn ($m) => [
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
            'groups' => $device->groups->map(fn ($g) => ['id' => $g->id, 'name' => $g->name]),
            'can' => [
                'update' => $user->canDo('securityDevices.devices.update'),
                'delete' => $user->canDo('securityDevices.devices.delete'),
                'assign' => $user->canDo('securityDevices.devices.assign'),
            ],
        ]);
    }

    /**
     * Link a device to an asset. POST /security-devices/devices/{device}/asset-links
     */
    public function linkAsset(Request $request, Device $device)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.devices.update'), 403);

        $validated = $request->validate([
            'asset_id' => ['required', 'integer', 'exists:assets,id'],
            'link_type' => ['required', 'string', 'in:primary,installed_in,accessory'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $asset = Asset::findOrFail($validated['asset_id']);

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

        $validated = $request->validate([
            'other_device_id' => ['required', 'integer', 'different:device', 'exists:devices,id'],
            'relationship_type' => ['required', 'string', 'in:records_to,powered_by,connected_to,mounted_in,controls,uplinks_to,backs_up_to'],
            'direction' => ['required', 'string', 'in:upstream,downstream'],
            'port' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $other = Device::findOrFail($validated['other_device_id']);

        // Tenant guard — prevent cross-tenant relationships.
        if ($device->tenant_id && $other->tenant_id && $device->tenant_id !== $other->tenant_id) {
            return redirect()->back()->with('error', 'Cannot link devices from different tenants.');
        }

        $parentId = $validated['direction'] === 'downstream' ? $device->id : $other->id;
        $childId = $validated['direction'] === 'downstream' ? $other->id : $device->id;

        $exists = DeviceRelationship::query()
            ->where('parent_device_id', $parentId)
            ->where('child_device_id', $childId)
            ->where('relationship_type', $validated['relationship_type'])
            ->exists();

        if ($exists) {
            return redirect()->back()->with('error', 'That relationship already exists.');
        }

        DeviceRelationship::create([
            'parent_device_id' => $parentId,
            'child_device_id' => $childId,
            'relationship_type' => $validated['relationship_type'],
            'port' => $validated['port'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

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

        // Relationship must involve this device on at least one side.
        abort_unless(
            $relationship->parent_device_id === $device->id
                || $relationship->child_device_id === $device->id,
            404,
            'This relationship does not involve this device.',
        );

        $relationship->delete();

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

        abort_unless(
            $link->device_id === $device->id,
            404,
            'This asset link does not belong to this device.',
        );

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

        return Inertia::render('security-devices/devices/create', [
            'taxonomy' => DeviceTaxonomy::all(),
            'domains' => collect(DeviceDomain::cases())->map(fn ($d) => ['value' => $d->value, 'label' => $d->label()]),
            'statuses' => collect(DeviceStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
            'prefillDomain' => $prefillDomain,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.devices.create'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['required', 'string', 'in:' . implode(',', DeviceTaxonomy::domains())],
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
        ]);

        $validated['tenant_id'] = $this->resolveDeviceTenantId($user);
        $validated['created_by_user_id'] = $user->id;

        $device = Device::create($validated);

        return redirect()->route('security-devices.devices.show', $device)
            ->with('success', "Device '{$device->name}' registered.");
    }

    public function edit(Request $request, Device $device)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.devices.update'), 403);

        return Inertia::render('security-devices/devices/edit', [
            'device' => $this->mapDeviceForDetail($device),
            'taxonomy' => DeviceTaxonomy::all(),
            'domains' => collect(DeviceDomain::cases())->map(fn ($d) => ['value' => $d->value, 'label' => $d->label()]),
            'statuses' => collect(DeviceStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
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

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:5000'],
            'asset_tag' => ['nullable', 'string', 'max:100'],
            'location_description' => ['nullable', 'string', 'max:255'],
            'next_service_due' => ['nullable', 'date'],
        ]);

        $device->update($validated);

        return redirect()->back()->with('success', 'Device updated.');
    }

    public function update(Request $request, Device $device)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.devices.update'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['required', 'string', 'in:' . implode(',', DeviceTaxonomy::domains())],
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
            'health_status' => ['nullable', 'string'],
            'provider' => ['nullable', 'string', 'max:100'],
            'location_description' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $device->update($validated);

        return redirect()->route('security-devices.devices.show', $device)
            ->with('success', "Device '{$device->name}' updated.");
    }

    public function destroy(Request $request, Device $device)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.devices.delete'), 403);

        $device->update(['status' => DeviceStatus::Decommissioned->value]);
        $device->delete(); // soft delete

        return redirect()->route('security-devices.devices.index')
            ->with('success', "Device '{$device->name}' decommissioned.");
    }

    // ── Mapping helpers ───────────────────────────────────────────
    // mapDeviceForList() and resolveAssignableName() are in MapsDevicesForList trait.

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
            'external_ref' => $d->external_ref,
            'config' => $d->config,
            'meta' => $d->meta,
            'latitude' => $d->latitude,
            'longitude' => $d->longitude,
            'location_description' => $d->location_description,
            'notes' => $d->notes,
            'created_at' => $d->created_at?->toISOString(),
            'created_by' => $d->createdBy ? ['id' => $d->createdBy->id, 'name' => $d->createdBy->name] : null,
        ];
    }

}
