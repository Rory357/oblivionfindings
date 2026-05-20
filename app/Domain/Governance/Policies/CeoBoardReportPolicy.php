<?php

namespace App\Domain\Governance\Policies;

use App\Domain\Governance\Models\CeoBoardReport;
use App\Models\User;

class CeoBoardReportPolicy
{
    public function viewAny(User $user): bool { return $user->canDo('governance.ceo-reports.view'); }
    public function view(User $user, CeoBoardReport $report): bool { return $user->canDo('governance.ceo-reports.view'); }
    public function create(User $user): bool { return $user->canDo('governance.ceo-reports.manage'); }
    public function update(User $user, CeoBoardReport $report): bool { return $user->canDo('governance.ceo-reports.manage'); }
    public function delete(User $user, CeoBoardReport $report): bool { return $user->canDo('governance.ceo-reports.manage'); }
    public function submit(User $user, CeoBoardReport $report): bool { return $user->canDo('governance.ceo-reports.manage'); }
}
