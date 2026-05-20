<?php

namespace App\Domain\Governance\Policies;

use App\Domain\Governance\Models\BoardMemberInterest;
use App\Models\User;

class BoardMemberInterestPolicy
{
    public function viewAny(User $user): bool { return $user->canDo('governance.interests.view'); }
    public function view(User $user, BoardMemberInterest $interest): bool { return $user->canDo('governance.interests.view'); }
    public function create(User $user): bool { return $user->canDo('governance.interests.manage'); }
    public function update(User $user, BoardMemberInterest $interest): bool { return $user->canDo('governance.interests.manage'); }
    public function delete(User $user, BoardMemberInterest $interest): bool { return $user->canDo('governance.interests.manage'); }
}
