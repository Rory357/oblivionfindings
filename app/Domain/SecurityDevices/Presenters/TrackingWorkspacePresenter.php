<?php

namespace App\Domain\SecurityDevices\Presenters;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\AssetGeofence;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\FleetTelemetryEvent;
use App\Models\LoneWorkerSession;
use App\Models\User;
use App\Services\ConsentValidationService;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class TrackingWorkspacePresenter
{
    private const DEVICE_LIMIT = 100;

    private const HISTORY_LIMIT = 100;

    private const LIVE_LONE_WORKER_STATES = ['active', 'overdue', 'emergency'];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function present(User $viewer, Builder $trackingScope, array $activeTab): array
    {
        $permissions = $this->permissions($viewer);
        $restricted = $this->restricted($viewer, $activeTab, $permissions);
        $deviceCandidates = (clone $trackingScope)
            ->with([
                'assignments' => fn ($query) => $query
                    ->active()
                    ->with('consent.consentType'),
                'activeAssetLinks.asset' => fn ($query) => $query->with([
                    'site:id,name',
                    'homeSite:id,name',
                    'client:id,site_id,first_name,preferred_name',
                    'categoryRef:id,slug',
                    'fleetState',
                ]),
            ])
            ->orderBy('name')
            ->limit(self::DEVICE_LIMIT + 1)
            ->get();
        $inventoryTruncated = $deviceCandidates->count() > self::DEVICE_LIMIT;
        $devices = $deviceCandidates->take(self::DEVICE_LIMIT)->values();

        $context = $this->context($viewer, $devices, $permissions);
        $mapped = $devices
            ->map(fn (Device $device) => $this->mapDevice($viewer, $device, $context, $permissions))
            ->filter()
            ->values();
        $overview = $this->overview($mapped);

        $activeDevices = match ($activeTab['key']) {
            'personal-safety' => $mapped->where('group', 'personal-safety')->values(),
            'fleet' => $mapped->where('group', 'fleet')->values(),
            'assets' => $mapped->where('group', 'assets')->values(),
            'geofences', 'history' => collect(),
            default => $mapped,
        };
        $geofences = ! $restricted && $activeTab['key'] === 'geofences'
            ? $this->geofences($viewer, $mapped, $context['assets'], $permissions)
            : collect();
        $history = ! $restricted && $activeTab['key'] === 'history'
            ? $this->history($mapped)
            : collect();

        if ($restricted) {
            $activeDevices = collect();
            $geofences = collect();
            $history = collect();
        }

        $inventoryTotal = match ($activeTab['key']) {
            'geofences' => $geofences->count(),
            'history' => $history->count(),
            default => $activeDevices->count(),
        };

        return [
            'permissions' => $permissions,
            'boundary' => [
                'title' => 'Location access follows purpose',
                'description' => 'Technical device access does not grant personal or operational location access. Client consent, lone-worker purpose, Fleet/Asset permission, current source policy, and retention must all agree.',
                'retentionDays' => $this->retentionDays(),
            ],
            'overview' => $overview,
            'activeTab' => [
                'key' => $activeTab['key'],
                'label' => $activeTab['label'],
                'description' => $activeTab['description'],
                'restricted' => $restricted,
                'inventoryTotal' => $inventoryTotal,
                'inventoryShown' => $activeDevices->count(),
                'inventoryTruncated' => $inventoryTruncated,
                'devices' => $activeDevices,
                'markers' => $restricted ? [] : $this->markers($activeDevices, $history),
                'geofences' => $geofences,
                'history' => $history,
                'retentionDays' => $this->retentionDays(),
            ],
        ];
    }

    private function permissions(User $viewer): array
    {
        $canViewAssets = $viewer->canDo('assets.viewAny')
            || $viewer->canDo('assets.viewAssigned');

        return [
            'personalSafety' => $viewer->canDo('hazards.view')
                || $viewer->canDo('fleet.viewAny')
                || $canViewAssets,
            'fleet' => $viewer->canDo('fleet.viewAny'),
            'assets' => $canViewAssets,
            'telemetry' => $viewer->canDo('assets.telemetry.view'),
            'geofences' => $viewer->canDo('fleet.viewAny')
                || $viewer->canDo('assets.viewAny'),
        ];
    }

    private function restricted(User $viewer, array $tab, array $permissions): bool
    {
        if (isset($tab['requiredPermission'])
            && ! $viewer->canDo($tab['requiredPermission'])) {
            return true;
        }

        if (isset($tab['requiredAnyPermission'])
            && ! collect($tab['requiredAnyPermission'])->contains(
                fn (string $permission): bool => $viewer->canDo($permission),
            )) {
            return true;
        }

        return $tab['key'] === 'history'
            && ! ($permissions['personalSafety'] || $permissions['fleet'] || $permissions['assets']);
    }

    /** @param Collection<int, Device> $devices */
    private function context(User $viewer, Collection $devices, array $permissions): array
    {
        $assignments = $devices->flatMap->assignments;
        $clientIds = $assignments
            ->where('assignable_type', DeviceAssignment::TARGET_CLIENT)
            ->pluck('assignable_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
        $staffIds = $assignments
            ->where('assignable_type', DeviceAssignment::TARGET_STAFF)
            ->pluck('assignable_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
        $assetIds = collect($this->access->accessibleAssetIds($viewer));

        $clients = $clientIds->isEmpty()
            ? collect()
            : $this->access->assignableClients($viewer)
                ->whereIn('id', $clientIds)
                ->keyBy('id');

        $staff = collect();
        if ($permissions['personalSafety'] && $viewer->canDo('hazards.view') && $staffIds->isNotEmpty()) {
            $staff = $this->access->assignableStaff($viewer)
                ->whereKey($staffIds)
                ->get(['id', 'name'])
                ->keyBy('id');
        }
        $staffProfileHrefs = $this->staffProfileHrefs($viewer, $staff->keys());

        $assets = $assetIds->isEmpty()
            ? collect()
            : Asset::query()
                ->whereKey($assetIds)
                ->with([
                    'site:id,name',
                    'homeSite:id,name',
                    'client:id,site_id,first_name,preferred_name',
                    'categoryRef:id,slug',
                    'fleetState',
                ])
                ->get()
                ->keyBy('id');

        $sessions = $staff->isEmpty()
            ? collect()
            : LoneWorkerSession::query()
                ->whereIn('user_id', $staff->keys())
                ->whereIn('status', self::LIVE_LONE_WORKER_STATES)
                ->with([
                    'user:id,name',
                    'site:id,name',
                    'client:id,site_id',
                    'client.site:id',
                    'shift:id,user_id,client_id,site_id',
                    'shift.site:id',
                    'shift.client:id,site_id',
                    'shift.client.site:id',
                ])
                ->latest('started_at')
                ->get()
                ->filter(fn (LoneWorkerSession $session): bool => $this->sessionAccessible($viewer, $session))
                ->unique('user_id')
                ->keyBy('user_id');

        return compact('clients', 'staff', 'staffProfileHrefs', 'assets', 'sessions');
    }

    private function sessionAccessible(User $viewer, LoneWorkerSession $session): bool
    {
        if ($session->client && Gate::forUser($viewer)->denies('view', $session->client)) {
            return false;
        }

        if ($session->shift) {
            if (($session->shift->user_id && (int) $session->shift->user_id !== (int) $session->user_id)
                || ($session->client_id && $session->shift->client_id && (int) $session->shift->client_id !== (int) $session->client_id)) {
                return false;
            }
        }

        $directSiteId = $session->site_id ? (int) $session->site_id : null;
        $clientSiteId = $session->client?->site_id ? (int) $session->client->site_id : null;
        $shiftSiteId = $session->shift?->site_id
            ? (int) $session->shift->site_id
            : ($session->shift?->client?->site_id ? (int) $session->shift->client->site_id : null);
        $authoritativeSiteId = $directSiteId ?? $clientSiteId ?? $shiftSiteId;

        if (! $authoritativeSiteId
            || ($directSiteId && $clientSiteId && $directSiteId !== $clientSiteId)
            || ($directSiteId && $shiftSiteId && $directSiteId !== $shiftSiteId)) {
            return false;
        }

        return in_array($authoritativeSiteId, $this->access->accessibleSiteIds($viewer), true);
    }

    private function mapDevice(
        User $viewer,
        Device $device,
        array $context,
        array $permissions,
    ): ?array {
        $assignment = $device->assignments->first();
        $asset = $this->assetForDevice($device, $assignment, $context['assets']);
        $group = $this->group($device, $assignment, $asset);
        $client = $assignment?->assignable_type === DeviceAssignment::TARGET_CLIENT
            ? $context['clients']->get($assignment->assignable_id)
            : null;
        $staff = $assignment?->assignable_type === DeviceAssignment::TARGET_STAFF
            ? $context['staff']->get($assignment->assignable_id)
            : null;

        if (! $this->sourceAuthorised($viewer, $device, $group, $client, $staff, $asset, $permissions)) {
            return null;
        }

        $session = $staff ? $context['sessions']->get($staff->id) : null;
        $privacy = match ($group) {
            'personal-safety' => $client
                ? $this->clientPrivacy($viewer, $client, $assignment, $permissions)
                : $this->staffPrivacy($viewer, $assignment, $session, $permissions),
            'fleet' => $this->operationalPrivacy(
                'fleet_operations',
                $permissions['fleet'],
                ! (bool) $asset?->fleetState?->consent_blocked,
                'Fleet operational purpose and permission are active.',
            ),
            default => $asset
                ? $this->assetPrivacy($viewer, $asset, $permissions)
                : $this->noPurpose('unassigned', 'Assign this tracker to a canonical asset before location can be used.'),
        };
        $location = $privacy['locationAllowed']
            ? $this->location($device, $asset, $group)
            : null;
        $canonicalHref = $this->canonicalHref($group, $client, $staff, $session, $asset);

        return [
            'id' => $device->id,
            'deviceUid' => $device->device_uid,
            'name' => $device->name,
            'category' => $device->category,
            'subcategory' => $device->subcategory,
            'manufacturer' => $device->manufacturer,
            'model' => $device->model,
            'status' => $device->status?->value,
            'health' => $device->health_status?->value,
            'battery' => $device->battery_level,
            'lastSeenAt' => $device->last_seen_at?->toISOString(),
            'siteId' => $this->siteId($assignment, $client, $session, $asset),
            'deviceHref' => "/security-devices/devices/{$device->id}",
            'group' => $group,
            'person' => $client ? [
                'id' => $client->id,
                'displayName' => $client->preferred_name ?: $client->first_name,
                'href' => "/operations/clients/{$client->id}",
            ] : ($staff ? [
                'id' => $staff->id,
                'displayName' => $staff->name,
                'href' => $context['staffProfileHrefs']->get($staff->id),
            ] : null),
            'asset' => $asset ? $this->mapAsset($asset) : null,
            'personalSafety' => $group === 'personal-safety' ? [
                'personType' => $client ? 'client' : ($staff ? 'staff' : 'unassigned'),
                'purposeLabel' => $privacy['purposeLabel'] ?? null,
                'sessionStatus' => $session?->status,
            ] : null,
            'privacy' => collect($privacy)->except('purposeLabel')->all(),
            'location' => $location,
            'canonicalHref' => $canonicalHref,
            'historyHref' => $privacy['locationAllowed'] ? $canonicalHref : null,
        ];
    }

    /** @param Collection<int, int|string> $staffIds @return Collection<int, string> */
    private function staffProfileHrefs(User $viewer, Collection $staffIds): Collection
    {
        if (! $viewer->canDo('hr.employees.viewAny') || $staffIds->isEmpty()) {
            return collect();
        }

        $visibleStaff = User::query()
            ->whereKey($staffIds->all())
            ->select('users.id');
        $this->siteAccess->applyStaffScope($visibleStaff, $viewer);

        return HrEmployeeProfile::query()
            ->whereIn('user_id', $visibleStaff)
            ->pluck('id', 'user_id')
            ->mapWithKeys(fn (mixed $profileId, mixed $userId): array => [
                (int) $userId => "/hr/people/{$profileId}",
            ]);
    }

    private function siteId(
        ?DeviceAssignment $assignment,
        ?Client $client,
        ?LoneWorkerSession $session,
        ?Asset $asset,
    ): ?int {
        if ($assignment?->assignable_type === DeviceAssignment::TARGET_SITE) {
            return (int) $assignment->assignable_id;
        }

        if ($assignment?->assignable_type === DeviceAssignment::TARGET_CLIENT) {
            return is_numeric($client?->site_id) ? (int) $client->site_id : null;
        }

        if ($assignment?->assignable_type === DeviceAssignment::TARGET_STAFF) {
            $siteId = $session?->site_id
                ?? $session?->client?->site_id
                ?? $session?->shift?->site_id
                ?? $session?->shift?->client?->site_id;

            return is_numeric($siteId) ? (int) $siteId : null;
        }

        $siteId = $asset?->site_id
            ?? $asset?->home_site_id
            ?? $asset?->client?->site_id;

        return is_numeric($siteId) ? (int) $siteId : null;
    }

    private function sourceAuthorised(
        User $viewer,
        Device $device,
        string $group,
        ?Client $client,
        ?User $staff,
        ?Asset $asset,
        array $permissions,
    ): bool {
        return match ($group) {
            'personal-safety' => $permissions['personalSafety']
                && ($client !== null || $staff !== null
                    || (! $device->assignments->first()
                        && ($viewer->canDo('securityDevices.devices.assign')
                            || $viewer->canDo('securityDevices.integrations.manage')))),
            'fleet' => $permissions['fleet'] && $asset !== null,
            'assets' => $permissions['assets']
                && ($asset !== null
                    || $viewer->canDo('securityDevices.devices.assign')
                    || $viewer->canDo('securityDevices.integrations.manage')),
            default => false,
        };
    }

    private function group(Device $device, ?DeviceAssignment $assignment, ?Asset $asset): string
    {
        if (in_array($assignment?->assignable_type, [
            DeviceAssignment::TARGET_CLIENT,
            DeviceAssignment::TARGET_STAFF,
        ], true)) {
            return 'personal-safety';
        }

        if ($assignment?->assignable_type === DeviceAssignment::TARGET_VEHICLE
            || $this->isVehicle($asset)
            || in_array($device->category, ['vehicle_tracker', 'telematics'], true)) {
            return 'fleet';
        }

        if ($device->category === 'personal_tracker') {
            return 'personal-safety';
        }

        return 'assets';
    }

    private function assetForDevice(
        Device $device,
        ?DeviceAssignment $assignment,
        Collection $assets,
    ): ?Asset {
        $linkedId = $device->activeAssetLinks->first()?->asset_id;
        $assignedVehicleId = $assignment?->assignable_type === DeviceAssignment::TARGET_VEHICLE
            ? $assignment->assignable_id
            : null;

        return $assets->get($linkedId ?? $assignedVehicleId);
    }

    private function clientPrivacy(
        User $viewer,
        Client $client,
        ?DeviceAssignment $assignment,
        array $permissions,
    ): array {
        $assignmentConsent = $assignment?->consent;
        if ($assignmentConsent
            && ((int) $assignmentConsent->client_id !== (int) $client->id
                || ! ConsentValidationService::isTrackingConsent($assignmentConsent))) {
            $assignmentConsent = null;
        }
        $activeConsent = $assignment
            ? ($assignment->isCollectionActive()
                && $assignmentConsent
                && ConsentValidationService::isValidTrackingConsent($assignmentConsent)
                    ? $assignmentConsent
                    : null)
            : ConsentValidationService::latestValidTrackingConsentForClient($client);
        $destinationAllowed = $permissions['telemetry'] && $permissions['assets'];

        if ($activeConsent) {
            return [
                'state' => 'active',
                'basis' => 'active_client_tracking_consent',
                'locationAllowed' => $destinationAllowed,
                'reason' => $destinationAllowed
                    ? 'Active client tracking consent and destination permissions.'
                    : 'Consent is active, but Asset telemetry permission is required for location.',
                'expiresAt' => $activeConsent->expires_at?->toISOString(),
                'purposeLabel' => $assignment?->tracking_purpose
                    ?: $activeConsent->consentType?->purpose
                    ?: $activeConsent->consentType?->name
                    ?: 'Client personal safety tracking',
            ];
        }

        $state = $assignment?->collection_stopped_at
            ? ($assignment->collection_stop_reason === 'consent_withdrawn' ? 'withdrawn' : 'inactive')
            : $this->consentState($assignmentConsent);

        return [
            'state' => $state,
            'basis' => 'none',
            'locationAllowed' => false,
            'reason' => match ($state) {
                'withdrawn' => 'Tracking consent was withdrawn.',
                'expired' => 'Tracking consent expired.',
                'refused' => 'Tracking consent was refused.',
                default => 'No active tracking consent is available.',
            },
            'expiresAt' => $assignmentConsent?->expires_at?->toISOString(),
            'purposeLabel' => $assignment?->tracking_purpose
                ?: $assignmentConsent?->consentType?->purpose
                ?: 'Client personal safety tracking',
        ];
    }

    private function staffPrivacy(
        User $viewer,
        ?DeviceAssignment $assignment,
        ?LoneWorkerSession $session,
        array $permissions,
    ): array {
        $allowed = $viewer->canDo('hazards.view')
            && $permissions['telemetry']
            && ($assignment?->isCollectionActive() ?? true)
            && $session !== null
            && in_array($session->status, self::LIVE_LONE_WORKER_STATES, true);

        if ($assignment?->isCollectionActive() !== false
            && $session
            && $viewer->canDo('hazards.view')) {
            return [
                'state' => 'active',
                'basis' => 'active_lone_worker_session',
                'locationAllowed' => $allowed,
                'reason' => $allowed
                    ? 'An authorised lone-worker safety session is live.'
                    : 'Asset telemetry permission is required for the live safety-session location.',
                'expiresAt' => $session->expected_end_at?->toISOString(),
                'purposeLabel' => $assignment?->tracking_purpose ?: 'Lone-worker safety monitoring',
            ];
        }

        return [
            ...$this->noPurpose(
                'inactive',
                $assignment?->collection_stopped_at
                    ? 'This tracking assignment has ended and its live location was revoked.'
                    : 'No authorised live lone-worker safety session is present.',
            ),
            'purposeLabel' => $assignment?->tracking_purpose ?: 'Lone-worker safety monitoring',
        ];
    }

    private function operationalPrivacy(
        string $basis,
        bool $destinationAllowed,
        bool $collectionAllowed,
        string $activeReason,
    ): array {
        $allowed = $destinationAllowed && $collectionAllowed;

        return [
            'state' => $allowed ? 'operational' : 'blocked',
            'basis' => $allowed ? $basis : 'none',
            'locationAllowed' => $allowed,
            'reason' => $allowed
                ? $activeReason
                : 'Location is blocked by destination permission or the canonical privacy state.',
            'expiresAt' => null,
        ];
    }

    private function assetPrivacy(User $viewer, Asset $asset, array $permissions): array
    {
        if ($asset->client) {
            return $this->clientPrivacy($viewer, $asset->client, null, $permissions);
        }

        return $this->operationalPrivacy(
            'asset_operations',
            $permissions['assets'] && $permissions['telemetry'],
            true,
            'Asset operational purpose and telemetry permission are active.',
        );
    }

    private function noPurpose(string $state, string $reason): array
    {
        return [
            'state' => $state,
            'basis' => 'none',
            'locationAllowed' => false,
            'reason' => $reason,
            'expiresAt' => null,
        ];
    }

    private function consentState(?ClientConsent $consent): string
    {
        if (! $consent) {
            return 'missing';
        }

        if ($consent->withdrawn_at || $consent->status === 'withdrawn') {
            return 'withdrawn';
        }

        if ($consent->isExpired() || ($consent->expires_at && $consent->expires_at->isPast())) {
            return 'expired';
        }

        return $consent->status ?: 'unknown';
    }

    private function location(Device $device, ?Asset $asset, string $group): ?array
    {
        $fleetState = $group === 'fleet' ? $asset?->fleetState : null;
        $latitude = $fleetState?->latitude ?? $device->latitude;
        $longitude = $fleetState?->longitude ?? $device->longitude;

        if ($latitude === null || $longitude === null) {
            return null;
        }

        return [
            'latitude' => (float) $latitude,
            'longitude' => (float) $longitude,
            'observedAt' => ($fleetState?->last_seen_at ?? $device->last_seen_at)?->toISOString(),
            'source' => $fleetState ? 'fleet_state' : 'canonical_device',
        ];
    }

    private function canonicalHref(
        string $group,
        ?Client $client,
        ?User $staff,
        ?LoneWorkerSession $session,
        ?Asset $asset,
    ): ?string {
        if ($group === 'personal-safety' && $client) {
            return "/operations/clients/{$client->id}?tab=location";
        }

        if ($group === 'personal-safety' && $staff) {
            return $session
                ? "/health-safety/lone-workers?session={$session->id}"
                : "/health-safety/lone-workers?worker={$staff->id}";
        }

        if (! $asset) {
            return null;
        }

        return $this->isVehicle($asset)
            ? "/fleet-assets/vehicles/{$asset->id}"
            : "/fleet-assets/assets/{$asset->id}";
    }

    private function mapAsset(Asset $asset): array
    {
        $vehicle = $this->isVehicle($asset);

        return [
            'id' => $asset->id,
            'name' => $asset->name,
            'category' => $asset->category,
            'reference' => $vehicle ? $asset->registration_number : $asset->asset_tag,
            'href' => $vehicle
                ? "/fleet-assets/vehicles/{$asset->id}"
                : "/fleet-assets/assets/{$asset->id}",
        ];
    }

    private function isVehicle(?Asset $asset): bool
    {
        return $asset !== null
            && (strcasecmp((string) $asset->category, 'vehicle') === 0
                || $asset->categoryRef?->slug === 'vehicle');
    }

    private function overview(Collection $devices): array
    {
        $personal = $devices->where('group', 'personal-safety')->count();
        $fleet = $devices->where('group', 'fleet')->count();
        $assets = $devices->where('group', 'assets')->count();
        $offline = $devices->where('status', DeviceStatus::Offline->value)->count();
        $lowBattery = $devices->filter(fn (array $device): bool => $device['battery'] !== null
            && $device['battery'] <= 20)->count();
        $consentBlocked = $devices
            ->where('group', 'personal-safety')
            ->where('privacy.locationAllowed', false)
            ->count();
        $unassigned = $devices->filter(fn (array $device): bool => $device['person'] === null
            && $device['asset'] === null)->count();
        $stale = $devices->filter(fn (array $device): bool => ! $device['lastSeenAt']
            || now()->diffInHours($device['lastSeenAt']) >= 24)->count();

        return [
            'inventory' => [
                'total' => $devices->count(),
                'personal_safety' => $personal,
                'fleet' => $fleet,
                'assets' => $assets,
            ],
            'attention' => [
                'offline' => $offline,
                'low_battery' => $lowBattery,
                'consent_blocked' => $consentBlocked,
                'unassigned' => $unassigned,
                'stale' => $stale,
            ],
            'groups' => collect([
                $this->overviewGroup('personal-safety', 'Personal safety', $personal, 'Client and lone-worker safety trackers.'),
                $this->overviewGroup('fleet', 'Fleet', $fleet, 'Vehicle tracking hardware linked to Fleet.'),
                $this->overviewGroup('assets', 'Assets', $assets, 'Operational asset tags and trackers.'),
            ]),
            'requiredActions' => collect([
                $this->overviewAction('offline', 'Offline tracking devices', $offline, 'Investigate devices whose canonical state is offline.', 'overview'),
                $this->overviewAction('low-battery', 'Low tracker battery', $lowBattery, 'Replace or charge tracker batteries before coverage is lost.', 'overview'),
                $this->overviewAction('consent-blocked', 'Location access blocked', $consentBlocked, 'Review purpose or consent in the owning Client or H&S workflow.', 'personal-safety'),
                $this->overviewAction('unassigned', 'Unassigned trackers', $unassigned, 'Reconcile trackers to their canonical person, vehicle, or asset record.', 'assets'),
                $this->overviewAction('stale', 'Stale tracker check-in', $stale, 'Review trackers that have not checked in during the last 24 hours.', 'overview'),
            ])->filter(fn (array $action): bool => $action['count'] > 0)->values(),
        ];
    }

    private function overviewGroup(string $key, string $label, int $count, string $description): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'count' => $count,
            'description' => $description,
            'href' => "/security-devices/tracking?tab={$key}",
        ];
    }

    private function overviewAction(
        string $key,
        string $label,
        int $count,
        string $description,
        string $tab,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'count' => $count,
            'description' => $description,
            'href' => "/security-devices/tracking?tab={$tab}&attention={$key}",
        ];
    }

    private function markers(Collection $devices, Collection $history): Collection
    {
        if ($history->isNotEmpty()) {
            return $history->map(fn (array $event): array => [
                'id' => "history-{$event['id']}",
                'lat' => $event['latitude'],
                'lng' => $event['longitude'],
                'title' => $event['subjectLabel'],
                'type' => $event['group'] === 'fleet' ? 'vehicle' : 'asset',
                'status' => 'historical',
            ])->values();
        }

        return $devices
            ->filter(fn (array $device): bool => $device['location'] !== null)
            ->map(fn (array $device): array => [
                'id' => "device-{$device['id']}",
                'lat' => $device['location']['latitude'],
                'lng' => $device['location']['longitude'],
                'title' => $device['name'],
                'type' => $device['group'] === 'fleet'
                    ? 'vehicle'
                    : ($device['group'] === 'assets' ? 'asset' : 'default'),
                'status' => $device['status'] ?? 'unknown',
            ])->values();
    }

    private function geofences(
        User $viewer,
        Collection $devices,
        Collection $assets,
        array $permissions,
    ): Collection {
        if (! $permissions['geofences']) {
            return collect();
        }

        $deviceByAsset = $devices
            ->filter(fn (array $device): bool => $device['asset'] !== null)
            ->keyBy('asset.id');
        if ($deviceByAsset->isEmpty()) {
            return collect();
        }

        return AssetGeofence::query()
            ->whereIn('asset_id', $deviceByAsset->keys())
            ->with(['asset:id,name,category,asset_tag,registration_number,client_id', 'site:id,name'])
            ->orderBy('name')
            ->limit(100)
            ->get()
            ->filter(function (AssetGeofence $geofence) use ($viewer, $deviceByAsset): bool {
                if ($geofence->site
                    && ! in_array((int) $geofence->site->id, $this->access->accessibleSiteIds($viewer), true)) {
                    return false;
                }

                $device = $deviceByAsset->get($geofence->asset_id);

                return $device !== null && $device['privacy']['locationAllowed'];
            })
            ->map(function (AssetGeofence $geofence) use ($deviceByAsset): array {
                $device = $deviceByAsset->get($geofence->asset_id);

                return [
                    'id' => $geofence->id,
                    'name' => $geofence->name,
                    'type' => $geofence->type,
                    'scope' => $geofence->scope,
                    'active' => (bool) $geofence->is_active,
                    'shape' => $this->mapGeofenceShape($geofence),
                    'subjectLabel' => $device['person']['displayName']
                        ?? $device['asset']['name']
                        ?? $device['name'],
                    'canonicalHref' => '/fleet-assets/geofences',
                    'privacy' => [
                        'state' => $device['privacy']['state'],
                        'basis' => $device['privacy']['basis'],
                    ],
                ];
            })
            ->values();
    }

    private function mapGeofenceShape(AssetGeofence $geofence): array
    {
        $shape = $geofence->shape ?? [];
        if ($geofence->type === 'circle') {
            return [
                'center' => [
                    'lat' => (float) ($shape['lat'] ?? data_get($shape, 'center.lat', 0)),
                    'lng' => (float) ($shape['lng'] ?? data_get($shape, 'center.lng', 0)),
                ],
                'radius_m' => (int) ($shape['radius_m'] ?? 0),
            ];
        }

        return [
            'coordinates' => collect($shape['coordinates'] ?? $shape['points'] ?? [])
                ->map(fn (array $point): array => [
                    'lat' => (float) ($point['lat'] ?? $point[0] ?? 0),
                    'lng' => (float) ($point['lng'] ?? $point[1] ?? 0),
                ])
                ->values(),
        ];
    }

    private function history(Collection $devices): Collection
    {
        $byId = $devices->keyBy('id');
        $eligibleIds = $devices
            ->filter(fn (array $device): bool => $device['privacy']['locationAllowed'])
            ->pluck('id');
        if ($eligibleIds->isEmpty()) {
            return collect();
        }

        return FleetTelemetryEvent::query()
            ->whereIn('device_id', $eligibleIds)
            ->where('consent_blocked', false)
            ->where('occurred_at', '>=', now()->subDays($this->retentionDays()))
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->latest('occurred_at')
            ->latest('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->map(function (FleetTelemetryEvent $event) use ($byId): ?array {
                $device = $byId->get($event->device_id);
                if (! $device || ! $device['privacy']['locationAllowed']) {
                    return null;
                }

                return [
                    'id' => $event->id,
                    'eventType' => $event->event_type ?: 'location_report',
                    'occurredAt' => $event->occurred_at?->toISOString(),
                    'deviceName' => $device['name'],
                    'subjectLabel' => $device['person']['displayName']
                        ?? $device['asset']['name']
                        ?? $device['name'],
                    'group' => $device['group'],
                    'latitude' => (float) $event->latitude,
                    'longitude' => (float) $event->longitude,
                    'battery' => $event->battery_pct,
                    'speed' => $event->speed_kph === null ? null : (float) $event->speed_kph,
                    'canonicalHref' => $device['canonicalHref'],
                ];
            })
            ->filter()
            ->values();
    }

    private function retentionDays(): int
    {
        return max(1, (int) config('fleet.retention.telemetry_days', 365));
    }
}
