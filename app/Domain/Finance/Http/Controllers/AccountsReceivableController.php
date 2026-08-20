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
     * AR overview: summary cards + paginated outstanding invoices.
     */
    public function index(Request $request)
    {
        $orgId = $request->user()->organization_id;
        $invoices = $this->service->getOutstandingInvoices($orgId);

        $today = Carbon::today();
        $totalOutstanding = $invoices->sum('amount_due');
        $overdueInvoices = $invoices->filter(fn ($inv) => $inv->due_date->lt($today));
        $totalOverdue = $overdueInvoices->sum('amount_due');
        $unpaidCount = $invoices->count();

        // Build rows for the table
        $rows = $invoices->map(fn ($inv) => [
            'id' => $inv->id,
            'invoice_number' => $inv->invoice_number,
            'client_name' => $inv->client
                ? $inv->client->first_name.' '.$inv->client->last_name
                : ($inv->client_name ?: 'Unknown'),
            'issue_date' => $inv->invoice_date->toDateString(),
            'due_date' => $inv->due_date->toDateString(),
            'total_amount' => (float) $inv->total_amount,
            'amount_paid' => $inv->amount_paid,
            'amount_due' => $inv->amount_due,
            'is_overdue' => $inv->due_date->lt($today),
            'days_overdue' => $inv->due_date->lt($today)
                ? (int) $today->diffInDays($inv->due_date)
                : 0,
        ])->values()->all();

        return Inertia::render('finance/receivables/Index', [
            'summary' => [
                'total_outstanding' => round($totalOutstanding, 2),
                'total_overdue' => round($totalOverdue, 2),
                'unpaid_count' => $unpaidCount,
            ],
            'invoices' => $rows,
        ]);
    }

    /**
     * Aged receivables report.
     */
    public function aging(Request $request)
    {
        $orgId = $request->user()->organization_id;
        $aged = $this->service->getAgedReceivables($orgId);

        return Inertia::render('finance/receivables/Aging', [
            'clients' => $aged['clients'],
            'totals' => $aged['totals'],
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
