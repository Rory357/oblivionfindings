<?php

namespace App\Policies;

use App\Models\Asset;
use App\Models\Client;
use App\Models\User;

class AssetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('assets.viewAny') || $user->canDo('assets.viewAssigned');
    }

    public function view(User $user, Asset $asset): bool
    {
        if ($user->canDo('assets.viewAny')) {
            return true;
        }

        // Assigned-only access for support workers:
        if (!$user->canDo('assets.viewAssigned')) {
            return false;
        }

        // If the asset is linked to a client, allow access if the user is assigned to
        // that client OR is assigned to any client at the same site (Option C).
        if ($asset->client_id) {
            if (!$user->hasRole('support_worker')) {
                return false;
            }

            $assignedToClient = Client::query()
                ->whereKey($asset->client_id)
                ->whereHas('supportWorkers', fn ($q) => $q->whereKey($user->id))
                ->exists();

            if ($assignedToClient) {
                return true;
            }

            // If the asset is stored at a site, allow if the worker is assigned to any client at that site.
            if ($asset->site_id) {
                return Client::query()
                    ->where('site_id', $asset->site_id)
                    ->whereHas('supportWorkers', fn ($q) => $q->whereKey($user->id))
                    ->exists();
            }

            return false;
        }

        // If it's a site-level asset, allow if the user is assigned to ANY client at that site.
        if ($asset->site_id) {
            return $user->hasRole('support_worker')
                && Client::query()
                    ->where('site_id', $asset->site_id)
                    ->whereHas('supportWorkers', fn ($q) => $q->whereKey($user->id))
                    ->exists();
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->canDo('assets.create');
    }

    public function update(User $user, Asset $asset): bool
    {
        // Update is allowed if they have the permission and can view the asset.
        return $user->canDo('assets.update') && $this->view($user, $asset);
    }

    public function delete(User $user, Asset $asset): bool
    {
        return $user->canDo('assets.delete') && $this->view($user, $asset);
    }

    public function recordInspection(User $user, Asset $asset): bool
    {
        return $user->canDo('assets.inspections.record') && $this->view($user, $asset);
    }

    public function recordMaintenance(User $user, Asset $asset): bool
    {
        return $user->canDo('assets.maintenance.record') && $this->view($user, $asset);
    }

    public function manageDocuments(User $user, Asset $asset): bool
    {
        return $user->canDo('assets.documents.manage') && $this->view($user, $asset);
    }
}
