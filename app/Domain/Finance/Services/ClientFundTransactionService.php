<?php

namespace App\Domain\Finance\Services;

use App\Models\ClientFund;
use App\Models\ClientFundTransaction;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ClientFundTransactionService
{
    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /**
     * Record a canonical client-money request. Policy-sensitive requests stay
     * pending; non-sensitive requests are approved and applied under the same
     * fund row lock. GL dispatch remains after-commit through the observer.
     *
     * @param  array{
     *     type: 'credit'|'debit'|'transfer',
     *     amount: numeric-string|int|float,
     *     description: string,
     *     reference?: string|null,
     *     idempotency_key: string,
     *     destination_fund_id?: int|null,
     *     currency_code?: string|null,
     *     source_type?: string|null,
     *     source_id?: int|null
     * }  $data
     */
    public function record(ClientFund $fund, User $actor, array $data): ClientFundTransaction
    {
        $this->assertPermission($actor, 'client_funds.manage');

        $payload = $this->normalizePayload($data);
        $destinationFundId = $payload['type'] === 'transfer'
            ? (int) ($data['destination_fund_id'] ?? 0)
            : null;

        if ($payload['type'] === 'transfer' && $destinationFundId <= 0) {
            throw ValidationException::withMessages([
                'destination_fund_id' => 'A destination fund is required.',
            ]);
        }

        return DB::transaction(function () use ($fund, $actor, $payload, $destinationFundId): ClientFundTransaction {
            $funds = $this->lockAccessibleFunds(
                $actor,
                array_values(array_unique(array_filter([$fund->getKey(), $destinationFundId]))),
            );
            $lockedFund = $funds->get((int) $fund->getKey());

            if (! $lockedFund) {
                throw (new ModelNotFoundException)->setModel(ClientFund::class);
            }

            $destinationFund = $destinationFundId ? $funds->get($destinationFundId) : null;
            if ($destinationFundId && ! $destinationFund) {
                throw (new ModelNotFoundException)->setModel(ClientFund::class);
            }

            $this->assertCanonicalFund($lockedFund);

            if ($destinationFund) {
                $this->assertCanonicalTransfer($lockedFund, $destinationFund);
            }

            $currency = $payload['currency_code'] ?: strtoupper((string) $lockedFund->currency_code);
            if ($currency !== strtoupper((string) $lockedFund->currency_code)) {
                throw ValidationException::withMessages([
                    'currency_code' => 'The transaction currency does not match the fund currency.',
                ]);
            }

            $existing = ClientFundTransaction::query()
                ->where('client_fund_id', $lockedFund->id)
                ->where('idempotency_key', $payload['idempotency_key'])
                ->first();

            if ($existing) {
                if (! $this->matchesPayload($existing, $actor, $payload, $destinationFund?->id)) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'This submission key was already used for a different transaction.',
                    ]);
                }

                return $existing;
            }

            $requiresApproval = $this->requiresIndependentApproval(
                $payload['type'],
                $payload['amount'],
            );
            $newBalance = (string) $lockedFund->balance;

            if (! $requiresApproval) {
                $newBalance = $this->applySingleFundEffect(
                    $lockedFund,
                    $payload['type'],
                    $payload['amount'],
                );
            }

            $transaction = $lockedFund->transactions()->create([
                'client_id' => $lockedFund->client_id,
                'site_id' => $lockedFund->client->site_id,
                'destination_fund_id' => $destinationFund?->id,
                'idempotency_key' => $payload['idempotency_key'],
                'status' => $requiresApproval ? 'pending' : 'approved',
                'transaction_type' => $payload['type'],
                'amount' => $payload['amount'],
                'currency_code' => $currency,
                'description' => $payload['description'],
                'reference' => $payload['reference'],
                'source_type' => $payload['source_type'],
                'source_id' => $payload['source_id'],
                'running_balance' => $newBalance,
                'transaction_date' => now()->toDateString(),
                'recorded_by' => $actor->id,
                'approval_required' => $requiresApproval,
                'requested_at' => now(),
                'approved_by' => $requiresApproval ? null : $actor->id,
                'approved_at' => $requiresApproval ? null : now(),
                'approval_reason' => $requiresApproval ? null : 'Independent approval not required by client-money policy.',
                'balance_effect_applied_at' => $requiresApproval ? null : now(),
            ]);

            if (! $requiresApproval) {
                $this->markFundAwaitingReconciliation($lockedFund);
            }

            return $transaction;
        }, 3);
    }

    public function approve(
        ClientFundTransaction $transaction,
        User $checker,
        string $reason,
    ): ClientFundTransaction {
        $this->assertPermission($checker, 'client_funds.approve');
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'An approval reason is required.']);
        }

        $visible = $this->accessibleTransactions($checker)->findOrFail($transaction->getKey());

        return DB::transaction(function () use ($visible, $checker, $reason): ClientFundTransaction {
            $fundIds = array_values(array_unique(array_filter([
                (int) $visible->client_fund_id,
                $visible->destination_fund_id ? (int) $visible->destination_fund_id : null,
            ])));
            $funds = $this->lockAccessibleFunds($checker, $fundIds);
            if ($funds->count() !== count($fundIds)) {
                throw (new ModelNotFoundException)->setModel(ClientFund::class);
            }

            $locked = ClientFundTransaction::query()->lockForUpdate()->findOrFail($visible->id);
            $sourceFund = $funds->get((int) $locked->client_fund_id);
            $destinationFund = $locked->destination_fund_id
                ? $funds->get((int) $locked->destination_fund_id)
                : null;

            $this->assertBoundProvenance($locked, $sourceFund, $destinationFund);

            if ((int) $locked->recorded_by === (int) $checker->id) {
                throw ValidationException::withMessages([
                    'approval' => 'The transaction maker cannot approve their own transaction.',
                ]);
            }

            if (in_array($locked->status, ['approved', 'posted'], true)) {
                return $locked;
            }

            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages([
                    'transaction' => 'This transaction is not awaiting approval.',
                ]);
            }

            $original = null;
            if ($locked->reversal_of_id) {
                $original = ClientFundTransaction::query()->lockForUpdate()->findOrFail($locked->reversal_of_id);
                if ($original->status !== 'posted' || $original->reversal_of_id !== null) {
                    throw ValidationException::withMessages([
                        'transaction' => 'The original transaction cannot be reversed.',
                    ]);
                }
            }

            if ($locked->transaction_type === 'transfer' || $locked->transaction_type === 'transfer_reversal') {
                $this->applyTransferEffect($locked, $sourceFund, $destinationFund, $checker, $reason);
            } else {
                $newBalance = $this->applySingleFundEffect(
                    $sourceFund,
                    (string) $locked->transaction_type,
                    (string) $locked->amount,
                );
                $locked->forceFill(['running_balance' => $newBalance]);
            }

            $locked->forceFill([
                'status' => 'approved',
                'approved_by' => $checker->id,
                'approved_at' => now(),
                'approval_reason' => $reason,
                'balance_effect_applied_at' => now(),
                'posting_failed_at' => null,
                'posting_failure_code' => null,
                'posting_failure_message' => null,
            ])->save();

            if ($original) {
                $original->forceFill(['status' => 'reversed'])->save();
                if ($original->counterpart_transaction_id) {
                    ClientFundTransaction::query()
                        ->whereKey($original->counterpart_transaction_id)
                        ->update(['status' => 'reversed']);
                }
            }

            $this->markFundAwaitingReconciliation($sourceFund);
            if ($destinationFund) {
                $this->markFundAwaitingReconciliation($destinationFund);
            }

            return $locked->refresh();
        }, 3);
    }

    public function reject(
        ClientFundTransaction $transaction,
        User $checker,
        string $reason,
    ): ClientFundTransaction {
        $this->assertPermission($checker, 'client_funds.approve');
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'A rejection reason is required.']);
        }

        $visible = $this->accessibleTransactions($checker)->findOrFail($transaction->getKey());

        return DB::transaction(function () use ($visible, $checker, $reason): ClientFundTransaction {
            $fundIds = array_values(array_unique(array_filter([
                (int) $visible->client_fund_id,
                $visible->destination_fund_id ? (int) $visible->destination_fund_id : null,
            ])));
            $funds = $this->lockAccessibleFunds($checker, $fundIds);
            if ($funds->count() !== count($fundIds)) {
                throw (new ModelNotFoundException)->setModel(ClientFund::class);
            }

            $fund = $funds->get((int) $visible->client_fund_id);
            $destinationFund = $visible->destination_fund_id
                ? $funds->get((int) $visible->destination_fund_id)
                : null;
            $locked = ClientFundTransaction::query()->lockForUpdate()->findOrFail($visible->id);
            $this->assertBoundProvenance($locked, $fund, $destinationFund);

            if ($locked->status === 'rejected') {
                return $locked;
            }

            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages([
                    'transaction' => 'This transaction is not awaiting approval.',
                ]);
            }

            if ((int) $locked->recorded_by === (int) $checker->id) {
                throw ValidationException::withMessages([
                    'approval' => 'The transaction maker cannot decide their own transaction.',
                ]);
            }

            $locked->forceFill([
                'status' => 'rejected',
                'rejected_by' => $checker->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            return $locked->refresh();
        }, 3);
    }

    /**
     * Request one linked, equal-and-opposite reversal. It remains pending until
     * a different authorized checker approves it.
     *
     * @param  array{idempotency_key: string, reason: string, reference?: string|null}  $data
     */
    public function reverse(
        ClientFundTransaction $transaction,
        User $actor,
        array $data,
    ): ClientFundTransaction {
        $this->assertPermission($actor, 'client_funds.manage');
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'A reversal reason is required.']);
        }

        $key = strtolower(trim((string) ($data['idempotency_key'] ?? '')));
        if (! Str::isUuid($key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'A submission key is required.']);
        }

        $visible = $this->accessibleTransactions($actor)->findOrFail($transaction->getKey());

        return DB::transaction(function () use ($visible, $actor, $data, $reason, $key): ClientFundTransaction {
            $fundIds = array_values(array_unique(array_filter([
                (int) $visible->client_fund_id,
                $visible->destination_fund_id ? (int) $visible->destination_fund_id : null,
            ])));
            $funds = $this->lockAccessibleFunds($actor, $fundIds);
            if ($funds->count() !== count($fundIds)) {
                throw (new ModelNotFoundException)->setModel(ClientFund::class);
            }

            $original = ClientFundTransaction::query()->lockForUpdate()->findOrFail($visible->id);
            $sourceFund = $funds->get((int) $original->client_fund_id);
            $destinationFund = $original->destination_fund_id
                ? $funds->get((int) $original->destination_fund_id)
                : null;
            $this->assertBoundProvenance($original, $sourceFund, $destinationFund);

            $existing = ClientFundTransaction::query()->where('reversal_of_id', $original->id)->first();
            if ($existing) {
                if ($existing->idempotency_key !== $key
                    || (int) $existing->recorded_by !== (int) $actor->id
                    || $existing->reversal_reason !== $reason) {
                    throw ValidationException::withMessages([
                        'transaction' => 'A reversal has already been requested for this transaction.',
                    ]);
                }

                return $existing;
            }

            if ($original->status !== 'posted' || $original->reversal_of_id !== null) {
                throw ValidationException::withMessages([
                    'transaction' => 'Only an unreversed posted transaction can be reversed.',
                ]);
            }

            $isTransfer = $original->transaction_type === 'transfer';
            $type = $isTransfer
                ? 'transfer_reversal'
                : ($this->isCreditType((string) $original->transaction_type) ? 'debit' : 'credit');

            return $sourceFund->transactions()->create([
                'client_id' => $original->client_id,
                'site_id' => $original->site_id,
                'destination_fund_id' => $original->destination_fund_id,
                'reversal_of_id' => $original->id,
                'idempotency_key' => $key,
                'status' => 'pending',
                'transaction_type' => $type,
                'amount' => $original->amount,
                'currency_code' => $original->currency_code,
                'running_balance' => $sourceFund->balance,
                'description' => 'Reversal: '.$original->description,
                'reference' => isset($data['reference']) && trim((string) $data['reference']) !== ''
                    ? trim((string) $data['reference'])
                    : $original->reference,
                'source_type' => 'reversal',
                'source_id' => $original->id,
                'transaction_date' => now()->toDateString(),
                'recorded_by' => $actor->id,
                'approval_required' => true,
                'requested_at' => now(),
                'reversal_reason' => $reason,
            ]);
        }, 3);
    }

    /** @return Builder<ClientFundTransaction> */
    private function accessibleTransactions(User $actor): Builder
    {
        return ClientFundTransaction::query()
            ->whereHas('fund.client', fn (Builder $query) => $this->siteAccess->applyClientScope(
                $query,
                $actor,
                $this->siteBypassPermissions(),
            ));
    }

    /**
     * @param  list<int>  $fundIds
     * @return Collection<int, ClientFund>
     */
    private function lockAccessibleFunds(User $actor, array $fundIds)
    {
        if ($fundIds === []) {
            return collect();
        }

        return ClientFund::query()
            ->whereIn('id', $fundIds)
            ->whereHas('client', fn (Builder $query) => $this->siteAccess->applyClientScope(
                $query,
                $actor,
                $this->siteBypassPermissions(),
            ))
            ->with('client:id,site_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (ClientFund $lockedFund): int => (int) $lockedFund->id);
    }

    private function assertCanonicalFund(ClientFund $fund): void
    {
        if (! $fund->client || ! $fund->client->site_id || ! $fund->is_active) {
            throw (new ModelNotFoundException)->setModel(ClientFund::class);
        }
    }

    private function assertCanonicalTransfer(ClientFund $source, ClientFund $destination): void
    {
        $this->assertCanonicalFund($destination);

        if ((int) $source->id === (int) $destination->id
            || (int) $source->client_id !== (int) $destination->client_id
            || (int) $source->client->site_id !== (int) $destination->client->site_id
            || strtoupper((string) $source->currency_code) !== strtoupper((string) $destination->currency_code)) {
            throw (new ModelNotFoundException)->setModel(ClientFund::class);
        }
    }

    private function assertBoundProvenance(
        ClientFundTransaction $transaction,
        ?ClientFund $sourceFund,
        ?ClientFund $destinationFund,
    ): void {
        if (! $sourceFund) {
            throw (new ModelNotFoundException)->setModel(ClientFund::class);
        }

        $this->assertCanonicalFund($sourceFund);
        if ((int) $transaction->client_id !== (int) $sourceFund->client_id
            || (int) $transaction->site_id !== (int) $sourceFund->client->site_id
            || strtoupper((string) $transaction->currency_code) !== strtoupper((string) $sourceFund->currency_code)) {
            throw (new ModelNotFoundException)->setModel(ClientFundTransaction::class);
        }

        if ($transaction->destination_fund_id) {
            if (! $destinationFund) {
                throw (new ModelNotFoundException)->setModel(ClientFund::class);
            }
            $this->assertCanonicalTransfer($sourceFund, $destinationFund);
        }
    }

    private function applyTransferEffect(
        ClientFundTransaction $transaction,
        ClientFund $sourceFund,
        ?ClientFund $destinationFund,
        User $checker,
        string $reason,
    ): void {
        if (! $destinationFund) {
            throw (new ModelNotFoundException)->setModel(ClientFund::class);
        }

        $isReversal = $transaction->transaction_type === 'transfer_reversal';
        $debitFund = $isReversal ? $destinationFund : $sourceFund;
        $creditFund = $isReversal ? $sourceFund : $destinationFund;

        $debitBalance = $this->applySingleFundEffect($debitFund, 'debit', (string) $transaction->amount);
        $creditBalance = $this->applySingleFundEffect($creditFund, 'credit', (string) $transaction->amount);
        $sourceBalance = $isReversal ? $creditBalance : $debitBalance;
        $destinationBalance = $isReversal ? $debitBalance : $creditBalance;

        $counterpart = $destinationFund->transactions()->create([
            'client_id' => $transaction->client_id,
            'site_id' => $transaction->site_id,
            'destination_fund_id' => $sourceFund->id,
            'counterpart_transaction_id' => $transaction->id,
            'idempotency_key' => $transaction->idempotency_key,
            'status' => 'approved',
            'transaction_type' => $isReversal ? 'transfer_reversal_debit' : 'transfer_credit',
            'amount' => $transaction->amount,
            'currency_code' => $transaction->currency_code,
            'running_balance' => $destinationBalance,
            'description' => $transaction->description,
            'reference' => $transaction->reference,
            'source_type' => 'client_fund_transfer_counterpart',
            'source_id' => $transaction->id,
            'transaction_date' => $transaction->transaction_date,
            'recorded_by' => $transaction->recorded_by,
            'approval_required' => true,
            'requested_at' => $transaction->requested_at,
            'approved_by' => $checker->id,
            'approved_at' => now(),
            'approval_reason' => $reason,
            'balance_effect_applied_at' => now(),
            'reversal_of_id' => $isReversal
                ? ClientFundTransaction::query()
                    ->whereKey($transaction->reversal_of_id)
                    ->value('counterpart_transaction_id')
                : null,
            'reversal_reason' => $transaction->reversal_reason,
        ]);

        $transaction->forceFill([
            'counterpart_transaction_id' => $counterpart->id,
            'running_balance' => $sourceBalance,
        ]);
    }

    private function applySingleFundEffect(ClientFund $fund, string $type, string $amount): string
    {
        $newAvailableBalance = $this->isCreditType($type)
            ? bcadd((string) $fund->available_balance, $amount, 2)
            : bcsub((string) $fund->available_balance, $amount, 2);
        $newLedgerBalance = $this->isCreditType($type)
            ? bcadd((string) $fund->balance, $amount, 2)
            : bcsub((string) $fund->balance, $amount, 2);

        $minimum = '0.00';
        if ($this->hasGovernedOverdraftPolicy($fund)) {
            $minimum = bcmul((string) $fund->overdraft_limit, '-1', 2);
        }

        if (bccomp($newAvailableBalance, $minimum, 2) < 0) {
            throw ValidationException::withMessages([
                'amount' => 'The transaction exceeds the available cleared balance.',
            ]);
        }

        $fund->forceFill([
            'balance' => $newLedgerBalance,
            'available_balance' => $newAvailableBalance,
        ])->save();

        return $newLedgerBalance;
    }

    private function hasGovernedOverdraftPolicy(ClientFund $fund): bool
    {
        return $fund->overdraft_policy_state === 'authorized'
            && bccomp((string) $fund->overdraft_limit, '0.00', 2) > 0
            && $fund->overdraft_authorized_by !== null
            && $fund->overdraft_authorized_at !== null
            && trim((string) $fund->overdraft_authorization_reason) !== '';
    }

    private function markFundAwaitingReconciliation(ClientFund $fund): void
    {
        $fund->forceFill([
            'reconciliation_status' => 'review',
            'reconciliation_details' => [
                'reason' => 'Approved client-money effect awaits dimensional GL reconciliation.',
            ],
            'reconciled_at' => null,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{type: string, amount: string, description: string, reference: ?string, idempotency_key: string, currency_code: ?string, source_type: string, source_id: ?int}
     */
    private function normalizePayload(array $data): array
    {
        $type = strtolower(trim((string) ($data['type'] ?? '')));
        if (! in_array($type, ['credit', 'debit', 'transfer'], true)) {
            throw ValidationException::withMessages(['type' => 'The transaction type is invalid.']);
        }

        $rawAmount = isset($data['amount']) ? trim((string) $data['amount']) : '';
        if (preg_match('/^\d+(?:\.\d{1,2})?$/', $rawAmount) !== 1) {
            throw ValidationException::withMessages(['amount' => 'The amount must be a valid monetary value.']);
        }

        $amount = bcadd($rawAmount, '0', 2);
        if (bccomp($amount, '0.00', 2) <= 0) {
            throw ValidationException::withMessages(['amount' => 'The amount must be greater than zero.']);
        }

        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            throw ValidationException::withMessages(['description' => 'A description is required.']);
        }

        $idempotencyKey = strtolower(trim((string) ($data['idempotency_key'] ?? '')));
        if (! Str::isUuid($idempotencyKey)) {
            throw ValidationException::withMessages(['idempotency_key' => 'A submission key is required.']);
        }

        $sourceType = isset($data['source_type']) && trim((string) $data['source_type']) !== ''
            ? trim((string) $data['source_type'])
            : 'manual';
        $sourceId = isset($data['source_id']) ? (int) $data['source_id'] : null;
        if (! in_array($sourceType, ['manual', 'opening_balance'], true) || $sourceId !== null) {
            throw ValidationException::withMessages([
                'source' => 'The transaction source is not available.',
            ]);
        }

        $currencyCode = isset($data['currency_code']) && trim((string) $data['currency_code']) !== ''
            ? strtoupper(trim((string) $data['currency_code']))
            : null;
        if ($currencyCode !== null && preg_match('/^[A-Z]{3}$/', $currencyCode) !== 1) {
            throw ValidationException::withMessages([
                'currency_code' => 'The currency code is invalid.',
            ]);
        }

        return [
            'type' => $type,
            'amount' => $amount,
            'description' => $description,
            'reference' => isset($data['reference']) && trim((string) $data['reference']) !== ''
                ? trim((string) $data['reference'])
                : null,
            'idempotency_key' => $idempotencyKey,
            'currency_code' => $currencyCode,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ];
    }

    /**
     * @param  array{type: string, amount: string, description: string, reference: ?string, idempotency_key: string, currency_code: ?string, source_type: string, source_id: ?int}  $payload
     */
    private function matchesPayload(
        ClientFundTransaction $transaction,
        User $actor,
        array $payload,
        ?int $destinationFundId,
    ): bool {
        return $transaction->transaction_type === $payload['type']
            && bccomp((string) $transaction->amount, $payload['amount'], 2) === 0
            && $transaction->description === $payload['description']
            && $transaction->reference === $payload['reference']
            && (int) $transaction->recorded_by === (int) $actor->id
            && ($transaction->destination_fund_id ? (int) $transaction->destination_fund_id : null) === $destinationFundId
            && $transaction->source_type === $payload['source_type']
            && ($transaction->source_id ? (int) $transaction->source_id : null) === $payload['source_id']
            && strtoupper((string) $transaction->currency_code) === strtoupper((string) ($payload['currency_code'] ?: $transaction->currency_code));
    }

    private function requiresIndependentApproval(string $type, string $amount): bool
    {
        $sensitive = config('finance.client_funds.sensitive_transaction_types', []);
        $threshold = bcadd((string) config('finance.client_funds.approval_threshold', '500.00'), '0', 2);

        return in_array($type, $sensitive, true)
            || bccomp($amount, $threshold, 2) >= 0;
    }

    private function isCreditType(string $type): bool
    {
        return in_array($type, ['credit', 'deposit', 'inflow', 'transfer_credit', 'transfer_reversal'], true);
    }

    private function assertPermission(User $actor, string $permission): void
    {
        abort_unless($actor->canDo($permission), 403, UserSiteAccessService::DEFAULT_MESSAGE);
    }

    /** @return list<string> */
    private function siteBypassPermissions(): array
    {
        return [(string) config('finance.client_funds.site_bypass_permission', 'client_funds.viewAllSites')];
    }
}
