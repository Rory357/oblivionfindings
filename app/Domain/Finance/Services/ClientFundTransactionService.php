<?php

namespace App\Domain\Finance\Services;

use App\Models\ClientFund;
use App\Models\ClientFundTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientFundTransactionService
{
    /**
     * Record one balance movement. The affected fund is locked so concurrent
     * requests cannot derive their running balance from the same stale value.
     *
     * @param  array{
     *     type: 'credit'|'debit',
     *     amount: numeric-string|int|float,
     *     description: string,
     *     reference?: string|null,
     *     idempotency_key: string
     * }  $data
     */
    public function record(
        ClientFund $fund,
        User $actor,
        array $data,
    ): ClientFundTransaction {
        $amount = bcadd((string) $data['amount'], '0', 2);
        $description = trim($data['description']);
        $reference = isset($data['reference']) && trim((string) $data['reference']) !== ''
            ? trim((string) $data['reference'])
            : null;
        $idempotencyKey = strtolower(trim($data['idempotency_key']));

        return DB::transaction(function () use (
            $fund,
            $actor,
            $data,
            $amount,
            $description,
            $reference,
            $idempotencyKey,
        ): ClientFundTransaction {
            $lockedFund = ClientFund::query()
                ->whereKey($fund->getKey())
                ->where('organization_id', $actor->organization_id)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = ClientFundTransaction::query()
                ->where('client_fund_id', $lockedFund->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                if (! $this->matchesPayload(
                    $existing,
                    $actor,
                    $data['type'],
                    $amount,
                    $description,
                    $reference,
                )) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'This submission key was already used for a different transaction.',
                    ]);
                }

                return $existing;
            }

            $newBalance = $data['type'] === 'credit'
                ? bcadd((string) $lockedFund->balance, $amount, 2)
                : bcsub((string) $lockedFund->balance, $amount, 2);

            $transaction = $lockedFund->transactions()->create([
                'organization_id' => $lockedFund->organization_id,
                'idempotency_key' => $idempotencyKey,
                'transaction_type' => $data['type'],
                'amount' => $amount,
                'description' => $description,
                'reference' => $reference,
                'running_balance' => $newBalance,
                'transaction_date' => now()->toDateString(),
                'recorded_by' => $actor->id,
            ]);

            $lockedFund->update(['balance' => $newBalance]);

            return $transaction;
        }, 3);
    }

    private function matchesPayload(
        ClientFundTransaction $transaction,
        User $actor,
        string $type,
        string $amount,
        string $description,
        ?string $reference,
    ): bool {
        return $transaction->transaction_type === $type
            && bccomp((string) $transaction->amount, $amount, 2) === 0
            && $transaction->description === $description
            && $transaction->reference === $reference
            && (int) $transaction->recorded_by === (int) $actor->id;
    }
}
