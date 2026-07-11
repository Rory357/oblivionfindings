<?php

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\User;

class ClientOnboardingAccess
{
    public function canViewWorkflows(User $user): bool
    {
        return $user->canDo('onboarding.viewAny')
            || $user->canDo('onboarding.view')
            || $user->canDo('clients.viewAny');
    }

    public function canCreateWorkflows(User $user): bool
    {
        return $user->canDo('onboarding.create')
            || $user->canDo('clients.create')
            || $user->canDo('clients.update');
    }

    public function canManageWorkflows(User $user): bool
    {
        return $user->canDo('onboarding.edit')
            || $user->canDo('clients.create')
            || $user->canDo('clients.update');
    }

    public function canManageChecklist(User $user): bool
    {
        return $user->canDo('clients.onboarding.manage')
            || $user->canDo('clients.update');
    }

    /** @return array{view: bool, create_workflow: bool, manage_workflow: bool, manage_checklist: bool} */
    public function forClient(User $user, Client $client): array
    {
        $canViewClient = $user->can('view', $client);

        return [
            'view' => $canViewClient && (
                $this->canViewWorkflows($user)
                || $this->canCreateWorkflows($user)
                || $this->canManageWorkflows($user)
                || $this->canManageChecklist($user)
            ),
            'create_workflow' => $canViewClient && $this->canCreateWorkflows($user),
            'manage_workflow' => $canViewClient && $this->canManageWorkflows($user),
            'manage_checklist' => $canViewClient && $this->canManageChecklist($user),
        ];
    }
}
