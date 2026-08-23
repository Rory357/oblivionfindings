<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBankTransaction;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinExternalSettlement;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinJournalLine;
use App\Domain\Finance\Models\FinPaymentRun;
use App\Domain\Finance\Models\FinPaymentRunItem;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrPayslip;
use App\Domain\Hr\Services\HrPayrollAccessService;
use App\Domain\Hr\Services\PayrollExportService;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

/**
 * Canonical owner of externally executed AP and payroll settlement lifecycles.
 *
 * A prepared/exported file is evidence of an instruction only. No source is
 * paid and no bank GL is credited until independently supplied bank acceptance
 * evidence is locked and settled here.
 */
final class ExternalSettlementService
{
    public const PAYMENT_RUN = 'vendor_payment_run';

    public const PAYROLL_NET_PAY = 'payroll_net_pay';

    public function __construct(
        private readonly JournalPostingService $journalPostingService,
        private readonly AccountsPayableService $accountsPayableService,
        private readonly PaymentSettlementRecorder $paymentRecorder,
        private readonly PaymentSettlementSiteScope $paymentSiteScope,
        private readonly PayrollJournalService $payrollJournalService,
        private readonly PayrollExportService $payrollExportService,
        private readonly HrPayrollAccessService $payrollAccess,
    ) {}

    public function preparePaymentRun(FinPaymentRun $paymentRun, User $actor): FinPaymentRun
    {
        $writtenPath = null;

        try {
            return DB::transaction(function () use ($paymentRun, $actor, &$writtenPath): FinPaymentRun {
                $run = FinPaymentRun::query()->lockForUpdate()->findOrFail($paymentRun->getKey());
                $this->assertSourceAuthority($run, self::PAYMENT_RUN, $actor);

                $existing = $this->settlementFor($run, self::PAYMENT_RUN, true);
                if ($existing !== null) {
                    if (in_array($existing->status, ['prepared', 'exported', 'accepted', 'settled', 'reconciled'], true)) {
                        $this->verifiedArtifactContents($run, self::PAYMENT_RUN, $existing);

                        return $run->refresh();
                    }

                    throw new InvalidArgumentException('A rejected payment run must be corrected in a new run.');
                }

                if ($run->status !== 'approved') {
                    throw new InvalidArgumentException(
                        "Payment run {$run->run_number} cannot be prepared: status is '{$run->status}', expected 'approved'."
                    );
                }
                if ($run->created_by === null
                    || $run->approved_by === null
                    || (int) $run->created_by === (int) $run->approved_by) {
                    throw new InvalidArgumentException('A payment run must be approved by someone other than its creator.');
                }

                // Canonical non-journal order: source aggregate -> bank account
                // -> run items -> bills -> newly-created settlement occurrence.
                $bankAccount = FinBankAccount::query()
                    ->where('organization_id', $run->organization_id)
                    ->whereKey($run->bank_account_id)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->firstOrFail();
                $bankAccount->loadMissing('glAccount');
                $items = $run->items()
                    ->with(['vendor', 'paymentRun'])
                    ->orderBy('bill_id')
                    ->lockForUpdate()
                    ->get();
                $bills = FinBill::query()
                    ->where('organization_id', $run->organization_id)
                    ->whereIn('id', $items->pluck('bill_id'))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                if ($items->isEmpty()
                    || $bills->count() !== $items->count()
                    || $items->count() !== (int) $run->item_count
                    || bccomp(
                        $items->reduce(
                            fn (string $total, FinPaymentRunItem $item): string => bcadd($total, (string) $item->amount, 2),
                            '0.00',
                        ),
                        (string) $run->total_amount,
                        2,
                    ) !== 0
                    || ! $bankAccount->glAccount?->is_active
                    || (int) $bankAccount->glAccount?->organization_id !== (int) $run->organization_id) {
                    abort(404);
                }

                foreach ($items as $item) {
                    $bill = $bills->get($item->bill_id);
                    $item->setRelation('bill', $bill);
                    abort_unless(
                        $bill !== null
                            && (int) $item->settlement_bill_id === (int) $bill->id
                            && (int) $item->active_settlement_bill_id === (int) $bill->id
                            && (int) $item->site_id === (int) $bill->site_id
                            && (int) $item->vendor_id === (int) $bill->vendor_id
                            && $item->vendor !== null
                            && (int) $item->vendor->organization_id === (int) $run->organization_id,
                        404,
                    );
                    $this->paymentSiteScope->assertCanAccessBill($actor, $bill);

                    $amountDue = bcsub((string) $bill->total_amount, (string) $bill->amount_paid, 2);
                    if (! in_array($bill->status, ['approved', 'partially_paid'], true)
                        || bccomp((string) $item->amount, $amountDue, 2) !== 0) {
                        throw new InvalidArgumentException(
                            "Bill {$bill->bill_number} changed after this payment run was created. Create a corrected run."
                        );
                    }
                }

                $run->setRelation('items', $items);
                $csv = $this->paymentRunCsv($run);
                $writtenPath = "finance/payment-runs/{$run->run_number}.csv";
                if (! Storage::disk('local')->put($writtenPath, $csv)) {
                    throw new RuntimeException('The payment run bank file could not be written.');
                }

                $settlement = $this->createPreparedSettlement(
                    source: $run,
                    purpose: self::PAYMENT_RUN,
                    organizationId: (int) $run->organization_id,
                    bankAccountId: (int) $bankAccount->id,
                    amount: (string) $run->total_amount,
                    artifactDisk: 'local',
                    artifactPath: $writtenPath,
                    artifactSha256: hash('sha256', $csv),
                    actor: $actor,
                );
                $this->verifiedArtifactContents($run, self::PAYMENT_RUN, $settlement);

                $run->update([
                    'status' => 'prepared',
                    'processed_at' => now(),
                    'processed_by' => $actor->id,
                    'file_path' => $writtenPath,
                ]);

                AuditLogger::logOrFail('finance.payment_run.prepared', $run, [
                    'actor_id' => $actor->id,
                    'settlement_id' => $settlement->id,
                    'artifact_sha256' => $settlement->artifact_sha256,
                ]);

                return $run->refresh();
            });
        } catch (\Throwable $exception) {
            if ($writtenPath !== null) {
                Storage::disk('local')->delete($writtenPath);
            }

            throw $exception;
        }
    }

    public function preparePayrollNetPay(HrPayrollRun $payrollRun, User $actor): FinExternalSettlement
    {
        $writtenPath = null;

        try {
            return DB::transaction(function () use ($payrollRun, $actor, &$writtenPath): FinExternalSettlement {
                $run = HrPayrollRun::query()->lockForUpdate()->findOrFail($payrollRun->getKey());
                $organizationId = (int) $run->tenant_id;
                $this->assertSourceAuthority($run, self::PAYROLL_NET_PAY, $actor);

                $existing = $this->settlementFor($run, self::PAYROLL_NET_PAY, true);
                $attemptNumber = 1;
                if ($existing !== null) {
                    if (in_array($existing->status, ['prepared', 'exported', 'accepted', 'settled', 'reconciled'], true)) {
                        $this->verifiedArtifactContents($run, self::PAYROLL_NET_PAY, $existing);

                        return $existing;
                    }
                    if ($existing->status !== 'rejected') {
                        throw new InvalidArgumentException("A {$existing->status} payroll bank batch cannot be prepared again.");
                    }
                    $this->verifiedArtifactContents($run, self::PAYROLL_NET_PAY, $existing);
                    $attemptNumber = (int) $existing->attempt_number + 1;
                }

                if ($run->locked_at === null || $run->journal_id === null) {
                    throw new InvalidArgumentException('Lock and post the payroll run before preparing net pay.');
                }
                if ($run->net_paid_at !== null || $run->payment_journal_id !== null) {
                    throw new InvalidArgumentException('Net pay for this run has already been settled.');
                }

                $bankAccount = FinBankAccount::query()
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true)
                    ->whereNotNull('gl_account_id')
                    ->orderByDesc('is_primary')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->firstOrFail();

                $payslips = HrPayslip::query()
                    ->where('payroll_run_id', $run->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $totalNet = $payslips->reduce(
                    fn (string $total, HrPayslip $payslip): string => bcadd($total, (string) $payslip->net_pay, 2),
                    '0.00',
                );
                if ($payslips->isEmpty() || bccomp($totalNet, '0.00', 2) <= 0) {
                    throw new InvalidArgumentException('This payroll run has no positive net pay to prepare.');
                }

                $csv = $this->payrollJournalService->buildNetPayDirectCreditCsv($run);
                [, $writtenPath] = $this->expectedArtifactLocation(
                    $run,
                    self::PAYROLL_NET_PAY,
                    $attemptNumber,
                );
                if (! Storage::disk('private')->put($writtenPath, $csv)) {
                    throw new RuntimeException('The payroll bank file could not be written.');
                }

                $settlement = $this->createPreparedSettlement(
                    source: $run,
                    purpose: self::PAYROLL_NET_PAY,
                    organizationId: $organizationId,
                    bankAccountId: (int) $bankAccount->id,
                    amount: $totalNet,
                    artifactDisk: 'private',
                    artifactPath: $writtenPath,
                    artifactSha256: hash('sha256', $csv),
                    actor: $actor,
                    attemptNumber: $attemptNumber,
                );
                $this->verifiedArtifactContents($run, self::PAYROLL_NET_PAY, $settlement);

                AuditLogger::logOrFail('hr.payroll_net_pay.prepared', $run, [
                    'actor_id' => $actor->id,
                    'settlement_id' => $settlement->id,
                    'artifact_sha256' => $settlement->artifact_sha256,
                ]);

                return $settlement;
            });
        } catch (\Throwable $exception) {
            if ($writtenPath !== null) {
                Storage::disk('private')->delete($writtenPath);
            }

            throw $exception;
        }
    }

    public function markExported(Model $source, string $purpose, User $actor): FinExternalSettlement
    {
        return $this->exportArtifact($source, $purpose, $actor)['settlement'];
    }

    /**
     * Lock, verify, and return the exact bytes that may be downloaded.
     *
     * @return array{settlement: FinExternalSettlement, contents: string}
     */
    public function exportArtifact(Model $source, string $purpose, User $actor): array
    {
        return DB::transaction(function () use ($source, $purpose, $actor): array {
            $lockedSource = $this->lockSource($source);
            $this->assertSourceAuthority($lockedSource, $purpose, $actor);
            $settlement = $this->lockedSettlement($lockedSource, $purpose);
            $this->assertLockedOrganization(
                $lockedSource,
                $settlement,
                $this->sourceOrganizationId($lockedSource),
            );
            $contents = $this->verifiedArtifactContents($lockedSource, $purpose, $settlement);

            if (! in_array($settlement->status, ['prepared', 'exported', 'accepted', 'settled', 'reconciled'], true)) {
                throw new InvalidArgumentException("A {$settlement->status} settlement cannot be exported.");
            }

            if ($settlement->status !== 'prepared') {
                return [
                    'settlement' => $settlement,
                    'contents' => $contents,
                ];
            }

            $replayKey = $this->occurrenceReplayKey($settlement, 'exported', $settlement->artifact_sha256);
            $from = $settlement->status;
            $settlement->update([
                'status' => 'exported',
                'exported_at' => now(),
                'exported_by' => $actor->id,
                'export_replay_key' => $replayKey,
            ]);
            $this->recordEvent($settlement, 'exported', $from, 'exported', $replayKey, [
                'artifact_sha256' => $settlement->artifact_sha256,
            ], $actor);
            $this->syncSourceStatus($lockedSource, 'exported');

            return [
                'settlement' => $settlement->refresh(),
                'contents' => $contents,
            ];
        });
    }

    public function accept(
        Model $source,
        string $purpose,
        User $actor,
        string $idempotencyKey,
        string $reference,
        array $evidence,
    ): FinExternalSettlement {
        return DB::transaction(function () use ($source, $purpose, $actor, $idempotencyKey, $reference, $evidence): FinExternalSettlement {
            $lockedSource = $this->lockSource($source);
            $this->assertSourceAuthority($lockedSource, $purpose, $actor);
            $settlement = $this->lockedSettlement($lockedSource, $purpose);
            $this->assertLockedOrganization(
                $lockedSource,
                $settlement,
                $this->sourceOrganizationId($lockedSource),
            );
            $evidence = $this->requiredEvidence($reference, $evidence);
            $replayKey = $this->transitionReplayKey($lockedSource, $purpose, 'accepted', $idempotencyKey);
            $bound = $this->settlementForReplayKey(
                $lockedSource,
                $purpose,
                'acceptance_replay_key',
                $replayKey,
            );

            if ($bound !== null) {
                $this->assertIndependentChecker($lockedSource, $bound, $actor);
                $this->verifiedArtifactContents($lockedSource, $purpose, $bound);
                if ((int) $bound->accepted_by === (int) $actor->id
                    && $bound->acceptance_reference === $reference
                    && hash_equals(
                        hash('sha256', $this->canonicalJson($bound->acceptance_evidence ?? [])),
                        hash('sha256', $this->canonicalJson($evidence)),
                    )) {
                    if ((int) $bound->id !== (int) $settlement->id) {
                        throw new InvalidArgumentException(
                            'The acceptance idempotency key belongs to a prior settlement attempt; use a new key.'
                        );
                    }

                    return $bound;
                }

                throw new InvalidArgumentException(
                    'The acceptance idempotency key is already bound to different evidence or actor.'
                );
            }
            $this->assertIndependentChecker($lockedSource, $settlement, $actor);
            $this->verifiedArtifactContents($lockedSource, $purpose, $settlement);
            if ($settlement->status !== 'exported') {
                throw new InvalidArgumentException('Bank acceptance requires an exported immutable settlement file.');
            }

            $from = $settlement->status;
            $settlement->update([
                'status' => 'accepted',
                'accepted_at' => now(),
                'accepted_by' => $actor->id,
                'acceptance_reference' => $reference,
                'acceptance_evidence' => $evidence,
                'acceptance_replay_key' => $replayKey,
            ]);
            $this->recordEvent($settlement, 'accepted', $from, 'accepted', $replayKey, [
                'reference' => $reference,
                'evidence' => $evidence,
                'artifact_sha256' => $settlement->artifact_sha256,
            ], $actor);
            $this->syncSourceStatus($lockedSource, 'accepted');

            return $settlement->refresh();
        });
    }

    public function reject(
        Model $source,
        string $purpose,
        User $actor,
        string $idempotencyKey,
        string $reference,
        string $reason,
        array $evidence,
    ): FinExternalSettlement {
        return DB::transaction(function () use ($source, $purpose, $actor, $idempotencyKey, $reference, $reason, $evidence): FinExternalSettlement {
            $lockedSource = $this->lockSource($source);
            $this->assertSourceAuthority($lockedSource, $purpose, $actor);
            $settlement = $this->lockedSettlement($lockedSource, $purpose);
            $this->assertLockedOrganization(
                $lockedSource,
                $settlement,
                $this->sourceOrganizationId($lockedSource),
            );
            $evidence = $this->requiredEvidence($reference, $evidence);
            $replayKey = $this->transitionReplayKey($lockedSource, $purpose, 'rejected', $idempotencyKey);
            $bound = $this->settlementForReplayKey(
                $lockedSource,
                $purpose,
                'rejection_replay_key',
                $replayKey,
            );

            if ($bound !== null) {
                $this->assertIndependentChecker($lockedSource, $bound, $actor);
                $this->verifiedArtifactContents($lockedSource, $purpose, $bound);
                if ((int) $bound->rejected_by === (int) $actor->id
                    && $bound->rejection_reference === $reference
                    && $bound->rejection_reason === trim($reason)
                    && $this->sameEvidence($bound->rejection_evidence ?? [], $evidence)) {
                    if ((int) $bound->id !== (int) $settlement->id) {
                        throw new InvalidArgumentException(
                            'The rejection idempotency key belongs to a prior settlement attempt; use a new key.'
                        );
                    }

                    return $bound;
                }

                throw new InvalidArgumentException(
                    'The rejection idempotency key is already bound to another decision or actor.'
                );
            }
            $this->assertIndependentChecker($lockedSource, $settlement, $actor);
            $this->verifiedArtifactContents($lockedSource, $purpose, $settlement);
            if (! in_array($settlement->status, ['exported', 'accepted'], true)) {
                throw new InvalidArgumentException("A {$settlement->status} settlement cannot be rejected.");
            }

            $from = $settlement->status;
            $settlement->update([
                'status' => 'rejected',
                'active_source_key' => null,
                'rejected_at' => now(),
                'rejected_by' => $actor->id,
                'rejection_reference' => $reference,
                'rejection_reason' => trim($reason),
                'rejection_evidence' => $evidence,
                'rejection_replay_key' => $replayKey,
            ]);
            $this->recordEvent($settlement, 'rejected', $from, 'rejected', $replayKey, [
                'reference' => $reference,
                'reason' => trim($reason),
                'evidence' => $evidence,
            ], $actor);

            if ($lockedSource instanceof FinPaymentRun) {
                $lockedSource->items()->lockForUpdate()->update([
                    'active_settlement_bill_id' => null,
                    'status' => 'failed',
                ]);
                $lockedSource->update(['status' => 'rejected']);
            }

            return $settlement->refresh();
        });
    }

    public function settlePaymentRun(FinPaymentRun $paymentRun, User $actor, string $idempotencyKey): FinPaymentRun
    {
        $canonicalRun = FinPaymentRun::query()->findOrFail($paymentRun->getKey());
        $this->assertSourceAuthority($canonicalRun, self::PAYMENT_RUN, $actor);
        $organizationId = $this->sourceOrganizationId($canonicalRun);

        return DB::transaction(function () use ($paymentRun, $actor, $idempotencyKey, $organizationId): FinPaymentRun {
            // Shared migration 000080 guarantees this per-organisation mutex.
            // It must be first for every journal-producing transaction.
            $this->journalPostingService->lockJournalSequence($organizationId);

            $run = FinPaymentRun::query()->lockForUpdate()->findOrFail($paymentRun->getKey());
            $this->assertSourceAuthority($run, self::PAYMENT_RUN, $actor);
            $settlement = $this->lockedSettlement($run, self::PAYMENT_RUN);
            $this->assertLockedOrganization($run, $settlement, $organizationId);
            $this->assertIndependentChecker($run, $settlement, $actor);
            $this->verifiedArtifactContents($run, self::PAYMENT_RUN, $settlement);
            $replayKey = $this->transitionReplayKey($run, self::PAYMENT_RUN, 'settled', $idempotencyKey);
            $bound = $this->settlementForReplayKey(
                $run,
                self::PAYMENT_RUN,
                'settlement_replay_key',
                $replayKey,
            );

            if ($bound !== null) {
                if ((int) $bound->id === (int) $settlement->id
                    && (int) $bound->settled_by === (int) $actor->id
                    && $bound->journal_id !== null) {
                    $journal = $this->verifiedSettlementJournal($bound);
                    if ((int) $run->journal_id === (int) $journal->id
                        && in_array($run->status, ['settled', 'reconciled'], true)) {
                        return $run->refresh();
                    }
                }

                throw new InvalidArgumentException('The settlement idempotency key is already bound to another result.');
            }
            if ($settlement->status !== 'accepted' || $settlement->acceptance_reference === null) {
                throw new InvalidArgumentException('A payment run can settle only after recorded bank acceptance.');
            }

            // Canonical journal order: 000080 sequence -> source -> settlement
            // -> bank account -> items -> bills.
            $bankAccount = FinBankAccount::query()
                ->where('organization_id', $organizationId)
                ->whereKey($settlement->bank_account_id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();
            $bankAccount->loadMissing('glAccount');
            $items = $run->items()->with(['vendor', 'paymentRun'])->orderBy('bill_id')->lockForUpdate()->get();
            $bills = FinBill::query()
                ->where('organization_id', $organizationId)
                ->whereIn('id', $items->pluck('bill_id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($items->isEmpty()
                || $bills->count() !== $items->count()
                || $items->count() !== (int) $run->item_count
                || bccomp((string) $run->total_amount, (string) $settlement->amount, 2) !== 0
                || bccomp(
                    $items->reduce(
                        fn (string $total, FinPaymentRunItem $item): string => bcadd($total, (string) $item->amount, 2),
                        '0.00',
                    ),
                    (string) $settlement->amount,
                    2,
                ) !== 0
                || ! $bankAccount->glAccount?->is_active
                || (int) $bankAccount->glAccount?->organization_id !== $organizationId) {
                throw new InvalidArgumentException('The payment-run source set is incomplete.');
            }

            $journalLines = [];
            $apAccountId = $this->accountsPayableAccountId($organizationId);
            foreach ($items as $item) {
                $bill = $bills->get($item->bill_id);
                $item->setRelation('bill', $bill);
                if ($bill === null
                    || (int) $item->active_settlement_bill_id !== (int) $bill->id
                    || (int) $item->settlement_bill_id !== (int) $bill->id) {
                    throw new InvalidArgumentException('The active payment-run membership changed before settlement.');
                }
                $this->paymentSiteScope->assertCanAccessBill($actor, $bill);
                $amountDue = bcsub((string) $bill->total_amount, (string) $bill->amount_paid, 2);
                if (! in_array($bill->status, ['approved', 'partially_paid'], true)
                    || bccomp((string) $item->amount, $amountDue, 2) !== 0) {
                    throw new InvalidArgumentException(
                        "Bill {$bill->bill_number} changed after bank export. Reject and correct the run without posting."
                    );
                }

                $journalLines[] = [
                    'account_id' => $apAccountId,
                    'description' => "Payment to {$item->vendor->name} — {$item->reference}",
                    'debit' => $item->amount,
                    'credit' => 0,
                    'site_id' => $item->site_id,
                ];
            }
            $journalLines[] = [
                'account_id' => $bankAccount->gl_account_id,
                'description' => "Payment run {$run->run_number} bank settlement",
                'debit' => 0,
                'credit' => $settlement->amount,
                'site_id' => null,
            ];

            $journal = $this->journalPostingService->createAndPost($organizationId, [
                'journal_date' => $run->payment_date->toDateString(),
                'type' => 'standard',
                'reference' => $run->run_number,
                'description' => "Payment run {$run->run_number} — {$run->item_count} payments",
                'source_type' => FinExternalSettlement::class,
                'source_id' => $settlement->id,
                'actor_id' => $actor->id,
                'lines' => $journalLines,
            ]);

            foreach ($items as $item) {
                $item->setRelation('bill', $this->accountsPayableService->recordPayment(
                    $item->bill,
                    (string) $item->amount,
                ));
                $this->paymentRecorder->record(
                    target: $item->bill,
                    journal: $journal,
                    source: $item,
                    siteId: (int) $item->site_id,
                    amount: (string) $item->amount,
                    paymentDate: $run->payment_date->toDateString(),
                    actor: $actor,
                    notes: "Payment run {$run->run_number}; bank acceptance {$settlement->acceptance_reference}",
                );
                $item->update([
                    'status' => 'paid',
                    'active_settlement_bill_id' => null,
                ]);
            }

            $from = $settlement->status;
            $settlement->update([
                'status' => 'settled',
                'settled_at' => now(),
                'settled_by' => $actor->id,
                'journal_id' => $journal->id,
                'settlement_replay_key' => $replayKey,
            ]);
            $this->recordEvent($settlement, 'settled', $from, 'settled', $replayKey, [
                'journal_id' => $journal->id,
                'acceptance_reference' => $settlement->acceptance_reference,
            ], $actor);

            $run->update([
                'status' => 'settled',
                'journal_id' => $journal->id,
            ]);
            AuditLogger::logOrFail('finance.payment_run.settled', $run, [
                'actor_id' => $actor->id,
                'settlement_id' => $settlement->id,
                'journal_id' => $journal->id,
            ]);

            return $run->refresh();
        });
    }

    public function settlePayrollNetPay(HrPayrollRun $payrollRun, User $actor, string $idempotencyKey): FinJournal
    {
        $canonicalRun = HrPayrollRun::query()->findOrFail($payrollRun->getKey());
        $this->assertSourceAuthority($canonicalRun, self::PAYROLL_NET_PAY, $actor);
        $organizationId = $this->sourceOrganizationId($canonicalRun);

        return DB::transaction(function () use ($payrollRun, $actor, $idempotencyKey, $organizationId): FinJournal {
            $this->journalPostingService->lockJournalSequence($organizationId);

            $run = HrPayrollRun::query()->lockForUpdate()->findOrFail($payrollRun->getKey());
            $this->assertSourceAuthority($run, self::PAYROLL_NET_PAY, $actor);
            $settlement = $this->lockedSettlement($run, self::PAYROLL_NET_PAY);
            $this->assertLockedOrganization($run, $settlement, $organizationId);
            $this->assertIndependentChecker($run, $settlement, $actor);
            $this->verifiedArtifactContents($run, self::PAYROLL_NET_PAY, $settlement);
            $replayKey = $this->transitionReplayKey($run, self::PAYROLL_NET_PAY, 'settled', $idempotencyKey);
            $bound = $this->settlementForReplayKey(
                $run,
                self::PAYROLL_NET_PAY,
                'settlement_replay_key',
                $replayKey,
            );

            if ($bound !== null) {
                if ((int) $bound->id === (int) $settlement->id
                    && (int) $bound->settled_by === (int) $actor->id
                    && $bound->journal_id !== null) {
                    $journal = $this->verifiedSettlementJournal($bound);
                    if ((int) $run->payment_journal_id === (int) $journal->id
                        && $run->net_paid_at !== null) {
                        return $journal;
                    }
                }

                throw new InvalidArgumentException('The payroll settlement idempotency key is already bound to another result.');
            }
            if ($settlement->status !== 'accepted' || $settlement->acceptance_reference === null) {
                throw new InvalidArgumentException('Payroll net pay can settle only after recorded bank acceptance.');
            }

            $journal = $this->payrollJournalService->postAcceptedNetPay($run, $settlement, $actor);
            $this->payrollExportService->markRunTimesheetsPaid($run);

            $from = $settlement->status;
            $settlement->update([
                'status' => 'settled',
                'settled_at' => now(),
                'settled_by' => $actor->id,
                'journal_id' => $journal->id,
                'settlement_replay_key' => $replayKey,
            ]);
            $this->recordEvent($settlement, 'settled', $from, 'settled', $replayKey, [
                'journal_id' => $journal->id,
                'acceptance_reference' => $settlement->acceptance_reference,
            ], $actor);
            AuditLogger::logOrFail('hr.payroll_net_pay.settled', $run, [
                'actor_id' => $actor->id,
                'settlement_id' => $settlement->id,
                'journal_id' => $journal->id,
            ]);

            return $journal;
        });
    }

    public function reconcile(
        Model $source,
        string $purpose,
        int $bankTransactionId,
        User $actor,
        string $idempotencyKey,
        string $reference,
        array $evidence,
    ): FinExternalSettlement {
        return DB::transaction(function () use ($source, $purpose, $bankTransactionId, $actor, $idempotencyKey, $reference, $evidence): FinExternalSettlement {
            $lockedSource = $this->lockSource($source);
            $this->assertSourceAuthority($lockedSource, $purpose, $actor);
            $settlement = $this->lockedSettlement($lockedSource, $purpose);
            $this->assertLockedOrganization(
                $lockedSource,
                $settlement,
                $this->sourceOrganizationId($lockedSource),
            );
            $this->verifiedArtifactContents($lockedSource, $purpose, $settlement);
            $evidence = $this->requiredEvidence($reference, $evidence);
            $replayKey = $this->transitionReplayKey($lockedSource, $purpose, 'reconciled', $idempotencyKey);
            $bound = $this->settlementForReplayKey(
                $lockedSource,
                $purpose,
                'reconciliation_replay_key',
                $replayKey,
            );

            if ($bound !== null) {
                if ((int) $bound->id === (int) $settlement->id
                    && (int) $bound->reconciled_by === (int) $actor->id
                    && (int) $bound->reconciled_bank_transaction_id === $bankTransactionId
                    && $bound->reconciliation_reference === $reference
                    && $this->sameEvidence($bound->reconciliation_evidence ?? [], $evidence)) {
                    $this->lockedClearedBankTransaction($bound, $bankTransactionId);

                    return $bound;
                }

                throw new InvalidArgumentException(
                    'The reconciliation idempotency key is already bound to another bank match, evidence, or actor.'
                );
            }
            if ($settlement->status !== 'settled') {
                throw new InvalidArgumentException('Only a settled instruction can be reconciled.');
            }
            $transaction = $this->lockedClearedBankTransaction($settlement, $bankTransactionId);

            $from = $settlement->status;
            $settlement->update([
                'status' => 'reconciled',
                'reconciled_at' => now(),
                'reconciled_by' => $actor->id,
                'reconciled_bank_transaction_id' => $transaction->id,
                'reconciliation_reference' => $reference,
                'reconciliation_evidence' => $evidence,
                'reconciliation_replay_key' => $replayKey,
            ]);
            $this->recordEvent($settlement, 'reconciled', $from, 'reconciled', $replayKey, [
                'bank_transaction_id' => $transaction->id,
                'reference' => $reference,
                'evidence' => $evidence,
            ], $actor);
            $this->syncSourceStatus($lockedSource, 'reconciled');

            return $settlement->refresh();
        });
    }

    public function requiredSettlement(Model $source, string $purpose, User $actor): FinExternalSettlement
    {
        $canonicalSource = $source->newQuery()->findOrFail($source->getKey());
        $this->assertSourceAuthority($canonicalSource, $purpose, $actor);
        $settlement = $this->settlementFor($canonicalSource, $purpose, false)
            ?? throw new InvalidArgumentException('No prepared external settlement exists for this source.');
        $this->assertLockedOrganization(
            $canonicalSource,
            $settlement,
            $this->sourceOrganizationId($canonicalSource),
        );

        return $settlement;
    }

    private function createPreparedSettlement(
        Model $source,
        string $purpose,
        int $organizationId,
        int $bankAccountId,
        string $amount,
        string $artifactDisk,
        string $artifactPath,
        string $artifactSha256,
        User $actor,
        int $attemptNumber = 1,
    ): FinExternalSettlement {
        $replayKey = hash('sha256', implode('|', [
            $organizationId,
            $purpose,
            $source->getMorphClass(),
            $source->getKey(),
            $attemptNumber,
        ]));
        $settlement = FinExternalSettlement::query()->create([
            'organization_id' => $organizationId,
            'source_type' => $source->getMorphClass(),
            'source_id' => $source->getKey(),
            'purpose' => $purpose,
            'attempt_number' => $attemptNumber,
            'active_source_key' => $this->activeSourceKey($source, $purpose),
            'status' => 'prepared',
            'replay_key' => $replayKey,
            'bank_account_id' => $bankAccountId,
            'amount' => $amount,
            'artifact_disk' => $artifactDisk,
            'artifact_path' => $artifactPath,
            'artifact_sha256' => $artifactSha256,
            'prepared_at' => now(),
            'prepared_by' => $actor->id,
        ]);
        $this->recordEvent($settlement, 'prepared', null, 'prepared', $replayKey, [
            'artifact_sha256' => $artifactSha256,
            'amount' => $amount,
        ], $actor);

        return $settlement;
    }

    private function lockedSettlement(Model $source, string $purpose): FinExternalSettlement
    {
        return FinExternalSettlement::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->where('purpose', $purpose)
            ->orderByDesc('attempt_number')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function settlementFor(Model $source, string $purpose, bool $lock): ?FinExternalSettlement
    {
        $query = FinExternalSettlement::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->where('purpose', $purpose)
            ->orderByDesc('attempt_number')
            ->orderByDesc('id');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    private function lockSource(Model $source): Model
    {
        return $source->newQuery()->lockForUpdate()->findOrFail($source->getKey());
    }

    private function syncSourceStatus(Model $source, string $status): void
    {
        if ($source instanceof FinPaymentRun) {
            FinPaymentRun::query()->whereKey($source->getKey())->update(['status' => $status]);
        }
    }

    private function assertSourceAuthority(Model $source, string $purpose, User $actor): void
    {
        if ($source instanceof FinPaymentRun && $purpose === self::PAYMENT_RUN) {
            abort_unless($actor->canDo('finance.ap.manage'), 403);
            $this->assertActorOrganization($actor, $this->sourceOrganizationId($source));
            $this->paymentSiteScope->assertCanAccessPaymentRun($actor, $source);

            return;
        }

        if ($source instanceof HrPayrollRun && $purpose === self::PAYROLL_NET_PAY) {
            abort_unless($actor->canDo('hr.payroll.export'), 403);
            $this->assertActorOrganization($actor, $this->sourceOrganizationId($source));
            $this->payrollAccess->payrollRun($actor, $source);

            return;
        }

        abort(404);
    }

    private function sourceOrganizationId(Model $source): int
    {
        return match (true) {
            $source instanceof FinPaymentRun => (int) $source->organization_id,
            $source instanceof HrPayrollRun => (int) $source->tenant_id,
            default => abort(404),
        };
    }

    private function assertClearedJournalOwnership(
        FinBankTransaction $transaction,
        FinExternalSettlement $settlement,
        FinBankAccount $bankAccount,
    ): void {
        $matchedLine = FinJournalLine::query()
            ->whereKey($transaction->matched_journal_line_id)
            ->lockForUpdate()
            ->first();
        $journal = $matchedLine === null
            ? null
            : FinJournal::query()->whereKey($matchedLine->journal_id)->lockForUpdate()->first();

        if ($settlement->journal_id === null
            || $matchedLine === null
            || $journal === null
            || (int) $matchedLine->journal_id !== (int) $settlement->journal_id
            || (int) $matchedLine->account_id !== (int) $bankAccount->gl_account_id
            || bccomp((string) $matchedLine->debit, '0.00', 2) !== 0
            || bccomp((string) $matchedLine->credit, (string) $settlement->amount, 2) !== 0
            || (int) $journal->organization_id !== (int) $settlement->organization_id
            || $journal->status !== 'posted'
            || $journal->reversed_by_journal_id !== null
            || $journal->source_type !== $settlement->getMorphClass()
            || (int) $journal->source_id !== (int) $settlement->id) {
            throw new InvalidArgumentException(
                'The cleared bank transaction is not linked to this settlement journal.'
            );
        }
    }

    private function lockedClearedBankTransaction(
        FinExternalSettlement $settlement,
        int $bankTransactionId,
    ): FinBankTransaction {
        $bankAccount = FinBankAccount::query()
            ->where('organization_id', $settlement->organization_id)
            ->whereKey($settlement->bank_account_id)
            ->lockForUpdate()
            ->firstOrFail();
        $bankAccount->loadMissing('glAccount');
        $transaction = FinBankTransaction::query()
            ->where('organization_id', $settlement->organization_id)
            ->whereKey($bankTransactionId)
            ->lockForUpdate()
            ->firstOrFail();

        if ($bankAccount->glAccount === null
            || (int) $bankAccount->glAccount->organization_id !== (int) $settlement->organization_id
            || (int) $transaction->bank_account_id !== (int) $bankAccount->id
            || $transaction->status !== 'reconciled'
            || bccomp(
                $this->decimal((string) $transaction->amount),
                bcsub('0.00', (string) $settlement->amount, 2),
                2,
            ) !== 0) {
            throw new InvalidArgumentException('The cleared bank transaction does not match this settlement.');
        }
        $this->assertClearedJournalOwnership($transaction, $settlement, $bankAccount);

        return $transaction;
    }

    private function verifiedSettlementJournal(FinExternalSettlement $settlement): FinJournal
    {
        $journal = FinJournal::query()
            ->where('organization_id', $settlement->organization_id)
            ->where('source_type', $settlement->getMorphClass())
            ->where('source_id', $settlement->id)
            ->where('status', 'posted')
            ->whereNull('reversed_by_journal_id')
            ->whereKey($settlement->journal_id)
            ->first();

        if ($journal === null) {
            throw new InvalidArgumentException(
                'The settlement idempotency result is missing its canonical posted journal.'
            );
        }

        return $journal;
    }

    private function assertActorOrganization(User $actor, int $organizationId): void
    {
        abort_unless((int) $actor->organization_id === $organizationId, 404);
    }

    private function assertLockedOrganization(
        Model $source,
        FinExternalSettlement $settlement,
        int $organizationId,
    ): void {
        $sourceOrganizationId = match (true) {
            $source instanceof FinPaymentRun => (int) $source->organization_id,
            $source instanceof HrPayrollRun => (int) $source->tenant_id,
            default => null,
        };

        abort_unless(
            $sourceOrganizationId === $organizationId
                && (int) $settlement->organization_id === $organizationId,
            404,
        );
    }

    private function assertIndependentChecker(
        Model $source,
        FinExternalSettlement $settlement,
        User $actor,
    ): void {
        $creatorId = $source->getAttribute('created_by');
        if ($creatorId === null) {
            throw new InvalidArgumentException('The settlement source is missing immutable maker evidence.');
        }
        if ((int) $creatorId === (int) $actor->id) {
            throw new InvalidArgumentException('The settlement checker must be different from the source creator.');
        }
        if ((int) $settlement->prepared_by === (int) $actor->id) {
            throw new InvalidArgumentException('The settlement checker must be different from the bank-file preparer.');
        }

        if ($source instanceof FinPaymentRun
            && $source->created_by !== null
            && (int) $source->created_by === (int) $source->approved_by) {
            throw new InvalidArgumentException('A payment run must be approved by someone other than its creator.');
        }
    }

    private function requiredEvidence(string $reference, array $evidence): array
    {
        if (trim($reference) === '' || $evidence === []) {
            throw new InvalidArgumentException('A bank reference and structured evidence are required.');
        }

        return $this->sortRecursively($evidence);
    }

    private function transitionReplayKey(
        Model $source,
        string $purpose,
        string $transition,
        string $idempotencyKey,
    ): string {
        if (trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('An idempotency key is required.');
        }

        return hash('sha256', implode('|', [
            $source->getMorphClass(),
            $source->getKey(),
            $purpose,
            $transition,
            trim($idempotencyKey),
        ]));
    }

    private function occurrenceReplayKey(
        FinExternalSettlement $settlement,
        string $transition,
        string $identity,
    ): string {
        return hash('sha256', implode('|', [
            $settlement->replay_key,
            $transition,
            $identity,
        ]));
    }

    private function settlementForReplayKey(
        Model $source,
        string $purpose,
        string $column,
        string $replayKey,
    ): ?FinExternalSettlement {
        if (! in_array($column, [
            'acceptance_replay_key',
            'rejection_replay_key',
            'settlement_replay_key',
            'reconciliation_replay_key',
        ], true)) {
            throw new InvalidArgumentException('The settlement replay field is invalid.');
        }

        return FinExternalSettlement::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->where('purpose', $purpose)
            ->where($column, $replayKey)
            ->first();
    }

    private function recordEvent(
        FinExternalSettlement $settlement,
        string $eventType,
        ?string $fromStatus,
        string $toStatus,
        string $replayKey,
        array $evidence,
        User $actor,
    ): void {
        $settlement->events()->create([
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'replay_key' => $replayKey,
            'evidence' => $this->sortRecursively($evidence),
            'actor_id' => $actor->id,
            'occurred_at' => now(),
        ]);
    }

    private function accountsPayableAccountId(int $organizationId): int
    {
        return (int) FinAccount::query()
            ->where('organization_id', $organizationId)
            ->where('code', '2000')
            ->where('is_active', true)
            ->firstOrFail(['id'])
            ->id;
    }

    private function paymentRunCsv(FinPaymentRun $run): string
    {
        $csv = "Vendor Name,Bank Account Number,Amount,Reference\n";
        foreach ($run->items as $item) {
            $csv .= implode(',', [
                str_replace([',', "\r", "\n"], ' ', (string) $item->vendor->name),
                str_replace([',', "\r", "\n"], ' ', (string) $item->bank_account_number),
                $this->decimal((string) $item->amount),
                str_replace([',', "\r", "\n"], ' ', (string) $item->reference),
            ])."\n";
        }

        return $csv;
    }

    private function canonicalJson(array $value): string
    {
        return json_encode($this->sortRecursively($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function sameEvidence(array $left, array $right): bool
    {
        return hash_equals(
            hash('sha256', $this->canonicalJson($left)),
            hash('sha256', $this->canonicalJson($right)),
        );
    }

    private function verifiedArtifactContents(
        Model $source,
        string $purpose,
        FinExternalSettlement $settlement,
    ): string {
        [$expectedDisk, $expectedPath] = $this->expectedArtifactLocation(
            $source,
            $purpose,
            (int) $settlement->attempt_number,
        );
        if (! hash_equals($expectedDisk, (string) $settlement->artifact_disk)
            || ! hash_equals($expectedPath, (string) $settlement->artifact_path)) {
            throw new InvalidArgumentException('The prepared settlement file location is not canonical.');
        }

        try {
            $disk = Storage::disk($expectedDisk);
            if (! $disk->exists($expectedPath)) {
                throw new InvalidArgumentException('The prepared settlement file is missing; prepare a corrected instruction.');
            }
            $contents = $disk->get($expectedPath);
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('The prepared settlement file is missing; prepare a corrected instruction.');
        }

        $actualSha256 = hash('sha256', $contents);
        if (! hash_equals($settlement->artifact_sha256, $actualSha256)) {
            throw new InvalidArgumentException('The prepared settlement file changed after preparation.');
        }

        return $contents;
    }

    /** @return array{string, string} */
    private function expectedArtifactLocation(Model $source, string $purpose, int $attemptNumber = 1): array
    {
        if ($purpose === self::PAYMENT_RUN && $source instanceof FinPaymentRun) {
            $runNumber = (string) $source->run_number;
            if (! preg_match('/\A[A-Za-z0-9_-]+\z/D', $runNumber)) {
                throw new InvalidArgumentException('The payment-run artifact identifier is not canonical.');
            }

            return ['local', "finance/payment-runs/{$runNumber}.csv"];
        }
        if ($purpose === self::PAYROLL_NET_PAY && $source instanceof HrPayrollRun) {
            if ($attemptNumber < 1) {
                throw new InvalidArgumentException('The payroll settlement attempt number is invalid.');
            }
            $suffix = $attemptNumber === 1 ? '' : "-attempt-{$attemptNumber}";

            return ['private', "payroll-settlements/net-pay-run-{$source->getKey()}{$suffix}.csv"];
        }

        throw new InvalidArgumentException('The settlement source does not own this artifact purpose.');
    }

    private function activeSourceKey(Model $source, string $purpose): string
    {
        return hash('sha256', implode('|', [
            $source->getMorphClass(),
            $source->getKey(),
            $purpose,
        ]));
    }

    private function decimal(string $value): string
    {
        if (! preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value)) {
            throw new InvalidArgumentException('Settlement money must be an exact decimal with at most two fractional digits.');
        }

        return bcadd($value, '0.00', 2);
    }

    private function sortRecursively(array $value): array
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->sortRecursively($item);
            }
        }
        unset($item);

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
