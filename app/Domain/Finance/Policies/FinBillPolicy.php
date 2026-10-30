<?php

namespace App\Domain\Finance\Policies;

use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Services\BillSiteScope;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class FinBillPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('finance.ap.view');
    }

    public function view(User $user, FinBill $bill): Response
    {
        return $this->record($user, $bill, false);
    }

    public function create(User $user): bool
    {
        return $user->canDo('finance.ap.manage');
    }

    public function update(User $user, FinBill $bill): Response
    {
        return $this->record($user, $bill, true);
    }

    public function approve(User $user, FinBill $bill): Response
    {
        return $this->record($user, $bill, true);
    }

    private function record(User $user, FinBill $bill, bool $manage): Response
    {
        if (! $user->canDo($manage ? 'finance.ap.manage' : 'finance.ap.view')) {
            return Response::deny();
        }

        return app(BillSiteScope::class)->allows($user, $bill, $manage)
            ? Response::allow() : Response::denyAsNotFound();
    }
}
