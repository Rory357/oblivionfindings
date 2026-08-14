<?php

namespace App\Policies;

use App\Models\SiteChecklistRun;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class SiteChecklistRunPolicy
{
    public function view(User $user, SiteChecklistRun $run): bool
    {
        return $user->canDo('checklists.view')
            && $run->hasCanonicalExecutionProvenance()
            && Gate::forUser($user)->allows('view', $run->site);
    }

    public function execute(User $user, SiteChecklistRun $run): bool
    {
        return $user->canDo('checklists.run')
            && $this->view($user, $run)
            && $run->isExecutableBy($user);
    }
}
