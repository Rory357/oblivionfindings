<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Finance Settings hub entry point. The tabs (accounting integrations, funding
 * streams) live on their own permission-gated routes, so this redirects to the
 * first tab the user can actually open and 403s when they can open none — so
 * /finance/settings never lands on a 403. Mirrors TaxController.
 *
 * Both tabs are gated by finance.admin today; the redirect keeps tab order (a
 * list, not a permission-keyed map) so it stays correct if a differently-gated
 * tab — e.g. tax configuration — is added later.
 */
class SettingsController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        $user = $request->user();

        $tabs = [
            ['permission' => 'finance.admin', 'route' => 'finance.integrations.index'],
            ['permission' => 'finance.admin', 'route' => 'finance.funding-streams.index'],
        ];

        $firstTab = collect($tabs)->first(fn (array $tab) => $user?->canDo($tab['permission']));

        abort_unless($firstTab !== null, 403);

        return redirect()->route($firstTab['route']);
    }
}
