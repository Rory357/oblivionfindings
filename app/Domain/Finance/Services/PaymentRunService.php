<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinPaymentRun;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class PaymentRunService
{
    public function __construct(
        private JournalPostingService $journalPostingService,
        private AccountsPayableService $accountsPayableService,
    ) {}

    /**
     * Create a new payment run from a set of approved/partially-paid bills.
     */
    public function createPaymentRun(int $orgId, array $data): FinPaymentRun
    {
        return DB::transaction(function () use ($orgId, $data) {
            $runNumber = $this->generateRunNumber($orgId);

            $bills = FinBill::forOrganization($orgId)
                ->whereIn('id', $data['bill_ids'])
                ->whereIn('status', ['approved', 'partially_paid'])
                ->with('vendor')
                ->get();

            if ($bills->isEmpty()) {
                throw new InvalidArgumentException('No valid approved or partially-paid bills found for the selected IDs.');
            }

            $totalAmount = '0';
            $items = [];

            foreach ($bills as $bill) {
                $amountDue = $bill->getAmountDue();
                if ($amountDue <= 0) {
                    continue;
                }

                $totalAmount = bcadd($totalAmount, (string) $amountDue, 2);

                $items[] = [
                    'bill_id' => $bill->id,
                    'vendor_id' => $bill->vendor_id,
                    'amount' => $amountDue,
                    'reference' => $bill->bill_number,
                    'bank_account_number' => $bill->vendor->bank_account_number ?? '',
                    'status' => 'pending',
                ];
            }

            if (empty($items)) {
                throw new InvalidArgumentException('All selected bills have been fully paid.');
            }

            $run = FinPaymentRun::create([
                'organization_id' => $orgId,
                'run_number' => $runNumber,
                'bank_account_id' => $data['bank_account_id'],
                'status' => 'draft',
                'payment_date' => $data['payment_date'],
                'total_amount' => $totalAmount,
                'item_count' => count($items),
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);

            foreach ($items as $item) {
                $run->items()->create($item);
            }

            return $run->load('items.vendor', 'bankAccount');
        });
    }

    /**
     * Approve a draft payment run.
     */
    public function approvePaymentRun(FinPaymentRun $run, int $userId): FinPaymentRun
    {
        if ($run->status !== 'draft') {
            throw new InvalidArgumentException("Payment run {$run->run_number} cannot be approved: status is '{$run->status}', expected 'draft'.");
        }

        $run->update([
            'status' => 'approved',
            'approved_by' => $userId,
            'approved_at' => now(),
        ]);

        return $run->refresh();
    }

    /**
     * Process an approved payment run: pay each bill, post GL journal, generate bank file.
     */
    public function processPaymentRun(FinPaymentRun $run, int $userId): FinPaymentRun
    {
        if ($run->status !== 'approved') {
            throw new InvalidArgumentException("Payment run {$run->run_number} cannot be processed: status is '{$run->status}', expected 'approved'.");
        }

        return DB::transaction(function () use ($run, $userId) {
            $run->update(['status' => 'processing']);
            $run->loadMissing(['items.bill', 'bankAccount']);

            $bankAccount = $run->bankAccount;
            $journalLines = [];

            foreach ($run->items as $item) {
                $item->update(['status' => 'paid']);

                $this->accountsPayableService->recordPayment(
                    $item->bill,
                    (float) $item->amount,
                );

                // DR Accounts Payable (2000)
                $journalLines[] = [
                    'account_id' => $this->getAccountsPayableAccountId($run->organization_id),
                    'description' => "Payment to {$item->vendor->name} — {$item->reference}",
                    'debit' => $item->amount,
                    'credit' => 0,
                ];

                // CR Bank Account
                $journalLines[] = [
                    'account_id' => $bankAccount->gl_account_id,
                    'description' => "Payment to {$item->vendor->name} — {$item->reference}",
                    'debit' => 0,
                    'credit' => $item->amount,
                ];
            }

            $journal = $this->journalPostingService->createAndPost($run->organization_id, [
                'journal_date' => $run->payment_date->toDateString(),
                'type' => 'payment',
                'reference' => $run->run_number,
                'description' => "Payment run {$run->run_number} — {$run->item_count} payments",
                'source_type' => FinPaymentRun::class,
                'source_id' => $run->id,
                'lines' => $journalLines,
            ]);

            $filePath = $this->generateBankFile($run);

            $run->update([
                'status' => 'completed',
                'processed_at' => now(),
                'processed_by' => $userId,
                'journal_id' => $journal->id,
                'file_path' => $filePath,
            ]);

            return $run->refresh();
        });
    }

    /**
     * Generate a NZ direct credit CSV file for the payment run.
     */
    public function generateBankFile(FinPaymentRun $run): string
    {
        $run->loadMissing('items.vendor');

        $csv = "Vendor Name,Bank Account Number,Amount,Reference\n";

        foreach ($run->items as $item) {
            $vendorName = str_replace(',', ' ', $item->vendor->name);
            $bankAccountNumber = $item->bank_account_number;
            $amount = number_format((float) $item->amount, 2, '.', '');
            $reference = str_replace(',', ' ', $item->reference);

            $csv .= "{$vendorName},{$bankAccountNumber},{$amount},{$reference}\n";
        }

        $path = "finance/payment-runs/{$run->run_number}.csv";
        Storage::disk('local')->put($path, $csv);

        return $path;
    }

    /**
     * Get all approved or partially-paid bills for an organisation.
     */
    public function getApprovedUnpaidBills(int $orgId): Collection
    {
        return FinBill::forOrganization($orgId)
            ->whereIn('status', ['approved', 'partially_paid'])
            ->unpaid()
            ->with('vendor:id,name')
            ->orderBy('due_date')
            ->get();
    }

    /**
     * Generate the next sequential payment run number.
     * Format: PAY-YYYYMM-001, PAY-YYYYMM-002, etc.
     */
    private function generateRunNumber(int $orgId): string
    {
        $prefix = 'PAY-' . now()->format('Ym') . '-';

        $maxNumber = FinPaymentRun::where('organization_id', $orgId)
            ->where('run_number', 'like', $prefix . '%')
            ->selectRaw("MAX(CAST(SUBSTRING(run_number, " . (strlen($prefix) + 1) . ") AS UNSIGNED)) as max_num")
            ->value('max_num');

        $next = ($maxNumber ?? 0) + 1;

        return $prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Resolve the Accounts Payable GL account (code 2000) for an organisation.
     */
    private function getAccountsPayableAccountId(int $orgId): int
    {
        $account = \App\Domain\Finance\Models\FinAccount::where('organization_id', $orgId)
            ->where('code', '2000')
            ->first();

        if (! $account) {
            throw new InvalidArgumentException('Accounts Payable account (code 2000) not found for this organisation.');
        }

        return $account->id;
    }
}
