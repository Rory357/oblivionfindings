<?php

namespace App\Domain\Roadmap\Policies;

use App\Domain\Roadmap\Models\DecisionRequest;
use App\Models\User;

class DecisionRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('roadmap.decisions.view') || $user->canDo('governance.resolutions.view');
    }

    public function view(User $user, DecisionRequest $decisionRequest): bool
    {
        return ($user->canDo('roadmap.decisions.view') || $user->canDo('governance.resolutions.view'))
            && $this->sameTenant($user, $decisionRequest->tenant_id);
    }

    public function create(User $user): bool
    {
        return $user->canDo('roadmap.decisions.manage') || $user->canDo('governance.resolutions.manage');
    }

    public function update(User $user, DecisionRequest $decisionRequest): bool
    {
        return ($user->canDo('roadmap.decisions.manage') || $user->canDo('governance.resolutions.manage'))
            && $this->sameTenant($user, $decisionRequest->tenant_id);
    }

    public function resolve(User $user, DecisionRequest $decisionRequest): bool
    {
        return ($user->canDo('roadmap.decisions.manage') || $user->canDo('governance.resolutions.manage'))
            && $this->sameTenant($user, $decisionRequest->tenant_id);
    }

    protected function sameTenant(User $user, ?int $tenantId): bool
    {
        if ($tenantId === null || ! isset($user->tenant_id) || $user->tenant_id === null) {
            return true;
        }

        return (int) $user->tenant_id === (int) $tenantId;
    }
}
