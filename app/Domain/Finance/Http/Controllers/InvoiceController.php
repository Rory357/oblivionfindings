<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Http\Requests\StoreInvoiceRequest;
use App\Domain\Finance\Http\Requests\UpdateInvoiceRequest;
use App\Domain\Finance\Jobs\PostFinInvoiceJournalJob;
use App\Domain\Finance\Jobs\SendInvoiceEmailJob;
use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Models\FinPaymentAllocation;
use App\Domain\Finance\Models\FinTaxRate;
use App\Domain\Finance\Services\AccountsReceivableService;
use App\Domain\Finance\Services\FinInvoiceJournalService;
use App\Domain\Finance\Services\InvoicePdfService;
use App\Http\Controllers\Controller;
use App\Models\BillingEntry;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $query = FinInvoice::forOrganization($orgId)
            ->orderBy('invoice_date', 'desc');

        if ($request->filled('status')) {
            $query->ofStatus($request->input('status'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhere('client_name', 'like', "%{$search}%")
                    ->orWhere('client_email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('q')) {
            $search = $request->input('q');
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhere('client_name', 'like', "%{$search}%")
                    ->orWhere('funding_body', 'like', "%{$search}%");
            });
        }

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->integer('client_id'));
        }

        if ($request->filled('date_from')) {
            $query->where('invoice_date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('invoice_date', '<=', $request->input('date_to'));
        }

        $invoices = $query->paginate(20)->withQueryString();

        $allInvoices = FinInvoice::forOrganization($orgId)->get();
        $summary = [
            'total_outstanding' => $allInvoices->whereIn('status', ['sent', 'viewed', 'overdue'])->sum('total_amount'),
            'total_overdue' => $allInvoices->where('status', 'overdue')->sum('total_amount'),
            'draft_count' => $allInvoices->where('status', 'draft')->count(),
            'paid_this_month' => $allInvoices->where('status', 'paid')
                ->where('paid_at', '>=', now()->startOfMonth())->sum('total_amount'),
        ];

        return Inertia::render('finance/invoices/Index', [
            'invoices' => $invoices,
            'filters' => $request->only(['status', 'search', 'date_from', 'date_to']),
            'summary' => $summary,
        ]);
    }

    public function create(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $accounts = FinAccount::forOrganization($orgId)
            ->active()
            ->whereIn('type', ['revenue', 'income', 'asset'])
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type']);

        $taxRates = FinTaxRate::forOrganization($orgId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'rate']);

        $bills = FinBill::forOrganization($orgId)
            ->with('vendor:id,name')
            ->orderBy('bill_date', 'desc')
            ->limit(50)
            ->get(['id', 'bill_number', 'vendor_id', 'total_amount']);

        $clients = $this->clientOptions($orgId);

        $billingEntries = BillingEntry::query()
            ->where('organization_id', $orgId)
            ->whereIn('status', ['pending', 'approved'])
            ->with(['client:id,first_name,last_name'])
            ->orderByDesc('service_date')
            ->limit(250)
            ->get();

        return Inertia::render('finance/invoices/Create', [
            'accounts' => $accounts,
            'taxRates' => $taxRates,
            'bills' => $bills,
            'clients' => $clients,
            'billingEntries' => $billingEntries,
        ]);
    }

    public function store(StoreInvoiceRequest $request)
    {
        $validated = $request->validated();

        $orgId = $request->user()->organization_id;
        $isOperationsPayload = $request->has('line_items')
            || $request->has('items')
            || $request->has('issue_date')
            || $request->has('payment_terms');
        $client = ! empty($validated['client_id'])
            ? Client::query()
                ->when(
                    $orgId && Schema::hasColumn('clients', 'organization_id'),
                    fn ($query) => $query->where('organization_id', $orgId),
                )
                ->findOrFail($validated['client_id'])
            : null;

        $billingEntryIds = collect($validated['lines'])
            ->pluck('billing_entry_id')
            ->filter()
            ->unique()
            ->values();

        if ($billingEntryIds->isNotEmpty()) {
            $scopedCount = BillingEntry::query()
                ->where('organization_id', $orgId)
                ->whereIn('id', $billingEntryIds)
                ->count();

            if ($scopedCount !== $billingEntryIds->count()) {
                throw ValidationException::withMessages([
                    'lines' => 'One or more billing entries are not available for this organisation.',
                ]);
            }
        }

        $invoice = DB::transaction(function () use ($validated, $orgId, $request, $client, $billingEntryIds, $isOperationsPayload) {
            // Auto-generate invoice number if not provided
            $invoiceNumber = $validated['invoice_number'] ?? $this->generateInvoiceNumber($orgId);

            // Calculate line totals using bcmath to avoid float rounding errors
            $lines = [];
            $subtotal = '0';
            $taxTotal = '0';

            foreach ($validated['lines'] as $index => $lineData) {
                $qty = (string) $lineData['quantity'];
                $price = (string) $lineData['unit_price'];
                $lineSubtotal = bcmul($qty, $price, 2);

                $taxRateId = $lineData['tax_rate_id'] ?? null;
                $taxAmount = '0';

                if ($taxRateId) {
                    $taxRate = FinTaxRate::find($taxRateId);
                    if ($taxRate) {
                        $taxAmount = bcmul($lineSubtotal, bcdiv((string) $taxRate->rate, '100', 6), 2);
                    }
                } else {
                    // Default 15% GST for NZ
                    $taxAmount = bcmul($lineSubtotal, '0.15', 2);
                }

                $lineTotal = bcadd($lineSubtotal, $taxAmount, 2);

                $lines[] = [
                    'billing_entry_id' => $lineData['billing_entry_id'] ?? null,
                    'description' => $lineData['description'],
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'tax_rate_id' => $taxRateId,
                    'tax_amount' => $taxAmount,
                    'line_total' => $lineTotal,
                    'service_date' => $lineData['service_date'] ?? null,
                    'category' => $lineData['category'] ?? null,
                    'sort_order' => $index,
                    'account_id' => $lineData['account_id'] ?? null,
                ];

                $subtotal = bcadd($subtotal, $lineSubtotal, 2);
                $taxTotal = bcadd($taxTotal, $taxAmount, 2);
            }

            $invoice = FinInvoice::create([
                'organization_id' => $orgId,
                'client_id' => $client?->id,
                'invoice_number' => $invoiceNumber,
                'invoice_date' => $validated['invoice_date'],
                'due_date' => $validated['due_date'],
                'client_name' => $validated['client_name'] ?? $client?->full_name ?? $validated['funding_body'],
                'client_email' => $validated['client_email'] ?? $client?->email,
                'client_address' => $validated['client_address'] ?? $this->formatClientAddress($client),
                'funding_body' => $validated['funding_body'] ?? null,
                'bill_id' => $validated['bill_id'] ?? null,
                'source' => $isOperationsPayload || $billingEntryIds->isNotEmpty() ? 'operations' : null,
                'subtotal' => $subtotal,
                'tax_amount' => $taxTotal,
                'total_amount' => bcadd($subtotal, $taxTotal, 2),
                'currency_code' => $validated['currency_code'] ?? 'NZD',
                'status' => 'draft',
                'notes' => $validated['notes'] ?? null,
                'terms' => $validated['terms'] ?? null,
                'email_subject' => $validated['email_subject'] ?? null,
                'email_body' => $validated['email_body'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            foreach ($lines as $line) {
                $invoice->lines()->create($line);
            }

            if ($billingEntryIds->isNotEmpty()) {
                BillingEntry::query()
                    ->where('organization_id', $orgId)
                    ->whereIn('id', $billingEntryIds)
                    ->update(['status' => 'invoiced']);
            }

            return $invoice;
        });

        return redirect()->route('finance.invoices.show', $invoice)
            ->with('success', 'Invoice created successfully.');
    }

    public function show(Request $request, FinInvoice $invoice)
    {
        $invoice->load([
            'lines.taxRate:id,name,rate',
            'lines.account:id,code,name',
            'bill:id,bill_number',
            'createdBy:id,name',
            'journal:id,journal_number,status,posted_at',
        ]);

        return Inertia::render('finance/invoices/Show', [
            'invoice' => $invoice,
        ]);
    }

    public function edit(Request $request, FinInvoice $invoice)
    {
        if ($invoice->status !== 'draft') {
            return redirect()->route('finance.invoices.show', $invoice)
                ->with('error', 'Only draft invoices can be edited.');
        }

        $orgId = $request->user()->organization_id;

        $invoice->load('lines');

        $accounts = FinAccount::forOrganization($orgId)
            ->active()
            ->whereIn('type', ['revenue', 'income', 'asset'])
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type']);

        $taxRates = FinTaxRate::forOrganization($orgId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'rate']);

        $bills = FinBill::forOrganization($orgId)
            ->with('vendor:id,name')
            ->orderBy('bill_date', 'desc')
            ->limit(50)
            ->get(['id', 'bill_number', 'vendor_id', 'total_amount']);

        return Inertia::render('finance/invoices/Edit', [
            'invoice' => $invoice,
            'accounts' => $accounts,
            'taxRates' => $taxRates,
            'bills' => $bills,
        ]);
    }

    public function update(UpdateInvoiceRequest $request, FinInvoice $invoice)
    {
        if ($invoice->status !== 'draft') {
            return redirect()->route('finance.invoices.show', $invoice)
                ->with('error', 'Only draft invoices can be updated.');
        }

        $validated = $request->validated();

        DB::transaction(function () use ($invoice, $validated) {
            $lines = [];
            $subtotal = '0';
            $taxTotal = '0';

            foreach ($validated['lines'] as $index => $lineData) {
                $qty = (string) $lineData['quantity'];
                $price = (string) $lineData['unit_price'];
                $lineSubtotal = bcmul($qty, $price, 2);

                $taxRateId = $lineData['tax_rate_id'] ?? null;
                $taxAmount = '0';

                if ($taxRateId) {
                    $taxRate = FinTaxRate::find($taxRateId);
                    if ($taxRate) {
                        $taxAmount = bcmul($lineSubtotal, bcdiv((string) $taxRate->rate, '100', 6), 2);
                    }
                } else {
                    $taxAmount = bcmul($lineSubtotal, '0.15', 2);
                }

                $lineTotal = bcadd($lineSubtotal, $taxAmount, 2);

                $lines[] = [
                    'description' => $lineData['description'],
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'tax_rate_id' => $taxRateId,
                    'tax_amount' => $taxAmount,
                    'line_total' => $lineTotal,
                    'sort_order' => $index,
                    'account_id' => $lineData['account_id'] ?? null,
                ];

                $subtotal = bcadd($subtotal, $lineSubtotal, 2);
                $taxTotal = bcadd($taxTotal, $taxAmount, 2);
            }

            $invoice->update([
                'invoice_date' => $validated['invoice_date'],
                'due_date' => $validated['due_date'],
                'client_name' => $validated['client_name'],
                'client_email' => $validated['client_email'] ?? null,
                'client_address' => $validated['client_address'] ?? null,
                'bill_id' => $validated['bill_id'] ?? null,
                'currency_code' => $validated['currency_code'] ?? 'NZD',
                'subtotal' => $subtotal,
                'tax_amount' => $taxTotal,
                'total_amount' => bcadd($subtotal, $taxTotal, 2),
                'notes' => $validated['notes'] ?? null,
                'terms' => $validated['terms'] ?? null,
                'email_subject' => $validated['email_subject'] ?? null,
                'email_body' => $validated['email_body'] ?? null,
                'pdf_path' => null, // Clear PDF so it regenerates
            ]);

            // Replace all lines
            $invoice->lines()->delete();
            foreach ($lines as $line) {
                $invoice->lines()->create($line);
            }
        });

        return redirect()->route('finance.invoices.show', $invoice)
            ->with('success', 'Invoice updated successfully.');
    }

    public function send(Request $request, FinInvoice $invoice)
    {
        $this->authorize('update', $invoice);

        if (! $invoice->client_email) {
            return back()->withErrors(['invoice' => 'Invoice has no client email address.']);
        }

        if ($invoice->status === 'cancelled') {
            return back()->withErrors(['invoice' => 'Cannot send a cancelled invoice.']);
        }

        $shouldPostJournal = false;

        DB::transaction(function () use ($invoice, &$shouldPostJournal) {
            $invoice->refresh();

            if ($invoice->status === 'draft') {
                $invoice->update([
                    'status' => 'sent',
                    'sent_at' => $invoice->sent_at ?? now(),
                ]);

                $shouldPostJournal = $invoice->journal_id === null;
            }
        });

        $invoice->refresh();

        if ($shouldPostJournal) {
            PostFinInvoiceJournalJob::dispatch($invoice);
        }

        SendInvoiceEmailJob::dispatch($invoice->id);

        return redirect()->route('finance.invoices.show', $invoice)
            ->with('success', 'Invoice is being sent to '.$invoice->client_email);
    }

    public function downloadPdf(Request $request, FinInvoice $invoice, InvoicePdfService $pdfService)
    {
        $this->authorize('view', $invoice);

        // Generate PDF if not exists
        if (! $invoice->pdf_path || ! Storage::disk('local')->exists($invoice->pdf_path)) {
            $pdfService->generate($invoice);
            $invoice->refresh();
        }

        return Storage::disk('local')->download(
            $invoice->pdf_path,
            "Invoice-{$invoice->invoice_number}.pdf",
            ['Content-Type' => 'application/pdf']
        );
    }

    public function markPaid(Request $request, int $invoiceId, AccountsReceivableService $arService)
    {
        $orgId = $request->user()->organization_id;
        $invoice = FinInvoice::forOrganization($orgId)->findOrFail($invoiceId);

        $this->authorize('update', $invoice);

        if ($invoice->status === 'cancelled') {
            return back()->withErrors(['invoice' => 'Cannot mark a cancelled invoice as paid.']);
        }

        // Idempotent: a paid invoice has already posted its receipt journal.
        if ($invoice->status === 'paid') {
            return redirect()->route('finance.invoices.show', $invoice)
                ->with('success', 'Invoice already marked as paid.');
        }

        // Outstanding = total − already-allocated receipts. Posting a receipt for
        // this amount (DR Bank / CR AR) clears the AR balance the send-journal
        // raised; without it AR stays overstated forever. allocatePayment also
        // flips the invoice to paid when fully settled.
        $alreadyPaid = (string) FinPaymentAllocation::where('allocatable_type', FinInvoice::class)
            ->where('allocatable_id', $invoice->id)
            ->sum('amount');
        $amountDue = bcsub((string) $invoice->total_amount, $alreadyPaid, 2);

        try {
            if (bccomp($amountDue, '0', 2) > 0) {
                $arService->allocatePayment($orgId, [
                    'invoice_id' => $invoice->id,
                    'amount' => $amountDue,
                    'payment_date' => now()->toDateString(),
                    'notes' => 'Marked as paid',
                ]);
            } else {
                // Fully allocated already; just flag the status.
                $invoice->update(['status' => 'paid', 'paid_at' => now()]);
            }
        } catch (\Throwable $e) {
            return back()->withErrors(['invoice' => 'Could not record the receipt: '.$e->getMessage()]);
        }

        return redirect()->route('finance.invoices.show', $invoice->fresh())
            ->with('success', 'Invoice marked as paid.');
    }

    public function cancel(Request $request, FinInvoice $invoice, FinInvoiceJournalService $journalService)
    {
        $this->authorize('update', $invoice);

        if ($invoice->status === 'paid') {
            return back()->withErrors(['invoice' => 'Cannot cancel a paid invoice.']);
        }

        if ($invoice->status === 'cancelled') {
            return redirect()->route('finance.invoices.show', $invoice)
                ->with('success', 'Invoice already cancelled.');
        }

        DB::transaction(function () use ($invoice, $journalService) {
            $invoice->refresh();

            if ($invoice->journal_id !== null) {
                $journalService->reverseInvoiceJournal($invoice);
                $invoice->refresh();
            }

            $invoice->update(['status' => 'cancelled']);
        });

        return redirect()->route('finance.invoices.show', $invoice)
            ->with('success', 'Invoice cancelled.');
    }

    private function generateInvoiceNumber(int $orgId): string
    {
        return FinInvoice::nextNumber($orgId);
    }

    private function clientOptions(?int $orgId)
    {
        return Client::query()
            ->when(
                $orgId && Schema::hasColumn('clients', 'organization_id'),
                fn ($query) => $query->where('organization_id', $orgId),
            )
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name']);
    }

    private function formatClientAddress(?Client $client): ?string
    {
        if (! $client) {
            return null;
        }

        return collect([
            $client->address_line_1,
            $client->address_line_2,
            $client->suburb,
            $client->city,
            $client->postcode,
        ])->filter()->implode(', ') ?: null;
    }
}
