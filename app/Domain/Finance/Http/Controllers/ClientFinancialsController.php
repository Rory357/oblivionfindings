<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Services\ClientFinancialSummaryService;
use App\Domain\Finance\Services\ClientLedgerService;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class ClientFinancialsController extends Controller
{
    public function __construct(
        private readonly ClientFinancialSummaryService $summaryService,
        private readonly ClientLedgerService $ledgerService,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function show(Request $request, Client $client)
    {
        $this->siteAccess->assertCanAccessClientId(
            $request->user(),
            (int) $client->id,
            ['reports.viewAny'],
        );

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
