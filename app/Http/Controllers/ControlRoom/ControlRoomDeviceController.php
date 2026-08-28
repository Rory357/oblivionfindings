<?php

namespace App\Http\Controllers\ControlRoom;

use App\Domain\SecurityDevices\Services\PersonalTrackingPrivacyService;
use App\Http\Controllers\Controller;
use App\Models\ControlRoom\Device;
use App\Models\ControlRoomAlert;
use App\Services\ControlRoom\ControlRoomAlertAccessService;
use App\Services\ControlRoom\ControlRoomDevicePresenter;
use App\Services\ControlRoom\ControlRoomDeviceVisibilityService;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ControlRoomDeviceController extends Controller
{
    public function __construct(
        private readonly ControlRoomDeviceVisibilityService $visibility,
        private readonly ControlRoomDevicePresenter $presenter,
        private readonly UserSiteAccessService $siteAccess,
        private readonly ControlRoomAlertAccessService $alertAccess,
    ) {}

    /**
     * List all devices with filtering and stats.
     * Enriches each device with canonical Security & Devices data where linked.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.viewAny'), 403);

        $filters = $request->validate([
            'type' => ['nullable', 'string', Rule::in(array_keys(Device::types()))],
            'activity' => ['nullable', 'string', Rule::in(['recent', 'quiet', 'never'])],
            'site_id' => ['nullable', 'integer', 'min:1'],
            'linkage' => ['nullable', 'string', Rule::in(['linked', 'unlinked'])],
        ]);
        $selectedSiteId = isset($filters['site_id'])
            ? (int) $filters['site_id']
            : null;
        if ($selectedSiteId) {
            $this->visibility->assertCanFilterSite($user, $selectedSiteId);
        }
        $canViewCanonicalDevices = $this->visibility->canViewCanonicalDevices($user);
        if (isset($filters['linkage']) && ! $canViewCanonicalDevices) {
            abort(403, UserSiteAccessService::DEFAULT_MESSAGE);
        }
        $recentSince = now()->subDay();

        $baseQuery = Device::query();
        $this->visibility->applyScope(
            $baseQuery,
            $user,
            $selectedSiteId ? [$selectedSiteId] : null,
        );

        $query = (clone $baseQuery)
            ->with([
                'signalSource:id,name,status',
                'canonicalDevice' => fn ($canonical) => $this->visibility
                    ->applyCanonicalDeviceScope($canonical, $user)
                    ->select([
                        'id',
                        'device_uid',
                        'name',
                        'domain',
                        'category',
                        'subcategory',
                        'status',
                        'health_status',
                        'manufacturer',
                        'model',
                        'battery_level',
                        'last_seen_at',
                    ]),
            ]);

        // Join site for site name.
        $query->leftJoin('sites', 'control_room_devices.site_id', '=', 'sites.id')
            ->select('control_room_devices.*', 'sites.name as site_name');

        if (isset($filters['type'])) {
            $query->where('control_room_devices.type', $filters['type']);
        }
        if (($filters['activity'] ?? null) === 'recent') {
            $query->where('control_room_devices.last_signal_at', '>=', $recentSince);
        }
        if (($filters['activity'] ?? null) === 'quiet') {
            $query->whereNotNull('control_room_devices.last_signal_at')
                ->where('control_room_devices.last_signal_at', '<', $recentSince);
        }
        if (($filters['activity'] ?? null) === 'never') {
            $query->whereNull('control_room_devices.last_signal_at');
        }
        if (($filters['linkage'] ?? null) === 'linked') {
            $query->whereNotNull('control_room_devices.canonical_device_id');
        }
        if (($filters['linkage'] ?? null) === 'unlinked') {
            $query->whereNull('control_room_devices.canonical_device_id');
        }

        $query->orderByDesc('control_room_devices.last_signal_at')
            ->orderBy('control_room_devices.id');

        $devices = $query->paginate(48)->through(fn (Device $device): array => $this->presenter->list(
            $device,
            $this->visibility->canViewPersonalLocation($user, $device),
        ));

        // Statistics follow the same visible Site and selected-Site boundary.
        $totalSources = (clone $baseQuery)->count();
        $active24h = (clone $baseQuery)
            ->where('last_signal_at', '>=', $recentSince)
            ->count();
        $canonicalLinked = $canViewCanonicalDevices
            ? (clone $baseQuery)->whereNotNull('canonical_device_id')->count()
            : null;
        $reconciliationNeeded = $canViewCanonicalDevices
            ? (clone $baseQuery)->whereNull('canonical_device_id')->count()
            : null;

        $sites = $this->visibility->visibleSites($user)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name]);

        return Inertia::render('control-room/devices/index', [
            'devices' => $devices,
            'stats' => [
                'signal_sources' => $totalSources,
                'active_24h' => $active24h,
                'canonical_linked' => $canonicalLinked,
                'reconciliation_needed' => $reconciliationNeeded,
            ],
            'filters' => [
                'type' => $filters['type'] ?? '',
                'activity' => $filters['activity'] ?? '',
                'site_id' => isset($filters['site_id']) ? (string) $filters['site_id'] : '',
                'linkage' => $filters['linkage'] ?? '',
            ],
            'sites' => $sites,
            'device_types' => Device::types(),
            'can' => [
                'view_canonical_devices' => $canViewCanonicalDevices,
            ],
            'canonicalIndexUrl' => $canViewCanonicalDevices ? '/security-devices/devices' : null,
        ]);
    }

    /**
     * Device detail with recent signals and linked alerts.
     * Enriches with canonical Security & Devices data where linked.
     */
    public function show(Request $request, Device $device)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.viewAny'), 403);
        $this->visibility->assertCanView($user, $device);

        $device->load([
            'signalSource:id,name,status,vendor',
            'canonicalDevice' => fn ($canonical) => $this->visibility
                ->applyCanonicalDeviceScope($canonical, $user)
                ->select([
                    'id',
                    'device_uid',
                    'name',
                    'domain',
                    'category',
                    'subcategory',
                    'status',
                    'health_status',
                    'manufacturer',
                    'model',
                    'battery_level',
                    'last_seen_at',
                ]),
        ]);
        $canViewPersonalLocation = $this->visibility->canViewPersonalLocation($user, $device);
        $isPersonalTracker = $device->type === Device::TYPE_PERSONAL_TRACKER
            || ($device->canonicalDevice?->domain === 'tracking' && $device->client_id !== null);
        $personalAssignment = null;
        $visibleClient = null;
        $trackingPrivacy = app(PersonalTrackingPrivacyService::class);
        if ($isPersonalTracker && $canViewPersonalLocation && $device->client_id) {
            $visibleClient = $this->visibility->visibleClient($user, (int) $device->client_id);
            $personalAssignment = $visibleClient
                ? $trackingPrivacy->authorisedClientAssignment($visibleClient)
                : null;
            $canViewPersonalLocation = $personalAssignment !== null;
        }

        $site = $device->site_id
            ? $this->visibility->visibleSites($user)->whereKey($device->site_id)->first(['id', 'name'])
            : null;
        $client = $this->visibility->visibleClient($user, $device->client_id ? (int) $device->client_id : null);
        $asset = $this->visibility->visibleAsset($user, $device->asset_id ? (int) $device->asset_id : null);
        $visibleSiteIds = $this->visibility->accessibleSiteIds($user);

        // Recent signals (last 50).
        $signals = $device->signals()
            ->with([
                'alert' => function ($query) use ($user): void {
                    $this->alertAccess->applyReadableScope($query->getQuery(), $user);
                    $query->select(['id', 'reference_number']);
                },
                'correlatedAlert' => function ($query) use ($user): void {
                    $this->alertAccess->applyReadableScope($query->getQuery(), $user);
                    $query->select(['id', 'reference_number']);
                },
            ])
            ->when($isPersonalTracker && ! $canViewPersonalLocation, fn ($query) => $query->whereRaw('1 = 0'))
            ->when(
                $isPersonalTracker && $personalAssignment,
                fn ($query) => $query->where(
                    'occurred_at',
                    '>=',
                    $personalAssignment->collection_started_at ?? $personalAssignment->assigned_at,
                ),
            )
            ->when(
                ! $isPersonalTracker && $device->canonical_device_id,
                function ($query) use ($device, $visibleSiteIds): void {
                    $query->whereExists(function ($assignments) use ($device, $visibleSiteIds): void {
                        $assignments->selectRaw('1')
                            ->from('device_assignments')
                            ->where('device_assignments.device_id', $device->canonical_device_id)
                            ->whereIn('device_assignments.custody_site_id', $visibleSiteIds)
                            ->whereColumn('device_assignments.assigned_at', '<=', 'control_room_signals.occurred_at')
                            ->where(function ($window): void {
                                $window->whereNull('device_assignments.released_at')
                                    ->orWhereColumn('device_assignments.released_at', '>', 'control_room_signals.occurred_at');
                            })
                            ->where(function ($site): void {
                                $site->whereNull('control_room_signals.site_id')
                                    ->orWhereColumn('device_assignments.custody_site_id', 'control_room_signals.site_id');
                            });
                    });
                },
            )
            ->where(function ($query) use ($visibleSiteIds): void {
                $query->whereNull('site_id');
                if ($visibleSiteIds !== []) {
                    $query->orWhereIn('site_id', $visibleSiteIds);
                }
            })
            ->orderByDesc('occurred_at')
            ->limit(50)
            ->get()
            ->map(fn ($signal): array => $this->presenter->signal($signal));

        // Linked alerts (last 20).
        $alertQuery = ControlRoomAlert::query()->where('device_id', $device->id);
        $this->siteAccess->applyAlertSiteScopeForSiteIds($alertQuery, $visibleSiteIds);
        $this->alertAccess->applyControlledMedicationContentScope($alertQuery, $user);
        $alerts = $alertQuery
            ->when($isPersonalTracker && ! $canViewPersonalLocation, fn ($query) => $query->whereRaw('1 = 0'))
            ->when(
                $isPersonalTracker && $personalAssignment,
                fn ($query) => $query->where(
                    'triggered_at',
                    '>=',
                    $personalAssignment->collection_started_at ?? $personalAssignment->assigned_at,
                ),
            )
            ->orderByDesc('triggered_at')
            ->limit(20)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'reference_number' => $a->reference_number,
                'alert_type' => $a->alert_type,
                'severity' => $a->severity,
                'status' => $a->status,
                'triggered_at' => $a->triggered_at?->toISOString(),
            ]);

        // Re-check after reading the governed rows. A withdrawal or transfer
        // racing this request must clear the prepared location response rather
        // than return data authorised from a stale in-memory assignment.
        if ($isPersonalTracker) {
            $currentAssignment = $visibleClient
                ? $trackingPrivacy->authorisedClientAssignment($visibleClient)
                : null;
            $canViewPersonalLocation = $personalAssignment
                && $currentAssignment
                && (int) $currentAssignment->id === (int) $personalAssignment->id
                && (int) $currentAssignment->device_id === (int) $personalAssignment->device_id
                && (int) $currentAssignment->consent_id === (int) $personalAssignment->consent_id
                && $this->visibility->canViewPersonalLocation($user, $device);
            if (! $canViewPersonalLocation) {
                $signals = collect();
                $alerts = collect();
            }
        }

        return Inertia::render('control-room/devices/show', [
            'device' => [
                ...$this->presenter->detail($device, $canViewPersonalLocation),
                'signal_source' => $device->signalSource ? [
                    'id' => $device->signalSource->id,
                    'name' => $device->signalSource->name,
                    'status' => $device->signalSource->status,
                    'vendor' => $device->signalSource->vendor,
                ] : null,
                'site' => $site ? ['id' => $site->id, 'name' => $site->name] : null,
                'client' => $client ? [
                    'id' => $client->id,
                    'name' => trim($client->first_name.' '.$client->last_name),
                ] : null,
                'asset' => $asset ? [
                    'id' => $asset->id,
                    'name' => $asset->name,
                    'asset_tag' => $asset->asset_tag,
                ] : null,
            ],
            'signals' => $signals,
            'alerts' => $alerts,
        ]);
    }
}
