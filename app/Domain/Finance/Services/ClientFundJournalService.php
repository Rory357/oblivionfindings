<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinJournal;
use App\Models\ClientFund;
use App\Models\ClientFundTransaction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ClientFundJournalService
{
    private const APPLICATION_STORAGE_CONTEXT_ID = 1;

    /** @var array<string, FinAccount> */
    private array $accountCache = [];

    public function __construct(
        private readonly JournalPostingService $journalPostingService,
        private readonly ClientFundReconciliationService $reconciliationService,
    ) {}

    /**
     * Post one approved client-money effect exactly once. The canonical source
     * transaction is locked with its fund and provenance before any GL write.
     */
    public function postClientFundJournal(ClientFundTransaction $transaction): FinJournal
    {
        $fundsToReconcile = [];

        $journal = DB::transaction(function () use ($transaction, &$fundsToReconcile): FinJournal {
            $snapshot = ClientFundTransaction::query()->findOrFail($transaction->id);
            $fundIds = array_values(array_unique(array_filter([
                (int) $snapshot->client_fund_id,
                $snapshot->destination_fund_id ? (int) $snapshot->destination_fund_id : null,
            ])));
            $funds = ClientFund::query()
                ->whereIn('id', $fundIds)
                ->with('client:id,site_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (ClientFund $fund): int => (int) $fund->id);

            $locked = ClientFundTransaction::query()->lockForUpdate()->findOrFail($snapshot->id);

            if ($locked->source_type === 'client_fund_transfer_counterpart') {
                throw new RuntimeException('Transfer counterpart rows cannot post a competing GL journal.');
            }

            if ($locked->journal_id !== null) {
                return FinJournal::query()->findOrFail($locked->journal_id);
            }

            if ($locked->status !== 'approved' || $locked->balance_effect_applied_at === null) {
                throw new RuntimeException("Client fund transaction #{$locked->id} is not approved for posting.");
            }

            $sourceFund = $funds->get((int) $locked->client_fund_id);
            $destinationFund = $locked->destination_fund_id
                ? $funds->get((int) $locked->destination_fund_id)
                : null;
            $this->assertBoundProvenance($locked, $sourceFund, $destinationFund);
            $this->lockClientMoneyPostingSequence();

            $locked->forceFill([
                'posting_attempted_at' => now(),
                'posting_failed_at' => null,
                'posting_failure_code' => null,
                'posting_failure_message' => null,
            ])->save();

            $journal = $locked->reversal_of_id
                ? $this->postReversalJournal($locked)
                : $this->postMovementJournal($locked, $sourceFund, $destinationFund);

            $locked->forceFill([
                'journal_id' => $journal->id,
                'gl_posted_at' => now(),
                'status' => 'posted',
            ])->save();

            if ($locked->counterpart_transaction_id) {
                ClientFundTransaction::query()
                    ->whereKey($locked->counterpart_transaction_id)
                    ->update([
                        'journal_id' => $journal->id,
                        'gl_posted_at' => now(),
                        'status' => 'posted',
                        'posting_attempted_at' => now(),
                    ]);
            }

            $fundsToReconcile = $funds->values()->all();

            return $journal;
        }, 3);

        foreach ($fundsToReconcile as $fund) {
            $this->reconciliationService->reconcile($fund);
        }

        return $journal;
    }

    private function postMovementJournal(
        ClientFundTransaction $transaction,
        ClientFund $sourceFund,
        ?ClientFund $destinationFund,
    ): FinJournal {
        $storageContextId = self::APPLICATION_STORAGE_CONTEXT_ID;
        $amount = ltrim((string) $transaction->amount, '-');

        if (bccomp($amount, '0', 2) === 0) {
            throw new RuntimeException("Client fund transaction #{$transaction->id} has a zero amount. Cannot post.");
        }

        $clientTrustAccount = $this->findAccountByCode($storageContextId, '2500');
        $lines = [];

        if ($transaction->transaction_type === 'transfer') {
            if (! $destinationFund) {
                throw new RuntimeException('A client-fund transfer has no canonical destination.');
            }

            $lines[] = $this->line(
                $clientTrustAccount,
                $sourceFund,
                'Client Trust Funds (transfer out)',
                $amount,
                '0.00',
            );
            $lines[] = $this->line(
                $clientTrustAccount,
                $destinationFund,
                'Client Trust Funds (transfer in)',
                '0.00',
                $amount,
            );
        } else {
            $bankTrustAccount = $this->findAccountByCode($storageContextId, '1010');
            $isDeposit = $this->isCreditType((string) $transaction->transaction_type);

            if ($isDeposit) {
                $lines[] = $this->line($bankTrustAccount, $sourceFund, 'Bank - Trust Account (deposit)', $amount, '0.00');
                $lines[] = $this->line($clientTrustAccount, $sourceFund, 'Client Trust Funds (deposit)', '0.00', $amount);
            } else {
                $lines[] = $this->line($clientTrustAccount, $sourceFund, 'Client Trust Funds (withdrawal)', $amount, '0.00');
                $lines[] = $this->line($bankTrustAccount, $sourceFund, 'Bank - Trust Account (withdrawal)', '0.00', $amount);
            }
        }

        return $this->journalPostingService->createAndPost($storageContextId, [
            'journal_date' => ($transaction->transaction_date ?? now())->toDateString(),
            'type' => 'standard',
            'reference' => $transaction->reference ?: $transaction->idempotency_key,
            'source_type' => 'client_fund_transaction',
            'source_id' => $transaction->id,
            'description' => "Client fund {$transaction->transaction_type}: {$transaction->description}",
            'actor_id' => $transaction->approved_by ?: $transaction->recorded_by,
            'lines' => $lines,
        ]);
    }

    private function postReversalJournal(ClientFundTransaction $reversal): FinJournal
    {
        $original = ClientFundTransaction::query()
            ->with('journal.lines')
            ->lockForUpdate()
            ->findOrFail($reversal->reversal_of_id);

        if (! $original->journal || $original->journal->status !== 'posted') {
            throw new RuntimeException('The original client-money journal is not available for reversal.');
        }

        $originalJournal = FinJournal::query()->lockForUpdate()->findOrFail($original->journal->id);
        $journal = $this->journalPostingService->reverse(
            $originalJournal,
            $reversal->reversal_reason,
            [
                'journal_date' => ($reversal->transaction_date ?? now())->toDateString(),
                'reference' => $reversal->reference ?: 'REV-'.$originalJournal->journal_number,
                'description' => "Reversal of {$originalJournal->journal_number}: {$reversal->reversal_reason}",
                'source_type' => 'client_fund_transaction_reversal',
                'source_id' => $reversal->id,
                'actor_id' => $reversal->approved_by,
            ],
        );

        return $journal;
    }

    /** @return array<string, mixed> */
    private function line(
        FinAccount $account,
        ClientFund $fund,
        string $description,
        string $debit,
        string $credit,
    ): array {
        return [
            'account_id' => $account->id,
            'description' => $description,
            'debit' => $debit,
            'credit' => $credit,
            'client_id' => $fund->client_id,
            'client_fund_id' => $fund->id,
            'site_id' => $fund->client->site_id,
        ];
    }

    private function assertBoundProvenance(
        ClientFundTransaction $transaction,
        ?ClientFund $sourceFund,
        ?ClientFund $destinationFund,
    ): void {
        if (! $sourceFund || ! $sourceFund->client || ! $sourceFund->client->site_id) {
            throw (new ModelNotFoundException)->setModel(ClientFund::class);
        }

        if ((int) $transaction->client_id !== (int) $sourceFund->client_id
            || (int) $transaction->site_id !== (int) $sourceFund->client->site_id
            || strtoupper((string) $transaction->currency_code) !== strtoupper((string) $sourceFund->currency_code)) {
            throw (new ModelNotFoundException)->setModel(ClientFundTransaction::class);
        }

        if ($transaction->destination_fund_id) {
            if (! $destinationFund
                || (int) $destinationFund->client_id !== (int) $sourceFund->client_id
                || (int) $destinationFund->client->site_id !== (int) $sourceFund->client->site_id
                || strtoupper((string) $destinationFund->currency_code) !== strtoupper((string) $sourceFund->currency_code)) {
                throw (new ModelNotFoundException)->setModel(ClientFund::class);
            }
        }
    }

    public function findAccountByCode(int $storageContextId, string $code): FinAccount
    {
        $cacheKey = "{$storageContextId}:{$code}";

        if (isset($this->accountCache[$cacheKey])) {
            return $this->accountCache[$cacheKey];
        }

        $account = FinAccount::query()
            ->where('organization_id', $storageContextId)
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if (! $account) {
            throw new RuntimeException("GL account with code '{$code}' not found (or inactive) for the application storage context.");
        }

        return $this->accountCache[$cacheKey] = $account;
    }

    /**
     * Journal numbers are organisation-wide. Locking the canonical trust
     * liability account serializes client-money number generation without a
     * competing sequence or ledger and keeps concurrent approved posts exact.
     */
    private function lockClientMoneyPostingSequence(): void
    {
        $account = FinAccount::query()
            ->where('organization_id', self::APPLICATION_STORAGE_CONTEXT_ID)
            ->where('code', '2500')
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        if (! $account) {
            throw new RuntimeException('The Client Trust Funds GL account is not available for posting.');
        }

        $this->accountCache[self::APPLICATION_STORAGE_CONTEXT_ID.':2500'] = $account;
    }

    private function isCreditType(string $type): bool
    {
        return in_array($type, ['credit', 'deposit', 'inflow', 'transfer_credit'], true);
    }
}
