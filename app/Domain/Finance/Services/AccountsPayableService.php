<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinCreditNote;
use App\Domain\Finance\Models\FinVendor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AccountsPayableService
{
    public function __construct(
        private JournalPostingService $journalPostingService,
    ) {}

    /**
     * Create a bill with lines. Auto-generate bill_number if not provided.
     */
    public function createBill(?int $orgId, array $data): FinBill
    {
        return DB::transaction(function () use ($orgId, $data) {
            $billNumber = ! empty($data['bill_number'])
                ? $data['bill_number']
                : $this->generateBillNumber($orgId);

            $bill = FinBill::create([
                'organization_id' => $orgId,
                'vendor_id' => $data['vendor_id'],
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'bill_number' => $billNumber,
                'vendor_reference' => $data['vendor_reference'] ?? null,
                'status' => 'draft',
                'bill_date' => $data['bill_date'],
                'due_date' => $data['due_date'],
                'subtotal' => 0,
                'gst_amount' => 0,
                'total_amount' => 0,
                'amount_paid' => 0,
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);

            $subtotal = '0';
            $gstAmount = '0';

            foreach ($data['lines'] as $line) {
                $qty = (string) $line['quantity'];
                $price = (string) $line['unit_price'];
                $gstRate = (string) ($line['gst_rate'] ?? 15);

                $lineSubtotal = bcmul($qty, $price, 2);
                $lineGst = bcmul($lineSubtotal, bcdiv($gstRate, '100', 6), 2);
                $lineTotal = bcadd($lineSubtotal, $lineGst, 2);

                $bill->lines()->create([
                    'description' => $line['description'],
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'gst_rate' => $gstRate,
                    'gst_amount' => $lineGst,
                    'line_total' => $lineTotal,
                    'account_id' => $line['account_id'],
                    'cost_centre_id' => $line['cost_centre_id'] ?? null,
                    'funding_stream_id' => $line['funding_stream_id'] ?? null,
                ]);

                $subtotal = bcadd($subtotal, $lineSubtotal, 2);
                $gstAmount = bcadd($gstAmount, $lineGst, 2);
            }

            $totalAmount = bcadd($subtotal, $gstAmount, 2);

            $bill->update([
                'subtotal' => $subtotal,
                'gst_amount' => $gstAmount,
                'total_amount' => $totalAmount,
            ]);

            return $bill->load('lines', 'vendor');
        });
    }

    /**
     * Update a bill. Only allowed if draft.
     */
    public function updateBill(FinBill $bill, array $data): FinBill
    {
        if ($bill->status !== 'draft') {
            throw new InvalidArgumentException('Only draft bills can be updated.');
        }

        return DB::transaction(function () use ($bill, $data) {
            $bill->update([
                'vendor_id' => $data['vendor_id'],
                'vendor_reference' => $data['vendor_reference'] ?? null,
                'bill_date' => $data['bill_date'],
                'due_date' => $data['due_date'],
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            // Delete existing lines and recreate
            $bill->lines()->delete();

            $subtotal = '0';
            $gstAmount = '0';

            foreach ($data['lines'] as $line) {
                $qty = (string) $line['quantity'];
                $price = (string) $line['unit_price'];
                $gstRate = (string) ($line['gst_rate'] ?? 15);

                $lineSubtotal = bcmul($qty, $price, 2);
                $lineGst = bcmul($lineSubtotal, bcdiv($gstRate, '100', 6), 2);
                $lineTotal = bcadd($lineSubtotal, $lineGst, 2);

                $bill->lines()->create([
                    'description' => $line['description'],
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'gst_rate' => $gstRate,
                    'gst_amount' => $lineGst,
                    'line_total' => $lineTotal,
                    'account_id' => $line['account_id'],
                    'cost_centre_id' => $line['cost_centre_id'] ?? null,
                    'funding_stream_id' => $line['funding_stream_id'] ?? null,
                ]);

                $subtotal = bcadd($subtotal, $lineSubtotal, 2);
                $gstAmount = bcadd($gstAmount, $lineGst, 2);
            }

            $totalAmount = bcadd($subtotal, $gstAmount, 2);

            $bill->update([
                'subtotal' => $subtotal,
                'gst_amount' => $gstAmount,
                'total_amount' => $totalAmount,
            ]);

            return $bill->load('lines', 'vendor');
        });
    }

    /**
     * Approve a bill and create the GL journal entry.
     * DR expense accounts (per line), CR Accounts Payable (code '2000').
     */
    public function approveBill(FinBill $bill, int $userId): FinBill
    {
        if (! in_array($bill->status, ['draft', 'awaiting_approval'])) {
            throw new InvalidArgumentException('Only draft or awaiting approval bills can be approved.');
        }

        return DB::transaction(function () use ($bill, $userId) {
            $bill->loadMissing('lines');

            $apAccount = $this->findApAccount($bill->organization_id);

            $journalLines = [];

            // DR expense accounts for each bill line
            foreach ($bill->lines as $line) {
                $journalLines[] = [
                    'account_id' => $line->account_id,
                    'description' => $line->description,
                    'debit' => $line->line_total,
                    'credit' => 0,
                    'cost_centre_id' => $line->cost_centre_id,
                    'funding_stream_id' => $line->funding_stream_id,
                ];
            }

            // CR Accounts Payable for the total amount
            $journalLines[] = [
                'account_id' => $apAccount->id,
                'description' => 'Bill '.$bill->bill_number.' — '.($bill->vendor?->name ?? 'Unknown vendor'),
                'debit' => 0,
                'credit' => $bill->total_amount,
            ];

            $journal = $this->journalPostingService->createAndPost($bill->organization_id, [
                'journal_date' => $bill->bill_date->toDateString(),
                'type' => 'standard',
                'reference' => $bill->bill_number,
                'description' => "Bill {$bill->bill_number} approved",
                'source_type' => FinBill::class,
                'source_id' => $bill->id,
                'lines' => $journalLines,
            ]);

            $bill->update([
                'status' => 'approved',
                'approved_by' => $userId,
                'approved_at' => now(),
                'journal_id' => $journal->id,
            ]);

            return $bill->refresh();
        });
    }

    /**
     * Cancel a bill. Only allowed if draft or awaiting_approval.
     */
    public function cancelBill(FinBill $bill): FinBill
    {
        if (! in_array($bill->status, ['draft', 'awaiting_approval'])) {
            throw new InvalidArgumentException('Only draft or awaiting approval bills can be cancelled.');
        }

        $bill->update(['status' => 'cancelled']);

        return $bill->refresh();
    }

    /**
     * Record a payment against a bill.
     */
    public function recordPayment(FinBill $bill, float $amount): FinBill
    {
        $newPaid = bcadd((string) $bill->amount_paid, (string) $amount, 2);

        $status = $bill->status;
        if (bccomp($newPaid, (string) $bill->total_amount, 2) >= 0) {
            $status = 'paid';
        } else {
            $status = 'partially_paid';
        }

        $bill->update([
            'amount_paid' => $newPaid,
            'status' => $status,
        ]);

        return $bill->refresh();
    }

    /**
     * Create a credit note (type='payable' for AP).
     */
    public function createCreditNote(?int $orgId, array $data): FinCreditNote
    {
        return DB::transaction(function () use ($orgId, $data) {
            $creditNoteNumber = $this->generateCreditNoteNumber($orgId);

            $creditNote = FinCreditNote::create([
                'organization_id' => $orgId,
                'credit_note_number' => $creditNoteNumber,
                'type' => $data['type'] ?? 'payable',
                'vendor_id' => $data['vendor_id'] ?? null,
                'client_id' => $data['client_id'] ?? null,
                'bill_id' => $data['bill_id'] ?? null,
                'invoice_id' => $data['invoice_id'] ?? null,
                'status' => 'draft',
                'credit_date' => $data['credit_date'],
                'subtotal' => 0,
                'gst_amount' => 0,
                'total_amount' => 0,
                'reason' => $data['reason'] ?? null,
                'created_by' => Auth::id(),
            ]);

            $subtotal = '0';
            $gstAmount = '0';

            foreach ($data['lines'] as $line) {
                $qty = (string) $line['quantity'];
                $price = (string) $line['unit_price'];
                $gstRate = (string) ($line['gst_rate'] ?? 15);

                $lineSubtotal = bcmul($qty, $price, 2);
                $lineGst = bcmul($lineSubtotal, bcdiv($gstRate, '100', 6), 2);
                $lineTotal = bcadd($lineSubtotal, $lineGst, 2);

                $creditNote->lines()->create([
                    'description' => $line['description'],
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'gst_rate' => $gstRate,
                    'gst_amount' => $lineGst,
                    'line_total' => $lineTotal,
                    'account_id' => $line['account_id'],
                ]);

                $subtotal = bcadd($subtotal, $lineSubtotal, 2);
                $gstAmount = bcadd($gstAmount, $lineGst, 2);
            }

            $totalAmount = bcadd($subtotal, $gstAmount, 2);

            $creditNote->update([
                'subtotal' => $subtotal,
                'gst_amount' => $gstAmount,
                'total_amount' => $totalAmount,
            ]);

            return $creditNote->load('lines');
        });
    }

    /**
     * Approve a credit note and create a reversing GL journal.
     */
    public function approveCreditNote(FinCreditNote $cn, int $userId): FinCreditNote
    {
        if ($cn->status !== 'draft') {
            throw new InvalidArgumentException('Only draft credit notes can be approved.');
        }

        return DB::transaction(function () use ($cn, $userId) {
            $cn->loadMissing('lines');

            $journalLines = [];

            if ($cn->type === 'payable') {
                // For AP credit notes: DR Accounts Payable, CR expense accounts
                $apAccount = $this->findApAccount($cn->organization_id);

                // DR Accounts Payable
                $journalLines[] = [
                    'account_id' => $apAccount->id,
                    'description' => "Credit Note {$cn->credit_note_number}",
                    'debit' => $cn->total_amount,
                    'credit' => 0,
                ];

                // CR expense accounts for each line
                foreach ($cn->lines as $line) {
                    $journalLines[] = [
                        'account_id' => $line->account_id,
                        'description' => $line->description,
                        'debit' => 0,
                        'credit' => $line->line_total,
                    ];
                }
            } else {
                // For AR credit notes: DR revenue accounts, CR Accounts Receivable
                $arAccount = FinAccount::forOrganization($cn->organization_id)
                    ->where('code', '1100')
                    ->firstOrFail();

                // DR revenue accounts for each line
                foreach ($cn->lines as $line) {
                    $journalLines[] = [
                        'account_id' => $line->account_id,
                        'description' => $line->description,
                        'debit' => $line->line_total,
                        'credit' => 0,
                    ];
                }

                // CR Accounts Receivable
                $journalLines[] = [
                    'account_id' => $arAccount->id,
                    'description' => "Credit Note {$cn->credit_note_number}",
                    'debit' => 0,
                    'credit' => $cn->total_amount,
                ];
            }

            $journal = $this->journalPostingService->createAndPost($cn->organization_id, [
                'journal_date' => $cn->credit_date->toDateString(),
                'type' => 'adjustment',
                'reference' => $cn->credit_note_number,
                'description' => "Credit Note {$cn->credit_note_number} approved",
                'source_type' => FinCreditNote::class,
                'source_id' => $cn->id,
                'lines' => $journalLines,
            ]);

            $cn->update([
                'status' => 'approved',
                'approved_by' => $userId,
                'approved_at' => now(),
                'journal_id' => $journal->id,
            ]);

            return $cn->refresh();
        });
    }

    /**
     * Get aged payables report for an organisation.
     */
    public function getAgedPayables(?int $orgId): array
    {
        $today = Carbon::today();

        $vendors = FinVendor::forOrganization($orgId)
            ->active()
            ->with(['bills' => function ($query) {
                $query->whereColumn('amount_paid', '<', 'total_amount')
                    ->whereNotIn('status', ['cancelled', 'draft']);
            }])
            ->get();

        $result = [];

        foreach ($vendors as $vendor) {
            if ($vendor->bills->isEmpty()) {
                continue;
            }

            $buckets = [
                'current' => ['count' => 0, 'total' => 0],
                '1_30' => ['count' => 0, 'total' => 0],
                '31_60' => ['count' => 0, 'total' => 0],
                '61_90' => ['count' => 0, 'total' => 0],
                '90_plus' => ['count' => 0, 'total' => 0],
            ];

            $vendorTotal = 0;

            foreach ($vendor->bills as $bill) {
                $amountDue = (float) $bill->total_amount - (float) $bill->amount_paid;
                $daysOverdue = $bill->due_date->lt($today)
                    ? $bill->due_date->diffInDays($today)
                    : 0;

                if ($daysOverdue === 0) {
                    $buckets['current']['count']++;
                    $buckets['current']['total'] = round($buckets['current']['total'] + $amountDue, 2);
                } elseif ($daysOverdue <= 30) {
                    $buckets['1_30']['count']++;
                    $buckets['1_30']['total'] = round($buckets['1_30']['total'] + $amountDue, 2);
                } elseif ($daysOverdue <= 60) {
                    $buckets['31_60']['count']++;
                    $buckets['31_60']['total'] = round($buckets['31_60']['total'] + $amountDue, 2);
                } elseif ($daysOverdue <= 90) {
                    $buckets['61_90']['count']++;
                    $buckets['61_90']['total'] = round($buckets['61_90']['total'] + $amountDue, 2);
                } else {
                    $buckets['90_plus']['count']++;
                    $buckets['90_plus']['total'] = round($buckets['90_plus']['total'] + $amountDue, 2);
                }

                $vendorTotal = round($vendorTotal + $amountDue, 2);
            }

            $result[] = [
                'vendor_id' => $vendor->id,
                'vendor_name' => $vendor->name,
                'buckets' => $buckets,
                'total' => $vendorTotal,
            ];
        }

        return $result;
    }

    /**
     * Find the Accounts Payable GL account (code '2000') for the org.
     */
    public function findApAccount(?int $orgId): FinAccount
    {
        return FinAccount::forOrganization($orgId)
            ->where('code', '2000')
            ->firstOrFail();
    }

    /**
     * Generate the next sequential bill number for an organisation.
     * Format: BILL-YYYYMM-001
     */
    private function generateBillNumber(?int $orgId): string
    {
        $prefix = 'BILL-'.now()->format('Ym').'-';

        $maxNumber = FinBill::where('organization_id', $orgId)
            ->where('bill_number', 'like', $prefix.'%')
            ->selectRaw('MAX(CAST(SUBSTRING(bill_number, '.(strlen($prefix) + 1).') AS UNSIGNED)) as max_num')
            ->value('max_num');

        $next = ($maxNumber ?? 0) + 1;

        return $prefix.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Generate the next sequential credit note number for an organisation.
     * Format: CN-YYYYMM-001
     */
    private function generateCreditNoteNumber(?int $orgId): string
    {
        $prefix = 'CN-'.now()->format('Ym').'-';

        $maxNumber = FinCreditNote::where('organization_id', $orgId)
            ->where('credit_note_number', 'like', $prefix.'%')
            ->selectRaw('MAX(CAST(SUBSTRING(credit_note_number, '.(strlen($prefix) + 1).') AS UNSIGNED)) as max_num')
            ->value('max_num');

        $next = ($maxNumber ?? 0) + 1;

        return $prefix.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }
}
