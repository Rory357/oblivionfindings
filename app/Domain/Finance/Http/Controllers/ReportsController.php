<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Reports & Planning hub entry point. The report tabs (P&L, balance sheet, trial
 * balance, cash flow, aged AR/AP, funding summary, budget vs actuals, cash-flow
 * forecast) all live on their own `finance.reports.view`-gated routes; this
 * redirects to the first (P&L) so /finance/reports never lands on a 403. Mirrors
 * PayablesController (homogeneous gate).
 */
class ReportsController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canDo('finance.reports.view'), 403);

        return redirect()->route('finance.reports.profit-loss');
    }
}
