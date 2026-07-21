<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Contracts\ApprovedProbeScopeProvider;
use App\Domain\Monitoring\Contracts\ProbeScopeResolver;
use App\Domain\Monitoring\Data\ProbeScope;
use App\Domain\Monitoring\Exceptions\EgressDenied;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Site;
use App\Models\SiteRoom;
use Illuminate\Support\Collection;
use Throwable;

final class CanonicalProbeScopeResolver implements ProbeScopeResolver
{
    public function __construct(private readonly ApprovedProbeScopeProvider $scopeProvider) {}

    public function resolve(int $siteId, int $deviceId): ProbeScope
    {
        if ($siteId < 1 || $deviceId < 1) {
            throw new EgressDenied('probe scope is invalid');
        }

        $device = Device::query()
            ->whereKey($deviceId)
            ->whereIn('status', [DeviceStatus::Active->value, DeviceStatus::Degraded->value])
            ->with(['assignments' => fn ($query) => $query
                ->active()
                ->where('assigned_at', '<=', now())
                ->orderBy('id')])
            ->first();

        if ($device === null) {
            throw new EgressDenied('canonical device is unavailable');
        }

        $assignmentSiteIds = $device->assignments
            ->map(fn (DeviceAssignment $assignment): Collection => Collection::make($this->siteIdsFor($assignment))
                ->filter(fn (mixed $candidate): bool => is_int($candidate) && $candidate > 0)
                ->unique()
                ->values());

        if ($assignmentSiteIds->isEmpty()
            || $assignmentSiteIds->contains(fn (Collection $siteIds): bool => $siteIds->count() !== 1)) {
            throw new EgressDenied('device must resolve to one canonical active site');
        }

        $canonicalSiteIds = $assignmentSiteIds
            ->flatten()
            ->unique()
            ->values();

        if ($canonicalSiteIds->count() !== 1) {
            throw new EgressDenied('device must resolve to one canonical active site');
        }

        $canonicalSiteId = $canonicalSiteIds->first();
        if ($canonicalSiteId !== $siteId) {
            throw new EgressDenied('canonical site mismatch');
        }

        $siteIsActive = Site::query()
            ->whereKey($siteId)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('archived')->orWhere('archived', false))
            ->exists();

        if (! $siteIsActive) {
            throw new EgressDenied('canonical site is unavailable');
        }

        try {
            $scope = $this->scopeProvider->forDeviceAtSite($siteId, $deviceId);
        } catch (Throwable) {
            throw new EgressDenied('approved probe scope is unavailable');
        }

        if ($scope->siteId !== $siteId || $scope->deviceId !== $deviceId) {
            throw new EgressDenied('approved scope mismatch');
        }

        return $scope;
    }

    /** @return list<int> */
    private function siteIdsFor(DeviceAssignment $assignment): array
    {
        return match ($assignment->assignable_type) {
            DeviceAssignment::TARGET_SITE => [(int) $assignment->assignable_id],
            DeviceAssignment::TARGET_ROOM => $this->oneSiteId(
                SiteRoom::query()->whereKey($assignment->assignable_id)->value('site_id'),
            ),
            DeviceAssignment::TARGET_CLIENT => $this->oneSiteId(
                Client::query()
                    ->whereKey($assignment->assignable_id)
                    ->where('status', 'active')
                    ->value('site_id'),
            ),
            DeviceAssignment::TARGET_STAFF => $this->staffSiteIds((int) $assignment->assignable_id),
            DeviceAssignment::TARGET_VEHICLE => $this->vehicleSiteIds((int) $assignment->assignable_id),
            default => [],
        };
    }

    /** @return list<int> */
    private function staffSiteIds(int $userId): array
    {
        return HrEmployeeProfile::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('start_date')->orWhereDate('start_date', '<=', today()))
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', today()))
            ->whereNotNull('primary_site_id')
            ->pluck('primary_site_id')
            ->filter(fn (mixed $candidate): bool => is_numeric($candidate) && (int) $candidate > 0)
            ->map(fn (mixed $candidate): int => (int) $candidate)
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<int> */
    private function vehicleSiteIds(int $assetId): array
    {
        $asset = Asset::query()
            ->whereKey($assetId)
            ->where(fn ($query) => $query
                ->whereRaw('LOWER(category) = ?', ['vehicle'])
                ->orWhereHas('categoryRef', fn ($category) => $category->whereRaw('LOWER(slug) = ?', ['vehicle'])))
            ->where('status', 'active')
            ->first(['site_id', 'home_site_id', 'client_id']);

        if ($asset === null) {
            return [];
        }

        $siteIds = Collection::make([$asset->site_id, $asset->home_site_id]);

        if ($asset->client_id !== null) {
            $client = Client::query()
                ->whereKey($asset->client_id)
                ->where('status', 'active')
                ->first(['site_id']);

            if ($client === null) {
                return [];
            }

            $siteIds->push($client->site_id);
        }

        return $siteIds
            ->filter(fn (mixed $candidate): bool => is_numeric($candidate) && (int) $candidate > 0)
            ->map(fn (mixed $candidate): int => (int) $candidate)
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<int> */
    private function oneSiteId(mixed $siteId): array
    {
        return is_numeric($siteId) && (int) $siteId > 0 ? [(int) $siteId] : [];
    }
}
