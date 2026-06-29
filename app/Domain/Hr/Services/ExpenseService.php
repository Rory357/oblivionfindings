<?php

namespace App\Domain\Hr\Services;

use App\Domain\Finance\Jobs\PostExpenseJournalJob;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrExpenseClaim;
use App\Domain\Hr\Models\HrExpenseItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ExpenseService
{
    /**
     * Expense categories supported by the system.
     */
    public const CATEGORIES = ['travel', 'meals', 'accommodation', 'supplies', 'mileage', 'development', 'other'];

    /**
     * Create a new expense claim with optional items.
     *
     * @param  User|null  $onBehalfOf  When a manager files for another employee,
     *                                 the claim is OWNED by them but created_by
     *                                 records the manager. Tenant is the actor's.
     */
    public function createClaim(User $user, array $data, ?User $onBehalfOf = null): HrExpenseClaim
    {
        return DB::transaction(function () use ($user, $data, $onBehalfOf) {
            $tenantId = $this->resolveTenantId($user);
            $claimNumber = $this->generateClaimNumber($tenantId);
            $owner = $onBehalfOf ?? $user;

            $claim = HrExpenseClaim::create([
                'tenant_id' => $tenantId,
                'user_id' => $owner->id,
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
            'source_type' => $data['source_type'] ?? null,
            'source_id' => $data['source_id'] ?? null,
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

        $claim = $claim->fresh();

        app(HrNotificationService::class)->notifyExpenseSubmitted($claim);

        return $claim;
    }

    /**
     * Approve a submitted claim.
     *
     *
     * @throws \LogicException If claim is not submitted
     */
    public function approveClaim(HrExpenseClaim $claim, User $approver): HrExpenseClaim
    {
        if ($claim->status !== 'submitted') {
            throw new \LogicException("Cannot approve a '{$claim->status}' claim.");
        }

        $result = DB::transaction(function () use ($claim, $approver) {
            $claim->update([
                'status' => 'approved',
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            return $claim->fresh();
        });

        // Post the approved expense to the GL (DR expense accounts / CR accounts
        // payable). Idempotent: the job + service both short-circuit if a journal
        // already exists. Mirrors the payroll lock→PostPayrollJournalJob bridge.
        if ($result->journal_id === null) {
            PostExpenseJournalJob::dispatch($result);
        }

        app(HrNotificationService::class)->notifyExpenseApproved($result);

        return $result;
    }

    /**
     * Reject a submitted claim.
     *
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

        return 'EXP-'.str_pad($next, 5, '0', STR_PAD_LEFT);
    }

    protected function resolveTenantId(User $user): int
    {
        $tenantId = $user->getAttribute('tenant_id');
        if (is_numeric($tenantId)) {
            return (int) $tenantId;
        }

        $organizationId = $user->getAttribute('organization_id');
        if (is_numeric($organizationId)) {
            return (int) $organizationId;
        }

        $profileTenantId = HrEmployeeProfile::query()
            ->where('user_id', $user->id)
            ->value('tenant_id');

        return (int) ($profileTenantId ?: 1);
    }
}
