<?php

namespace App\Domain\Roadmap\Policies;

use App\Domain\Roadmap\Models\Initiative;
use App\Models\User;

class InitiativePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('roadmap.view');
    }

    public function view(User $user, Initiative $initiative): bool
    {
        return $user->canDo('roadmap.view') && $this->sameTenant($user, $initiative->tenant_id);
    }

    public function create(User $user): bool
    {
        return $user->canDo('roadmap.manage');
    }

    public function update(User $user, Initiative $initiative): bool
    {
        return $user->canDo('roadmap.manage') && $this->sameTenant($user, $initiative->tenant_id);
    }

    public function delete(User $user, Initiative $initiative): bool
    {
        return $user->canDo('roadmap.manage') && $this->sameTenant($user, $initiative->tenant_id);
    }

    public function approve(User $user, Initiative $initiative): bool
    {
        return $user->canDo('roadmap.approve') && $this->sameTenant($user, $initiative->tenant_id);
    }

    public function score(User $user, Initiative $initiative): bool
    {
        return $user->canDo('roadmap.manage') && $this->sameTenant($user, $initiative->tenant_id);
    }

    protected function sameTenant(User $user, ?int $tenantId): bool
    {
        if ($tenantId === null || ! isset($user->tenant_id) || $user->tenant_id === null) {
            return true;
        }

        return (int) $user->tenant_id === (int) $tenantId;
    }
}
