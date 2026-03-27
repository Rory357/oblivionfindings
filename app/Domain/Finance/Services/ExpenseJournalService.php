<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Hr\Models\HrExpenseClaim;
use App\Models\MileageClaim;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class ExpenseJournalService
{
    /**
     * GL account code mapping for expense categories.
     */
    private const CATEGORY_ACCOUNT_MAP = [
        'travel'        => '6100',
        'meals'         => '7010',
        'accommodation' => '6000',
        'supplies'      => '6300',
        'mileage'       => '6100',
        'other'         => '6300',
    ];

    private const ACCOUNTS_PAYABLE_CODE = '2000';

    public function __construct(
        private readonly JournalPostingService $journalPostingService,
    ) {}

    /**
     * Post a GL journal for an approved expense claim.
     *
     * DR various expense accounts (grouped by category)
     * CR 2000 Accounts Payable for total
     */
    public function postExpenseClaimJournal(HrExpenseClaim $claim): FinJournal
    {
        if ($claim->journal_id !== null) {
            throw new InvalidArgumentException(
                "Expense claim {$claim->claim_number} has already been posted to GL (journal_id: {$claim->journal_id})."
            );
        }

        $orgId = $claim->tenant_id;
        $claim->loadMissing('items');

        if ($claim->items->isEmpty()) {
            throw new InvalidArgumentException(
                "Expense claim {$claim->claim_number} has no items to post."
            );
        }

        // Group items by GL account code and sum amounts
        $groupedAmounts = [];
        foreach ($claim->items as $item) {
            $accountCode = $this->getExpenseAccountCode($item->category);
            $groupedAmounts[$accountCode] = bcadd(
                $groupedAmounts[$accountCode] ?? '0',
                (string) $item->amount,
                2
            );
        }

        // Build journal lines: DR each expense account, CR Accounts Payable
        $lines = [];

        foreach ($groupedAmounts as $accountCode => $amount) {
            $account = $this->findAccountByCode($orgId, $accountCode);
            $lines[] = [
                'account_id'  => $account->id,
                'description' => "Expense Claim {$claim->claim_number} — {$account->name}",
                'debit'       => $amount,
                'credit'      => 0,
            ];
        }

        $apAccount = $this->findAccountByCode($orgId, self::ACCOUNTS_PAYABLE_CODE);
        $lines[] = [
            'account_id'  => $apAccount->id,
            'description' => "Expense Claim {$claim->claim_number} — Accounts Payable",
            'debit'       => 0,
            'credit'      => $claim->total_amount,
        ];

        $journal = $this->journalPostingService->createAndPost($orgId, [
            'journal_date' => $claim->approved_at?->toDateString() ?? now()->toDateString(),
            'type'         => 'standard',
            'source_type'  => 'expense_claim',
            'source_id'    => $claim->id,
            'description'  => "Expense Claim {$claim->claim_number}",
            'lines'        => $lines,
        ]);

        $claim->update([
            'journal_id'   => $journal->id,
            'gl_posted_at' => now(),
        ]);

        return $journal;
    }

    /**
     * Post a GL journal for a mileage claim.
     *
     * DR 6100 Vehicle Running Costs
     * CR 2000 Accounts Payable
     */
    public function postMileageClaimJournal(MileageClaim $mileageClaim): FinJournal
    {
        $orgId = $mileageClaim->organization_id;

        $expenseAccount = $this->findAccountByCode($orgId, '6100');
        $apAccount = $this->findAccountByCode($orgId, self::ACCOUNTS_PAYABLE_CODE);

        $lines = [
            [
                'account_id'  => $expenseAccount->id,
                'description' => "Mileage Claim — {$mileageClaim->purpose}",
                'debit'       => $mileageClaim->amount,
                'credit'      => 0,
            ],
            [
                'account_id'  => $apAccount->id,
                'description' => "Mileage Claim — Accounts Payable",
                'debit'       => 0,
                'credit'      => $mileageClaim->amount,
            ],
        ];

        return $this->journalPostingService->createAndPost($orgId, [
            'journal_date' => $mileageClaim->approved_at?->toDateString() ?? $mileageClaim->claim_date->toDateString(),
            'type'         => 'standard',
            'source_type'  => 'mileage_claim',
            'source_id'    => $mileageClaim->id,
            'description'  => "Mileage Claim — {$mileageClaim->purpose}",
            'lines'        => $lines,
        ]);
    }

    /**
     * Map an expense item category to a GL account code.
     */
    public function getExpenseAccountCode(string $category): string
    {
        return self::CATEGORY_ACCOUNT_MAP[$category] ?? self::CATEGORY_ACCOUNT_MAP['other'];
    }

    /**
     * Reverse the GL journal for an expense claim and clear posting fields.
     */
    public function reverseExpenseClaimJournal(HrExpenseClaim $claim): ?FinJournal
    {
        if ($claim->journal_id === null) {
            return null;
        }

        $journal = FinJournal::findOrFail($claim->journal_id);

        $reversalJournal = $this->journalPostingService->reverse(
            $journal,
            "Reversal of Expense Claim {$claim->claim_number}"
        );

        $claim->update([
            'journal_id'   => null,
            'gl_posted_at' => null,
        ]);

        return $reversalJournal;
    }

    /**
     * Find a FinAccount by its code within an organisation.
     *
     * @throws RuntimeException if the account does not exist or is inactive
     */
    public function findAccountByCode(int $orgId, string $code): FinAccount
    {
        $account = FinAccount::where('organization_id', $orgId)
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if (! $account) {
            throw new RuntimeException(
                "GL account with code '{$code}' not found or inactive for organisation {$orgId}."
            );
        }

        return $account;
    }
}
