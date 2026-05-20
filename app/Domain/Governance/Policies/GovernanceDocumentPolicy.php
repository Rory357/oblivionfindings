<?php

namespace App\Domain\Governance\Policies;

use App\Domain\Governance\Models\GovernanceDocument;
use App\Models\User;

class GovernanceDocumentPolicy
{
    public function viewAny(User $user): bool { return $user->canDo('governance.documents.view'); }
    public function view(User $user, GovernanceDocument $document): bool { return $user->canDo('governance.documents.view'); }
    public function create(User $user): bool { return $user->canDo('governance.documents.manage'); }
    public function update(User $user, GovernanceDocument $document): bool { return $user->canDo('governance.documents.manage'); }
    public function delete(User $user, GovernanceDocument $document): bool { return $user->canDo('governance.documents.manage'); }
    public function download(User $user, GovernanceDocument $document): bool { return $user->canDo('governance.documents.view'); }
}
