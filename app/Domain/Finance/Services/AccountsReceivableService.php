<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Models\FinPaymentAllocation;
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
