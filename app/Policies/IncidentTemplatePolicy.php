<?php

namespace App\Policies;

use App\Models\IncidentTemplate;
use App\Models\User;

class IncidentTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('incidents.templates.manage');
    }

    public function view(User $user, IncidentTemplate $template): bool
    {
        return $user->canDo('incidents.templates.manage');
    }

    public function create(User $user): bool
    {
        return $user->canDo('incidents.templates.manage');
    }

    public function update(User $user, IncidentTemplate $template): bool
    {
        return $user->canDo('incidents.templates.manage');
    }

    public function delete(User $user, IncidentTemplate $template): bool
    {
        return false;
    }
}
