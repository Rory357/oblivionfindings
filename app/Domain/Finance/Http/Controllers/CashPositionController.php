<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinPettyCashFund;
use App\Domain\Finance\Services\Calendar\FinanceCalendarAggregator;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Cash position — the fourth Overview-hub tab. Composes EXISTING data only:
 * live bank/petty-cash balances plus the next 30 days of dated money
 * obligations from the FinanceCalendarAggregator (invoices in, bills /
 * payment runs / payroll / GST out). No new stores, no invented figures.
 */
class CashPositionController extends Controller
{
    public function index(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $accounts = FinBankAccount::forOrganization($orgId)
            ->active()
            ->orderByDesc('current_balance')
            ->get(['id', 'name', 'bank_name', 'account_type', 'current_balance', 'is_primary'])
            ->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'bank_name' => $a->bank_name,
                'account_type' => $a->account_type,
                'current_balance' => (float) $a->current_balance,
                'is_primary' => (bool) $a->is_primary,
            ]);

        $pettyCash = FinPettyCashFund::forOrganization($orgId)
            ->active()
            ->get(['id', 'name', 'current_balance'])
            ->map(fn ($f) => [
                'id' => $f->id,
                'name' => $f->name,
                'current_balance' => (float) $f->current_balance,
            ]);

        $bankTotal = (string) $accounts->reduce(fn (string $carry, array $a) => bcadd($carry, number_format($a['current_balance'], 2, '.', ''), 2), '0.00');
        $pettyTotal = (string) $pettyCash->reduce(fn (string $carry, array $f) => bcadd($carry, number_format($f['current_balance'], 2, '.', ''), 2), '0.00');
        $totalCash = bcadd($bankTotal, $pettyTotal, 2);

        $obligations = (new FinanceCalendarAggregator)
            ->arrayForRange($orgId, now()->startOfDay(), now()->addDays(30)->endOfDay());

        $inflows = '0.00';
        $outflows = '0.00';
        foreach ($obligations as $item) {
            $amount = number_format((float) ($item['amount'] ?? 0), 2, '.', '');
            if (($item['direction'] ?? null) === 'inflow') {
                $inflows = bcadd($inflows, $amount, 2);
            } elseif (($item['direction'] ?? null) === 'outflow') {
                $outflows = bcadd($outflows, $amount, 2);
            }
        }

        return Inertia::render('finance/cash-position/Index', [
            'accounts' => $accounts,
            'pettyCash' => $pettyCash,
            'totals' => [
                'bank' => (float) $bankTotal,
                'petty_cash' => (float) $pettyTotal,
                'cash_on_hand' => (float) $totalCash,
                'expected_in_30d' => (float) $inflows,
                'expected_out_30d' => (float) $outflows,
                'projected_30d' => (float) bcsub(bcadd($totalCash, $inflows, 2), $outflows, 2),
            ],
            'obligations' => array_slice($obligations, 0, 20),
            'asOf' => now()->toIso8601String(),
        ]);
    }
}
