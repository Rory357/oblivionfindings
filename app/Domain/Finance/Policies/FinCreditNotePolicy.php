<?php

namespace App\Domain\Finance\Policies;

use App\Domain\Finance\Models\FinCreditNote;
use App\Models\User;

class FinCreditNotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('finance.ap.view');
    }

    public function view(User $user, FinCreditNote $creditNote): bool
    {
        return $user->canDo('finance.ap.view');
    }

    public function create(User $user): bool
    {
        return $user->canDo('finance.ap.manage');
    }

    public function update(User $user, FinCreditNote $creditNote): bool
    {
        return $user->canDo('finance.ap.manage');
    }

    public function approve(User $user, FinCreditNote $creditNote): bool
    {
        return $user->canDo('finance.ap.manage');
    }
}
