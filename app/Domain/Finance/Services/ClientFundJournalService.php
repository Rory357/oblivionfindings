<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinJournal;
use App\Models\ClientFundTransaction;
use InvalidArgumentException;
use RuntimeException;

class ClientFundJournalService
{
    /**
     * GL account code -> FinAccount cache (per-request, keyed by orgId:code).
     *
     * @var array<string, FinAccount>
     */
    private array $accountCache = [];

    public function __construct(
        private readonly JournalPostingService $journalPostingService,
    ) {}

    /* ------------------------------------------------------------------
     |  Post a client fund transaction to the General Ledger
     | ------------------------------------------------------------------ */

    public function postClientFundJournal(ClientFundTransaction $txn): FinJournal
    {
        if ($txn->journal_id !== null) {
            throw new InvalidArgumentException(
                "Client fund transaction #{$txn->id} has already been posted to journal #{$txn->journal_id}."
            );
        }

        $orgId = $txn->organization_id;
        $amount = (string) $txn->amount;
        $absAmount = ltrim($amount, '-');

        if (bccomp($absAmount, '0', 2) === 0) {
            throw new RuntimeException(
                "Client fund transaction #{$txn->id} has a zero amount. Cannot post."
            );
        }

        $bankTrustAccount    = $this->findAccountByCode($orgId, '1010');
        $clientTrustAccount  = $this->findAccountByCode($orgId, '2500');

        $isDeposit = bccomp($amount, '0', 2) > 0;

        $lines = [];

        if ($isDeposit) {
            // Deposit: DR 1010 Bank - Trust, CR 2500 Client Trust Funds
            $lines[] = [
                'account_id'  => $bankTrustAccount->id,
                'description' => 'Bank - Trust Account (deposit)',
                'debit'       => $absAmount,
                'credit'      => 0,
            ];
            $lines[] = [
                'account_id'  => $clientTrustAccount->id,
                'description' => 'Client Trust Funds (deposit)',
                'debit'       => 0,
                'credit'      => $absAmount,
            ];
        } else {
            // Withdrawal: DR 2500 Client Trust Funds, CR 1010 Bank - Trust
            $lines[] = [
                'account_id'  => $clientTrustAccount->id,
                'description' => 'Client Trust Funds (withdrawal)',
                'debit'       => $absAmount,
                'credit'      => 0,
            ];
            $lines[] = [
                'account_id'  => $bankTrustAccount->id,
                'description' => 'Bank - Trust Account (withdrawal)',
                'debit'       => 0,
                'credit'      => $absAmount,
            ];
        }

        $description = $txn->description
            ? "Client fund {$txn->transaction_type}: {$txn->description}"
            : "Client fund {$txn->transaction_type}";

        $journal = $this->journalPostingService->createAndPost($orgId, [
            'journal_date' => ($txn->transaction_date ?? now())->toDateString(),
            'type'         => 'standard',
            'source_type'  => 'client_fund_transaction',
            'source_id'    => $txn->id,
            'description'  => $description,
            'lines'        => $lines,
        ]);

        $txn->update([
            'journal_id'   => $journal->id,
            'gl_posted_at' => now(),
        ]);

        return $journal;
    }

    /* ------------------------------------------------------------------
     |  Helper: find a GL account by code (cached per request)
     | ------------------------------------------------------------------ */

    public function findAccountByCode(int $orgId, string $code): FinAccount
    {
        $cacheKey = "{$orgId}:{$code}";

        if (isset($this->accountCache[$cacheKey])) {
            return $this->accountCache[$cacheKey];
        }

        $account = FinAccount::where('organization_id', $orgId)
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if (! $account) {
            throw new RuntimeException(
                "GL account with code '{$code}' not found (or inactive) for organisation #{$orgId}."
            );
        }

        $this->accountCache[$cacheKey] = $account;

        return $account;
    }
}
