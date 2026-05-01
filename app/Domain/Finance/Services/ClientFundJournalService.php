<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinJournal;
use App\Models\ClientFundTransaction;
use Illuminate\Support\Facades\DB;
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
        return DB::transaction(function () use ($txn) {
            $txn = ClientFundTransaction::query()
                ->lockForUpdate()
                ->findOrFail($txn->id);

            if ($txn->journal_id !== null) {
                return FinJournal::findOrFail($txn->journal_id);
            }

            $orgId = $txn->organization_id;
            $amount = (string) $txn->amount;
            $absAmount = ltrim($amount, '-');

            if (bccomp($absAmount, '0', 2) === 0) {
                throw new RuntimeException(
                    "Client fund transaction #{$txn->id} has a zero amount. Cannot post."
                );
            }

            $bankTrustAccount = $this->findAccountByCode($orgId, '1010');
            $clientTrustAccount = $this->findAccountByCode($orgId, '2500');

            $transactionType = strtolower((string) $txn->transaction_type);
            $isWithdrawal = in_array($transactionType, ['debit', 'withdrawal', 'outflow'], true)
                || bccomp($amount, '0', 2) < 0;
            $isDeposit = ! $isWithdrawal;

            $lines = [];

            if ($isDeposit) {
                // Deposit: DR 1010 Bank - Trust, CR 2500 Client Trust Funds
                $lines[] = [
                    'account_id' => $bankTrustAccount->id,
                    'description' => 'Bank - Trust Account (deposit)',
                    'debit' => $absAmount,
                    'credit' => 0,
                ];
                $lines[] = [
                    'account_id' => $clientTrustAccount->id,
                    'description' => 'Client Trust Funds (deposit)',
                    'debit' => 0,
                    'credit' => $absAmount,
                ];
            } else {
                // Withdrawal: DR 2500 Client Trust Funds, CR 1010 Bank - Trust
                $lines[] = [
                    'account_id' => $clientTrustAccount->id,
                    'description' => 'Client Trust Funds (withdrawal)',
                    'debit' => $absAmount,
                    'credit' => 0,
                ];
                $lines[] = [
                    'account_id' => $bankTrustAccount->id,
                    'description' => 'Bank - Trust Account (withdrawal)',
                    'debit' => 0,
                    'credit' => $absAmount,
                ];
            }

            $description = $txn->description
                ? "Client fund {$txn->transaction_type}: {$txn->description}"
                : "Client fund {$txn->transaction_type}";

            $journal = $this->journalPostingService->createAndPost($orgId, [
                'journal_date' => ($txn->transaction_date ?? now())->toDateString(),
                'type' => 'standard',
                'source_type' => 'client_fund_transaction',
                'source_id' => $txn->id,
                'description' => $description,
                'lines' => $lines,
            ]);

            $txn->forceFill([
                'journal_id' => $journal->id,
                'gl_posted_at' => now(),
            ])->save();

            return $journal;
        });
    }

    /* ------------------------------------------------------------------
     |  Helper: find a GL account by code (cached per request)
     | ------------------------------------------------------------------ */

    public function findAccountByCode(?int $orgId, string $code): FinAccount
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
