<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinBillLine;
use App\Domain\Finance\Models\FinCreditNote;
use App\Domain\Finance\Models\FinCreditNoteLine;
use App\Domain\Finance\Models\FinGstReturn;
use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Models\FinInvoiceLine;
use App\Domain\Finance\Models\FinJournalLine;
use App\Domain\Finance\Models\FinPaymentAllocation;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class GstReturnService
{
    private const SIDE_SALES = 'sales';

    private const SIDE_PURCHASES = 'purchases';

    public function __construct(
        private readonly GstTaxRateResolver $gstTaxRateResolver,
    ) {}

    /**
     * Build one replay-safe evidence snapshot for the selected IRD period.
     *
     * Invoice basis recognises posted invoices and bills on their source dates.
     * Payments basis recognises both sides proportionally on canonical settlement
     * dates. Hybrid recognises sales on invoice dates and purchases on settlement
     * dates. Posted credit adjustments use their credit date because there is no
     * separate credit-application event in the canonical subledgers.
     */
    public function prepareReturn(?int $orgId, array $data): FinGstReturn
    {
        if ($orgId === null) {
            throw new InvalidArgumentException('An organisation is required to prepare a GST return.');
        }

        $periodStart = Carbon::parse($data['period_start'])->startOfDay();
        $periodEnd = Carbon::parse($data['period_end'])->startOfDay();

        try {
            return DB::transaction(function () use ($orgId, $data, $periodStart, $periodEnd) {
                $latest = $this->lockPeriodReturnChain(
                    $orgId,
                    $periodStart,
                    $periodEnd,
                )->last();

                if ($latest !== null) {
                    $this->assertSamePreparationContract($latest, $data);
                }

                $snapshot = $this->buildSnapshot(
                    $orgId,
                    (string) $data['basis'],
                    $periodStart,
                    $periodEnd,
                );

                if ($latest === null) {
                    return $this->createSnapshotReturn(
                        $orgId,
                        $data,
                        $periodStart,
                        $periodEnd,
                        1,
                        null,
                        $snapshot,
                    );
                }

                if ($latest->status === 'draft') {
                    return $this->replaceDraftSnapshot($latest, $snapshot);
                }

                if (hash_equals((string) $latest->source_digest, $snapshot['digest'])) {
                    return $latest->load('lines');
                }

                throw new InvalidArgumentException(
                    'This period already has a filed GST return. Prepare an explicit amendment instead of replacing filed evidence.'
                );
            }, attempts: 3);
        } catch (QueryException $exception) {
            if (! $this->isReturnUniquenessCollision($exception)) {
                throw $exception;
            }

            return $this->resolvePreparationRace($orgId, $data, $periodStart, $periodEnd);
        }
    }

    /**
     * Create a new draft revision without mutating the filed source return.
     */
    public function prepareAmendment(FinGstReturn $return): FinGstReturn
    {
        try {
            return DB::transaction(function () use ($return) {
                $periodReturns = $this->lockPeriodReturnChain(
                    (int) $return->organization_id,
                    $return->period_start->copy()->startOfDay(),
                    $return->period_end->copy()->startOfDay(),
                );
                $locked = $periodReturns->firstWhere('id', $return->id);

                if ($locked === null) {
                    throw new InvalidArgumentException('The GST return no longer exists in this period.');
                }

                if ($locked->status !== 'filed') {
                    throw new InvalidArgumentException('Only a filed GST return can be amended.');
                }

                /** @var FinGstReturn $latest */
                $latest = $periodReturns->last();

                if (! $latest->is($locked)) {
                    if ((int) $latest->supersedes_gst_return_id !== (int) $locked->id
                        || $latest->status !== 'draft') {
                        throw new InvalidArgumentException('A later GST return revision already owns this period.');
                    }

                    $snapshot = $this->buildSnapshot(
                        (int) $locked->organization_id,
                        (string) $locked->basis,
                        $locked->period_start->copy()->startOfDay(),
                        $locked->period_end->copy()->startOfDay(),
                    );

                    return $this->replaceDraftSnapshot($latest, $snapshot);
                }

                $snapshot = $this->buildSnapshot(
                    (int) $locked->organization_id,
                    (string) $locked->basis,
                    $locked->period_start->copy()->startOfDay(),
                    $locked->period_end->copy()->startOfDay(),
                );

                if (hash_equals((string) $locked->source_digest, $snapshot['digest'])) {
                    throw new InvalidArgumentException('The filed GST evidence has not changed; no amendment is required.');
                }

                return $this->createSnapshotReturn(
                    (int) $locked->organization_id,
                    [
                        'basis' => $locked->basis,
                        'filing_frequency' => $locked->filing_frequency,
                    ],
                    $locked->period_start->copy()->startOfDay(),
                    $locked->period_end->copy()->startOfDay(),
                    (int) $locked->revision + 1,
                    (int) $locked->id,
                    $snapshot,
                );
            }, attempts: 3);
        } catch (QueryException $exception) {
            if (! $this->isReturnUniquenessCollision($exception)) {
                throw $exception;
            }

            $existing = FinGstReturn::query()
                ->where('supersedes_gst_return_id', $return->id)
                ->first();

            if ($existing === null) {
                throw $exception;
            }

            return $existing->load('lines');
        }
    }

    /**
     * File the snapshot and close its superseded revision atomically.
     */
    public function fileReturn(FinGstReturn $return, int $userId): FinGstReturn
    {
        return DB::transaction(function () use ($return, $userId) {
            $periodReturns = $this->lockPeriodReturnChain(
                (int) $return->organization_id,
                $return->period_start->copy()->startOfDay(),
                $return->period_end->copy()->startOfDay(),
            );
            $locked = $periodReturns->firstWhere('id', $return->id);

            if ($locked === null) {
                throw new InvalidArgumentException('The GST return no longer exists in this period.');
            }

            if ($locked->status === 'filed') {
                return $locked;
            }

            if ($locked->status !== 'draft') {
                throw new InvalidArgumentException('Only a draft GST return can be filed.');
            }

            $snapshot = $this->buildSnapshot(
                (int) $locked->organization_id,
                (string) $locked->basis,
                $locked->period_start->copy()->startOfDay(),
                $locked->period_end->copy()->startOfDay(),
            );

            if (! hash_equals((string) $locked->source_digest, $snapshot['digest'])) {
                throw new InvalidArgumentException(
                    'GST source evidence changed after preparation. Prepare and review the draft again before filing.'
                );
            }

            if ($locked->supersedes_gst_return_id !== null) {
                $superseded = $periodReturns->firstWhere(
                    'id',
                    $locked->supersedes_gst_return_id,
                );

                if ($superseded === null || $superseded->status !== 'filed') {
                    throw new InvalidArgumentException('The amendment source is no longer a filed GST return.');
                }

                $superseded->update(['status' => 'amended']);
            }

            $locked->update([
                'status' => 'filed',
                'filed_at' => now(),
                'filed_by' => $userId,
            ]);

            return $locked->refresh();
        }, attempts: 3);
    }

    public function getReturnSummary(FinGstReturn $return): array
    {
        $return->loadMissing('lines.taxRate');

        $byTaxRate = [];
        foreach ($return->lines as $line) {
            $rateId = $line->tax_rate_id;
            if (! isset($byTaxRate[$rateId])) {
                $byTaxRate[$rateId] = [
                    'tax_rate_id' => $rateId,
                    'name' => $line->taxRate->name ?? 'Unknown',
                    'code' => $line->taxRate->code ?? '',
                    'rate' => $line->taxRate->rate ?? '0',
                    'net_amount' => '0',
                    'gst_amount' => '0',
                    'line_count' => 0,
                ];
            }

            $byTaxRate[$rateId]['net_amount'] = bcadd(
                $byTaxRate[$rateId]['net_amount'],
                (string) $line->net_amount,
                2,
            );
            $byTaxRate[$rateId]['gst_amount'] = bcadd(
                $byTaxRate[$rateId]['gst_amount'],
                (string) $line->gst_amount,
                2,
            );
            $byTaxRate[$rateId]['line_count']++;
        }

        return [
            'total_sales' => (float) $return->total_sales,
            'total_gst_collected' => (float) $return->total_gst_collected,
            'total_purchases' => (float) $return->total_purchases,
            'total_gst_paid' => (float) $return->total_gst_paid,
            'gst_payable' => (float) $return->gst_payable,
            'adjustments' => (float) $return->adjustments,
            'net_gst' => (float) $return->gst_payable,
            'is_refund' => bccomp((string) $return->gst_payable, '0', 2) < 0,
            'breakdown_by_tax_rate' => array_values($byTaxRate),
        ];
    }

    public function calculateIrdPeriod(string $periodEnd): string
    {
        return Carbon::parse($periodEnd)->format('Ym');
    }

    /**
     * @return array{lines:array<int, array<string, mixed>>,digest:string,total_sales:string,total_gst_collected:string,total_purchases:string,total_gst_paid:string,gst_payable:string}
     */
    private function buildSnapshot(
        int $organizationId,
        string $basis,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): array {
        $components = collect();

        $components = $components->concat(
            in_array($basis, ['invoice', 'hybrid'], true)
                ? $this->invoiceDateComponents($organizationId, $periodStart, $periodEnd)
                : $this->paymentDateComponents(
                    $organizationId,
                    FinInvoice::class,
                    'receivable',
                    self::SIDE_SALES,
                    $periodStart,
                    $periodEnd,
                ),
        );

        $components = $components->concat(
            $basis === 'invoice'
                ? $this->billDateComponents($organizationId, $periodStart, $periodEnd)
                : $this->paymentDateComponents(
                    $organizationId,
                    FinBill::class,
                    'payable',
                    self::SIDE_PURCHASES,
                    $periodStart,
                    $periodEnd,
                ),
        );

        $components = $components
            ->concat($this->creditDateComponents($organizationId, $periodStart, $periodEnd))
            ->concat($this->manualJournalComponents($organizationId, $periodStart, $periodEnd))
            ->sortBy([
                ['recognition_date', 'asc'],
                ['source_key', 'asc'],
            ])
            ->values();

        if ($components->pluck('source_key')->unique()->count() !== $components->count()) {
            throw new InvalidArgumentException('GST source evidence contains duplicate recognition keys.');
        }

        $totalSales = '0.00';
        $totalGstCollected = '0.00';
        $totalPurchases = '0.00';
        $totalGstPaid = '0.00';

        foreach ($components as $component) {
            if ($component['side'] === self::SIDE_SALES) {
                $totalSales = bcadd($totalSales, $component['net_amount'], 2);
                $totalGstCollected = bcadd($totalGstCollected, $component['gst_amount'], 2);
            } else {
                $totalPurchases = bcadd($totalPurchases, $component['net_amount'], 2);
                $totalGstPaid = bcadd($totalGstPaid, $component['gst_amount'], 2);
            }
        }

        $lines = $components->map(function (array $component): array {
            ksort($component, SORT_STRING);

            return $component;
        })->all();

        return [
            'lines' => $lines,
            'digest' => hash('sha256', json_encode($lines, JSON_THROW_ON_ERROR)),
            'total_sales' => $totalSales,
            'total_gst_collected' => $totalGstCollected,
            'total_purchases' => $totalPurchases,
            'total_gst_paid' => $totalGstPaid,
            'gst_payable' => bcsub($totalGstCollected, $totalGstPaid, 2),
        ];
    }

    private function invoiceDateComponents(
        int $organizationId,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): Collection {
        return FinInvoice::query()
            ->where('organization_id', $organizationId)
            ->whereNotNull('journal_id')
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereBetween('invoice_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->whereHas('journal', fn ($query) => $query->where('status', 'posted'))
            ->with('lines.taxRate')
            ->orderBy('id')
            ->get()
            ->flatMap(fn (FinInvoice $invoice) => $this->invoiceSourceLines($invoice)
                ->map(fn (array $line) => $this->component(
                    side: self::SIDE_SALES,
                    source: $invoice,
                    sourceLineType: FinInvoiceLine::class,
                    sourceLineId: $line['line_id'],
                    recognition: $invoice,
                    recognitionDate: $invoice->invoice_date->toDateString(),
                    accountId: $line['account_id'],
                    description: $line['description'],
                    netAmount: $line['net_amount'],
                    gstAmount: $line['gst_amount'],
                    taxRateId: $line['tax_rate_id'],
                )));
    }

    private function billDateComponents(
        int $organizationId,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): Collection {
        return FinBill::query()
            ->where('organization_id', $organizationId)
            ->whereNotNull('journal_id')
            ->whereIn('status', ['approved', 'partially_paid', 'paid'])
            ->whereBetween('bill_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->whereHas('journal', fn ($query) => $query->where('status', 'posted'))
            ->with('lines.taxRate')
            ->orderBy('id')
            ->get()
            ->flatMap(fn (FinBill $bill) => $this->billSourceLines($bill)
                ->map(fn (array $line) => $this->component(
                    side: self::SIDE_PURCHASES,
                    source: $bill,
                    sourceLineType: FinBillLine::class,
                    sourceLineId: $line['line_id'],
                    recognition: $bill,
                    recognitionDate: $bill->bill_date->toDateString(),
                    accountId: $line['account_id'],
                    description: $line['description'],
                    netAmount: $line['net_amount'],
                    gstAmount: $line['gst_amount'],
                    taxRateId: $line['tax_rate_id'],
                )));
    }

    private function creditDateComponents(
        int $organizationId,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): Collection {
        return FinCreditNote::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'approved')
            ->whereNotNull('journal_id')
            ->whereBetween('credit_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->whereHas('journal', fn ($query) => $query->where('status', 'posted'))
            ->with('lines.taxRate')
            ->orderBy('id')
            ->get()
            ->flatMap(function (FinCreditNote $creditNote) {
                $side = $creditNote->type === 'receivable'
                    ? self::SIDE_SALES
                    : self::SIDE_PURCHASES;

                return $this->creditSourceLines($creditNote)
                    ->map(fn (array $line) => $this->component(
                        side: $side,
                        source: $creditNote,
                        sourceLineType: FinCreditNoteLine::class,
                        sourceLineId: $line['line_id'],
                        recognition: $creditNote,
                        recognitionDate: $creditNote->credit_date->toDateString(),
                        accountId: $line['account_id'],
                        description: $line['description'],
                        netAmount: bcsub('0.00', $line['net_amount'], 2),
                        gstAmount: bcsub('0.00', $line['gst_amount'], 2),
                        taxRateId: $line['tax_rate_id'],
                    ));
            });
    }

    private function paymentDateComponents(
        int $organizationId,
        string $documentClass,
        string $allocationType,
        string $side,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): Collection {
        $allocations = FinPaymentAllocation::query()
            ->where('organization_id', $organizationId)
            ->where('type', $allocationType)
            ->where('integrity_state', FinPaymentAllocation::INTEGRITY_TRACEABLE)
            ->where('allocatable_type', $documentClass)
            ->whereDate('payment_date', '<=', $periodEnd->toDateString())
            ->whereHas('journal', fn ($query) => $query->where('status', 'posted'))
            ->orderBy('allocatable_id')
            ->orderBy('payment_date')
            ->orderBy('id')
            ->get();

        if ($allocations->isEmpty()) {
            return collect();
        }

        $documents = $documentClass::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $allocations->pluck('allocatable_id')->unique())
            ->whereNotNull('journal_id')
            ->whereHas('journal', fn ($query) => $query->where('status', 'posted'))
            ->with('lines.taxRate')
            ->get()
            ->keyBy('id');

        $components = collect();

        foreach ($allocations->groupBy('allocatable_id') as $documentId => $documentAllocations) {
            $document = $documents->get($documentId);
            if ($document === null) {
                throw new InvalidArgumentException(
                    "Payment allocations for {$documentClass} #{$documentId} have no posted canonical source document."
                );
            }

            $sourceLines = $document instanceof FinInvoice
                ? $this->invoiceSourceLines($document)
                : $this->billSourceLines($document);
            $documentCents = $this->moneyToCents((string) $document->total_amount);

            if ($documentCents <= 0) {
                throw new InvalidArgumentException(
                    "{$documentClass} #{$documentId} has a non-positive total and cannot support payments-basis GST."
                );
            }

            $cumulativeCents = 0;
            foreach ($documentAllocations as $allocation) {
                $beforeCents = min($cumulativeCents, $documentCents);
                $cumulativeCents += $this->moneyToCents((string) $allocation->amount);
                $afterCents = min($cumulativeCents, $documentCents);

                if (! $allocation->payment_date->betweenIncluded($periodStart, $periodEnd)) {
                    continue;
                }

                foreach ($sourceLines as $sourceLine) {
                    $netCents = $this->cumulativeDeltaCents(
                        $this->moneyToCents($sourceLine['net_amount']),
                        $beforeCents,
                        $afterCents,
                        $documentCents,
                    );
                    $gstCents = $this->cumulativeDeltaCents(
                        $this->moneyToCents($sourceLine['gst_amount']),
                        $beforeCents,
                        $afterCents,
                        $documentCents,
                    );

                    if ($netCents === 0 && $gstCents === 0) {
                        continue;
                    }

                    $components->push($this->component(
                        side: $side,
                        source: $document,
                        sourceLineType: $document instanceof FinInvoice
                            ? FinInvoiceLine::class
                            : FinBillLine::class,
                        sourceLineId: $sourceLine['line_id'],
                        recognition: $allocation,
                        recognitionDate: $allocation->payment_date->toDateString(),
                        accountId: $sourceLine['account_id'],
                        description: $sourceLine['description'],
                        netAmount: $this->centsToMoney($netCents),
                        gstAmount: $this->centsToMoney($gstCents),
                        taxRateId: $sourceLine['tax_rate_id'],
                    ));
                }
            }
        }

        return $components;
    }

    private function manualJournalComponents(
        int $organizationId,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): Collection {
        return FinJournalLine::query()
            ->whereNotNull('tax_rate_id')
            ->whereHas('journal', function ($query) use ($organizationId, $periodStart, $periodEnd) {
                $query->where('organization_id', $organizationId)
                    ->where('status', 'posted')
                    ->whereBetween('journal_date', [
                        $periodStart->toDateString(),
                        $periodEnd->toDateString(),
                    ])
                    ->where(function ($sourceQuery) {
                        $sourceQuery->whereNull('source_type')
                            ->orWhereNotIn('source_type', [
                                FinInvoice::class,
                                FinBill::class,
                                FinCreditNote::class,
                            ]);
                    });
            })
            ->with(['account:id,type', 'taxRate:id,organization_id', 'journal:id,journal_date,journal_number'])
            ->orderBy('id')
            ->get()
            ->map(function (FinJournalLine $line) use ($organizationId) {
                if ((int) $line->taxRate?->organization_id !== $organizationId) {
                    throw new InvalidArgumentException(
                        "Journal line #{$line->id} references a tax rate outside the organisation."
                    );
                }

                $isSales = ($line->account?->type ?? '') === 'revenue';
                $netAmount = $isSales
                    ? bcsub((string) $line->credit, (string) $line->debit, 2)
                    : bcsub((string) $line->debit, (string) $line->credit, 2);

                return $this->component(
                    side: $isSales ? self::SIDE_SALES : self::SIDE_PURCHASES,
                    source: $line->journal,
                    sourceLineType: FinJournalLine::class,
                    sourceLineId: (int) $line->id,
                    recognition: $line->journal,
                    recognitionDate: $line->journal->journal_date->toDateString(),
                    accountId: (int) $line->account_id,
                    description: $line->description ?? $line->journal->journal_number,
                    netAmount: $netAmount,
                    gstAmount: (string) $line->tax_amount,
                    taxRateId: (int) $line->tax_rate_id,
                    journalLineId: (int) $line->id,
                );
            });
    }

    /** @return Collection<int, array{line_id:int,account_id:?int,description:string,net_amount:string,gst_amount:string,tax_rate_id:int}> */
    private function invoiceSourceLines(FinInvoice $invoice): Collection
    {
        return $invoice->lines->map(function (FinInvoiceLine $line) use ($invoice): array {
            $taxRate = $this->gstTaxRateResolver->resolveInvoiceRate(
                (int) $invoice->organization_id,
                $line->tax_rate_id === null ? null : (int) $line->tax_rate_id,
                (string) $line->tax_amount,
                "Invoice {$invoice->invoice_number} line #{$line->id}",
            );

            if ($taxRate === null) {
                throw new InvalidArgumentException(
                    "Invoice {$invoice->invoice_number} line #{$line->id} has no canonical tax classification."
                );
            }

            return [
                'line_id' => (int) $line->id,
                'account_id' => $line->account_id === null ? null : (int) $line->account_id,
                'description' => (string) $line->description,
                'net_amount' => bcsub((string) $line->line_total, (string) $line->tax_amount, 2),
                'gst_amount' => (string) $line->tax_amount,
                'tax_rate_id' => (int) $taxRate->id,
            ];
        });
    }

    /** @return Collection<int, array{line_id:int,account_id:?int,description:string,net_amount:string,gst_amount:string,tax_rate_id:int}> */
    private function billSourceLines(FinBill $bill): Collection
    {
        return $bill->lines->map(function (FinBillLine $line) use ($bill): array {
            $taxRate = $this->gstTaxRateResolver->resolveStoredRate(
                (int) $bill->organization_id,
                $line->tax_rate_id === null ? null : (int) $line->tax_rate_id,
                (string) $line->gst_rate,
                "Bill {$bill->bill_number} line #{$line->id}",
            );

            if ($taxRate === null) {
                throw new InvalidArgumentException(
                    "Bill {$bill->bill_number} line #{$line->id} has no canonical tax classification."
                );
            }

            return [
                'line_id' => (int) $line->id,
                'account_id' => $line->account_id === null ? null : (int) $line->account_id,
                'description' => (string) $line->description,
                'net_amount' => bcsub((string) $line->line_total, (string) $line->gst_amount, 2),
                'gst_amount' => (string) $line->gst_amount,
                'tax_rate_id' => (int) $taxRate->id,
            ];
        });
    }

    /** @return Collection<int, array{line_id:int,account_id:?int,description:string,net_amount:string,gst_amount:string,tax_rate_id:int}> */
    private function creditSourceLines(FinCreditNote $creditNote): Collection
    {
        return $creditNote->lines->map(function (FinCreditNoteLine $line) use ($creditNote): array {
            $taxRate = $this->gstTaxRateResolver->resolveStoredRate(
                (int) $creditNote->organization_id,
                $line->tax_rate_id === null ? null : (int) $line->tax_rate_id,
                (string) $line->gst_rate,
                "Credit note {$creditNote->credit_note_number} line #{$line->id}",
            );

            if ($taxRate === null) {
                throw new InvalidArgumentException(
                    "Credit note {$creditNote->credit_note_number} line #{$line->id} has no canonical tax classification."
                );
            }

            return [
                'line_id' => (int) $line->id,
                'account_id' => $line->account_id === null ? null : (int) $line->account_id,
                'description' => (string) $line->description,
                'net_amount' => bcsub((string) $line->line_total, (string) $line->gst_amount, 2),
                'gst_amount' => (string) $line->gst_amount,
                'tax_rate_id' => (int) $taxRate->id,
            ];
        });
    }

    /** @return array<string, mixed> */
    private function component(
        string $side,
        object $source,
        string $sourceLineType,
        int $sourceLineId,
        object $recognition,
        string $recognitionDate,
        ?int $accountId,
        string $description,
        string $netAmount,
        string $gstAmount,
        int $taxRateId,
        ?int $journalLineId = null,
    ): array {
        $sourceType = $source->getMorphClass();
        $sourceId = (int) $source->getKey();
        $recognitionType = $recognition->getMorphClass();
        $recognitionId = (int) $recognition->getKey();
        $sourceKey = hash('sha256', implode('|', [
            $sourceType,
            $sourceId,
            $sourceLineType,
            $sourceLineId,
            $recognitionType,
            $recognitionId,
        ]));

        return [
            'journal_line_id' => $journalLineId,
            'account_id' => $accountId,
            'description' => $description,
            'net_amount' => $netAmount,
            'gst_amount' => $gstAmount,
            'tax_rate_id' => $taxRateId,
            'side' => $side,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_line_type' => $sourceLineType,
            'source_line_id' => $sourceLineId,
            'recognition_type' => $recognitionType,
            'recognition_id' => $recognitionId,
            'recognition_date' => $recognitionDate,
            'source_key' => $sourceKey,
        ];
    }

    private function assertSamePreparationContract(FinGstReturn $return, array $data): void
    {
        if ($return->basis !== $data['basis']
            || $return->filing_frequency !== $data['filing_frequency']) {
            throw new InvalidArgumentException(
                'This GST period already exists with a different basis or filing frequency.'
            );
        }
    }

    private function createSnapshotReturn(
        int $organizationId,
        array $data,
        Carbon $periodStart,
        Carbon $periodEnd,
        int $revision,
        ?int $supersedesId,
        array $snapshot,
    ): FinGstReturn {
        $return = FinGstReturn::create([
            'organization_id' => $organizationId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'filing_frequency' => $data['filing_frequency'],
            'basis' => $data['basis'],
            'revision' => $revision,
            'supersedes_gst_return_id' => $supersedesId,
            'source_digest' => $snapshot['digest'],
            'prepared_at' => now(),
            'total_sales' => $snapshot['total_sales'],
            'total_gst_collected' => $snapshot['total_gst_collected'],
            'total_purchases' => $snapshot['total_purchases'],
            'total_gst_paid' => $snapshot['total_gst_paid'],
            'gst_payable' => $snapshot['gst_payable'],
            'adjustments' => 0,
            'status' => 'draft',
            'ird_period' => $this->calculateIrdPeriod($periodEnd->toDateString()),
            'created_by' => Auth::id(),
        ]);

        $return->lines()->createMany($snapshot['lines']);

        return $return->load('lines');
    }

    private function replaceDraftSnapshot(FinGstReturn $return, array $snapshot): FinGstReturn
    {
        if (hash_equals((string) $return->source_digest, $snapshot['digest'])) {
            return $return->load('lines');
        }

        $return->lines()->delete();
        $return->update([
            'source_digest' => $snapshot['digest'],
            'prepared_at' => now(),
            'total_sales' => $snapshot['total_sales'],
            'total_gst_collected' => $snapshot['total_gst_collected'],
            'total_purchases' => $snapshot['total_purchases'],
            'total_gst_paid' => $snapshot['total_gst_paid'],
            'gst_payable' => $snapshot['gst_payable'],
        ]);
        $return->lines()->createMany($snapshot['lines']);

        return $return->refresh()->load('lines');
    }

    private function resolvePreparationRace(
        int $organizationId,
        array $data,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): FinGstReturn {
        return DB::transaction(function () use (
            $organizationId,
            $data,
            $periodStart,
            $periodEnd,
        ) {
            $winner = $this->lockPeriodReturnChain(
                $organizationId,
                $periodStart,
                $periodEnd,
            )->last();

            if ($winner === null) {
                throw new InvalidArgumentException('Concurrent GST preparation did not produce a stable winner.');
            }

            $this->assertSamePreparationContract($winner, $data);

            $snapshot = $this->buildSnapshot(
                $organizationId,
                (string) $data['basis'],
                $periodStart,
                $periodEnd,
            );

            if ($winner->status === 'draft') {
                return $this->replaceDraftSnapshot($winner, $snapshot);
            }

            if (hash_equals((string) $winner->source_digest, $snapshot['digest'])) {
                return $winner->load('lines');
            }

            throw new InvalidArgumentException(
                'This period already has a filed GST return. Prepare an explicit amendment instead of replacing filed evidence.'
            );
        }, attempts: 3);
    }

    /**
     * Lock every revision for a GST period in one canonical oldest-to-newest
     * query. Prepare, amend, and file all use this order, so no operation can
     * acquire a newer revision and then wait for an older one.
     *
     * @return Collection<int, FinGstReturn>
     */
    private function lockPeriodReturnChain(
        int $organizationId,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): Collection {
        return FinGstReturn::query()
            ->where('organization_id', $organizationId)
            ->where('period_start', $periodStart->toDateString())
            ->where('period_end', $periodEnd->toDateString())
            ->orderBy('revision')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function isReturnUniquenessCollision(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = strtolower($exception->getMessage());

        return ($sqlState === '23000' || $driverCode === 1062)
            && (str_contains($message, 'fin_gst_returns_period_revision_unique')
                || str_contains($message, 'fin_gst_returns_supersedes_unique'));
    }

    private function moneyToCents(string $amount): int
    {
        return (int) bcmul($amount, '100', 0);
    }

    private function centsToMoney(int $cents): string
    {
        return bcdiv((string) $cents, '100', 2);
    }

    private function cumulativeDeltaCents(
        int $componentCents,
        int $beforeGrossCents,
        int $afterGrossCents,
        int $documentGrossCents,
    ): int {
        return $this->cumulativePortionCents(
            $componentCents,
            $afterGrossCents,
            $documentGrossCents,
        ) - $this->cumulativePortionCents(
            $componentCents,
            $beforeGrossCents,
            $documentGrossCents,
        );
    }

    private function cumulativePortionCents(
        int $componentCents,
        int $cumulativeGrossCents,
        int $documentGrossCents,
    ): int {
        $numerator = bcadd(
            bcmul((string) $componentCents, (string) $cumulativeGrossCents, 0),
            (string) intdiv($documentGrossCents, 2),
            0,
        );

        return (int) bcdiv($numerator, (string) $documentGrossCents, 0);
    }
}
