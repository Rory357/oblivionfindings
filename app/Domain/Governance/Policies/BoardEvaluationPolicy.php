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
    /**
     * Results open only once the evaluation has closed — for everyone,
     * including the people who run it. While answers are still coming in, a
     * small number of responses could identify who wrote what (owner
     * decision, 15 September 2026). Response counts stay on the evaluation page.
     */
    public function results(User $user, BoardEvaluation $evaluation): bool
    {
        return $user->canDo('governance.evaluations.view')
            && in_array($evaluation->status, BoardEvaluation::RESULTS_OPEN_STATUSES, true);
    }
}
