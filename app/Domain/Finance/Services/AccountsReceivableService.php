<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinFundingStream;
use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Models\FinPaymentAllocation;
use App\Domain\Finance\Models\FinTaxRate;
use App\Models\Client;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AccountsReceivableService
{
    public function __construct(
        private JournalPostingService $journalPostingService,
    ) {}

    /**
     * Create a DRAFT accounts-receivable invoice with lines — the canonical AR
     * counterpart to AccountsPayableService::createBill. Draft is GL-safe: the
     * AR issue journal (DR 1100 / CR revenue) posts later when the invoice is
     * sent, never on create. Line tax resolves by `tax_rate_id`, else a direct
     * `gst_rate` percentage, else NZ 15% GST. `source_type`/`source_id` capture
     * the originating record so callers can be idempotent (one invoice per source).
     *
     * @param  array{client_id?:int,client_name?:string,funding_body?:string,invoice_date?:string,due_date?:string,source_type?:string,source_id?:int,source?:string,notes?:string,currency_code?:string,invoice_number?:string,lines:array<array{description:string,quantity?:float|int,unit_price:float|string,tax_rate_id?:int,gst_rate?:float|int,account_id?:int,service_date?:string,category?:string}>}  $data
     */
    public function createInvoice(?int $orgId, array $data): FinInvoice
    {
        return DB::transaction(function () use ($orgId, $data) {
            $invoiceNumber = ! empty($data['invoice_number'])
                ? $data['invoice_number']
                : FinInvoice::nextNumber($orgId);

            $subtotal = '0';
            $taxTotal = '0';
            $lines = [];

            foreach ($data['lines'] as $index => $line) {
                $qty = (string) ($line['quantity'] ?? 1);
                $price = (string) $line['unit_price'];
                $lineSubtotal = bcmul($qty, $price, 2);

                $taxRateId = $line['tax_rate_id'] ?? null;
                if ($taxRateId && ($rate = FinTaxRate::find($taxRateId))) {
                    $taxAmount = bcmul($lineSubtotal, bcdiv((string) $rate->rate, '100', 6), 2);
                } elseif (array_key_exists('gst_rate', $line)) {
                    $taxAmount = bcmul($lineSubtotal, bcdiv((string) $line['gst_rate'], '100', 6), 2);
                } else {
                    $taxAmount = bcmul($lineSubtotal, '0.15', 2); // NZ 15% GST default
                }
                $lineTotal = bcadd($lineSubtotal, $taxAmount, 2);

                $lines[] = [
                    'description' => $line['description'],
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'tax_rate_id' => $taxRateId,
                    'tax_amount' => $taxAmount,
                    'line_total' => $lineTotal,
                    'service_date' => $line['service_date'] ?? null,
                    'category' => $line['category'] ?? null,
                    'sort_order' => $index,
                    'account_id' => $line['account_id'] ?? null,
                    'funding_stream_id' => $line['funding_stream_id'] ?? null,
                ];
                $subtotal = bcadd($subtotal, $lineSubtotal, 2);
                $taxTotal = bcadd($taxTotal, $taxAmount, 2);
            }

            $invoice = FinInvoice::create([
                'organization_id' => $orgId,
                'client_id' => $data['client_id'] ?? null,
                'invoice_number' => $invoiceNumber,
                'invoice_date' => $data['invoice_date'] ?? now()->toDateString(),
                'due_date' => $data['due_date'] ?? now()->addDays(30)->toDateString(),
                // client_name is NOT NULL — fall back to the funder, then a generic
                // label, so a funder-only capture invoice still has a bill-to name.
                'client_name' => $data['client_name'] ?? $data['funding_body'] ?? 'Customer',
                'client_email' => $data['client_email'] ?? null,
                'client_address' => $data['client_address'] ?? null,
                'funding_body' => $data['funding_body'] ?? null,
                'source' => $data['source'] ?? null,
                'source_type' => $data['source_type'] ?? null,
                'source_id' => $data['source_id'] ?? null,
                'subtotal' => $subtotal,
                'tax_amount' => $taxTotal,
                'total_amount' => bcadd($subtotal, $taxTotal, 2),
                'currency_code' => $data['currency_code'] ?? 'NZD',
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);

            foreach ($lines as $line) {
                $invoice->lines()->create($line);
            }

            return $invoice->load('lines');
        });
    }

    /**
     * Capture-at-source: record an operational event (a confirmed respite booking,
     * …) as a DRAFT receivable invoice. Idempotent on `source_type`/`source_id` so
     * a source event that fires more than once never creates a duplicate. Amount =
     * quantity × unit_price; a zero/absent amount is a no-op. The revenue account is
     * resolved best-effort by code (draft, so a finance user can set it before
     * sending). Draft is GL-safe — the AR issue journal posts only when sent.
     *
     * @param  array{source_type:string,source_id:int,quantity?:float|int,unit_price:float|string,description:string,funding_body?:string,client_id?:int,client_name?:string,revenue_account_code?:string,gst_rate?:float|int,notes?:string}  $data
     */
    public function captureOperationalInvoice(?int $orgId, array $data): ?FinInvoice
    {
        $amount = (float) ($data['quantity'] ?? 1) * (float) $data['unit_price'];
        if ($amount <= 0) {
            return null;
        }

        $existing = FinInvoice::where('organization_id', $orgId)
            ->where('source_type', $data['source_type'])
            ->where('source_id', $data['source_id'])
            ->first();
        if ($existing) {
            return $existing;
        }

        $accountId = $data['revenue_account_id']
            ?? (! empty($data['revenue_account_code'])
                ? FinAccount::where('organization_id', $orgId)
                    ->where('code', $data['revenue_account_code'])
                    ->where('is_active', true)
                    ->value('id')
                : null);

        return $this->createInvoice($orgId, [
            'client_id' => $data['client_id'] ?? null,
            'client_name' => $data['client_name'] ?? null,
            'funding_body' => $data['funding_body'] ?? null,
            'source' => 'operations',
            'source_type' => $data['source_type'],
            'source_id' => $data['source_id'],
            'notes' => $data['notes'] ?? null,
            'lines' => [[
                'description' => $data['description'],
                'quantity' => $data['quantity'] ?? 1,
                'unit_price' => $data['unit_price'],
                'gst_rate' => $data['gst_rate'] ?? 0,
                'account_id' => $accountId,
                'funding_stream_id' => $data['funding_stream_id'] ?? null,
            ]],
        ]);
    }

    /**
     * Resolve a funding stream from an operational funder key (e.g. a respite
     * booking's `funding_source` such as "whaikaha" or "acc") by matching the
     * stream code or funder_type, case-insensitively. Returns null when nothing
     * matches — attribution is best-effort, never blocking.
     */
    public function resolveFundingStream(?int $orgId, ?string $funderKey): ?FinFundingStream
    {
        $key = strtolower(trim((string) $funderKey));
        if ($key === '') {
            return null;
        }

        return FinFundingStream::forOrganization($orgId)
            ->active()
            ->where(fn ($q) => $q->whereRaw('LOWER(code) = ?', [$key])
                ->orWhereRaw('LOWER(funder_type) = ?', [$key]))
            ->first();
    }

    /**
     * Get aged receivables grouped by client with aging buckets.
     *
     * Reads the live FinInvoice table (the legacy App\Models\Invoice table is a
     * write-orphan — nothing creates rows in it any more), netting partial
     * payments tracked as FinPaymentAllocation rows.
     *
     * @return array{clients: array, totals: array}
     */
    public function getAgedReceivables(?int $orgId): array
    {
        $today = Carbon::today();

        $invoices = FinInvoice::where('organization_id', $orgId)
            ->where('status', 'sent')
            ->with('client:id,first_name,last_name')
            ->get();

        $clientBuckets = [];
        $grandTotals = [
            'current' => '0',
            '1_30' => '0',
            '31_60' => '0',
            '61_90' => '0',
            '90_plus' => '0',
            'total' => '0',
        ];

        foreach ($invoices as $invoice) {
            // Funder invoices carry a client_name but no client_id; group those by name.
            $bucketKey = $invoice->client_id ?? ('name:'.$invoice->client_name);
            $amountDue = $this->calculateAmountDue($invoice);

            if (bccomp((string) $amountDue, '0', 2) <= 0) {
                continue;
            }

            if (! isset($clientBuckets[$bucketKey])) {
                $clientBuckets[$bucketKey] = [
                    'client_id' => $invoice->client_id,
                    'client_name' => $this->invoiceClientName($invoice),
                    'current' => '0',
                    '1_30' => '0',
                    '31_60' => '0',
                    '61_90' => '0',
                    '90_plus' => '0',
                    'total' => '0',
                ];
            }

            $daysOverdue = $today->diffInDays($invoice->due_date, false);
            // daysOverdue is negative if overdue
            $daysOverdue = (int) round($daysOverdue);

            $bucket = match (true) {
                $daysOverdue >= 0 => 'current',
                $daysOverdue >= -30 => '1_30',
                $daysOverdue >= -60 => '31_60',
                $daysOverdue >= -90 => '61_90',
                default => '90_plus',
            };

            $clientBuckets[$bucketKey][$bucket] = bcadd($clientBuckets[$bucketKey][$bucket], (string) $amountDue, 2);
            $clientBuckets[$bucketKey]['total'] = bcadd($clientBuckets[$bucketKey]['total'], (string) $amountDue, 2);

            $grandTotals[$bucket] = bcadd($grandTotals[$bucket], (string) $amountDue, 2);
            $grandTotals['total'] = bcadd($grandTotals['total'], (string) $amountDue, 2);
        }

        // Sort by client name
        $clients = array_values($clientBuckets);
        usort($clients, fn ($a, $b) => strcasecmp($a['client_name'], $b['client_name']));

        // Cast string amounts to float for frontend
        foreach ($clients as &$client) {
            $client['current'] = (float) $client['current'];
            $client['1_30'] = (float) $client['1_30'];
            $client['31_60'] = (float) $client['31_60'];
            $client['61_90'] = (float) $client['61_90'];
            $client['90_plus'] = (float) $client['90_plus'];
            $client['total'] = (float) $client['total'];
        }
        unset($client);

        foreach ($grandTotals as $key => $value) {
            $grandTotals[$key] = (float) $value;
        }

        return [
            'clients' => $clients,
            'totals' => $grandTotals,
        ];
    }

    /**
     * Allocate a payment against an invoice and create the GL journal.
     */
    public function allocatePayment(?int $orgId, array $data): FinPaymentAllocation
    {
        return DB::transaction(function () use ($orgId, $data) {
            $invoice = FinInvoice::where('organization_id', $orgId)
                ->where('id', $data['invoice_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $amountDue = $this->calculateAmountDue($invoice);

            if (bccomp((string) $data['amount'], (string) $amountDue, 2) > 0) {
                throw new \InvalidArgumentException(
                    "Payment amount ({$data['amount']}) exceeds the outstanding balance ({$amountDue})."
                );
            }

            $bankAccount = $this->findBankAccount($orgId);
            $arAccount = $this->findArAccount($orgId);

            // Create the GL journal: DR Bank, CR Accounts Receivable
            $journal = $this->journalPostingService->createAndPost($orgId, [
                'journal_date' => $data['payment_date'],
                'type' => 'standard',
                'reference' => "PMT-{$invoice->invoice_number}",
                'description' => "Payment received for invoice {$invoice->invoice_number}",
                'source_type' => FinInvoice::class,
                'source_id' => $invoice->id,
                'lines' => [
                    [
                        'account_id' => $bankAccount->id,
                        'description' => "Payment received — Invoice {$invoice->invoice_number}",
                        'debit' => $data['amount'],
                        'credit' => 0,
                    ],
                    [
                        'account_id' => $arAccount->id,
                        'description' => "Payment received — Invoice {$invoice->invoice_number}",
                        'debit' => 0,
                        'credit' => $data['amount'],
                    ],
                ],
            ]);

            // Create the payment allocation record
            $allocation = FinPaymentAllocation::create([
                'organization_id' => $orgId,
                'type' => 'receivable',
                'payment_date' => $data['payment_date'],
                'amount' => $data['amount'],
                'allocatable_type' => FinInvoice::class,
                'allocatable_id' => $invoice->id,
                'journal_id' => $journal->id,
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);

            // Check if invoice is fully paid
            $totalPaid = FinPaymentAllocation::where('allocatable_type', FinInvoice::class)
                ->where('allocatable_id', $invoice->id)
                ->sum('amount');

            if (bccomp((string) $totalPaid, (string) $invoice->total_amount, 2) >= 0) {
                $invoice->update([
                    'status' => 'paid',
                    'paid_at' => $data['payment_date'],
                ]);
            }

            return $allocation;
        });
    }

    /**
     * Generate a statement for a client as of a given date.
     *
     * @return array{client: array, invoices: array, total_outstanding: float}
     */
    public function generateStatement(?int $orgId, int $clientId, string $asOfDate): array
    {
        $client = Client::findOrFail($clientId);
        $asOf = Carbon::parse($asOfDate);

        $invoices = FinInvoice::where('organization_id', $orgId)
            ->where('client_id', $clientId)
            ->where('status', 'sent')
            ->where('invoice_date', '<=', $asOf)
            ->orderBy('invoice_date')
            ->get();

        $statementInvoices = [];
        $totalOutstanding = '0';

        foreach ($invoices as $invoice) {
            $amountPaid = (float) FinPaymentAllocation::where('allocatable_type', FinInvoice::class)
                ->where('allocatable_id', $invoice->id)
                ->where('payment_date', '<=', $asOf)
                ->sum('amount');

            $amountDue = bcsub((string) $invoice->total_amount, (string) $amountPaid, 2);

            if (bccomp($amountDue, '0', 2) <= 0) {
                continue;
            }

            $statementInvoices[] = [
                'invoice_number' => $invoice->invoice_number,
                'issue_date' => $invoice->invoice_date->toDateString(),
                'due_date' => $invoice->due_date->toDateString(),
                'total' => (float) $invoice->total_amount,
                'amount_paid' => $amountPaid,
                'amount_due' => (float) $amountDue,
            ];

            $totalOutstanding = bcadd($totalOutstanding, $amountDue, 2);
        }

        return [
            'client' => [
                'id' => $client->id,
                'name' => $client->first_name.' '.$client->last_name,
                'email' => $client->email,
                'address_line_1' => $client->address_line_1,
                'address_line_2' => $client->address_line_2,
                'suburb' => $client->suburb,
                'city' => $client->city,
                'postcode' => $client->postcode,
            ],
            'invoices' => $statementInvoices,
            'total_outstanding' => (float) $totalOutstanding,
            'as_of_date' => $asOfDate,
        ];
    }

    /**
     * Get outstanding (unpaid) invoices, optionally filtered by client.
     */
    public function getOutstandingInvoices(?int $orgId, ?int $clientId = null): Collection
    {
        $query = FinInvoice::where('organization_id', $orgId)
            ->where('status', 'sent')
            ->with('client:id,first_name,last_name');

        if ($clientId) {
            $query->where('client_id', $clientId);
        }

        $invoices = $query->orderBy('due_date')->get();

        // Add computed amount_due and amount_paid to each invoice
        $invoiceIds = $invoices->pluck('id');

        $paymentTotals = FinPaymentAllocation::where('allocatable_type', FinInvoice::class)
            ->whereIn('allocatable_id', $invoiceIds)
            ->groupBy('allocatable_id')
            ->selectRaw('allocatable_id, SUM(amount) as total_paid')
            ->pluck('total_paid', 'allocatable_id');

        foreach ($invoices as $invoice) {
            $paid = (float) ($paymentTotals[$invoice->id] ?? 0);
            $invoice->amount_paid = $paid;
            $invoice->amount_due = round((float) $invoice->total_amount - $paid, 2);
        }

        // Drop fully-paid invoices whose status hasn't caught up to their allocations.
        return $invoices->filter(fn ($invoice) => $invoice->amount_due > 0)->values();
    }

    /**
     * Find the Accounts Receivable account (code 1100) for the organisation.
     */
    public function findArAccount(?int $orgId): FinAccount
    {
        return FinAccount::where('organization_id', $orgId)
            ->where('code', '1100')
            ->where('is_active', true)
            ->firstOrFail();
    }

    /**
     * Find the Bank - Operating account (code 1000) for the organisation.
     */
    public function findBankAccount(?int $orgId): FinAccount
    {
        return FinAccount::where('organization_id', $orgId)
            ->where('code', '1000')
            ->where('is_active', true)
            ->firstOrFail();
    }

    /**
     * Display name for an invoice's customer (Client relation, else the
     * denormalised client_name used for funder invoices).
     */
    private function invoiceClientName(FinInvoice $invoice): string
    {
        if ($invoice->client) {
            return trim($invoice->client->first_name.' '.$invoice->client->last_name);
        }

        return $invoice->client_name ?: 'Unknown';
    }

    /**
     * Calculate the amount still due on an invoice.
     */
    private function calculateAmountDue(FinInvoice $invoice): float
    {
        $totalPaid = (float) FinPaymentAllocation::where('allocatable_type', FinInvoice::class)
            ->where('allocatable_id', $invoice->id)
            ->sum('amount');

        return round((float) $invoice->total_amount - $totalPaid, 2);
    }
}
