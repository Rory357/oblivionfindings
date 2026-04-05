<?php

namespace App\Policies;

use App\Models\DataSubjectRequest;
use App\Models\User;

class DataSubjectRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('privacy.viewRequests');
    }

    public function view(User $user, DataSubjectRequest $request): bool
    {
        return $user->canDo('privacy.viewRequests');
    }

    public function create(User $user): bool
    {
        return $user->canDo('privacy.processRequests');
    }

    public function update(User $user, DataSubjectRequest $request): bool
    {
        return $user->canDo('privacy.processRequests');
    }

    public function delete(User $user, DataSubjectRequest $request): bool
    {
        return $user->canDo('privacy.processRequests');
    }
}
