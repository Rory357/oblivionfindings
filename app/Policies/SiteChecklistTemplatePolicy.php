<?php

namespace App\Policies;

use App\Models\SiteChecklistTemplate;
use App\Models\User;

class SiteChecklistTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('checklists.view');
    }

    public function view(User $user, SiteChecklistTemplate $template): bool
    {
        return $user->hasPermission('checklists.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('checklists.manage');
    }

    public function update(User $user, SiteChecklistTemplate $template): bool
    {
        return $user->hasPermission('checklists.manage');
    }

    public function delete(User $user, SiteChecklistTemplate $template): bool
    {
        return $user->hasPermission('checklists.manage');
    }
}
