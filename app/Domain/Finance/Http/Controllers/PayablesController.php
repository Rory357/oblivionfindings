<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Purchases & Payables hub entry point. The AP tabs (bills, purchase orders,
 * vendors, credit notes, payment runs) live on their own routes — all gated
 * `finance.ap.view` — so this redirects to the first tab the user can open so
 * /finance/payables never lands on a 403. Mirrors LedgerController.
 */
class PayablesController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user?->canDo('finance.ap.view'), 403);

        return redirect()->route('finance.bills.index');
    }
}
