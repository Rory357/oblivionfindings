<?php

namespace App\Domain\Governance\Policies;

use App\Domain\Governance\Models\BoardEvaluation;
use App\Models\User;

class BoardEvaluationPolicy
{
    public function viewAny(User $user): bool { return $user->canDo('governance.evaluations.view'); }
    public function view(User $user, BoardEvaluation $evaluation): bool { return $user->canDo('governance.evaluations.view'); }
    public function create(User $user): bool { return $user->canDo('governance.evaluations.manage'); }
    public function update(User $user, BoardEvaluation $evaluation): bool { return $user->canDo('governance.evaluations.manage'); }
    public function delete(User $user, BoardEvaluation $evaluation): bool { return $user->canDo('governance.evaluations.manage'); }
    public function launch(User $user, BoardEvaluation $evaluation): bool { return $user->canDo('governance.evaluations.manage'); }
    public function close(User $user, BoardEvaluation $evaluation): bool { return $user->canDo('governance.evaluations.manage'); }
    public function results(User $user, BoardEvaluation $evaluation): bool { return $user->canDo('governance.evaluations.view'); }
}
