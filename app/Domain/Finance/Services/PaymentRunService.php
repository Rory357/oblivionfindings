<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinPaymentRun;
use App\Domain\Finance\Models\FinPaymentRunItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PaymentRunService
{
    public function __construct(
        private PaymentSettlementSiteScope $paymentSiteScope,
        private ExternalSettlementService $externalSettlements,
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

            if (FinPaymentRunItem::query()->whereIn('active_settlement_bill_id', $billIds)->exists()) {
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
                $amountDue = bcsub((string) $bill->total_amount, (string) $bill->amount_paid, 2);
                if (bccomp($amountDue, '0.00', 2) <= 0) {
                    continue;
                }

                $totalAmount = bcadd($totalAmount, $amountDue, 2);

                $items[] = [
                    'bill_id' => $bill->id,
                    'settlement_bill_id' => $bill->id,
                    'active_settlement_bill_id' => $bill->id,
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
            if ($run->created_by === null || (int) $run->created_by === (int) $actor->id) {
                throw new InvalidArgumentException('A payment run must be approved by someone other than its creator.');
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
     * Prepare the immutable bank instruction only. External acceptance and the
     * later atomic settlement own every paid/allocation/GL mutation.
     */
    public function processPaymentRun(FinPaymentRun $run, User $actor): FinPaymentRun
    {
        return $this->externalSettlements->preparePaymentRun($run, $actor);
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
}
