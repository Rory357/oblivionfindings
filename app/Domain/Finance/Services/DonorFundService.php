<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinDonorFund;
use App\Domain\Finance\Models\FinDonorFundReport;
use App\Domain\Finance\Models\FinDonorFundTransaction;
use App\Domain\Finance\Models\FinFundingStream;
use App\Domain\Finance\Models\FinJournal;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class DonorFundService
{
    public function __construct(
        private readonly JournalPostingService $journalService,
        private readonly DonorFundApplicationSiteScope $billSiteScope,
    ) {}

    /**
     * Record a receipt (incoming funds) against a donor fund.
     */
    public function recordReceipt(FinDonorFund $fund, array $data): FinDonorFundTransaction
    {
        return $this->recordApplication($fund, 'receipt', $data);
    }

    /**
     * Record an expenditure against a donor fund.
     */
    public function recordExpenditure(FinDonorFund $fund, array $data): FinDonorFundTransaction
    {
        return $this->recordApplication($fund, 'expenditure', $data);
    }

    /**
     * Reverse an immutable receipt or expenditure with an equal and opposite
     * ledger row and the canonical journal reversal.
     */
    public function reverseTransaction(FinDonorFundTransaction $transaction, array $data): FinDonorFundTransaction
    {
        $payload = $this->normalizeReversalPayload($transaction, $data);
        $snapshot = FinDonorFundTransaction::query()
            ->whereKey($transaction->getKey())
            ->first(['fund_id', 'journal_id']);
        if ($snapshot === null) {
            throw new InvalidArgumentException('The donor-fund transaction is unavailable.');
        }
        $organizationId = FinDonorFund::query()->whereKey($snapshot->fund_id)->value('organization_id');
        if ($organizationId === null) {
            throw new InvalidArgumentException('The donor fund is unavailable.');
        }
        $organizationId = (int) $organizationId;

        try {
            return DB::transaction(function () use ($organizationId, $payload, $snapshot, $transaction): FinDonorFundTransaction {
                $this->journalService->lockJournalSequence($organizationId);

                $fund = FinDonorFund::query()->lockForUpdate()->findOrFail($snapshot->fund_id);
                if ((int) $fund->organization_id !== $organizationId) {
                    throw new InvalidArgumentException('The donor fund changed accounting context while the reversal was being prepared.');
                }
                $actor = $this->actorForFund($fund, $payload['actor_id']);

                $original = FinDonorFundTransaction::query()
                    ->where('fund_id', $fund->id)
                    ->lockForUpdate()
                    ->findOrFail($transaction->getKey());

                if (! in_array($original->type, ['receipt', 'expenditure'], true)
                    || $original->reversal_of_transaction_id !== null
                    || bccomp((string) $original->amount, '0.00', 2) <= 0) {
                    throw new InvalidArgumentException('Only an original receipt or expenditure can be reversed.');
                }
                if ($original->type === 'expenditure') {
                    $bill = $original->bill_id === null
                        ? null
                        : FinBill::query()
                            ->where('organization_id', $organizationId)
                            ->lockForUpdate()
                            ->find($original->bill_id);
                    if ($bill === null) {
                        throw new InvalidArgumentException(
                            'The donor-fund application lacks a governed bill source and requires finance review before reversal.'
                        );
                    }
                    if ($original->site_id === null
                        || (int) $bill->site_id !== (int) $original->site_id) {
                        throw new InvalidArgumentException(
                            'The donor-fund bill Site lineage changed and requires finance review before reversal.'
                        );
                    }
                    $this->billSiteScope->assertCanAccessBill($actor, $bill);
                }
                if ($original->journal_id === null) {
                    throw new InvalidArgumentException(
                        'The donor-fund application lacks a canonical journal and requires finance review before reversal.'
                    );
                }
                if (($original->journal_id === null) !== ($snapshot->journal_id === null)
                    || ($original->journal_id !== null && (int) $original->journal_id !== (int) $snapshot->journal_id)) {
                    throw new InvalidArgumentException('The donor-fund journal lineage changed while the reversal was being prepared.');
                }
                $sourceJournal = FinJournal::query()
                    ->where('organization_id', $organizationId)
                    ->lockForUpdate()
                    ->findOrFail($original->journal_id);
                if ($sourceJournal->source_type !== FinDonorFundTransaction::class
                    || (int) $sourceJournal->source_id !== (int) $original->id) {
                    throw new InvalidArgumentException('The donor-fund source journal does not own this application.');
                }

                $existing = FinDonorFundTransaction::query()
                    ->where('fund_id', $fund->id)
                    ->where(function ($query) use ($original, $payload): void {
                        $query->where('idempotency_key', $payload['idempotency_key'])
                            ->orWhere('reversal_of_transaction_id', $original->id);
                    })
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    return $this->assertReversalReplay(
                        $existing,
                        $original,
                        $payload['payload_hash'],
                        $payload['actor_id'],
                    );
                }

                [$totalReceived, $totalSpent, $available] = $this->reversedBalances($fund, $original);

                $reversal = $fund->transactions()->create([
                    'site_id' => $original->site_id,
                    'funding_stream_id' => $original->funding_stream_id,
                    'idempotency_key' => $payload['idempotency_key'],
                    'payload_hash' => $payload['payload_hash'],
                    'transaction_date' => $payload['transaction_date'],
                    'type' => $original->type,
                    'description' => "Reversal: {$original->description}",
                    'amount' => bcsub('0.00', (string) $original->amount, 2),
                    'bank_account_id' => $original->bank_account_id,
                    'expense_account_id' => $original->expense_account_id,
                    'reversal_of_transaction_id' => $original->id,
                    'reference' => $payload['reference'] ?? "REV-{$original->id}",
                    'created_by' => $payload['actor_id'],
                ]);

                $journal = $this->journalService->reverse(
                    $sourceJournal,
                    $payload['reason'],
                    [
                        'journal_date' => $payload['transaction_date'],
                        'reference' => $payload['reference'] ?? "REV-FUND-{$fund->fund_code}-{$original->id}",
                        'source_type' => FinDonorFundTransaction::class,
                        'source_id' => $reversal->id,
                        'actor_id' => $payload['actor_id'],
                    ],
                );
                if ($journal->source_type !== FinDonorFundTransaction::class
                    || (int) $journal->source_id !== (int) $reversal->id
                    || (int) $journal->reversal_of_journal_id !== (int) $sourceJournal->id) {
                    throw new InvalidArgumentException('The donor-fund source journal was already reversed outside this application lineage.');
                }
                $reversal->linkJournal($journal);

                $this->persistBalances($fund, $totalReceived, $totalSpent, $available);

                return $reversal->refresh();
            }, attempts: 3);
        } catch (QueryException $exception) {
            if (! $this->isApplicationUniqueCollision($exception)) {
                throw $exception;
            }

            return $this->resolveReversalAfterCollision($transaction, $payload, $exception);
        }
    }

    /** @param  'receipt'|'expenditure'  $type */
    private function recordApplication(FinDonorFund $fund, string $type, array $data): FinDonorFundTransaction
    {
        $payload = $this->normalizeApplicationPayload($fund, $type, $data);
        $organizationId = FinDonorFund::query()->whereKey($fund->getKey())->value('organization_id');
        if ($organizationId === null) {
            throw new InvalidArgumentException('The donor fund is unavailable.');
        }
        $organizationId = (int) $organizationId;

        try {
            return DB::transaction(function () use ($fund, $organizationId, $payload, $type): FinDonorFundTransaction {
                // Shared ordering is sequence -> fund -> request/source -> journal.
                // createAndPost() safely re-enters the same sequence mutex.
                $this->journalService->lockJournalSequence($organizationId);

                $lockedFund = FinDonorFund::query()->lockForUpdate()->findOrFail($fund->getKey());
                if ((int) $lockedFund->organization_id !== $organizationId) {
                    throw new InvalidArgumentException('The donor fund changed accounting context while the application was being prepared.');
                }
                $actor = $this->actorForFund($lockedFund, $payload['actor_id']);

                $existing = FinDonorFundTransaction::query()
                    ->where('fund_id', $lockedFund->id)
                    ->where('idempotency_key', $payload['idempotency_key'])
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    return $this->assertApplicationReplay(
                        $existing,
                        $lockedFund,
                        $type,
                        $payload['payload_hash'],
                        $payload['actor_id'],
                    );
                }

                $bill = $type === 'expenditure'
                    ? $this->lockBill($lockedFund, $payload, $actor)
                    : null;
                if ($bill) {
                    $existing = FinDonorFundTransaction::query()
                        ->where('bill_id', $bill->id)
                        ->lockForUpdate()
                        ->first();
                    if ($existing) {
                        return $this->assertApplicationReplay(
                            $existing,
                            $lockedFund,
                            $type,
                            $payload['payload_hash'],
                            $payload['actor_id'],
                        );
                    }
                }

                $this->assertRecordableState($lockedFund, $type);
                if ($bill) {
                    $this->validateBillApplicationSource($lockedFund, $bill, $payload['amount']);
                }

                $bankGlAccountId = $type === 'receipt'
                    ? $this->receiptDebitAccountId($lockedFund, $payload['bank_account_id'])
                    : null;
                $releaseAccountId = $type === 'expenditure'
                    ? $this->releaseRevenueAccountId($lockedFund)
                    : null;
                $this->validateExpenseClassification($lockedFund, $bill, $payload['expense_account_id']);

                if ($type === 'expenditure'
                    && $lockedFund->is_restricted
                    && bccomp($payload['amount'], (string) $lockedFund->available_balance, 2) > 0) {
                    throw new InvalidArgumentException(
                        "Insufficient fund balance. Available: \${$lockedFund->available_balance}, Requested: \${$payload['amount']}"
                    );
                }

                $transaction = $lockedFund->transactions()->create([
                    'site_id' => $bill?->site_id,
                    'funding_stream_id' => $lockedFund->funding_stream_id,
                    'idempotency_key' => $payload['idempotency_key'],
                    'payload_hash' => $payload['payload_hash'],
                    'transaction_date' => $payload['transaction_date'],
                    'type' => $type,
                    'description' => $payload['description'],
                    'amount' => $payload['amount'],
                    'bill_id' => $bill?->id,
                    'bank_account_id' => $payload['bank_account_id'],
                    'expense_account_id' => $payload['expense_account_id'],
                    'reference' => $payload['reference'],
                    'created_by' => $payload['actor_id'],
                ]);

                if ($lockedFund->gl_account_id !== null) {
                    $journal = $this->journalService->createAndPost($lockedFund->organization_id, [
                        'journal_date' => $payload['transaction_date'],
                        'type' => 'standard',
                        'reference' => "FUND-{$lockedFund->fund_code}",
                        'description' => $type === 'receipt'
                            ? "Fund receipt: {$lockedFund->fund_name} - {$payload['description']}"
                            : "Fund release: {$lockedFund->fund_name} - {$payload['description']}",
                        'source_type' => FinDonorFundTransaction::class,
                        'source_id' => $transaction->id,
                        'actor_id' => $payload['actor_id'],
                        'lines' => $type === 'receipt'
                            ? [
                                ['account_id' => $bankGlAccountId, 'debit' => $payload['amount'], 'credit' => 0, 'funding_stream_id' => $lockedFund->funding_stream_id],
                                ['account_id' => $lockedFund->gl_account_id, 'debit' => 0, 'credit' => $payload['amount'], 'funding_stream_id' => $lockedFund->funding_stream_id],
                            ]
                            : [
                                ['account_id' => $lockedFund->gl_account_id, 'debit' => $payload['amount'], 'credit' => 0, 'funding_stream_id' => $lockedFund->funding_stream_id, 'site_id' => $bill->site_id],
                                ['account_id' => $releaseAccountId, 'debit' => 0, 'credit' => $payload['amount'], 'funding_stream_id' => $lockedFund->funding_stream_id, 'site_id' => $bill->site_id],
                            ],
                    ]);
                    $transaction->linkJournal($journal);
                }

                $totalReceived = $type === 'receipt'
                    ? bcadd((string) $lockedFund->total_received, $payload['amount'], 2)
                    : bcadd((string) $lockedFund->total_received, '0.00', 2);
                $totalSpent = $type === 'expenditure'
                    ? bcadd((string) $lockedFund->total_spent, $payload['amount'], 2)
                    : bcadd((string) $lockedFund->total_spent, '0.00', 2);
                $available = bcsub(
                    $totalReceived,
                    bcadd($totalSpent, (string) $lockedFund->total_committed, 2),
                    2,
                );
                $this->persistBalances($lockedFund, $totalReceived, $totalSpent, $available);

                return $transaction->refresh();
            }, attempts: 3);
        } catch (QueryException $exception) {
            if (! $this->isApplicationUniqueCollision($exception)) {
                throw $exception;
            }

            return $this->resolveApplicationAfterCollision($fund, $type, $payload, $exception);
        }
    }

    /** @return array<string, mixed> */
    private function normalizeApplicationPayload(FinDonorFund $fund, string $type, array $data): array
    {
        $idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));
        if (! Str::isUuid($idempotencyKey)) {
            throw new InvalidArgumentException('A valid donor-fund submission key is required.');
        }

        $amount = bcadd((string) ($data['amount'] ?? '0'), '0.00', 2);
        if (bccomp($amount, '0.00', 2) <= 0) {
            throw new InvalidArgumentException('The donor-fund transaction amount must be greater than zero.');
        }

        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            throw new InvalidArgumentException('A donor-fund transaction description is required.');
        }

        $payload = [
            'fund_id' => (int) $fund->getKey(),
            'type' => $type,
            'transaction_date' => Carbon::parse($data['transaction_date'])->toDateString(),
            'description' => $description,
            'amount' => $amount,
            'reference' => $this->nullableString($data['reference'] ?? null),
            'bank_account_id' => $this->nullableId($data['bank_account_id'] ?? null),
            'expense_account_id' => $this->nullableId($data['expense_account_id'] ?? null),
            'bill_id' => $this->nullableId($data['bill_id'] ?? null),
        ];
        if ($type === 'receipt' && ($payload['bill_id'] !== null || $payload['expense_account_id'] !== null)) {
            throw new InvalidArgumentException('A donor-fund receipt cannot claim an expenditure source.');
        }
        if ($type === 'expenditure' && $payload['bank_account_id'] !== null) {
            throw new InvalidArgumentException('A donor-fund expenditure cannot claim a receipt bank source.');
        }

        $actorId = isset($data['actor_id']) ? (int) $data['actor_id'] : Auth::id();
        if ($actorId === null) {
            throw new InvalidArgumentException('An authenticated actor is required to record a donor-fund application.');
        }

        return [
            ...$payload,
            'idempotency_key' => $idempotencyKey,
            'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            'actor_id' => $actorId,
        ];
    }

    /** @return array<string, mixed> */
    private function normalizeReversalPayload(FinDonorFundTransaction $transaction, array $data): array
    {
        $idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));
        if (! Str::isUuid($idempotencyKey)) {
            throw new InvalidArgumentException('A valid donor-fund reversal key is required.');
        }

        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new InvalidArgumentException('A donor-fund reversal reason is required.');
        }

        $canonical = [
            'action' => 'reverse',
            'transaction_id' => (int) $transaction->getKey(),
            'transaction_date' => Carbon::parse($data['transaction_date'])->toDateString(),
            'reason' => $reason,
            'reference' => $this->nullableString($data['reference'] ?? null),
        ];

        $actorId = isset($data['actor_id']) ? (int) $data['actor_id'] : Auth::id();
        if ($actorId === null) {
            throw new InvalidArgumentException('An authenticated actor is required to reverse a donor-fund application.');
        }

        return [
            ...$canonical,
            'idempotency_key' => $idempotencyKey,
            'payload_hash' => hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR)),
            'actor_id' => $actorId,
        ];
    }

    private function actorForFund(FinDonorFund $fund, int $actorId): User
    {
        $actor = Auth::user();
        if ($actor === null || (int) $actor->id !== $actorId) {
            $actor = User::query()->find($actorId);
        }
        if ($actor === null
            || (int) $actor->organization_id !== (int) $fund->organization_id) {
            throw new InvalidArgumentException('The donor fund is unavailable.');
        }

        return $actor;
    }

    private function assertRecordableState(FinDonorFund $fund, string $type): void
    {
        $allowed = $type === 'receipt' ? ['active', 'fully_spent'] : ['active'];
        if (! in_array($fund->status, $allowed, true)) {
            throw new InvalidArgumentException("The donor fund cannot record a {$type} while it is {$fund->status}.");
        }

        if ($fund->gl_account_id === null) {
            throw new InvalidArgumentException('A liability or equity GL account is required before donor-fund applications can be recorded.');
        }

        $validGl = FinAccount::query()
            ->whereKey($fund->gl_account_id)
            ->where('organization_id', $fund->organization_id)
            ->where('is_active', true)
            ->whereIn('type', ['liability', 'equity'])
            ->exists();
        if (! $validGl) {
            throw new InvalidArgumentException('The donor fund GL account is unavailable or invalid.');
        }
    }

    private function lockBill(FinDonorFund $fund, array $payload, User $actor): FinBill
    {
        if ($payload['bill_id'] === null) {
            throw new InvalidArgumentException('A posted approved bill is required for every donor-fund expenditure.');
        }

        $bill = FinBill::query()
            ->whereKey($payload['bill_id'])
            ->where('organization_id', $fund->organization_id)
            ->lockForUpdate()
            ->first();
        if ($bill === null) {
            throw new InvalidArgumentException(
                'Only a posted approved bill in the same accounting context can be applied to this fund.'
            );
        }

        $this->billSiteScope->assertCanAccessBill($actor, $bill);

        return $bill;
    }

    private function validateBillApplicationSource(FinDonorFund $fund, FinBill $bill, string $amount): void
    {
        $journal = $bill->journal_id === null
            ? null
            : FinJournal::query()
                ->where('organization_id', $fund->organization_id)
                ->lockForUpdate()
                ->find($bill->journal_id);
        if ((int) $bill->organization_id !== (int) $fund->organization_id
            || ! in_array($bill->status, ['approved', 'partially_paid', 'paid'], true)
            || $journal === null
            || $journal->status !== 'posted'
            || $journal->source_type !== FinBill::class
            || (int) $journal->source_id !== (int) $bill->id
            || $journal->reversal_of_journal_id !== null
            || $journal->reversed_by_journal_id !== null
            || bccomp((string) $journal->total_amount, (string) $bill->total_amount, 2) !== 0) {
            throw new InvalidArgumentException('Only a posted approved bill in the same accounting context can be applied to this fund.');
        }
        if (bccomp($amount, (string) $bill->total_amount, 2) > 0) {
            throw new InvalidArgumentException('The donor-fund application cannot exceed the linked bill total.');
        }
        if ($bill->lines()
            ->whereNotNull('funding_stream_id')
            ->where('funding_stream_id', '!=', $fund->funding_stream_id)
            ->exists()) {
            throw new InvalidArgumentException(
                'The approved bill contains a funding-stream classification that conflicts with this donor fund.'
            );
        }
    }

    private function receiptDebitAccountId(FinDonorFund $fund, ?int $bankAccountId): int
    {
        if ($bankAccountId !== null) {
            $bank = FinBankAccount::query()
                ->whereKey($bankAccountId)
                ->where('organization_id', $fund->organization_id)
                ->where('is_active', true)
                ->first();
            $account = $bank?->glAccount()->first();
        } else {
            $account = FinAccount::forOrganization($fund->organization_id)
                ->where('code', '1000')
                ->first();
        }

        if ($account === null
            || (int) $account->organization_id !== (int) $fund->organization_id
            || ! $account->is_active
            || $account->type !== 'asset') {
            throw new InvalidArgumentException('An active bank GL account is required to record this receipt.');
        }

        return (int) $account->id;
    }

    private function releaseRevenueAccountId(FinDonorFund $fund): int
    {
        $stream = FinFundingStream::query()
            ->whereKey($fund->funding_stream_id)
            ->where('organization_id', $fund->organization_id)
            ->where('is_active', true)
            ->first();
        $account = $stream?->defaultRevenueAccount()->first();
        if ($account === null
            || (int) $account->organization_id !== (int) $fund->organization_id
            || ! $account->is_active
            || $account->type !== 'revenue') {
            throw new InvalidArgumentException('An active funding-stream revenue account is required to release this fund application.');
        }

        return (int) $account->id;
    }

    private function validateExpenseClassification(FinDonorFund $fund, ?FinBill $bill, ?int $expenseAccountId): void
    {
        if ($expenseAccountId === null) {
            return;
        }

        $valid = $bill !== null
            && FinAccount::query()
                ->whereKey($expenseAccountId)
                ->where('organization_id', $fund->organization_id)
                ->where('is_active', true)
                ->where('type', 'expense')
                ->exists()
            && $bill->lines()->where('account_id', $expenseAccountId)->exists();
        if (! $valid) {
            throw new InvalidArgumentException('The donor-fund expense classification must come from the linked bill.');
        }
    }

    private function assertApplicationReplay(
        FinDonorFundTransaction $existing,
        FinDonorFund $fund,
        string $type,
        string $payloadHash,
        int $actorId,
    ): FinDonorFundTransaction {
        if ((int) $existing->fund_id !== (int) $fund->id
            || $existing->type !== $type
            || (int) $existing->created_by !== $actorId
            || $existing->payload_hash === null
            || ! hash_equals($existing->payload_hash, $payloadHash)) {
            throw new InvalidArgumentException('The donor-fund submission key or source was already used for a different application.');
        }

        return $existing;
    }

    private function assertReversalReplay(
        FinDonorFundTransaction $existing,
        FinDonorFundTransaction $original,
        string $payloadHash,
        int $actorId,
    ): FinDonorFundTransaction {
        if ((int) $existing->fund_id !== (int) $original->fund_id
            || (int) $existing->reversal_of_transaction_id !== (int) $original->id
            || (int) $existing->created_by !== $actorId
            || $existing->payload_hash === null
            || ! hash_equals($existing->payload_hash, $payloadHash)) {
            throw new InvalidArgumentException('The donor-fund reversal key was already used for a different request.');
        }

        return $existing;
    }

    /** @return array{0:string,1:string,2:string} */
    private function reversedBalances(FinDonorFund $fund, FinDonorFundTransaction $original): array
    {
        $totalReceived = bcadd((string) $fund->total_received, '0.00', 2);
        $totalSpent = bcadd((string) $fund->total_spent, '0.00', 2);
        if ($original->type === 'receipt') {
            $totalReceived = bcsub($totalReceived, (string) $original->amount, 2);
        } else {
            $totalSpent = bcsub($totalSpent, (string) $original->amount, 2);
        }

        if (bccomp($totalReceived, '0.00', 2) < 0 || bccomp($totalSpent, '0.00', 2) < 0) {
            throw new InvalidArgumentException('The donor-fund reversal would make an aggregate total negative.');
        }

        $available = bcsub($totalReceived, bcadd($totalSpent, (string) $fund->total_committed, 2), 2);
        if ($fund->is_restricted && bccomp($available, '0.00', 2) < 0) {
            throw new InvalidArgumentException('The receipt cannot be reversed while restricted funds are applied or committed.');
        }

        return [$totalReceived, $totalSpent, $available];
    }

    private function persistBalances(FinDonorFund $fund, string $totalReceived, string $totalSpent, string $available): void
    {
        $terminalStatus = in_array($fund->status, ['expired', 'returned'], true) ? $fund->status : null;
        $fund->update([
            'total_received' => $totalReceived,
            'total_spent' => $totalSpent,
            'available_balance' => $available,
            'status' => $terminalStatus ?? (bccomp($available, '0.00', 2) <= 0 ? 'fully_spent' : 'active'),
        ]);
    }

    private function resolveApplicationAfterCollision(
        FinDonorFund $fund,
        string $type,
        array $payload,
        QueryException $exception,
    ): FinDonorFundTransaction {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $existing = FinDonorFundTransaction::query()
                ->where('idempotency_key', $payload['idempotency_key'])
                ->when(
                    $payload['bill_id'] !== null,
                    fn ($query) => $query->orWhere('bill_id', $payload['bill_id']),
                )
                ->first();
            if ($existing) {
                return $this->assertApplicationReplay(
                    $existing,
                    $fund,
                    $type,
                    $payload['payload_hash'],
                    $payload['actor_id'],
                );
            }
            usleep(10_000);
        }

        throw $exception;
    }

    private function resolveReversalAfterCollision(
        FinDonorFundTransaction $original,
        array $payload,
        QueryException $exception,
    ): FinDonorFundTransaction {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $existing = FinDonorFundTransaction::query()
                ->where('idempotency_key', $payload['idempotency_key'])
                ->orWhere('reversal_of_transaction_id', $original->id)
                ->first();
            if ($existing) {
                return $this->assertReversalReplay(
                    $existing,
                    $original,
                    $payload['payload_hash'],
                    $payload['actor_id'],
                );
            }
            usleep(10_000);
        }

        throw $exception;
    }

    private function isApplicationUniqueCollision(QueryException $exception): bool
    {
        return (int) ($exception->errorInfo[1] ?? 0) === 1062
            && (str_contains($exception->getMessage(), 'fin_donor_fund_txn_request_unique')
                || str_contains($exception->getMessage(), 'fin_donor_fund_txn_bill_unique')
                || str_contains($exception->getMessage(), 'fin_donor_fund_txn_reversal_unique'));
    }

    private function nullableId(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * Generate a fund report for a given period.
     */
    public function generateReport(FinDonorFund $fund, string $periodFrom, string $periodTo): FinDonorFundReport
    {
        $transactions = $fund->transactions()
            ->whereBetween('transaction_date', [$periodFrom, $periodTo])
            ->orderBy('transaction_date')
            ->get();

        $openingBalance = $fund->transactions()
            ->where('transaction_date', '<', $periodFrom)
            ->selectRaw("SUM(CASE WHEN type = 'receipt' THEN amount ELSE 0 END) - SUM(CASE WHEN type = 'expenditure' THEN amount ELSE 0 END) as balance")
            ->value('balance') ?? 0;

        $totalReceipts = $transactions->where('type', 'receipt')->sum('amount');
        $totalExpenditure = $transactions->where('type', 'expenditure')->sum('amount');
        $closingBalance = $openingBalance + $totalReceipts - $totalExpenditure;
        $reportTransactions = $transactions->map(fn (FinDonorFundTransaction $transaction): array => [
            'transaction_date' => $transaction->transaction_date->toDateString(),
            'type' => $transaction->type,
            'description' => $transaction->description,
            'amount' => (string) $transaction->amount,
        ]);

        return FinDonorFundReport::create([
            'fund_id' => $fund->id,
            'report_name' => "{$fund->fund_name} - Report ".now()->format('M Y'),
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'opening_balance' => $openingBalance,
            'total_receipts' => $totalReceipts,
            'total_expenditure' => $totalExpenditure,
            'closing_balance' => $closingBalance,
            'report_data' => [
                'transactions' => $reportTransactions->all(),
                'summary_by_type' => $transactions->groupBy('type')->map->sum('amount'),
            ],
            'status' => 'draft',
            'created_by' => Auth::id(),
        ]);
    }

    public function exportReportPdf(FinDonorFundReport $report, string $disk = 'local'): FinDonorFundReport
    {
        $report->loadMissing('fund');

        $transactions = collect($report->report_data['transactions'] ?? []);
        $path = sprintf(
            'donor-fund-reports/%d/%s-%d.pdf',
            $report->fund->organization_id,
            Str::slug($report->report_name),
            $report->id,
        );

        $pdf = Pdf::loadView('finance.donor-funds.report-pdf', [
            'fund' => $report->fund,
            'report' => $report,
            'transactions' => $transactions,
        ]);

        Storage::disk($disk)->put($path, $pdf->output());

        $report->update(['file_path' => $path]);

        return $report->refresh();
    }

    /**
     * Get a summary of all active funds for an organisation.
     */
    public function getFundsSummary(?int $orgId): array
    {
        $funds = FinDonorFund::forOrganization($orgId)->active()->get();

        return [
            'total_funds' => $funds->count(),
            'total_received' => $funds->sum('total_received'),
            'total_spent' => $funds->sum('total_spent'),
            'total_available' => $funds->sum('available_balance'),
            'restricted_balance' => $funds->where('is_restricted', true)->sum('available_balance'),
            'unrestricted_balance' => $funds->where('is_restricted', false)->sum('available_balance'),
            'expiring_soon' => $funds->filter(fn ($f) => $f->end_date && Carbon::parse($f->end_date)->diffInDays(now()) <= 90)->count(),
        ];
    }
}
