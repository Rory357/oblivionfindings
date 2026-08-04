<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrAsset;
use App\Domain\Hr\Models\HrAssetAssignment;
use App\Domain\Hr\Models\HrAssetDocument;
use App\Domain\Hr\Models\HrAssetMaintenanceLog;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;

/** Canonical Site, staff, stock, and direct-object boundary for HR equipment. */
final class HrAssetAccessService
{
    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly SecurityDevicesAccessService $devicesAccess,
    ) {}

    /** @return list<int> */
    public function accessibleSiteIds(User $viewer): array
    {
        if ($viewer->canDo('hr.assets.viewAllSites')) {
            return Site::query()
                ->active()
                ->notArchived()
                ->whereNull('archived_at')
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
        }

        return $this->siteAccess->accessibleSiteIds($viewer);
    }

    public function canViewUnassigned(User $viewer): bool
    {
        return $viewer->canDo('hr.assets.viewUnassigned');
    }

    /** @return list<int> */
    public function authorizedFleetAssetIds(User $viewer): array
    {
        return $this->devicesAccess->authorizedAssetIds($viewer);
    }

    /** @return Builder<HrAsset> */
    public function visibleAssets(User $viewer): Builder
    {
        $profileIds = $this->historicalProfileIds($viewer);
        $query = HrAsset::query();

        $query->where(function (Builder $visibility) use ($viewer, $profileIds): void {
            if ($profileIds !== []) {
                $visibility->where(function (Builder $assigned) use ($profileIds): void {
                    $assigned->where('status', 'assigned')
                        ->whereHas('assignments', fn (Builder $assignment): Builder => $assignment
                            ->whereNull('returned_at')
                            ->whereIn('employee_profile_id', $profileIds));
                });
            } else {
                $visibility->whereRaw('1 = 0');
            }

            if ($this->canViewUnassigned($viewer)) {
                $visibility->orWhere(function (Builder $stock): void {
                    $stock->where('status', '!=', 'assigned')
                        ->whereDoesntHave('assignments', fn (Builder $assignment): Builder => $assignment
                            ->whereNull('returned_at'));
                });
            }
        });

        // A visible assignment cannot mask a second inaccessible assignment.
        if ($profileIds === []) {
            $query->whereDoesntHave('assignments', fn (Builder $assignment): Builder => $assignment
                ->whereNull('returned_at'));
        } else {
            $query->whereDoesntHave('assignments', fn (Builder $assignment): Builder => $assignment
                ->whereNull('returned_at')
                ->whereNotIn('employee_profile_id', $profileIds));
        }

        // Fleet wrappers are visible only when the canonical Fleet record also
        // passes its own source-domain policy and Site boundary.
        $fleetAssetIds = $this->authorizedFleetAssetIds($viewer);
        $query->where(function (Builder $ownership) use ($fleetAssetIds): void {
            $ownership->whereNull('fleet_asset_id');
            if ($fleetAssetIds !== []) {
                $ownership->orWhereIn('fleet_asset_id', $fleetAssetIds);
            }
        });

        return $query;
    }

    /** @return Builder<HrAssetAssignment> */
    public function visibleAssignments(User $viewer): Builder
    {
        return HrAssetAssignment::query()
            ->whereIn('asset_id', $this->visibleAssets($viewer)->select('hr_assets.id'));
    }

    /** @return Builder<HrAssetMaintenanceLog> */
    public function visibleMaintenanceLogs(User $viewer): Builder
    {
        return HrAssetMaintenanceLog::query()
            ->whereIn('asset_id', $this->visibleAssets($viewer)->select('hr_assets.id'));
    }

    /** @return Builder<HrAssetDocument> */
    public function visibleDocuments(User $viewer): Builder
    {
        return HrAssetDocument::query()
            ->whereIn('asset_id', $this->visibleAssets($viewer)->select('hr_assets.id'));
    }

    /** @return Builder<HrEmployeeProfile> */
    public function assignableProfiles(User $viewer): Builder
    {
        $profileIds = $this->currentProfileIds($viewer);

        return HrEmployeeProfile::query()
            ->whereKey($profileIds)
            ->whereHas('user', fn (Builder $user): Builder => $user->whereNotNull('approved_at'));
    }

    public function visibleAsset(User $viewer, int $assetId, bool $lockForUpdate = false): ?HrAsset
    {
        $query = $this->visibleAssets($viewer)->whereKey($assetId);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function visibleAssignment(User $viewer, int $assignmentId, bool $lockForUpdate = false): ?HrAssetAssignment
    {
        $query = $this->visibleAssignments($viewer)->whereKey($assignmentId);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function visibleDocument(User $viewer, int $documentId, bool $lockForUpdate = false): ?HrAssetDocument
    {
        $query = $this->visibleDocuments($viewer)->whereKey($documentId);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function assignableProfile(User $viewer, int $profileId, bool $lockForUpdate = false): ?HrEmployeeProfile
    {
        $query = $this->assignableProfiles($viewer)->whereKey($profileId);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /** @return list<int> */
    public function siteIdsForProfile(HrEmployeeProfile $profile): array
    {
        return collect([$profile->primary_site_id, ...($profile->secondary_site_ids ?? [])])
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $alert */
    public function canReceiveAlert(User $recipient, array $alert): bool
    {
        if (! $recipient->canDo('hr.assets.manage') || $recipient->approved_at === null) {
            return false;
        }

        $siteIds = collect($alert['site_ids'] ?? [])
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($siteIds->isEmpty()) {
            return $this->canViewUnassigned($recipient);
        }

        return $siteIds->diff($this->accessibleSiteIds($recipient))->isEmpty();
    }

    /** @return list<int> */
    private function currentProfileIds(User $viewer): array
    {
        return $this->profileIds($viewer, currentOnly: true);
    }

    /** @return list<int> */
    private function historicalProfileIds(User $viewer): array
    {
        return $this->profileIds($viewer, currentOnly: false);
    }

    /** @return list<int> */
    private function profileIds(User $viewer, bool $currentOnly): array
    {
        $siteIds = $this->accessibleSiteIds($viewer);
        if ($siteIds === []) {
            return [];
        }

        $query = $currentOnly
            ? HrEmployeeProfile::query()
            : HrEmployeeProfile::withTrashed();

        if ($currentOnly) {
            $query->where('is_active', true)
                ->where(function (Builder $dates): void {
                    $dates->whereNull('start_date')->orWhereDate('start_date', '<=', today());
                })
                ->where(function (Builder $dates): void {
                    $dates->whereNull('end_date')->orWhereDate('end_date', '>=', today());
                });
        }

        return $query
            ->where(function (Builder $sites) use ($siteIds): void {
                $sites->whereIn('primary_site_id', $siteIds);
                foreach ($siteIds as $siteId) {
                    $sites->orWhereJsonContains('secondary_site_ids', $siteId);
                }
            })
            ->get(['id', 'primary_site_id', 'secondary_site_ids'])
            ->filter(function (HrEmployeeProfile $profile) use ($siteIds): bool {
                $assigned = $this->siteIdsForProfile($profile);

                return $assigned !== [] && collect($assigned)->diff($siteIds)->isEmpty();
            })
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }
}
