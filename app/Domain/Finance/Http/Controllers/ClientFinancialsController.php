<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Services\ClientFinancialSummaryService;
use App\Domain\Finance\Services\ClientLedgerService;
use App\Domain\Finance\Services\FinancialInsightsScopeResolver;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class ClientFinancialsController extends Controller
{
    public function __construct(
        private readonly ClientFinancialSummaryService $summaryService,
        private readonly ClientLedgerService $ledgerService,
        private readonly FinancialInsightsScopeResolver $scopeResolver,
    ) {}

    public function show(Request $request, string $client)
    {
        abort_unless(ctype_digit($client) && (int) $client > 0, 404);
        $scope = $this->scopeResolver->resolveClient($request->user(), (int) $client);
        abort_if($scope->isDenied(), 404);
        $client = Client::query()->findOrFail($scope->targetClientId());

        $to = $request->filled('to') ? Carbon::parse($request->query('to')) : Carbon::now();
        $from = $request->filled('from') ? Carbon::parse($request->query('from')) : $to->copy()->subMonth()->startOfMonth();

        $summary = $this->summaryService->getSummary($client->id, $from, $to);
        $ledger = $this->ledgerService->getLedger($client->id, $from, $to, withRunningBalance: true);

        return Inertia::render('clients/Financials', [
            'client' => $client->only('id', 'first_name', 'last_name', 'full_name', 'site_id', 'status'),
            'summary' => $summary,
            'ledger' => $ledger,
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
        ]);
    }
}
