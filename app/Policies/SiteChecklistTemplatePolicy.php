<?php

namespace App\Policies;

use App\Models\SiteChecklistTemplate;
use App\Models\User;

class SiteChecklistTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('checklists.view');
    }

    public function view(User $user, SiteChecklistTemplate $template): bool
    {
        return $user->canDo('checklists.view');
    }

    public function create(User $user): bool
    {
        return $user->canDo('checklists.manage_templates');
    }

    public function update(User $user, SiteChecklistTemplate $template): bool
    {
        return $user->canDo('checklists.manage_templates');
    }

    public function delete(User $user, SiteChecklistTemplate $template): bool
    {
        return $user->canDo('checklists.manage_templates');
    }
}
