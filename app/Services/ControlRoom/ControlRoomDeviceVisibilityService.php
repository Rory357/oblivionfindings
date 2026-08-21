<?php

namespace App\Services\ControlRoom;

use App\Domain\SecurityDevices\Services\PersonalTrackingPrivacyService;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ControlRoom\Device;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Site and source-module boundary for the legacy Control Room signal projection.
 *
 * Control Room devices remain a signal-pipeline projection. Canonical identity
 * is visible only through SecurityDevicesAccessService and is never used to
 * broaden the operator's Control Room Site access.
 */
class ControlRoomDeviceVisibilityService
{
    public function __construct(
        private readonly SecurityDevicesAccessService $deviceAccess,
        private readonly PersonalTrackingPrivacyService $trackingPrivacy,
    ) {}

    /** @return list<int> */
    public function accessibleSiteIds(User $user): array
    {
        return $this->deviceAccess->accessibleSiteIds($user);
    }

    public function canViewCanonicalDevices(User $user): bool
    {
        return $user->canDo('securityDevices.devices.view');
    }

    public function applyCanonicalDeviceScope(Builder|Relation $query, User $user): Builder|Relation
    {
        if (! $this->canViewCanonicalDevices($user)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn(
            'devices.id',
            $this->deviceAccess->visibleDevices($user)->select('devices.id'),
        );
    }

    public function applyScope(Builder $query, User $user, ?array $requestedSiteIds = null): Builder
    {
        $accessibleSiteIds = $this->accessibleSiteIds($user);
        $siteIds = $requestedSiteIds === null
            ? $accessibleSiteIds
            : array_values(array_intersect(
                $accessibleSiteIds,
                collect($requestedSiteIds)
                    ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all(),
            ));

        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        $clientIds = $this->deviceAccess->authorizedClientIds($user);
        $assetIds = $this->deviceAccess->authorizedAssetIds($user);
        $canBypass = $this->deviceAccess->canViewQuarantined($user);
        // Canonical custody always constrains the projection, even when the
        // actor cannot see canonical identity fields in the response.
        $visibleCanonicalIds = $this->deviceAccess->visibleDevices($user)->select('devices.id');

        $query->where(function (Builder $visibility) use (
            $siteIds,
            $clientIds,
            $assetIds,
            $visibleCanonicalIds,
            $canBypass,
        ): void {
            $visibility->whereIn('control_room_devices.site_id', $siteIds)
                ->orWhere(function (Builder $clientFallback) use ($clientIds): void {
                    $clientFallback->whereNull('control_room_devices.site_id')
                        ->whereIn('control_room_devices.client_id', $clientIds);
                })
                ->orWhere(function (Builder $assetFallback) use ($assetIds): void {
                    $assetFallback->whereNull('control_room_devices.site_id')
                        ->whereNull('control_room_devices.client_id')
                        ->whereIn('control_room_devices.asset_id', $assetIds);
                });

            $visibility->orWhere(function (Builder $canonicalFallback) use ($visibleCanonicalIds): void {
                $canonicalFallback->whereNull('control_room_devices.site_id')
                    ->whereNull('control_room_devices.client_id')
                    ->whereNull('control_room_devices.asset_id')
                    ->whereIn('control_room_devices.canonical_device_id', clone $visibleCanonicalIds);
            });

            if ($canBypass) {
                $visibility->orWhere(function (Builder $unassigned): void {
                    $unassigned->whereNull('control_room_devices.site_id')
                        ->whereNull('control_room_devices.client_id')
                        ->whereNull('control_room_devices.asset_id')
                        ->whereNull('control_room_devices.canonical_device_id');
                });
            }
        });

        // Mixed provenance fails closed. One visible field must not mask an
        // explicitly inaccessible Client, Asset, or canonical Device link.
        $query->where(function (Builder $clients) use ($clientIds): void {
            $clients->whereNull('control_room_devices.client_id')
                ->orWhere(function (Builder $authoritative) use ($clientIds): void {
                    $authoritative->whereIn('control_room_devices.client_id', $clientIds)
                        ->whereExists(fn ($client) => $client
                            ->selectRaw('1')
                            ->from('clients')
                            ->whereColumn('clients.id', 'control_room_devices.client_id')
                            ->where('clients.status', 'active')
                            ->whereNull('clients.deleted_at')
                            ->where(function ($site): void {
                                $site->whereNull('control_room_devices.site_id')
                                    ->orWhereColumn('clients.site_id', 'control_room_devices.site_id');
                            }));
                });
        });
        $query->where(function (Builder $assets) use ($assetIds): void {
            $assets->whereNull('control_room_devices.asset_id')
                ->orWhere(function (Builder $authoritative) use ($assetIds): void {
                    $authoritative->whereIn('control_room_devices.asset_id', $assetIds)
                        ->whereExists(fn ($asset) => $asset
                            ->selectRaw('1')
                            ->from('assets')
                            ->whereColumn('assets.id', 'control_room_devices.asset_id')
                            ->where('assets.status', 'active')
                            ->where(function ($site): void {
                                $site->whereNull('control_room_devices.site_id')
                                    ->orWhereColumn('assets.site_id', 'control_room_devices.site_id')
                                    ->orWhere(function ($home): void {
                                        $home->whereNull('assets.site_id')
                                            ->whereColumn('assets.home_site_id', 'control_room_devices.site_id');
                                    });
                            }));
                });
        });
        $query->where(function (Builder $canonical) use ($visibleCanonicalIds): void {
            $canonical->whereNull('control_room_devices.canonical_device_id')
                ->orWhereIn('control_room_devices.canonical_device_id', clone $visibleCanonicalIds);
        });
        $query->where(function (Builder $siteBinding): void {
            $siteBinding->whereNull('control_room_devices.canonical_device_id')
                ->orWhereNull('control_room_devices.site_id')
                ->orWhereExists(fn ($assignment) => $assignment
                    ->selectRaw('1')
                    ->from('device_assignments')
                    ->whereColumn('device_assignments.device_id', 'control_room_devices.canonical_device_id')
                    ->whereColumn('device_assignments.custody_site_id', 'control_room_devices.site_id')
                    ->where('device_assignments.assigned_at', '<=', now())
                    ->whereNull('device_assignments.released_at'))
                ->orWhereExists(fn ($assetLink) => $assetLink
                    ->selectRaw('1')
                    ->from('device_asset_links')
                    ->join('assets', 'assets.id', '=', 'device_asset_links.asset_id')
                    ->whereColumn('device_asset_links.device_id', 'control_room_devices.canonical_device_id')
                    ->whereColumn('device_asset_links.asset_id', 'control_room_devices.asset_id')
                    ->whereNull('device_asset_links.unlinked_at')
                    ->where('assets.status', 'active')
                    ->where(function ($site): void {
                        $site->whereColumn('assets.site_id', 'control_room_devices.site_id')
                            ->orWhere(function ($home): void {
                                $home->whereNull('assets.site_id')
                                    ->whereColumn('assets.home_site_id', 'control_room_devices.site_id');
                            });
                    }));
        });
        $query->where(function (Builder $clientBinding): void {
            $clientBinding->whereNull('control_room_devices.canonical_device_id')
                ->orWhereNull('control_room_devices.client_id')
                ->orWhereExists(fn ($assignment) => $assignment
                    ->selectRaw('1')
                    ->from('device_assignments')
                    ->whereColumn('device_assignments.device_id', 'control_room_devices.canonical_device_id')
                    ->whereColumn('device_assignments.assignable_id', 'control_room_devices.client_id')
                    ->where('device_assignments.assignable_type', 'client')
                    ->where('device_assignments.assigned_at', '<=', now())
                    ->whereNull('device_assignments.released_at'))
                ->orWhereExists(fn ($assetLink) => $assetLink
                    ->selectRaw('1')
                    ->from('device_asset_links')
                    ->join('assets', 'assets.id', '=', 'device_asset_links.asset_id')
                    ->whereColumn('device_asset_links.device_id', 'control_room_devices.canonical_device_id')
                    ->whereColumn('device_asset_links.asset_id', 'control_room_devices.asset_id')
                    ->whereColumn('assets.client_id', 'control_room_devices.client_id')
                    ->whereNull('device_asset_links.unlinked_at')
                    ->where('assets.status', 'active'));
        });
        $query->where(function (Builder $assetBinding): void {
            $assetBinding->whereNull('control_room_devices.canonical_device_id')
                ->orWhereNull('control_room_devices.asset_id')
                ->orWhere(function (Builder $linked): void {
                    $linked->whereExists(fn ($assignment) => $assignment
                        ->selectRaw('1')
                        ->from('device_assignments')
                        ->whereColumn('device_assignments.device_id', 'control_room_devices.canonical_device_id')
                        ->whereColumn('device_assignments.assignable_id', 'control_room_devices.asset_id')
                        ->where('device_assignments.assignable_type', 'vehicle')
                        ->where('device_assignments.assigned_at', '<=', now())
                        ->whereNull('device_assignments.released_at'))
                        ->orWhereExists(fn ($assetLink) => $assetLink
                            ->selectRaw('1')
                            ->from('device_asset_links')
                            ->whereColumn('device_asset_links.device_id', 'control_room_devices.canonical_device_id')
                            ->whereColumn('device_asset_links.asset_id', 'control_room_devices.asset_id')
                            ->whereNull('device_asset_links.unlinked_at'));
                });
        });

        $this->applyPersonalTrackingAuthorityScope($query, $user, $clientIds);

        return $query;
    }

    public function assertCanView(User $user, Device $device): void
    {
        $query = Device::query()->whereKey($device->getKey());
        $this->applyScope($query, $user);

        abort_unless($query->exists(), 404);
    }

    public function assertCanFilterSite(User $user, int $siteId): void
    {
        abort_unless(
            in_array($siteId, $this->accessibleSiteIds($user), true),
            403,
            UserSiteAccessService::DEFAULT_MESSAGE,
        );
    }

    public function visibleSites(User $user): Builder
    {
        $ids = $this->accessibleSiteIds($user);

        return Site::query()
            ->active()
            ->notArchived()
            ->whereNull('archived_at')
            ->when($ids === [], fn (Builder $sites): Builder => $sites->whereRaw('1 = 0'))
            ->when($ids !== [], fn (Builder $sites): Builder => $sites->whereKey($ids));
    }

    public function visibleClient(User $user, ?int $clientId): ?Client
    {
        if (! $clientId) {
            return null;
        }

        return $this->deviceAccess->assignableClient($user, $clientId);
    }

    public function visibleAsset(User $user, ?int $assetId): ?Asset
    {
        if (! $assetId) {
            return null;
        }

        return $this->deviceAccess->assignableAsset($user, $assetId);
    }

    public function canViewPersonalLocation(User $user, Device $projection): bool
    {
        $canonical = $projection->canonicalDevice;
        $isPersonalTracker = $projection->type === Device::TYPE_PERSONAL_TRACKER
            || ($canonical && $canonical->domain === 'tracking' && $projection->client_id !== null);
        if (! $isPersonalTracker) {
            return true;
        }

        if (! $user->canDo('assets.telemetry.view')) {
            return false;
        }

        if (! $canonical || ! $projection->client_id) {
            return false;
        }

        $client = $this->deviceAccess->assignableClient($user, (int) $projection->client_id);
        if (! $client) {
            return false;
        }

        $assignment = app(PersonalTrackingPrivacyService::class)
            ->authorisedClientAssignment($client);

        return $assignment
            && (int) $assignment->device_id === (int) $canonical->id
            && $this->deviceAccess->canAccessCurrentAssignment($user, $assignment);
    }

    /** @param list<int> $authorisedClientIds */
    private function applyPersonalTrackingAuthorityScope(
        Builder $query,
        User $user,
        array $authorisedClientIds,
    ): void {
        $authorisedDeviceIds = Client::query()
            ->whereKey($authorisedClientIds)
            ->get(['id', 'site_id'])
            ->flatMap(fn (Client $client) => $this->trackingPrivacy
                ->authorisedClientAssignments($client)
                ->pluck('device_id'))
            ->filter(fn ($id): bool => is_numeric($id))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $query->where(function (Builder $privacy) use ($user, $authorisedClientIds, $authorisedDeviceIds): void {
            $privacy->where(function (Builder $ordinary): void {
                $ordinary->where('control_room_devices.type', '!=', Device::TYPE_PERSONAL_TRACKER)
                    ->where(function (Builder $notCanonicalPersonal): void {
                        $notCanonicalPersonal->whereNull('control_room_devices.client_id')
                            ->orWhereNull('control_room_devices.canonical_device_id')
                            ->orWhereNotExists(fn ($canonical) => $canonical
                                ->selectRaw('1')
                                ->from('devices')
                                ->whereColumn('devices.id', 'control_room_devices.canonical_device_id')
                                ->where('devices.domain', 'tracking')
                                ->whereNull('devices.deleted_at'));
                    });
            });

            if (! $user->canDo('assets.telemetry.view')
                || $authorisedClientIds === []
                || $authorisedDeviceIds === []) {
                return;
            }

            $privacy->orWhere(function (Builder $personal) use (
                $authorisedClientIds,
                $authorisedDeviceIds,
            ): void {
                $personal->whereNotNull('control_room_devices.client_id')
                    ->whereNotNull('control_room_devices.canonical_device_id')
                    ->whereIn('control_room_devices.client_id', $authorisedClientIds)
                    ->whereIn('control_room_devices.canonical_device_id', $authorisedDeviceIds)
                    ->where(function (Builder $classification): void {
                        $classification->where('control_room_devices.type', Device::TYPE_PERSONAL_TRACKER)
                            ->orWhereExists(fn ($canonical) => $canonical
                                ->selectRaw('1')
                                ->from('devices')
                                ->whereColumn('devices.id', 'control_room_devices.canonical_device_id')
                                ->where('devices.domain', 'tracking')
                                ->whereNull('devices.deleted_at'));
                    });
            });
        });
    }
}
