<?php

namespace App\Domain\Clinical\Policies;

use App\Domain\Clinical\Models\ClinicalEvent;
use App\Domain\Clinical\Services\ClinicalSiteAccessService;
use App\Models\User;

class ClinicalEventPolicy
{
    public function __construct(
        private readonly ClinicalSiteAccessService $siteAccess,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->canDo('clinical.events.viewAny')
            || $user->canDo('clinical.events.viewAssigned');
    }

    public function view(User $user, ClinicalEvent $event): bool
    {
        return ($user->canDo('clinical.events.viewAny')
            || $user->canDo('clinical.events.viewAssigned'))
            && $this->siteAccess->canAccessEvent($user, $event);
    }

    public function create(User $user): bool
    {
        return $user->canDo('clinical.events.record');
    }

    public function review(User $user, ?ClinicalEvent $event = null): bool
    {
        if (! $user->canDo('clinical.events.review')) {
            return false;
        }

        // Capability-only checks may omit a record. Every route-level review
        // authorisation supplies the event and therefore enforces Site scope.
        return $event === null || $this->siteAccess->canAccessEvent($user, $event);
    }
}
