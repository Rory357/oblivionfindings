<?php

namespace App\Domain\Governance\Policies;

use App\Domain\Governance\Models\BoardMember;
use App\Models\User;

class BoardMemberPolicy
{
    public function viewAny(User $user): bool { return $user->canDo('governance.meetings.manage'); }
    public function view(User $user, BoardMember $member): bool { return $user->canDo('governance.meetings.manage'); }
    public function create(User $user): bool { return $user->canDo('governance.meetings.manage'); }
    public function update(User $user, BoardMember $member): bool { return $user->canDo('governance.meetings.manage'); }
    public function delete(User $user, BoardMember $member): bool { return $user->canDo('governance.meetings.manage'); }
}
