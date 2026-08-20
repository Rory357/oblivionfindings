<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinPaymentRun;
use App\Domain\Finance\Models\FinPaymentRunItem;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class PaymentRunService
{
    public function __construct(
        private JournalPostingService $journalPostingService,
        private AccountsPayableService $accountsPayableService,
        private PaymentSettlementSiteScope $paymentSiteScope,
        private PaymentSettlementRecorder $settlementRecorder,
    ) {}

    /**
     * Create a new payment run from a set of approved/partially-paid bills.
     */
    public function createPaymentRun(?int $orgId, User $actor, array $data): FinPaymentRun
    {
        return DB::transaction(function () use ($orgId, $actor, $data) {
            abort_unless((int) $actor->organization_id === (int) $orgId, 404);

            $bankAccount = FinBankAccount::forOrganization($orgId)
                ->active()
                ->whereHas('glAccount', fn (Builder $accounts): Builder => $accounts
                    ->where('organization_id', $orgId)
                    ->where('is_active', true))
                ->lockForUpdate()
                ->findOrFail($data['bank_account_id']);

            $billIds = collect($data['bill_ids'])
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values();

            $billQuery = FinBill::forOrganization($orgId)
                ->whereIn('id', $billIds)
                ->whereIn('status', ['approved', 'partially_paid'])
                ->with('vendor')
                ->orderBy('id')
                ->lockForUpdate();
            $bills = $this->paymentSiteScope->applyBillScope($billQuery, $actor)->get();

            if ($bills->count() !== $billIds->count()) {
                abort(404);
            }

            if (FinPaymentRunItem::query()->whereIn('settlement_bill_id', $billIds)->exists()) {
                throw new InvalidArgumentException('One or more selected bills already belongs to a payment run.');
            }

            $runNumber = $this->generateRunNumber($orgId);

            $totalAmount = '0';
            $items = [];

            foreach ($bills as $bill) {
                abort_unless(
                    $bill->vendor !== null
                        && (int) $bill->vendor->organization_id === (int) $orgId,
                    404,
                );
                $amountDue = $bill->getAmountDue();
                if ($amountDue <= 0) {
                    continue;
                }

                $totalAmount = bcadd($totalAmount, (string) $amountDue, 2);

                $items[] = [
                    'bill_id' => $bill->id,
                    'settlement_bill_id' => $bill->id,
                    'site_id' => $this->paymentSiteScope->billSiteId($bill),
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
                'bank_account_id' => $bankAccount->id,
                'status' => 'draft',
                'payment_date' => $data['payment_date'],
                'total_amount' => $totalAmount,
                'item_count' => count($items),
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
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
    public function approvePaymentRun(FinPaymentRun $run, User $actor): FinPaymentRun
    {
        return DB::transaction(function () use ($run, $actor): FinPaymentRun {
            $run = FinPaymentRun::query()->lockForUpdate()->findOrFail($run->id);
            $this->paymentSiteScope->assertCanAccessPaymentRun($actor, $run);

            if ($run->status !== 'draft') {
                throw new InvalidArgumentException("Payment run {$run->run_number} cannot be approved: status is '{$run->status}', expected 'draft'.");
            }

            $run->update([
                'status' => 'approved',
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);

            return $run->refresh();
        });
    }

    /**
     * Process an approved payment run: pay each bill, post GL journal, generate bank file.
     */
    public function processPaymentRun(FinPaymentRun $run, User $actor): FinPaymentRun
    {
        $filePath = null;

        try {
            return DB::transaction(function () use ($run, $actor, &$filePath) {
                $run = FinPaymentRun::query()
                    ->lockForUpdate()
                    ->findOrFail($run->getKey());

                $this->paymentSiteScope->assertCanAccessPaymentRun($actor, $run);

                if ($run->status !== 'approved') {
                    throw new InvalidArgumentException("Payment run {$run->run_number} cannot be processed: status is '{$run->status}', expected 'approved'.");
                }

                $items = $run->items()
                    ->with(['vendor', 'paymentRun'])
                    ->orderBy('bill_id')
                    ->lockForUpdate()
                    ->get();
                $bills = FinBill::query()
                    ->where('organization_id', $run->organization_id)
                    ->whereIn('id', $items->pluck('bill_id'))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                $run->load('bankAccount.glAccount');
                abort_unless(
                    $items->isNotEmpty()
                        && $bills->count() === $items->count()
                        && $run->bankAccount !== null,
                    404,
                );
                abort_unless(
                    (int) $run->bankAccount->organization_id === (int) $run->organization_id
                        && $run->bankAccount->is_active
                        && $run->bankAccount->glAccount?->is_active
                        && (int) $run->bankAccount->glAccount?->organization_id === (int) $run->organization_id,
                    404,
                );

                foreach ($items as $item) {
                    $item->setRelation('bill', $bills->get($item->bill_id));
                    abort_unless(
                        $item->bill !== null
                            && (int) $item->settlement_bill_id === (int) $item->bill_id
                            && (int) $item->site_id === (int) $item->bill->site_id
                            && $item->vendor !== null
                            && (int) $item->vendor_id === (int) $item->bill->vendor_id
                            && (int) $item->vendor->organization_id === (int) $run->organization_id,
                        404,
                    );
                    $this->paymentSiteScope->assertCanAccessBill($actor, $item->bill);
                }

                $run->update(['status' => 'processing']);

                $bankAccount = $run->bankAccount;
                $journalLines = [];

                foreach ($items as $item) {
                    $item->setRelation('bill', $this->accountsPayableService->recordPayment(
                        $item->bill,
                        (float) $item->amount,
                    ));

                    $journalLines[] = [
                        'account_id' => $this->getAccountsPayableAccountId($run->organization_id),
                        'description' => "Payment to {$item->vendor->name} — {$item->reference}",
                        'debit' => $item->amount,
                        'credit' => 0,
                        'site_id' => $item->site_id,
                    ];

                    $journalLines[] = [
                        'account_id' => $bankAccount->gl_account_id,
                        'description' => "Payment to {$item->vendor->name} — {$item->reference}",
                        'debit' => 0,
                        'credit' => $item->amount,
                        'site_id' => $item->site_id,
                    ];
                }

                $journal = $this->journalPostingService->createAndPost($run->organization_id, [
                    'journal_date' => $run->payment_date->toDateString(),
                    'type' => 'standard',
                    'reference' => $run->run_number,
                    'description' => "Payment run {$run->run_number} — {$run->item_count} payments",
                    'source_type' => FinPaymentRun::class,
                    'source_id' => $run->id,
                    'actor_id' => $actor->id,
                    'lines' => $journalLines,
                ]);

                foreach ($items as $item) {
                    $this->settlementRecorder->record(
                        target: $item->bill,
                        journal: $journal,
                        source: $item,
                        siteId: (int) $item->site_id,
                        amount: number_format((float) $item->amount, 2, '.', ''),
                        paymentDate: $run->payment_date->toDateString(),
                        actor: $actor,
                        notes: "Payment run {$run->run_number}",
                    );
                    $item->update(['status' => 'paid']);
                }

                $run->setRelation('items', $items);
                $filePath = $this->generateBankFile($run);

                $run->update([
                    'status' => 'completed',
                    'processed_at' => now(),
                    'processed_by' => $actor->id,
                    'journal_id' => $journal->id,
                    'file_path' => $filePath,
                ]);

                AuditLogger::logOrFail('finance.payment_run.completed', $run, [
                    'actor_id' => $actor->id,
                    'journal_id' => $journal->id,
                    'item_count' => $items->count(),
                ]);

                return $run->refresh();
            });
        } catch (\Throwable $e) {
            if ($filePath !== null) {
                Storage::disk('local')->delete($filePath);
            }

            throw $e;
        }
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
        if (! Storage::disk('local')->put($path, $csv)) {
            Storage::disk('local')->delete($path);
            throw new \RuntimeException('The payment run bank file could not be written.');
        }

        return $path;
    }

    /**
     * Get all approved or partially-paid bills for an organisation.
     */
    public function getApprovedUnpaidBills(?int $orgId, User $actor): Collection
    {
        $query = FinBill::forOrganization($orgId)
            ->whereIn('status', ['approved', 'partially_paid'])
            ->unpaid()
            ->with('vendor:id,name')
            ->orderBy('due_date');

        return $this->paymentSiteScope->applyBillScope($query, $actor)->get();
    }

    public function scopeRunsForActor(Builder $query, User $actor): Builder
    {
        return $this->paymentSiteScope->applyPaymentRunScope($query, $actor);
    }

    public function assertCanViewRun(User $actor, FinPaymentRun $run): void
    {
        $this->paymentSiteScope->assertCanAccessPaymentRun($actor, $run, false);
    }

    public function assertCanManageRun(User $actor, FinPaymentRun $run): void
    {
        $this->paymentSiteScope->assertCanAccessPaymentRun($actor, $run, true);
    }

    /**
     * Generate the next sequential payment run number.
     * Format: PAY-YYYYMM-001, PAY-YYYYMM-002, etc.
     */
    private function generateRunNumber(?int $orgId): string
    {
        $prefix = 'PAY-'.now()->format('Ym').'-';

        $maxNumber = FinPaymentRun::where('organization_id', $orgId)
            ->where('run_number', 'like', $prefix.'%')
            ->selectRaw('MAX(CAST(SUBSTRING(run_number, '.(strlen($prefix) + 1).') AS UNSIGNED)) as max_num')
            ->value('max_num');

        $next = ($maxNumber ?? 0) + 1;

        return $prefix.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Resolve the Accounts Payable GL account (code 2000) for an organisation.
     */
    private function getAccountsPayableAccountId(?int $orgId): int
    {
        $account = FinAccount::where('organization_id', $orgId)
            ->where('code', '2000')
            ->where('is_active', true)
            ->first();

        if (! $account) {
            throw new InvalidArgumentException('Accounts Payable account (code 2000) not found for this organisation.');
        }

        return $account->id;
    }
}
