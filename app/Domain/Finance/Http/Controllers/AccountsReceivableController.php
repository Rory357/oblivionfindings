<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Services\AccountsReceivableService;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AccountsReceivableController extends Controller
{
    public function __construct(
        private AccountsReceivableService $service,
    ) {}

    /**
     * The Aged AR rail view: receivables bucketed by client and age, with the
     * outstanding/overdue headline figures. The outstanding-invoice LIST lives
     * on Invoices (`?status=unpaid`) — this page links there rather than
     * duplicating it.
     */
    public function index(Request $request)
    {
        $orgId = $request->user()->organization_id;
        $aged = $this->service->getAgedReceivables($orgId);
        $invoices = $this->service->getOutstandingInvoices($orgId);

        $today = Carbon::today();
        $overdueInvoices = $invoices->filter(fn ($inv) => $inv->due_date->lt($today));

        return Inertia::render('finance/receivables/Index', [
            'clients' => $aged['clients'],
            'totals' => $aged['totals'],
            'summary' => [
                'total_outstanding' => round($invoices->sum('amount_due'), 2),
                'total_overdue' => round($overdueInvoices->sum('amount_due'), 2),
                'unpaid_count' => $invoices->count(),
                'overdue_count' => $overdueInvoices->count(),
                'client_count' => count($aged['clients']),
            ],
        ]);
    }

    /**
     * Client statements.
     */
    public function statements(Request $request)
    {
        $orgId = $request->user()->organization_id;

        // Get clients that have outstanding invoices (live FinInvoice, not legacy)
        $clientsWithInvoices = Client::whereHas('finInvoices', function ($q) use ($orgId) {
            $q->where('organization_id', $orgId)->where('status', 'sent');
        })
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'email'])
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->first_name.' '.$c->last_name,
                'email' => $c->email,
            ])
            ->values()
            ->all();

        $statement = null;
        $clientId = $request->input('client_id');

        if ($clientId) {
            $asOfDate = $request->input('as_of_date', Carbon::today()->toDateString());
            $statement = $this->service->generateStatement($orgId, (int) $clientId, $asOfDate);
        }

        return Inertia::render('finance/receivables/Statements', [
            'clients' => $clientsWithInvoices,
            'statement' => $statement,
            'filters' => [
                'client_id' => $clientId ? (int) $clientId : null,
                'as_of_date' => $request->input('as_of_date', Carbon::today()->toDateString()),
            ],
        ]);
    }

    /**
     * Allocate a payment to an invoice.
     */
    public function allocate(Request $request)
    {
        $validated = $request->validate([
            'invoice_id' => 'required|exists:fin_invoices,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_date' => 'required|date',
            'idempotency_key' => 'required|uuid',
            'notes' => 'nullable|string',
        ]);

        try {
            $this->service->allocatePayment(
                $request->user()->organization_id,
                $request->user(),
                $validated,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Payment allocated successfully.');
    }
}
