<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinCostAllocation;
use App\Domain\Finance\Models\FinFinancialEvent;
use App\Domain\Finance\Models\FinJournal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The SOLE entry point for operational modules to post costs to the General Ledger.
 *
 * Every operational cost (fuel, maintenance, training, expenses, mileage, leave provisions,
 * etc.) flows through this service. It creates a FinFinancialEvent record, builds a balanced
 * journal, posts it via JournalPostingService, and creates cost allocation records for
 * cross-module reporting.
 *
 * Existing integrations (PayrollJournalService, FixedAssetService) are NOT routed
 * through here — they predate this service and remain independent.
 * New operational cost flows MUST use this service.
 */
class FinancialEventService
{
    private array $accountCache = [];

    public function __construct(
        private readonly JournalPostingService $journalPostingService,
    ) {}

    /**
     * Record a financial event from an operational module and post to GL.
     *
     * This method is designed to be called from a queued job (ProcessFinancialEventJob).
     * Observers should dispatch the job, not call this directly.
     *
     * @param  array{
     *     organization_id: int,
     *     source_type: class-string,
     *     source_id: int,
     *     event_type: string,
     *     description: string,
     *     amount: string|float,
     *     event_date: string,
     *     debit_account_code: string,
     *     credit_account_code?: string,
     *     payment_type?: string,
     *     cost_centre_id?: int|null,
     *     funding_stream_id?: int|null,
     *     site_id?: int|null,
     *     client_id?: int|null,
     *     staff_id?: int|null,
     *     asset_id?: int|null,
     *     shift_id?: int|null,
     *     journal_type?: string,
     *     source_updated_at?: string,
     *     created_by?: int|null,
     * }  $data
     * @return FinFinancialEvent  The posted financial event (with journal_id populated).
     *
     * @throws RuntimeException If amount is zero/negative, accounts not found, or posting fails.
     */
    public function record(array $data): FinFinancialEvent
    {
        $amount = (string) $data['amount'];

        // Guard: no zero or negative amounts
        if (bccomp($amount, '0', 2) <= 0) {
            throw new RuntimeException(
                "Financial event amount must be positive, got: {$amount}"
            );
        }

        $orgId = $data['organization_id'];
        $sourceType = $data['source_type'];
        $sourceId = $data['source_id'];
        $eventType = $data['event_type'];
        $paymentType = $data['payment_type'] ?? FinFinancialEvent::PAYMENT_AP;

        // Idempotency: include amount + updated_at so corrections create new events
        $idempotencyKey = FinFinancialEvent::buildIdempotencyKey(
            $sourceType,
            $sourceId,
            $eventType,
            $amount,
            $data['source_updated_at'] ?? null,
        );

        $existing = FinFinancialEvent::where('idempotency_key', $idempotencyKey)
            ->whereIn('status', ['posted', 'pending'])
            ->first();

        if ($existing) {
            Log::info("FinancialEventService: Duplicate event skipped [{$eventType}] for {$sourceType}#{$sourceId}");

            return $existing;
        }

        // Resolve the credit account code based on payment type
        $creditAccountCode = $this->resolveCreditAccountCode($data, $paymentType);
        $debitAccountCode = $data['debit_account_code'];

        // Resolve GL accounts
        $debitAccount = $this->resolveAccount($orgId, $debitAccountCode);
        $creditAccount = $this->resolveAccount($orgId, $creditAccountCode);

        return DB::transaction(function () use ($data, $orgId, $sourceType, $sourceId, $eventType, $amount, $paymentType, $debitAccount, $creditAccount, $idempotencyKey) {
            // 1. Create the financial event record
            $event = FinFinancialEvent::create([
                'organization_id' => $orgId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'event_type' => $eventType,
                'description' => $data['description'],
                'amount' => $amount,
                'currency' => $data['currency'] ?? 'NZD',
                'payment_type' => $paymentType,
                'debit_account_id' => $debitAccount->id,
                'credit_account_id' => $creditAccount->id,
                'cost_centre_id' => $data['cost_centre_id'] ?? null,
                'funding_stream_id' => $data['funding_stream_id'] ?? null,
                'site_id' => $data['site_id'] ?? null,
                'client_id' => $data['client_id'] ?? null,
                'staff_id' => $data['staff_id'] ?? null,
                'asset_id' => $data['asset_id'] ?? null,
                'shift_id' => $data['shift_id'] ?? null,
                'event_date' => $data['event_date'],
                'status' => 'pending',
                'idempotency_key' => $idempotencyKey,
                'created_by' => $data['created_by'] ?? Auth::id(),
            ]);

            // 2. Build and post journal
            try {
                $journal = $this->postJournal($event, $debitAccount, $creditAccount, $data);

                // 3. Update event with journal reference
                $event->update([
                    'status' => 'posted',
                    'journal_id' => $journal->id,
                    'posted_at' => now(),
                ]);

                // 4. Create cost allocation records (on the debit line — the expense)
                $debitLine = $journal->lines->first(fn ($line) => bccomp((string) $line->debit, '0', 2) > 0);

                if ($debitLine) {
                    FinCostAllocation::create([
                        'journal_id' => $journal->id,
                        'journal_line_id' => $debitLine->id,
                        'financial_event_id' => $event->id,
                        'site_id' => $event->site_id,
                        'client_id' => $event->client_id,
                        'staff_id' => $event->staff_id,
                        'asset_id' => $event->asset_id,
                        'shift_id' => $event->shift_id,
                        'amount' => $amount,
                        'event_type' => $eventType,
                        'event_date' => $event->event_date,
                    ]);
                }

                // 5. Link journal back to the source model (if it has journal_id column)
                $this->linkJournalToSource($sourceType, $sourceId, $journal->id, $eventType);

                return $event;
            } catch (\Throwable $e) {
                $event->markFailed($e->getMessage());

                Log::error("FinancialEventService: Failed to post [{$eventType}] for {$sourceType}#{$sourceId}: {$e->getMessage()}");

                throw new RuntimeException(
                    "Failed to post financial event [{$eventType}]: {$e->getMessage()}",
                    previous: $e,
                );
            }
        });
    }

    /**
     * Reverse a previously posted financial event.
     */
    public function reverse(FinFinancialEvent $event, ?string $reason = null): FinFinancialEvent
    {
        if (! $event->isPosted()) {
            throw new RuntimeException(
                "Financial event #{$event->id} cannot be reversed: status is '{$event->status}'."
            );
        }

        $journal = FinJournal::findOrFail($event->journal_id);

        return DB::transaction(function () use ($event, $journal, $reason) {
            $this->journalPostingService->reverse($journal, $reason ?? "Reversal of financial event #{$event->id}");

            $event->update(['status' => 'reversed']);

            // Remove cost allocations for this event
            FinCostAllocation::where('financial_event_id', $event->id)->delete();

            // Clear journal_id from source model
            $this->linkJournalToSource($event->source_type, $event->source_id, null, $event->event_type);

            return $event->refresh();
        });
    }

    /**
     * Check if a source model already has a posted financial event of the given type + amount.
     */
    public function hasPostedEvent(string $sourceType, int $sourceId, string $eventType, string $amount, ?string $sourceUpdatedAt = null): bool
    {
        $key = FinFinancialEvent::buildIdempotencyKey(
            $sourceType,
            $sourceId,
            $eventType,
            $amount,
            $sourceUpdatedAt,
        );

        return FinFinancialEvent::where('idempotency_key', $key)
            ->whereIn('status', ['posted', 'pending'])
            ->exists();
    }

    /* ------------------------------------------------------------------ */
    /*  Private                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Resolve the credit account code. If `credit_account_code` is explicitly passed,
     * use it directly. Otherwise, resolve from payment_type via config.
     */
    private function resolveCreditAccountCode(array $data, string $paymentType): string
    {
        if (! empty($data['credit_account_code'])) {
            return $data['credit_account_code'];
        }

        return match ($paymentType) {
            FinFinancialEvent::PAYMENT_AP => config('finance.payment_type_accounts.ap', '2000'),
            FinFinancialEvent::PAYMENT_CASH => config('finance.payment_type_accounts.cash', '1000'),
            FinFinancialEvent::PAYMENT_REIMBURSEMENT => config('finance.payment_type_accounts.reimburse', '2310'),
            default => config('finance.default_ap_code', '2000'),
        };
    }

    private function postJournal(
        FinFinancialEvent $event,
        FinAccount $debitAccount,
        FinAccount $creditAccount,
        array $data,
    ): FinJournal {
        $journalType = $data['journal_type'] ?? 'standard';
        $amount = (string) $event->amount;

        $lines = [
            [
                'account_id' => $debitAccount->id,
                'description' => $event->description,
                'debit' => $amount,
                'credit' => 0,
                'cost_centre_id' => $event->cost_centre_id,
                'funding_stream_id' => $event->funding_stream_id,
            ],
            [
                'account_id' => $creditAccount->id,
                'description' => $event->description,
                'debit' => 0,
                'credit' => $amount,
                'cost_centre_id' => $event->cost_centre_id,
                'funding_stream_id' => $event->funding_stream_id,
            ],
        ];

        return $this->journalPostingService->createAndPost($event->organization_id, [
            'journal_date' => $event->event_date->toDateString(),
            'type' => $journalType,
            'source_type' => $event->source_type,
            'source_id' => $event->source_id,
            'description' => $event->description,
            'lines' => $lines,
        ]);
    }

    /**
     * Link the journal back to the source model if it supports it.
     */
    private function linkJournalToSource(string $sourceType, int $sourceId, ?int $journalId, string $eventType): void
    {
        if (! class_exists($sourceType)) {
            return;
        }

        $source = $sourceType::find($sourceId);
        if (! $source) {
            return;
        }

        // Mileage events link to a different column on timesheets
        if ($eventType === 'mileage_reimbursement' && in_array('mileage_journal_id', $source->getFillable())) {
            $source->updateQuietly(['mileage_journal_id' => $journalId]);

            return;
        }

        if (in_array('journal_id', $source->getFillable())) {
            $source->updateQuietly(['journal_id' => $journalId]);
        }
    }

    private function resolveAccount(int $orgId, string $code): FinAccount
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
                "GL account '{$code}' not found (or inactive) for organisation #{$orgId}. "
                . 'Ensure the chart of accounts includes the required operational expense accounts.'
            );
        }

        $this->accountCache[$cacheKey] = $account;

        return $account;
    }
}
