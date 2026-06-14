<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * General Ledger hub entry point. The ledger's tabs (chart of accounts, journals,
 * cost centres, fiscal periods, currencies, FX revaluations, fixed assets) live on
 * their own permission-gated routes; this redirects to the first tab the user can
 * actually open so /finance/ledger never lands on a 403.
 */
class LedgerController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        $user = $request->user();

        $firstTab = collect([
            'finance.ledger.view' => 'finance.accounts.index',
            'finance.admin' => 'finance.cost-centres.index',
            'finance.assets.view' => 'finance.fixed-assets.index',
            'finance.ledger.manage' => 'finance.fx-revaluations.index',
        ])->first(fn (string $route, string $permission) => $user?->canDo($permission));

        abort_unless($firstTab !== null, 403);

        return redirect()->route($firstTab);
    }
}
