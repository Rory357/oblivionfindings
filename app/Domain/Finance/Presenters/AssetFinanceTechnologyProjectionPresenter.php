<?php

namespace App\Domain\Finance\Presenters;

use App\Domain\Finance\Models\FinFixedAsset;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Minimum-necessary, read-only reconciliation across the three canonical owners.
 *
 * Fleet & Assets owns the operational Asset and assignments. Finance owns
 * capitalisation, depreciation, and disposal. Security & Devices owns installed
 * technology. This projection joins those records without creating another
 * register or allowing cross-module writes.
 */
final class AssetFinanceTechnologyProjectionPresenter
{
    private const DEVICE_LIMIT = 50;

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
    ) {}

    /** @return array<string, mixed> */
    public function forOperationalAsset(User $viewer, Asset $asset): array
    {
        $operationalAsset = $this->access->assignableAsset($viewer, (int) $asset->getKey());
        if (! $operationalAsset) {
            abort(404);
        }

        $canViewFinance = $viewer->canDo('finance.assets.view');
        $fixedAssets = $canViewFinance
            ? FinFixedAsset::query()
                ->where('linked_asset_id', $operationalAsset->getKey())
                ->orderByDesc('id')
                ->limit(2)
                ->get()
            : null;

        return $this->assemble(
            $viewer,
            $operationalAsset,
            $fixedAssets,
            linkedSourceRestricted: false,
        );
    }

    /** @return array<string, mixed> */
    public function forFixedAsset(User $viewer, FinFixedAsset $fixedAsset): array
    {
        $operationalAsset = null;
        $linkedSourceRestricted = false;

        if ($fixedAsset->linked_asset_id) {
            $operationalAsset = $this->access->assignableAsset(
                $viewer,
                (int) $fixedAsset->linked_asset_id,
            );
            $linkedSourceRestricted = $operationalAsset === null;
        }

        return $this->assemble(
            $viewer,
            $operationalAsset,
            collect([$fixedAsset]),
            $linkedSourceRestricted,
        );
    }

    /**
     * @param  Collection<int, FinFixedAsset>|null  $fixedAssets
     * @return array<string, mixed>
     */
    private function assemble(
        User $viewer,
        ?Asset $operationalAsset,
        ?Collection $fixedAssets,
        bool $linkedSourceRestricted,
    ): array {
        $canViewFinance = $fixedAssets !== null;
        $canViewTechnology = $operationalAsset !== null
            && $viewer->canDo('securityDevices.devices.view');
        $technology = $canViewTechnology
            ? $this->technology($viewer, $operationalAsset)
            : null;
        $activeAssignments = $operationalAsset
            ? $operationalAsset->assignments()->whereNull('released_at')->count()
            : null;
        $fixedAsset = $fixedAssets?->first();

        return [
            'boundary' => [
                'title' => 'One asset, three clear owners',
                'description' => 'Fleet & Assets owns operational status and assignments. Finance owns capitalisation, depreciation, and disposal. Security & Devices owns installed technology.',
                'management' => 'Resolve each mismatch in its owning module. This profile is a read-only reconciliation view and never duplicates source records.',
            ],
            'reconciliation' => $this->reconciliation(
                $operationalAsset,
                $fixedAsset,
                $fixedAssets?->count(),
                $activeAssignments,
                $technology === null ? null : count($technology['devices']),
                $linkedSourceRestricted,
            ),
            'operational_asset' => $operationalAsset
                ? $this->mapOperationalAsset($operationalAsset, $activeAssignments ?? 0)
                : null,
            'finance' => $fixedAsset ? $this->mapFixedAsset($fixedAsset) : null,
            'technology' => $technology,
            'permissions' => [
                'operational_asset' => $operationalAsset !== null,
                'finance' => $canViewFinance,
                'technology' => $canViewTechnology,
            ],
            'links' => [
                'assets' => $operationalAsset
                    ? "/fleet-assets/assets/{$operationalAsset->id}"
                    : null,
                'finance' => $canViewFinance ? '/finance/fixed-assets' : null,
                'devices' => $canViewTechnology ? '/security-devices/devices' : null,
            ],
        ];
    }

    /** @return array{devices: list<array<string, mixed>>, truncated: bool} */
    private function technology(User $viewer, Asset $asset): array
    {
        $candidates = $this->access->visibleDevices($viewer)
            ->whereHas('activeAssetLinks', fn (Builder $link): Builder => $link
                ->where('asset_id', $asset->getKey()))
            ->with(['activeAssetLinks' => fn ($query) => $query
                ->where('asset_id', $asset->getKey())
                ->orderByDesc('linked_at')])
            ->orderBy('name')
            ->limit(self::DEVICE_LIMIT + 1)
            ->get();

        return [
            'devices' => $candidates
                ->take(self::DEVICE_LIMIT)
                ->map(function (Device $device): array {
                    $link = $device->activeAssetLinks->first();

                    return [
                        'id' => $device->id,
                        'device_uid' => $device->device_uid,
                        'name' => $device->name,
                        'domain' => $device->domain,
                        'category' => $device->category,
                        'provider' => $device->provider,
                        'status' => $device->status?->value,
                        'health' => $device->health_status?->value,
                        'battery' => $device->battery_level,
                        'last_seen_at' => $device->last_seen_at?->toISOString(),
                        'link_type' => $link?->link_type?->value,
                        'linked_at' => $link?->linked_at?->toISOString(),
                        'href' => "/security-devices/devices/{$device->id}",
                    ];
                })
                ->values()
                ->all(),
            'truncated' => $candidates->count() > self::DEVICE_LIMIT,
        ];
    }

    /** @return array<string, mixed> */
    private function mapOperationalAsset(Asset $asset, int $activeAssignments): array
    {
        $asset->loadMissing(['site:id,name', 'homeSite:id,name', 'categoryRef:id,name,slug']);

        return [
            'id' => $asset->id,
            'name' => $asset->name,
            'asset_tag' => $asset->asset_tag,
            'category' => $asset->categoryRef?->name ?? $asset->category,
            'status' => $asset->status,
            'site' => $asset->site?->name ?? $asset->homeSite?->name,
            'active_assignments' => $activeAssignments,
            'href' => "/fleet-assets/assets/{$asset->id}",
        ];
    }

    /** @return array<string, mixed> */
    private function mapFixedAsset(FinFixedAsset $asset): array
    {
        return [
            'id' => $asset->id,
            'name' => $asset->asset_name,
            'asset_tag' => $asset->asset_tag,
            'category' => $asset->category,
            'status' => $asset->status,
            'purchase_date' => $asset->purchase_date?->toDateString(),
            'purchase_cost' => (float) $asset->purchase_cost,
            'accumulated_depreciation' => (float) $asset->accumulated_depreciation,
            'book_value' => $asset->getBookValue(),
            'disposed_date' => $asset->disposed_date?->toDateString(),
            'disposal_proceeds' => $asset->disposal_proceeds === null
                ? null
                : (float) $asset->disposal_proceeds,
            'capitalised' => $asset->acquisition_journal_id !== null,
            'href' => "/finance/fixed-assets/{$asset->id}",
        ];
    }

    /** @return array<string, mixed> */
    private function reconciliation(
        ?Asset $operationalAsset,
        ?FinFixedAsset $fixedAsset,
        ?int $fixedAssetCount,
        ?int $activeAssignments,
        ?int $activeDevices,
        bool $linkedSourceRestricted,
    ): array {
        if ($fixedAssetCount === null) {
            return $this->state(
                'finance_restricted',
                'Financial ownership is access restricted',
                'Finance details are hidden because this user does not have Fixed Assets access.',
                'neutral',
                false,
            );
        }

        if ($linkedSourceRestricted) {
            return $this->state(
                'operational_source_restricted',
                'Operational reconciliation is access restricted',
                'The financial record has an operational link, but its Site and Asset details are not available to this user.',
                'neutral',
                false,
            );
        }

        if ($operationalAsset === null) {
            return $this->state(
                'financial_record_only',
                'Finance-only record',
                'No operational Asset is linked. Finance remains the owner of this fixed-asset record.',
                'neutral',
                false,
            );
        }

        if (($fixedAssetCount ?? 0) > 1) {
            return $this->state(
                'duplicate_financial_records',
                'Multiple financial records need review',
                'More than one Fixed Asset points to this operational Asset. Finance must retain one authoritative financial record.',
                'critical',
                true,
                [['owner' => 'Finance', 'label' => 'Review Fixed Assets', 'href' => '/finance/fixed-assets']],
            );
        }

        if ($fixedAsset === null) {
            return $this->state(
                'not_on_fixed_asset_register',
                'Not on the Fixed Asset register',
                'The operational Asset remains valid. Finance decides whether its value meets the capitalisation policy.',
                'neutral',
                false,
                [['owner' => 'Finance', 'label' => 'Open Fixed Assets', 'href' => '/finance/fixed-assets']],
            );
        }

        $operationalRetired = in_array($operationalAsset->status, ['retired', 'disposed', 'decommissioned'], true);
        $financialDisposed = $fixedAsset->status === 'disposed';

        if ($financialDisposed && ! $operationalRetired) {
            return $this->state(
                'operational_retirement_required',
                'Financial disposal recorded; operational follow-up remains',
                'Finance has disposed the value, but Fleet & Assets still shows the Asset as operational. Retire it and complete assignment and technology recovery in their source modules.',
                'critical',
                true,
                [['owner' => 'Fleet & Assets', 'label' => 'Open operational Asset', 'href' => "/fleet-assets/assets/{$operationalAsset->id}"]],
            );
        }

        if ($financialDisposed && ($activeAssignments ?? 0) > 0) {
            return $this->state(
                'assignment_recovery_required',
                'Disposed Asset is still assigned',
                'Release every active assignment in Fleet & Assets before disposal reconciliation can close.',
                'critical',
                true,
                [['owner' => 'Fleet & Assets', 'label' => 'Review assignments', 'href' => "/fleet-assets/assets/{$operationalAsset->id}?tab=assignments"]],
            );
        }

        if ($financialDisposed && $activeDevices === null) {
            return $this->state(
                'technology_verification_restricted',
                'Technology recovery still needs verification',
                'The operational Asset is retired and Finance has disposed it, but installed technology cannot be verified with the current permissions.',
                'warning',
                true,
            );
        }

        if ($financialDisposed && $activeDevices > 0) {
            return $this->state(
                'technology_recovery_required',
                'Disposed Asset still has installed technology',
                'Recover or reassign every linked Device in Security & Devices before disposal reconciliation can close.',
                'critical',
                true,
                [['owner' => 'Security & Devices', 'label' => 'Review installed technology', 'href' => '/security-devices/devices']],
            );
        }

        if (! $financialDisposed && $operationalRetired) {
            return $this->state(
                'financial_disposal_required',
                'Operational retirement recorded; Finance follow-up remains',
                'Fleet & Assets has retired the operational record, but the Fixed Asset is still active. Finance must review depreciation and record the financial disposal.',
                'warning',
                true,
                [['owner' => 'Finance', 'label' => 'Open Fixed Asset', 'href' => "/finance/fixed-assets/{$fixedAsset->id}"]],
            );
        }

        if ($financialDisposed) {
            return $this->state(
                'disposed_reconciled',
                'Disposal reconciled',
                'Financial disposal, operational retirement, assignment recovery, and installed-technology recovery agree.',
                'success',
                false,
            );
        }

        return $this->state(
            'active_reconciled',
            'Active records agree',
            'The operational and financial records are active. Installed technology remains owned by Security & Devices.',
            'success',
            false,
        );
    }

    /** @param list<array{owner: string, label: string, href: string}> $actions @return array<string, mixed> */
    private function state(
        string $state,
        string $title,
        string $description,
        string $tone,
        bool $attention,
        array $actions = [],
    ): array {
        return compact('state', 'title', 'description', 'tone', 'attention', 'actions');
    }
}
