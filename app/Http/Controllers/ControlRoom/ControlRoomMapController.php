<?php

namespace App\Http\Controllers\ControlRoom;

use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Presenters\TrackingWorkspacePresenter;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use App\Models\AssetGeofence;
use App\Models\ControlRoomAlert;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomDeviceVisibilityService;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class ControlRoomMapController extends Controller
{
    private const MAP_TYPES = [
        'personal_tracker',
        'vehicle_tracker',
        'asset_tracker',
    ];

    private const MAP_STATUSES = ['online', 'offline', 'unknown'];

    /** @var list<string> */
    private const SITE_BYPASS_PERMISSIONS = ['reports.viewAny'];

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly ControlRoomDeviceVisibilityService $projectionVisibility,
        private readonly SecurityDevicesAccessService $deviceAccess,
        private readonly TrackingWorkspacePresenter $trackingPresenter,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.viewAny'), 403);

        $accessibleSiteIds = $this->siteAccess->accessibleSiteIds(
            $user,
            self::SITE_BYPASS_PERMISSIONS,
        );
        $selectedSiteId = $this->selectedSiteId($request);
        if ($selectedSiteId !== null) {
            abort_unless(
                in_array($selectedSiteId, $accessibleSiteIds, true),
                403,
                UserSiteAccessService::DEFAULT_MESSAGE,
            );
        }

        $typeFilter = $this->allowlistedFilter($request->input('type'), self::MAP_TYPES);
        $statusFilter = $this->allowlistedFilter($request->input('status'), self::MAP_STATUSES);
        $alertOnly = $request->boolean('alert_only');

        $tracking = $this->trackingRows($user);
        $siteTracking = $tracking['rows']
            ->when(
                $selectedSiteId !== null,
                fn (Collection $rows): Collection => $rows
                    ->filter(fn (array $row): bool => (int) ($row['siteId'] ?? 0) === $selectedSiteId)
                    ->values(),
            );
        $safePositions = $siteTracking
            ->filter(fn (array $row): bool => is_array($row['location'] ?? null))
            ->values();
        $safePositionByDevice = $safePositions->keyBy('id');

        $alerts = $this->alerts($user, $selectedSiteId);
        $alertCanonicalIds = $alerts
            ->map(fn (ControlRoomAlert $alert): ?int => is_numeric($alert->device?->canonical_device_id)
                ? (int) $alert->device->canonical_device_id
                : null)
            ->filter()
            ->unique()
            ->values();

        $mapDevices = $safePositions
            ->map(fn (array $row): array => $this->mapDevice($row))
            ->when(
                $typeFilter !== null,
                fn (Collection $rows): Collection => $rows
                    ->where('type', $typeFilter)
                    ->values(),
            )
            ->when(
                $statusFilter !== null,
                fn (Collection $rows): Collection => $rows
                    ->where('status', $statusFilter)
                    ->values(),
            )
            ->when(
                $alertOnly,
                fn (Collection $rows): Collection => $rows
                    ->whereIn('id', $alertCanonicalIds)
                    ->values(),
            );

        $allSites = $this->projectionVisibility->visibleSites($user)
            ->orderBy('name')
            ->get(['id', 'name']);
        $sites = $this->mapSites($accessibleSiteIds, $selectedSiteId);
        $geofences = $this->mapGeofences(
            $accessibleSiteIds,
            $selectedSiteId,
            $safePositions,
        );
        $activeAlertCount = $this->scopedAlerts($user, $selectedSiteId)->actionable()->count();

        return Inertia::render('control-room/map', [
            'devices' => $mapDevices,
            'sites' => $sites,
            'geofences' => $geofences,
            'alerts' => $alerts->map(function (ControlRoomAlert $alert) use ($safePositionByDevice): array {
                $canonicalId = is_numeric($alert->device?->canonical_device_id)
                    ? (int) $alert->device->canonical_device_id
                    : null;
                $safePosition = $canonicalId ? $safePositionByDevice->get($canonicalId) : null;
                $location = is_array($safePosition) ? ($safePosition['location'] ?? null) : null;

                return [
                    'id' => $alert->id,
                    'alert_type' => $alert->alert_type,
                    'severity' => $alert->severity,
                    'status' => $alert->status,
                    'triggered_at' => $alert->triggered_at?->toISOString(),
                    'device_id' => is_array($safePosition) ? $canonicalId : null,
                    'site_id' => $alert->site_id,
                    'latitude' => is_array($location) ? (float) $location['latitude'] : null,
                    'longitude' => is_array($location) ? (float) $location['longitude'] : null,
                    'detail_url' => "/control-room/alerts/{$alert->id}",
                ];
            })->values(),
            'all_sites' => $allSites->map(fn (Site $site): array => [
                'id' => $site->id,
                'name' => $site->name,
            ])->values(),
            'stats' => [
                'total_devices' => $safePositions->count(),
                'online' => $safePositions
                    ->filter(fn (array $row): bool => $this->mapStatus($row['status'] ?? null) === 'online')
                    ->count(),
                'offline' => $safePositions
                    ->filter(fn (array $row): bool => $this->mapStatus($row['status'] ?? null) === 'offline')
                    ->count(),
                'active_alerts' => $activeAlertCount,
                'location_restricted' => $siteTracking
                    ->reject(fn (array $row): bool => is_array($row['location'] ?? null))
                    ->count(),
            ],
            'location_boundary' => [
                'position_access' => $tracking['position_access'],
                'title' => 'Location access follows purpose',
                'description' => $tracking['position_access']
                    ? 'Positions come from the canonical Tracking workspace. Current Site access, source permissions, purpose, consent and collection state must all agree.'
                    : 'Control Room access does not grant Security & Devices identity or exact tracker position. Ask an authorised manager if this operational view is required.',
                'canonical_url' => $tracking['position_access'] ? '/security-devices/tracking' : null,
            ],
            'filters' => [
                'site_id' => $selectedSiteId === null ? null : (string) $selectedSiteId,
                'type' => $typeFilter,
                'status' => $statusFilter,
                'alert_only' => $alertOnly ? '1' : null,
            ],
        ]);
    }

    /**
     * @return array{position_access: bool, rows: Collection<int, array<string, mixed>>}
     */
    private function trackingRows(User $user): array
    {
        if (! $this->projectionVisibility->canViewCanonicalDevices($user)) {
            return [
                'position_access' => false,
                'rows' => collect(),
            ];
        }

        $payload = $this->trackingPresenter->present(
            $user,
            $this->deviceAccess->visibleDevices($user)->where('devices.domain', 'tracking'),
            [
                'key' => 'overview',
                'label' => 'Overview',
                'description' => 'Current governed tracker positions.',
            ],
        );

        $rows = collect(data_get($payload, 'activeTab.devices', []));
        $personalDeviceIds = $rows
            ->where('group', 'personal-safety')
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->values();
        $personalAssignments = $personalDeviceIds->isEmpty()
            ? collect()
            : DeviceAssignment::query()
                ->active()
                ->whereIn('device_id', $personalDeviceIds)
                ->whereIn('assignable_type', [
                    DeviceAssignment::TARGET_CLIENT,
                    DeviceAssignment::TARGET_STAFF,
                ])
                ->get([
                    'device_id',
                    'tracking_purpose',
                    'authority_basis',
                    'access_audience',
                    'collection_stopped_at',
                ])
                ->groupBy('device_id');

        $rows = $rows->map(function (array $row) use ($personalAssignments): array {
            if (($row['group'] ?? null) !== 'personal-safety') {
                return $row;
            }

            $assignments = $personalAssignments->get((int) $row['id'], collect());
            if ($assignments->isEmpty()) {
                return $row;
            }

            $destinationAllowed = $assignments->every(function (DeviceAssignment $assignment): bool {
                $audience = collect($assignment->access_audience ?? [])
                    ->filter(fn (mixed $value): bool => is_string($value))
                    ->map(fn (string $value): string => strtolower(trim($value)));

                return $assignment->collection_stopped_at === null
                    && trim((string) $assignment->tracking_purpose) !== ''
                    && trim((string) $assignment->authority_basis) !== ''
                    && $audience->contains('control_room');
            });

            if ($destinationAllowed) {
                return $row;
            }

            return [
                ...$row,
                'location' => null,
                'privacy' => [
                    'state' => 'restricted',
                    'basis' => 'none',
                    'locationAllowed' => false,
                    'reason' => 'The assignment purpose or approved audience does not allow Control Room location access.',
                    'expiresAt' => null,
                ],
                'historyHref' => null,
            ];
        })->values();

        return [
            'position_access' => true,
            'rows' => $rows,
        ];
    }

    /** @return array<string, mixed> */
    private function mapDevice(array $row): array
    {
        $location = $row['location'];
        $type = match ($row['group'] ?? null) {
            'personal-safety' => 'personal_tracker',
            'fleet' => 'vehicle_tracker',
            default => 'asset_tracker',
        };

        return [
            'id' => (int) $row['id'],
            'device_uid' => $row['deviceUid'] ?? null,
            'name' => $row['name'],
            'type' => $type,
            'type_label' => match ($type) {
                'personal_tracker' => 'Personal safety tracker',
                'vehicle_tracker' => 'Fleet tracker',
                default => 'Asset tracker',
            },
            'status' => $this->mapStatus($row['status'] ?? null),
            'health_status' => $row['health'] ?? null,
            'latitude' => (float) $location['latitude'],
            'longitude' => (float) $location['longitude'],
            'battery_level' => $row['battery'] ?? null,
            'last_seen_at' => $location['observedAt'] ?? $row['lastSeenAt'] ?? null,
            'position_source' => $location['source'] ?? 'canonical_device',
            'site_id' => $row['siteId'] ?? null,
            'context_label' => data_get($row, 'person.displayName') ?? data_get($row, 'asset.name'),
            'manufacturer' => $row['manufacturer'] ?? null,
            'model' => $row['model'] ?? null,
            'identity_source' => 'canonical',
            'privacy_state' => data_get($row, 'privacy.state'),
            'privacy_basis' => data_get($row, 'privacy.basis'),
            'detail_url' => $row['deviceHref'],
        ];
    }

    private function mapStatus(mixed $status): string
    {
        return match ($status) {
            'active', 'degraded' => 'online',
            'offline' => 'offline',
            default => 'unknown',
        };
    }

    /** @return Collection<int, ControlRoomAlert> */
    private function alerts(User $user, ?int $selectedSiteId): Collection
    {
        return $this->scopedAlerts($user, $selectedSiteId)
            ->actionable()
            ->where(function (Builder $query): void {
                $query->whereNotNull('device_id')->orWhereNotNull('site_id');
            })
            ->with(['device:id,canonical_device_id'])
            ->latest('triggered_at')
            ->limit(200)
            ->get();
    }

    private function scopedAlerts(User $user, ?int $selectedSiteId): Builder
    {
        $query = ControlRoomAlert::query();
        $this->siteAccess->applyAlertScope($query, $user, self::SITE_BYPASS_PERMISSIONS);
        if ($selectedSiteId !== null) {
            $this->siteAccess->applyAlertSiteScopeForSiteIds($query, [$selectedSiteId]);
        }

        return $query;
    }

    /** @param list<int> $accessibleSiteIds */
    private function mapSites(array $accessibleSiteIds, ?int $selectedSiteId): Collection
    {
        if ($accessibleSiteIds === []) {
            return collect();
        }

        return Site::query()
            ->active()
            ->notArchived()
            ->whereNull('archived_at')
            ->whereIn('id', $accessibleSiteIds)
            ->when($selectedSiteId !== null, fn (Builder $query): Builder => $query->whereKey($selectedSiteId))
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get()
            ->map(fn (Site $site): array => [
                'id' => $site->id,
                'name' => $site->name,
                'address' => trim(implode(', ', array_filter([
                    $site->address_line_1,
                    $site->suburb,
                    $site->city,
                ]))),
                'latitude' => (float) $site->latitude,
                'longitude' => (float) $site->longitude,
            ])->values();
    }

    /**
     * @param  list<int>  $accessibleSiteIds
     * @param  Collection<int, array<string, mixed>>  $safePositions
     */
    private function mapGeofences(
        array $accessibleSiteIds,
        ?int $selectedSiteId,
        Collection $safePositions,
    ): Collection {
        if ($accessibleSiteIds === []) {
            return collect();
        }

        $safeAssetIds = $safePositions
            ->pluck('asset.id')
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $query = AssetGeofence::query()
            ->where('is_active', true)
            ->where(function (Builder $scope) use ($accessibleSiteIds, $safeAssetIds): void {
                $scope->where(function (Builder $siteOnly) use ($accessibleSiteIds): void {
                    $siteOnly->whereNull('asset_id')->whereIn('site_id', $accessibleSiteIds);
                });

                if ($safeAssetIds !== []) {
                    $scope->orWhere(function (Builder $assetBound) use ($accessibleSiteIds, $safeAssetIds): void {
                        $assetBound->whereIn('asset_id', $safeAssetIds)
                            ->where(function (Builder $site) use ($accessibleSiteIds): void {
                                $site->whereIn('site_id', $accessibleSiteIds)
                                    ->orWhere(function (Builder $assetFallback) use ($accessibleSiteIds): void {
                                        $assetFallback->whereNull('site_id')
                                            ->whereHas('asset', function (Builder $asset) use ($accessibleSiteIds): void {
                                                $asset->whereIn('site_id', $accessibleSiteIds)
                                                    ->orWhereIn('home_site_id', $accessibleSiteIds);
                                            });
                                    });
                            });
                    });
                }
            });

        if ($selectedSiteId !== null) {
            $query->where(function (Builder $selected) use ($selectedSiteId): void {
                $selected->where('site_id', $selectedSiteId)
                    ->orWhere(function (Builder $assetFallback) use ($selectedSiteId): void {
                        $assetFallback->whereNull('site_id')
                            ->whereHas('asset', fn (Builder $asset): Builder => $asset
                                ->where('site_id', $selectedSiteId)
                                ->orWhere('home_site_id', $selectedSiteId));
                    });
            });
        }

        return $query->get()->map(fn (AssetGeofence $geofence): array => [
            'id' => $geofence->id,
            'name' => $geofence->name,
            'type' => $geofence->type,
            'shape' => $geofence->shape,
            'breach_type' => $geofence->breach_type,
            'site_id' => $geofence->site_id,
        ])->values();
    }

    private function selectedSiteId(Request $request): ?int
    {
        $value = $request->input('site_id');
        if ($value === null || $value === '' || $value === 'all') {
            return null;
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT);
        abort_unless($validated !== false && (int) $validated > 0, 422, 'Choose a valid Site.');

        return (int) $validated;
    }

    /** @param list<string> $allowed */
    private function allowlistedFilter(mixed $value, array $allowed): ?string
    {
        if (! is_string($value) || $value === '' || $value === 'all') {
            return null;
        }

        return in_array($value, $allowed, true) ? $value : null;
    }
}
