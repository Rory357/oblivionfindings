<?php

namespace App\Policies;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class AssetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('assets.viewAny') || $user->canDo('assets.viewAssigned');
    }

    public function view(User $user, Asset $asset): Response
    {
        return $this->access()->canAccessAsset($user, $asset)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $user->canDo('assets.create');
    }

    public function update(User $user, Asset $asset): Response
    {
        return $this->objectAction($user, $asset, 'assets.update');
    }

    public function delete(User $user, Asset $asset): Response
    {
        return $this->objectAction($user, $asset, 'assets.delete');
    }

    public function recordInspection(User $user, Asset $asset): Response
    {
        return $this->objectAction($user, $asset, 'assets.inspections.record');
    }

    public function recordMaintenance(User $user, Asset $asset): Response
    {
        return $this->objectAction($user, $asset, 'assets.maintenance.record');
    }

    public function manageDocuments(User $user, Asset $asset): Response
    {
        return $this->objectAction($user, $asset, 'assets.documents.manage');
    }

    public function manageOwnership(User $user, Asset $asset): Response
    {
        return $this->objectAction($user, $asset, 'assets.ownership.manage');
    }

    public function manageAssignments(User $user, Asset $asset): Response
    {
        return $this->objectAction($user, $asset, 'assets.assignments.manage');
    }

    public function manageGeofences(User $user, Asset $asset): Response
    {
        return $this->objectAction($user, $asset, 'assets.geofences.manage');
    }

    public function recordScan(User $user, Asset $asset): Response
    {
        return $this->objectAction($user, $asset, 'assets.scan.record');
    }

    private function objectAction(User $user, Asset $asset, string $permission): Response
    {
        if (! $this->access()->canAccessAsset($user, $asset)) {
            return Response::denyAsNotFound();
        }

        return $user->canDo($permission)
            ? Response::allow()
            : Response::deny();
    }

    private function access(): SecurityDevicesAccessService
    {
        return app(SecurityDevicesAccessService::class);
    }
}
