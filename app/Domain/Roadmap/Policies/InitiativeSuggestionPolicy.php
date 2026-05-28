<?php

namespace App\Domain\Roadmap\Policies;

use App\Domain\Roadmap\Models\InitiativeSuggestion;
use App\Models\User;

class InitiativeSuggestionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('roadmap.view');
    }

    public function view(User $user, InitiativeSuggestion $suggestion): bool
    {
        return $user->canDo('roadmap.view') && $this->sameTenant($user, $suggestion->tenant_id);
    }

    public function create(User $user): bool
    {
        return $user->canDo('roadmap.view');
    }

    public function update(User $user, InitiativeSuggestion $suggestion): bool
    {
        return $user->canDo('roadmap.manage') && $this->sameTenant($user, $suggestion->tenant_id);
    }

    public function approve(User $user, InitiativeSuggestion $suggestion): bool
    {
        return $user->canDo('roadmap.approve') && $this->sameTenant($user, $suggestion->tenant_id);
    }

    public function reject(User $user, InitiativeSuggestion $suggestion): bool
    {
        return $user->canDo('roadmap.approve') && $this->sameTenant($user, $suggestion->tenant_id);
    }

    protected function sameTenant(User $user, ?int $tenantId): bool
    {
        if ($tenantId === null || $user->organization_id === null) {
            return false;
        }

        return (int) $user->organization_id === (int) $tenantId;
    }
}
