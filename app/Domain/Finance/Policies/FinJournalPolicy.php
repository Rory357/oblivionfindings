<?php

namespace App\Domain\Finance\Policies;

use App\Domain\Finance\Models\FinJournal;
use App\Models\User;

class FinJournalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('finance.ledger.view');
    }

    public function view(User $user, FinJournal $journal): bool
    {
        return $user->canDo('finance.ledger.view');
    }

    public function create(User $user): bool
    {
        return $user->canDo('finance.ledger.manage');
    }

    public function post(User $user, FinJournal $journal): bool
    {
        return $user->canDo('finance.ledger.manage');
    }

    public function reverse(User $user, FinJournal $journal): bool
    {
        return $user->canDo('finance.ledger.manage');
    }
}
