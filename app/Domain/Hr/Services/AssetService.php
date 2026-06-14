<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrAsset;
use App\Domain\Hr\Models\HrAssetAssignment;
use App\Domain\Hr\Models\HrEmployeeProfile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class AssetService
{
    /**
     * Assign an asset to an employee.
     */
    public function assignAsset(HrAsset $asset, HrEmployeeProfile $profile, array $data): HrAssetAssignment
    {
        if ($asset->status !== 'available') {
            throw new \LogicException("Asset '{$asset->asset_tag}' is not available for assignment (current status: {$asset->status}).");
        }

        return DB::transaction(function () use ($asset, $profile, $data) {
            $assignment = HrAssetAssignment::create([
                'tenant_id' => $asset->tenant_id,
                'asset_id' => $asset->id,
                'employee_profile_id' => $profile->id,
                'assigned_at' => $data['assigned_at'] ?? now(),
                'condition_on_assign' => $data['condition_on_assign'] ?? null,
                'assigned_by' => $data['assigned_by'],
                'notes' => $data['notes'] ?? null,
            ]);

            $asset->update(['status' => 'assigned']);

            return $assignment;
        });
    }

    /**
     * Return an asset from an employee.
     */
    public function returnAsset(HrAssetAssignment $assignment, array $data): HrAssetAssignment
    {
        if ($assignment->returned_at !== null) {
            throw new \LogicException('This asset assignment has already been returned.');
        }

        return DB::transaction(function () use ($assignment, $data) {
            $assignment->update([
                'returned_at' => $data['returned_at'] ?? now(),
                'condition_on_return' => $data['condition_on_return'] ?? null,
                'notes' => $data['notes'] ?? $assignment->notes,
            ]);

            $assignment->asset->update(['status' => 'available']);

            return $assignment->fresh();
        });
    }

    /**
     * Send an available asset to maintenance.
     */
    public function sendToMaintenance(HrAsset $asset, array $data = []): HrAsset
    {
        if ($asset->status !== 'available') {
            throw new \LogicException("Only an available asset can be sent to maintenance (current status: {$asset->status}).");
        }

        $attrs = ['status' => 'maintenance'];
        if (! empty($data['notes'])) {
            $attrs['notes'] = $data['notes'];
        }
        $asset->update($attrs);

        return $asset->fresh();
    }

    /**
     * Return an asset from maintenance back to the available pool.
     */
    public function returnFromMaintenance(HrAsset $asset, array $data = []): HrAsset
    {
        if ($asset->status !== 'maintenance') {
            throw new \LogicException("Only an asset in maintenance can be returned to service (current status: {$asset->status}).");
        }

        $attrs = ['status' => 'available'];
        if (! empty($data['notes'])) {
            $attrs['notes'] = $data['notes'];
        }
        $asset->update($attrs);

        return $asset->fresh();
    }

    /**
     * Retire (decommission) an asset. It must not be currently assigned — return
     * it from the employee first so no open assignment is orphaned.
     */
    public function retireAsset(HrAsset $asset, array $data = []): HrAsset
    {
        if (! in_array($asset->status, ['available', 'maintenance'], true)) {
            throw new \LogicException("Cannot retire a '{$asset->status}' asset. Return it from assignment first.");
        }

        $attrs = ['status' => 'retired'];
        if (! empty($data['notes'])) {
            $attrs['notes'] = $data['notes'];
        }
        $asset->update($attrs);

        return $asset->fresh();
    }

    /**
     * Get all assets currently assigned to an employee.
     */
    public function getAssetsForEmployee(HrEmployeeProfile $profile): Collection
    {
        return HrAssetAssignment::where('employee_profile_id', $profile->id)
            ->whereNull('returned_at')
            ->with('asset')
            ->get();
    }

    /**
     * Get all available assets for a tenant.
     */
    public function getAvailableAssets(?int $tenantId): Collection
    {
        return HrAsset::forTenant($tenantId)
            ->available()
            ->orderBy('category')
            ->orderBy('name')
            ->get();
    }
}
