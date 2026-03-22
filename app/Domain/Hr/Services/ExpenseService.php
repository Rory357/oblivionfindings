<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrExpenseClaim;
use App\Domain\Hr\Models\HrExpenseItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ExpenseService
{
    /**
     * Expense categories supported by the system.
     */
    public const CATEGORIES = ['travel', 'meals', 'accommodation', 'supplies', 'mileage', 'other'];

    /**
     * Create a new expense claim with optional items.
     *
     * @param  User   $user
     * @param  array  $data
     * @return HrExpenseClaim
     */
    public function createClaim(User $user, array $data): HrExpenseClaim
    {
        return DB::transaction(function () use ($user, $data) {
            $claimNumber = $this->generateClaimNumber($user->tenant_id);

            $claim = HrExpenseClaim::create([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'claim_number' => $claimNumber,
                'title' => $data['title'],
                'status' => 'draft',
                'total_amount' => 0,
                'currency' => $data['currency'] ?? 'NZD',
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            if (! empty($data['items'])) {
                foreach ($data['items'] as $itemData) {
                    $this->addItem($claim, $itemData);
                }
                $this->recalculateTotal($claim);
            }

            return $claim->load('items');
        });
    }

    /**
     * Add an item to an expense claim.
     *
     * @param  HrExpenseClaim  $claim
     * @param  array           $data
     * @return HrExpenseItem
     *
     * @throws \LogicException If claim is not in draft status
     */
    public function addItem(HrExpenseClaim $claim, array $data): HrExpenseItem
    {
        if (! in_array($claim->status, ['draft'], true)) {
            throw new \LogicException("Cannot add items to a '{$claim->status}' claim.");
        }

        $item = $claim->items()->create([
            'description' => $data['description'],
            'category' => $data['category'],
            'amount' => $data['amount'],
            'expense_date' => $data['expense_date'],
            'receipt_path' => $data['receipt_path'] ?? null,
            'tax_amount' => $data['tax_amount'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $this->recalculateTotal($claim);

        return $item;
    }

    /**
     * Submit a draft claim for approval.
     *
     * @param  HrExpenseClaim  $claim
     * @return HrExpenseClaim
     *
     * @throws \LogicException If claim is not a draft or has no items
     */
    public function submitClaim(HrExpenseClaim $claim): HrExpenseClaim
    {
        if ($claim->status !== 'draft') {
            throw new \LogicException("Cannot submit a '{$claim->status}' claim.");
        }

        if ($claim->items()->count() === 0) {
            throw new \LogicException('Cannot submit a claim with no items.');
        }

        $claim->update([
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        return $claim->fresh();
    }

    /**
     * Approve a submitted claim.
     *
     * @param  HrExpenseClaim  $claim
     * @param  User            $approver
     * @return HrExpenseClaim
     *
     * @throws \LogicException If claim is not submitted
     */
    public function approveClaim(HrExpenseClaim $claim, User $approver): HrExpenseClaim
    {
        if ($claim->status !== 'submitted') {
            throw new \LogicException("Cannot approve a '{$claim->status}' claim.");
        }

        return DB::transaction(function () use ($claim, $approver) {
            $claim->update([
                'status' => 'approved',
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            return $claim->fresh();
        });
    }

    /**
     * Reject a submitted claim.
     *
     * @param  HrExpenseClaim  $claim
     * @param  User            $reviewer
     * @param  string          $reason
     * @return HrExpenseClaim
     *
     * @throws \LogicException If claim is not submitted
     */
    public function rejectClaim(HrExpenseClaim $claim, User $reviewer, string $reason): HrExpenseClaim
    {
        if ($claim->status !== 'submitted') {
            throw new \LogicException("Cannot reject a '{$claim->status}' claim.");
        }

        return DB::transaction(function () use ($claim, $reviewer, $reason) {
            $claim->update([
                'status' => 'rejected',
                'approved_by' => $reviewer->id,
                'approved_at' => now(),
                'rejection_reason' => $reason,
            ]);

            return $claim->fresh();
        });
    }

    /**
     * Mark an approved claim as paid.
     *
     * @param  HrExpenseClaim  $claim
     * @return HrExpenseClaim
     *
     * @throws \LogicException If claim is not approved
     */
    public function markPaid(HrExpenseClaim $claim): HrExpenseClaim
    {
        if ($claim->status !== 'approved') {
            throw new \LogicException("Cannot mark a '{$claim->status}' claim as paid.");
        }

        $claim->update([
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        return $claim->fresh();
    }

    /**
     * Recalculate the total amount on a claim from its items.
     */
    protected function recalculateTotal(HrExpenseClaim $claim): void
    {
        $total = $claim->items()->sum('amount');
        $claim->update(['total_amount' => $total]);
    }

    /**
     * Generate a unique claim number for a tenant.
     */
    protected function generateClaimNumber(?int $tenantId): string
    {
        $latest = HrExpenseClaim::where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->value('claim_number');

        if ($latest && preg_match('/EXP-(\d+)/', $latest, $matches)) {
            $next = (int) $matches[1] + 1;
        } else {
            $next = 1;
        }

        return 'EXP-' . str_pad($next, 5, '0', STR_PAD_LEFT);
    }
}
