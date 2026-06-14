<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Tax & Compliance hub entry point. The tabs (GST returns, IRD filings, audit
 * exports, consolidation) live on their own permission-gated routes —
 * tax.view / tax.manage / reports.view / admin — so this redirects to the first
 * tab the user can actually open so /finance/tax never lands on a 403. Mirrors
 * LedgerController.
 */
class TaxController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        $user = $request->user();

        $firstTab = collect([
            'finance.tax.view' => 'finance.gst-returns.index',
            'finance.tax.manage' => 'finance.ird-filings.index',
            'finance.reports.view' => 'finance.audit-exports.index',
            'finance.admin' => 'finance.consolidation.index',
        ])->first(fn (string $route, string $permission) => $user?->canDo($permission));

        abort_unless($firstTab !== null, 403);

        return redirect()->route($firstTab);
    }
}
