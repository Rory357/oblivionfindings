<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinBankTransaction;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinExternalSettlement;
use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinPaymentAllocation;
use App\Domain\Finance\Models\FinPaymentMatch;
use App\Domain\Finance\Models\FinPaymentRunItem;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The append-only provenance boundary shared by every canonical payment path.
 * Balance and journal mutations remain owned by AR, matching, and payment-run
 * services; none may commit unless this evidence and its audit row also commit.
 */
final class PaymentSettlementRecorder
{
    public function record(
        FinBill|FinInvoice $target,
        FinJournal $journal,
        Model $source,
        int $siteId,
        string $amount,
        string $paymentDate,
        ?User $actor,
        ?FinBankTransaction $bankTransaction = null,
        ?string $notes = null,
    ): FinPaymentAllocation {
        if (bccomp($amount, '0.00', 2) <= 0) {
            throw new InvalidArgumentException('Settlement amount must be greater than zero.');
        }

        $organizationId = $target->organization_id;
        if ($organizationId === null
            || (int) $journal->organization_id !== (int) $organizationId
            || $journal->status !== 'posted') {
            throw new InvalidArgumentException('Settlement journal does not belong to the canonical target.');
        }

        if ($source instanceof FinJournal && ! $source->is($journal)) {
            throw new InvalidArgumentException('Settlement source journal does not match the posted journal.');
        }

        $targetSiteId = $target instanceof FinBill
            ? $target->site_id
            : $target->loadMissing('client:id,site_id')->client?->site_id;
        if ($targetSiteId === null
            || (int) $targetSiteId !== $siteId
            || ! Site::query()
                ->active()
                ->notArchived()
                ->whereNull('archived_at')
                ->whereKey($siteId)
                ->exists()) {
            throw new InvalidArgumentException('Settlement Site does not match the canonical target.');
        }

        if ($actor !== null && (int) $actor->organization_id !== (int) $organizationId) {
            throw new InvalidArgumentException('Settlement actor does not belong to the canonical target.');
        }

        $this->assertSourceOwnership(
            $source,
            $journal,
            (int) $organizationId,
            $target,
            $bankTransaction,
        );

        if ($bankTransaction !== null
            && (int) $bankTransaction->organization_id !== (int) $organizationId) {
            throw new InvalidArgumentException('Settlement bank transaction does not belong to the canonical target.');
        }

        $sourceType = $source->getMorphClass();
        $sourceId = (int) $source->getKey();
        $allocation = FinPaymentAllocation::create([
            'organization_id' => $organizationId,
            'site_id' => $siteId,
            'type' => $target instanceof FinBill ? 'payable' : 'receivable',
            'payment_date' => $paymentDate,
            'amount' => $amount,
            'allocatable_type' => $target->getMorphClass(),
            'allocatable_id' => $target->getKey(),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'settlement_source_key' => $sourceType.':'.$sourceId,
            'integrity_state' => FinPaymentAllocation::INTEGRITY_TRACEABLE,
            'journal_id' => $journal->id,
            'settlement_journal_id' => $journal->id,
            'bank_transaction_id' => $bankTransaction?->id,
            'notes' => $notes,
            'created_by' => $actor?->id,
        ]);

        AuditLogger::logOrFail('finance.payment_settlement.recorded', $allocation, [
            'actor_id' => $actor?->id,
            'site_id' => $siteId,
            'settlement_source_type' => $sourceType,
            'settlement_source_id' => $sourceId,
            'journal_id' => $journal->id,
            'bank_transaction_id' => $bankTransaction?->id,
        ]);

        return $allocation;
    }

    private function assertSourceOwnership(
        Model $source,
        FinJournal $journal,
        int $organizationId,
        FinBill|FinInvoice $target,
        ?FinBankTransaction $bankTransaction,
    ): void {
        $valid = match (true) {
            $source instanceof FinJournal => (int) $source->organization_id === $organizationId
                && $journal->source_type === $target->getMorphClass()
                && (int) $journal->source_id === (int) $target->getKey(),
            $source instanceof FinPaymentMatch => (int) $source->organization_id === $organizationId
                && $bankTransaction !== null
                && (int) $source->bank_transaction_id === (int) $bankTransaction->id
                && $source->matchable_type === $target->getMorphClass()
                && (int) $source->matchable_id === (int) $target->getKey()
                && $journal->source_type === $source->getMorphClass()
                && (int) $journal->source_id === (int) $source->getKey(),
            $source instanceof FinPaymentRunItem => (int) $source->paymentRun?->organization_id === $organizationId
                && (int) $source->settlement_bill_id === (int) $target->getKey()
                && $target instanceof FinBill
                && $journal->source_type === FinExternalSettlement::class
                && FinExternalSettlement::query()
                    ->whereKey($journal->source_id)
                    ->where('source_type', $source->paymentRun->getMorphClass())
                    ->where('source_id', $source->paymentRun->getKey())
                    ->where('purpose', ExternalSettlementService::PAYMENT_RUN)
                    ->exists(),
            default => false,
        };

        if (! $valid) {
            throw new InvalidArgumentException('Settlement source does not belong to the canonical target.');
        }
    }
}
