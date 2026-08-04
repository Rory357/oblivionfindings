<?php

namespace App\Domain\Hr\Services;

use App\Domain\Finance\Jobs\PostExpenseJournalJob;
use App\Domain\Hr\Models\HrExpenseClaim;
use App\Domain\Hr\Models\HrExpenseItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ExpenseService
{
    public function __construct(private readonly HrPerformanceAccessService $access) {}

    /**
     * Expense categories supported by the system.
     */
    public const CATEGORIES = ['travel', 'meals', 'accommodation', 'supplies', 'mileage', 'training', 'development', 'other'];

    /**
     * Create a new expense claim with optional items.
     *
     * @param  User|null  $onBehalfOf  When a manager files for another employee,
     *                                 the claim is OWNED by them but created_by
     *                                 records the manager.
     */
    public function createClaim(User $user, array $data, ?User $onBehalfOf = null): HrExpenseClaim
    {
        return DB::transaction(function () use ($user, $data, $onBehalfOf): HrExpenseClaim {
            $actor = $this->access->currentStaff($user, $user);
            if ($onBehalfOf !== null && $onBehalfOf->isNot($actor)) {
                abort_unless($actor->canDo('hr.expenses.manage'), 403);
            }
            $owner = $this->access->currentStaff($actor, $onBehalfOf ?? $actor);

            $claim = HrExpenseClaim::create([
                'user_id' => $owner->id,
                'claim_number' => $this->generateClaimNumber(),
                'title' => $data['title'],
                'status' => 'draft',
                'total_amount' => 0,
                'currency' => $data['currency'] ?? 'NZD',
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            if (! empty($data['items'])) {
                foreach ($data['items'] as $itemData) {
                    $this->addItem($claim, $itemData);
                }
                $this->recalculateTotal($claim);
            }

            return $claim->load('items');
        }, attempts: 1);
    }

    /**
     * Add an item to an expense claim.
     *
     *
     * @throws \LogicException If claim is not in draft status
     */
    public function addItem(HrExpenseClaim $claim, array $data): HrExpenseItem
    {
        return DB::transaction(function () use ($claim, $data): HrExpenseItem {
            $locked = HrExpenseClaim::query()->lockForUpdate()->findOrFail($claim->getKey());
            if ($locked->status !== 'draft') {
                throw new \LogicException("Cannot add items to a '{$locked->status}' claim.");
            }

            $item = $locked->items()->create([
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

            $this->recalculateTotal($locked);

            return $item;
        }, attempts: 1);
    }

    /**
     * Submit a draft claim for approval. A rejected claim may be resubmitted —
     * the prior decision (rejection reason + reviewer stamp) is cleared so it
     * re-enters the approval queue cleanly.
     *
     *
     * @throws \LogicException If claim is not draft/rejected or has no items
     */
    public function submitClaim(HrExpenseClaim $claim): HrExpenseClaim
    {
        $claim = DB::transaction(function () use ($claim): HrExpenseClaim {
            $locked = HrExpenseClaim::query()->lockForUpdate()->findOrFail($claim->getKey());
            if (! in_array($locked->status, ['draft', 'rejected'], true)) {
                throw new \LogicException("Cannot submit a '{$locked->status}' claim.");
            }
            if ($locked->items()->count() === 0) {
                throw new \LogicException('Cannot submit a claim with no items.');
            }

            $locked->update([
                'status' => 'submitted',
                'submitted_at' => now(),
                'rejection_reason' => null,
                'approved_by' => null,
                'approved_at' => null,
            ]);

            return $locked->fresh();
        }, attempts: 1);

        app(HrNotificationService::class)->notifyExpenseSubmitted($claim);

        return $claim;
    }

    /**
     * Withdraw a submitted claim back to draft — the claimant changed their
     * mind (or spotted a mistake) before anyone actioned it. Reversible: the
     * claim becomes editable again and can be resubmitted.
     *
     *
     * @throws \LogicException If claim is not submitted
     */
    public function withdrawClaim(HrExpenseClaim $claim): HrExpenseClaim
    {
        return DB::transaction(function () use ($claim): HrExpenseClaim {
            $locked = HrExpenseClaim::query()->lockForUpdate()->findOrFail($claim->getKey());
            if ($locked->status !== 'submitted') {
                throw new \LogicException("Cannot withdraw a '{$locked->status}' claim.");
            }

            $locked->update([
                'status' => 'draft',
                'submitted_at' => null,
            ]);

            return $locked->fresh();
        }, attempts: 1);
    }

    /**
     * Approve a submitted claim.
     *
     *
     * @throws \LogicException If claim is not submitted
     */
    public function approveClaim(HrExpenseClaim $claim, User $approver): HrExpenseClaim
    {
        $result = DB::transaction(function () use ($claim, $approver): HrExpenseClaim {
            $locked = HrExpenseClaim::query()->lockForUpdate()->findOrFail($claim->getKey());
            if ($locked->status !== 'submitted') {
                throw new \LogicException("Cannot approve a '{$locked->status}' claim.");
            }

            $locked->update([
                'status' => 'approved',
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            return $locked->fresh();
        }, attempts: 1);

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
        $result = DB::transaction(function () use ($claim, $reviewer, $reason): HrExpenseClaim {
            $locked = HrExpenseClaim::query()->lockForUpdate()->findOrFail($claim->getKey());
            if ($locked->status !== 'submitted') {
                throw new \LogicException("Cannot reject a '{$locked->status}' claim.");
            }

            $locked->update([
                'status' => 'rejected',
                'approved_by' => $reviewer->id,
                'approved_at' => now(),
                'rejection_reason' => $reason,
            ]);

            return $locked->fresh();
        }, attempts: 1);

        // Tell the claimant why (best-effort inside the service — mirrors the
        // approve path's notifyExpenseApproved).
        app(HrNotificationService::class)->notifyExpenseRejected($result);

        return $result;
    }

    /**
     * Mark an approved claim as paid.
     *
     *
     * @throws \LogicException If claim is not approved
     */
    public function markPaid(HrExpenseClaim $claim): HrExpenseClaim
    {
        return DB::transaction(function () use ($claim): HrExpenseClaim {
            $locked = HrExpenseClaim::query()->lockForUpdate()->findOrFail($claim->getKey());
            if ($locked->status !== 'approved') {
                throw new \LogicException("Cannot mark a '{$locked->status}' claim as paid.");
            }
            if ($locked->gl_posted_at === null) {
                throw new \LogicException('Expense claim must be posted to the general ledger before it can be marked paid.');
            }

            $locked->update([
                'status' => 'paid',
                'paid_at' => now(),
            ]);

            return $locked->fresh();
        }, attempts: 1);
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
     * Generate the next application-wide claim number while serializing against
     * existing canonical numbers. The database unique key is the final guard.
     */
    protected function generateClaimNumber(): string
    {
        $highest = HrExpenseClaim::query()
            ->where('claim_number', 'like', 'EXP-%')
            ->lockForUpdate()
            ->pluck('claim_number')
            ->reduce(function (int $maximum, string $number): int {
                return preg_match('/^EXP-(\d+)$/', $number, $matches) === 1
                    ? max($maximum, (int) $matches[1])
                    : $maximum;
            }, 0);
        $next = $highest + 1;

        return 'EXP-'.str_pad($next, 5, '0', STR_PAD_LEFT);
    }
}
