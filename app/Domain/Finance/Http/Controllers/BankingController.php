<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Banking & Cash hub entry point. The banking tabs (accounts, transactions,
 * reconciliation, matching, feeds, EFTPOS, petty cash, match rules) live on their
 * own permission-gated routes — bank.view / bank.manage / petty_cash.view — so this
 * redirects to the first tab the user can actually open so /finance/banking never
 * lands on a 403. Mirrors LedgerController.
 */
class BankingController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        $user = $request->user();

        $firstTab = collect([
            'finance.bank.view' => 'finance.bank-accounts.index',
            'finance.bank.manage' => 'finance.bank-feeds.index',
            'finance.petty_cash.view' => 'finance.petty-cash.index',
        ])->first(fn (string $route, string $permission) => $user?->canDo($permission));

        abort_unless($firstTab !== null, 403);

        return redirect()->route($firstTab);
    }
}
