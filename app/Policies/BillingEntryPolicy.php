<?php

namespace App\Policies;

use App\Models\BillingEntry;
use App\Models\User;
use App\Services\UserSiteAccessService;

class BillingEntryPolicy
{
    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->canDo('billing.viewAny');
    }

    public function view(User $user, BillingEntry $entry): bool
    {
        return $user->canDo('billing.viewAny') && $this->canAccessEntry($user, $entry);
    }

    public function create(User $user): bool
    {
        return $user->canDo('billing.create');
    }

    public function update(User $user, BillingEntry $entry): bool
    {
        return $user->canDo('billing.create') && $this->canAccessEntry($user, $entry);
    }

    public function approve(User $user, BillingEntry $entry): bool
    {
        return $user->canDo('billing.approve') && $this->canAccessEntry($user, $entry);
    }

    public function delete(User $user, BillingEntry $entry): bool
    {
        return $user->canDo('billing.approve') && $this->canAccessEntry($user, $entry);
    }

    private function canAccessEntry(User $user, BillingEntry $entry): bool
    {
        $entry->loadMissing(['client:id,site_id', 'serviceAgreement:id,client_id']);

        if (! $entry->client?->site_id) {
            return false;
        }

        if ($entry->service_agreement_id
            && (! $entry->serviceAgreement || $entry->serviceAgreement->client_id !== $entry->client_id)) {
            return false;
        }

        return in_array(
            (int) $entry->client->site_id,
            $this->siteAccess->accessibleSiteIds($user, ['reports.viewAny']),
            true,
        );
    }
}
