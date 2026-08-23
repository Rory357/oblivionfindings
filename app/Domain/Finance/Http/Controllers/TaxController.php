<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Tax & Compliance hub entry point. The tabs (GST returns, IRD filings, audit
 * exports) live on their own permission-gated routes — tax.view / tax.manage /
 * reports.view — so this redirects to the first supported tab the user can
 * actually open. The legacy consolidation surface remains quarantined.
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
        ])->first(fn (string $route, string $permission) => $user?->canDo($permission));

        abort_unless($firstTab !== null, 403);

        return redirect()->route($firstTab);
    }
}
