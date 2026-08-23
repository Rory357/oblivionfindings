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
use App\Domain\Finance\Services\GstTaxRateResolver;
use App\Domain\Finance\Services\InvoicePdfService;
use App\Domain\Finance\Services\PaymentSettlementSiteScope;
use App\Http\Controllers\Controller;
use App\Models\BillingEntry;
use App\Models\Client;
use App\Models\User;
use App\Services\Operations\BillingService;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class InvoiceController extends Controller
{
    private const APPLICATION_STORAGE_CONTEXT_ID = 1;

    public function index(Request $request)
    {
        $orgId = self::APPLICATION_STORAGE_CONTEXT_ID;

        $query = FinInvoice::forOrganization($orgId)
            // Lines power the in-place Edit modal's prefill for draft invoices.
            ->with('lines:id,invoice_id,description,quantity,unit_price,tax_rate_id,account_id')
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

        // Attach the outstanding balance to each row (one grouped query, no N+1) so
        // the Record-Receipt modal can default + cap the receipt amount.
        $pageIds = collect($invoices->items())->pluck('id');
        $paidById = FinPaymentAllocation::where('allocatable_type', FinInvoice::class)
            ->whereIn('allocatable_id', $pageIds)
            ->groupBy('allocatable_id')
            ->selectRaw('allocatable_id, SUM(amount) as total_paid')
            ->pluck('total_paid', 'allocatable_id');
        $invoices->through(function (FinInvoice $invoice) use ($paidById) {
            $paid = (float) ($paidById[$invoice->id] ?? 0);
            $invoice->amount_paid = round($paid, 2);
            $invoice->amount_due = round((float) $invoice->total_amount - $paid, 2);

            return $invoice;
        });

        $allInvoices = FinInvoice::forOrganization($orgId)->get();
        $summary = [
            'total_outstanding' => $allInvoices->whereIn('status', ['sent', 'viewed', 'overdue'])->sum('total_amount'),
            'total_overdue' => $allInvoices->where('status', 'overdue')->sum('total_amount'),
            'draft_count' => $allInvoices->where('status', 'draft')->count(),
            'paid_this_month' => $allInvoices->where('status', 'paid')
                ->where('paid_at', '>=', now()->startOfMonth())->sum('total_amount'),
        ];

        $canManage = (bool) $request->user()?->canDo('finance.ar.manage');

        return Inertia::render('finance/invoices/Index', [
            'invoices' => $invoices,
            'filters' => $request->only(['status', 'search', 'date_from', 'date_to']),
            'summary' => $summary,
            'canManage' => $canManage,
            // Reference data for the New Invoice modal — only for managers (the
            // create route is finance.ar.manage), so view-only users skip the queries.
            'clients' => $canManage
                ? $this->clientOptions($request->user())
                    ->map(fn ($c) => ['id' => $c->id, 'name' => trim($c->first_name.' '.$c->last_name)])
                    ->values()
                : [],
            'taxRates' => $canManage
                ? FinTaxRate::forOrganization($orgId)->active()->orderBy('name')->get(['id', 'name', 'rate'])
                : [],
        ]);
    }

    /**
     * Stream the (filtered) invoice list as a sanitised CSV. Honours the same
     * status/search/date filters as the index so "Export" respects the current view.
     */
    public function export(Request $request)
    {
        $orgId = self::APPLICATION_STORAGE_CONTEXT_ID;

        $query = FinInvoice::forOrganization($orgId)->orderBy('invoice_date', 'desc');

        if ($request->filled('status')) {
            $query->ofStatus($request->input('status'));
        }
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(fn ($q) => $q->where('invoice_number', 'like', "%{$search}%")
                ->orWhere('client_name', 'like', "%{$search}%")
                ->orWhere('client_email', 'like', "%{$search}%"));
        }
        if ($request->filled('date_from')) {
            $query->where('invoice_date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('invoice_date', '<=', $request->input('date_to'));
        }

        $rows = $query->get()->map(fn (FinInvoice $i) => [
            $i->invoice_number,
            $i->client_name ?? $i->funding_body,
            optional($i->invoice_date)->format('Y-m-d'),
            optional($i->due_date)->format('Y-m-d'),
            number_format((float) $i->subtotal, 2, '.', ''),
            number_format((float) $i->tax_amount, 2, '.', ''),
            number_format((float) $i->total_amount, 2, '.', ''),
            $i->status,
        ]);

        return $this->streamSanitizedCsv(
            'invoices-'.now()->format('Y-m-d').'.csv',
            ['Invoice #', 'Client / Funder', 'Invoice Date', 'Due Date', 'Subtotal', 'GST', 'Total', 'Status'],
            $rows,
        );
    }

    public function create(Request $request)
    {
        $orgId = self::APPLICATION_STORAGE_CONTEXT_ID;

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

        $clients = $this->clientOptions($request->user());

        $billingEntries = $this->accessibleBillingEntries($request->user())
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

    public function store(
        StoreInvoiceRequest $request,
        GstTaxRateResolver $gstTaxRateResolver,
        BillingService $billing,
    ) {
        $validated = $request->validated();

        $orgId = self::APPLICATION_STORAGE_CONTEXT_ID;
        $isOperationsPayload = $request->has('line_items')
            || $request->has('items')
            || $request->has('issue_date')
            || $request->has('payment_terms');
        $client = ! empty($validated['client_id'])
            ? $this->accessibleClients($request->user())->findOrFail($validated['client_id'])
            : null;

        $requestedBillingEntryIds = collect($validated['lines'])
            ->pluck('billing_entry_id')
            ->filter()
            ->map(fn ($entryId): int => (int) $entryId)
            ->values();
        if ($requestedBillingEntryIds->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                'lines' => 'A delivered-support record may appear only once on an invoice.',
            ]);
        }
        $billingEntryIds = $requestedBillingEntryIds->unique()->values();
        if ($billingEntryIds->isNotEmpty() && $billingEntryIds->count() !== count($validated['lines'])) {
            throw ValidationException::withMessages([
                'lines' => 'Delivered-support invoice lines cannot be mixed with unbound manual lines.',
            ]);
        }

        if ($billingEntryIds->isNotEmpty()) {
            $accessibleBillingEntries = $this->accessibleBillingEntries($request->user())
                ->whereIn('id', $billingEntryIds)
                ->whereIn('status', ['pending', 'approved'])
                ->whereDoesntHave('fundingClaimItem')
                ->get(['id', 'client_id']);

            if ($accessibleBillingEntries->count() !== $billingEntryIds->count()) {
                throw ValidationException::withMessages([
                    'lines' => 'One or more billing entries are not available for an accessible Client Site.',
                ]);
            }

            $billingClientIds = $accessibleBillingEntries
                ->pluck('client_id')
                ->unique()
                ->values();

            if (! $client || $billingClientIds->count() !== 1 || (int) $billingClientIds->first() !== (int) $client->id) {
                throw ValidationException::withMessages([
                    'lines' => 'Every billing entry must belong to the selected Client.',
                ]);
            }
        }

        $invoice = DB::transaction(function () use (
            $validated,
            $orgId,
            $request,
            $client,
            $gstTaxRateResolver,
            $billing,
            $billingEntryIds,
            $isOperationsPayload,
        ) {
            $lockedBillingEntries = collect();
            $invoiceClient = $client;
            if ($billingEntryIds->isNotEmpty()) {
                $lockedBillingEntries = $billing
                    ->lockInvoiceDeliveries($billingEntryIds->all(), (int) $client->id)
                    ->keyBy('id');
                $accessibleCount = $this->accessibleBillingEntries($request->user())
                    ->whereIn('id', $billingEntryIds)
                    ->count();
                if ($accessibleCount !== $billingEntryIds->count()) {
                    throw ValidationException::withMessages([
                        'lines' => 'One or more billing entries are not available for an accessible Client Site.',
                    ]);
                }
                $invoiceClient = $lockedBillingEntries->first()->client;
            }

            // Auto-generate invoice number if not provided
            $invoiceNumber = $validated['invoice_number'] ?? $this->generateInvoiceNumber($orgId);

            // Calculate line totals using bcmath to avoid float rounding errors
            $lines = [];
            $subtotal = '0';
            $taxTotal = '0';

            foreach ($validated['lines'] as $index => $lineData) {
                $billingEntryId = (int) ($lineData['billing_entry_id'] ?? 0);
                if ($billingEntryId > 0) {
                    /** @var BillingEntry|null $entry */
                    $entry = $lockedBillingEntries->get($billingEntryId);
                    if (! $entry) {
                        throw ValidationException::withMessages([
                            'lines' => 'One or more delivered-support records are no longer billable.',
                        ]);
                    }
                    if (
                        bccomp((string) $lineData['quantity'], (string) $entry->hours, 2) !== 0
                        || bccomp((string) $lineData['unit_price'], (string) $entry->rate, 2) !== 0
                        || (
                            filled($lineData['service_date'] ?? null)
                            && (string) $lineData['service_date'] !== $entry->service_date->toDateString()
                        )
                    ) {
                        throw ValidationException::withMessages([
                            'lines' => 'Invoice quantity, rate and service date must match the selected delivered-support record.',
                        ]);
                    }

                    $lineData['description'] = sprintf(
                        '%s - %s (%s hrs @ $%s)',
                        $entry->service_date->format('d M Y'),
                        $entry->rate_type,
                        $entry->hours,
                        number_format((float) $entry->rate, 2),
                    );
                    $lineData['quantity'] = (string) $entry->hours;
                    $lineData['unit_price'] = (string) $entry->rate;
                    $lineData['service_date'] = $entry->service_date->toDateString();
                    $lineData['category'] = $entry->rate_type;
                }

                $qty = (string) $lineData['quantity'];
                $price = (string) $lineData['unit_price'];
                $lineSubtotal = bcmul($qty, $price, 2);

                $taxRateId = isset($lineData['tax_rate_id']) ? (int) $lineData['tax_rate_id'] : null;
                $taxAmount = '0';

                if ($taxRateId !== null) {
                    $taxRate = $gstTaxRateResolver->matchInputRate($orgId, $taxRateId, '0');
                    $taxAmount = bcmul($lineSubtotal, (string) $taxRate->rate, 2);
                } else {
                    // Default 15% GST for NZ
                    $taxAmount = bcmul($lineSubtotal, '0.15', 2);
                    $taxRateId = $gstTaxRateResolver->matchInputRate($orgId, null, '15')?->id;
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
                'client_name' => $validated['client_name'] ?? $invoiceClient?->full_name ?? $validated['funding_body'],
                'client_email' => $validated['client_email'] ?? $invoiceClient?->email,
                'client_address' => $validated['client_address'] ?? $this->formatClientAddress($invoiceClient),
                'funding_body' => $validated['funding_body'] ?? null,
                'bill_id' => $validated['bill_id'] ?? null,
                'source' => $isOperationsPayload || $billingEntryIds->isNotEmpty() ? 'operations' : null,
                'source_type' => $billingEntryIds->isNotEmpty() ? BillingEntry::class : null,
                'source_id' => $billingEntryIds->first(),
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
                foreach ($lockedBillingEntries as $entry) {
                    $entry->update(['status' => 'invoiced']);
                }
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
        if ($this->isDeliveryBoundInvoice($invoice)) {
            return redirect()->route('finance.invoices.show', $invoice)
                ->with('error', 'Delivered-support invoice provenance is immutable; use the finance correction workflow.');
        }
        if ($invoice->status !== 'draft') {
            return redirect()->route('finance.invoices.show', $invoice)
                ->with('error', 'Only draft invoices can be edited.');
        }

        $orgId = self::APPLICATION_STORAGE_CONTEXT_ID;

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

    public function update(
        UpdateInvoiceRequest $request,
        FinInvoice $invoice,
        GstTaxRateResolver $gstTaxRateResolver,
    ) {
        if ($this->isDeliveryBoundInvoice($invoice)) {
            return back()->withErrors([
                'invoice' => 'Delivered-support invoice provenance is immutable; use the finance correction workflow.',
            ]);
        }
        if ($invoice->status !== 'draft') {
            return redirect()->route('finance.invoices.show', $invoice)
                ->with('error', 'Only draft invoices can be updated.');
        }

        $validated = $request->validated();

        $orgId = self::APPLICATION_STORAGE_CONTEXT_ID;
        // Resolve the client (when client-billed) exactly as store() does, so the
        // edit modal can switch between client- and funder-billed and the
        // denormalised name/email/address stay consistent.
        $client = ! empty($validated['client_id'])
            ? $this->accessibleClients($request->user())->findOrFail($validated['client_id'])
            : null;

        DB::transaction(function () use ($invoice, $validated, $client, $orgId, $gstTaxRateResolver) {
            $lines = [];
            $subtotal = '0';
            $taxTotal = '0';

            foreach ($validated['lines'] as $index => $lineData) {
                $qty = (string) $lineData['quantity'];
                $price = (string) $lineData['unit_price'];
                $lineSubtotal = bcmul($qty, $price, 2);

                $taxRateId = isset($lineData['tax_rate_id']) ? (int) $lineData['tax_rate_id'] : null;
                $taxAmount = '0';

                if ($taxRateId !== null) {
                    $taxRate = $gstTaxRateResolver->matchInputRate($orgId, $taxRateId, '0');
                    $taxAmount = bcmul($lineSubtotal, (string) $taxRate->rate, 2);
                } else {
                    $taxAmount = bcmul($lineSubtotal, '0.15', 2);
                    $taxRateId = $gstTaxRateResolver->matchInputRate($orgId, null, '15')?->id;
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
                'client_id' => $client?->id,
                'client_name' => $validated['client_name'] ?? $client?->full_name ?? ($validated['funding_body'] ?? null),
                'client_email' => $validated['client_email'] ?? $client?->email,
                'client_address' => $validated['client_address'] ?? $this->formatClientAddress($client),
                'funding_body' => $validated['funding_body'] ?? null,
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

    public function markPaid(
        Request $request,
        int $invoiceId,
        AccountsReceivableService $arService,
        PaymentSettlementSiteScope $paymentSiteScope,
    ) {
        $orgId = self::APPLICATION_STORAGE_CONTEXT_ID;
        $invoice = FinInvoice::forOrganization($orgId)->findOrFail($invoiceId);

        $this->authorize('update', $invoice);
        $paymentSiteScope->assertCanAccessInvoice($request->user(), $invoice);

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
                $arService->allocatePayment($orgId, $request->user(), [
                    'invoice_id' => $invoice->id,
                    'amount' => $amountDue,
                    'payment_date' => now()->toDateString(),
                    'idempotency_key' => (string) Str::uuid(),
                    'notes' => 'Marked as paid',
                ]);
            } else {
                // Fully allocated already; just flag the status.
                DB::transaction(function () use ($invoice, $request, $paymentSiteScope): void {
                    $lockedInvoice = FinInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
                    $paymentSiteScope->assertCanAccessInvoice($request->user(), $lockedInvoice);
                    $lockedInvoice->update(['status' => 'paid', 'paid_at' => now()]);
                });
            }
        } catch (\Throwable $e) {
            return back()->withErrors(['invoice' => 'Could not record the receipt: '.$e->getMessage()]);
        }

        return redirect()->route('finance.invoices.show', $invoice->fresh())
            ->with('success', 'Invoice marked as paid.');
    }

    public function cancel(
        Request $request,
        FinInvoice $invoice,
        FinInvoiceJournalService $journalService,
        PaymentSettlementSiteScope $paymentSiteScope,
    ) {
        $this->authorize('update', $invoice);

        try {
            $alreadyCancelled = DB::transaction(function () use (
                $invoice,
                $journalService,
                $paymentSiteScope,
                $request,
            ): bool {
                $invoice = FinInvoice::query()
                    ->where('organization_id', $request->user()->organization_id)
                    ->lockForUpdate()
                    ->findOrFail($invoice->id);
                $paymentSiteScope->assertCanAccessInvoice($request->user(), $invoice);

                if ($invoice->status === 'cancelled') {
                    return true;
                }

                $hasSettlement = FinPaymentAllocation::forOrganization($invoice->organization_id)
                    ->where('allocatable_type', FinInvoice::class)
                    ->where('allocatable_id', $invoice->id)
                    ->exists();

                if ($invoice->status === 'paid' || $invoice->paid_at !== null || $hasSettlement) {
                    throw new \InvalidArgumentException('Cannot cancel an invoice with recorded payments.');
                }

                if ($invoice->journal_id !== null) {
                    $journalService->reverseInvoiceJournal($invoice);
                    $invoice->refresh();
                }

                $invoice->update(['status' => 'cancelled']);

                return false;
            });
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['invoice' => $e->getMessage()]);
        }

        if ($alreadyCancelled) {
            return redirect()->route('finance.invoices.show', $invoice)
                ->with('success', 'Invoice already cancelled.');
        }

        return redirect()->route('finance.invoices.show', $invoice)
            ->with('success', 'Invoice cancelled.');
    }

    private function generateInvoiceNumber(int $orgId): string
    {
        return FinInvoice::nextNumber($orgId);
    }

    private function isDeliveryBoundInvoice(FinInvoice $invoice): bool
    {
        return $invoice->source_type === BillingEntry::class
            || $invoice->lines()->whereNotNull('billing_entry_id')->exists();
    }

    private function clientOptions(User $user)
    {
        return $this->accessibleClients($user)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name']);
    }

    private function accessibleClients(User $user): Builder
    {
        return app(UserSiteAccessService::class)->applyClientScope(
            Client::query(),
            $user,
            ['reports.viewAny'],
        );
    }

    private function accessibleBillingEntries(User $user): Builder
    {
        return BillingEntry::query()
            ->whereHas('client', fn (Builder $clientQuery) => app(UserSiteAccessService::class)->applyClientScope(
                $clientQuery,
                $user,
                ['reports.viewAny'],
            ))
            ->where(function (Builder $query): void {
                $query->whereNull('service_agreement_id')
                    ->orWhereHas('serviceAgreement', fn (Builder $agreementQuery) => $agreementQuery
                        ->whereColumn('service_agreements.client_id', 'billing_entries.client_id'));
            });
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
