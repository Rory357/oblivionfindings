<?php

namespace App\Domain\Clinical\Policies;

use App\Domain\Clinical\Models\ClinicalProtocol;
use App\Domain\Clinical\Services\ClinicalSiteAccessService;
use App\Models\User;

class ClinicalProtocolPolicy
{
    public function __construct(
        private readonly ClinicalSiteAccessService $siteAccess,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->canDo('clinical.protocols.viewAny')
            || $user->canDo('clinical.protocols.manage');
    }

    public function view(User $user, ClinicalProtocol $protocol): bool
    {
        return ($user->canDo('clinical.protocols.viewAny')
            || $user->canDo('clinical.protocols.manage'))
            && $this->siteAccess->canAccessProtocol($user, $protocol);
    }

    public function create(User $user): bool
    {
        return $user->canDo('clinical.protocols.manage');
    }

    public function update(User $user, ClinicalProtocol $protocol): bool
    {
        return $user->canDo('clinical.protocols.manage')
            && $this->siteAccess->canAccessProtocol($user, $protocol);
    }

    public function delete(User $user, ClinicalProtocol $protocol): bool
    {
        return $user->canDo('clinical.protocols.manage')
            && $this->siteAccess->canAccessProtocol($user, $protocol);
    }
}
