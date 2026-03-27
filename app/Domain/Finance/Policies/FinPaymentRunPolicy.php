<?php

namespace App\Domain\Finance\Policies;

use App\Domain\Finance\Models\FinPaymentRun;
use App\Models\User;

class FinPaymentRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('finance.ap.view');
    }

    public function view(User $user, FinPaymentRun $paymentRun): bool
    {
        return $user->canDo('finance.ap.view');
    }

    public function create(User $user): bool
    {
        return $user->canDo('finance.ap.manage');
    }

    public function approve(User $user, FinPaymentRun $paymentRun): bool
    {
        return $user->canDo('finance.ap.manage');
    }

    public function process(User $user, FinPaymentRun $paymentRun): bool
    {
        return $user->canDo('finance.ap.manage');
    }
}
