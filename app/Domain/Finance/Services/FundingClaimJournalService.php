<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinJournal;
use App\Models\FundingClaim;
use App\Services\AuditLogger;
use App\Services\Operations\FundingClaimService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class FundingClaimJournalService
{
    private const APPLICATION_STORAGE_CONTEXT_ID = 1;

    /**
     * GL account code -> FinAccount cache (per-request, keyed by storage context and code).
     *
     * @var array<string, FinAccount>
     */
    private array $accountCache = [];

    /**
     * Map funding body values to receivable account codes.
     */
    private const RECEIVABLE_ACCOUNT_MAP = [
        'whaikaha' => '1110',
        'acc' => '1120',
        'nasc' => '1100',
    ];

    /**
     * Map funding body values to revenue account codes.
     */
    private const REVENUE_ACCOUNT_MAP = [
        'whaikaha' => '4000',
        'acc' => '4010',
        'nasc' => '4020',
        'private' => '4030',
    ];

    public function __construct(
        private readonly JournalPostingService $journalPostingService,
        private readonly FundingClaimService $fundingClaims,
    ) {}

    /* ------------------------------------------------------------------
     |  Post a funding claim to the General Ledger
     | ------------------------------------------------------------------ */

    public function postFundingClaimJournal(FundingClaim $claim): FinJournal
    {
        try {
            return DB::transaction(function () use ($claim) {
                $storageContextId = self::APPLICATION_STORAGE_CONTEXT_ID;

                // Shared 000080 contract: every producer locks the guaranteed
                // journal sequence mutex before its own aggregate.
                $this->journalPostingService->lockJournalSequence($storageContextId);

                $claim = FundingClaim::query()
                    ->with(['serviceAgreement.client', 'items'])
                    ->whereHas('client', fn ($clientQuery) => $clientQuery
                        ->whereNotNull('site_id')
                        ->whereHas('site', fn ($siteQuery) => $siteQuery
                            ->active()
                            ->notArchived()
                            ->whereNull('archived_at')))
                    ->whereHas('serviceAgreement', fn ($agreementQuery) => $agreementQuery
                        ->whereColumn('service_agreements.client_id', 'funding_claims.client_id'))
                    ->lockForUpdate()
                    ->findOrFail($claim->id);

                $this->fundingClaims->assertClaimIntegrity($claim);

                if ($claim->journal_id !== null) {
                    $journal = FinJournal::query()->lockForUpdate()->findOrFail($claim->journal_id);
                    $this->assertPostedJournal($claim, $journal);
                    if ($claim->reversal_journal_id !== null) {
                        $reversal = FinJournal::query()->lockForUpdate()->findOrFail($claim->reversal_journal_id);
                        $this->assertReversalJournal($claim, $reversal);
                    }
                    $expectedStatus = $claim->reversal_journal_id === null ? 'posted' : 'reversed';
                    if ($claim->gl_posting_status !== $expectedStatus || $claim->gl_posting_error !== null) {
                        $claim->forceFill([
                            'gl_posting_status' => $expectedStatus,
                            'gl_posting_error' => null,
                        ])->saveQuietly();
                        AuditLogger::logOrFail(
                            'funding.claim.gl.reconciled',
                            $claim,
                            $this->auditMeta($claim, [
                                'journal_id' => $journal->id,
                                'reversal_journal_id' => $claim->reversal_journal_id,
                                'gl_posting_status' => $expectedStatus,
                            ]),
                        );
                    }

                    return $journal;
                }

                if (! in_array($claim->status, ['submitted', 'approved'], true)) {
                    throw new InvalidArgumentException(
                        "Funding claim #{$claim->id} ({$claim->claim_reference}) must be submitted or approved before GL posting."
                    );
                }

                $claim->forceFill([
                    'gl_posting_status' => 'posting',
                    'gl_posting_attempts' => ((int) $claim->gl_posting_attempts) + 1,
                    'gl_posting_attempted_at' => now(),
                    'gl_posting_error' => null,
                ])->saveQuietly();

                // Determine the funder type from the linked service agreement
                $funderType = $this->resolveFunderType($claim);

                // Determine receivable and revenue account codes
                $receivableCode = self::RECEIVABLE_ACCOUNT_MAP[$funderType] ?? '1100';
                $revenueCode = self::REVENUE_ACCOUNT_MAP[$funderType] ?? '4030';

                $lines = [];

                // DR Funder Receivable: exact provenance-bound total_amount
                if (bccomp((string) $claim->total_amount, '0', 2) > 0) {
                    $receivableAccount = $this->findAccountByCode($storageContextId, $receivableCode);
                    $lines[] = [
                        'account_id' => $receivableAccount->id,
                        'description' => "{$receivableAccount->name}",
                        'debit' => $claim->total_amount,
                        'credit' => 0,
                        'client_id' => $claim->client_id,
                        'site_id' => $claim->site_id,
                    ];

                    // CR Funding Revenue: exact provenance-bound total_amount
                    $revenueAccount = $this->findAccountByCode($storageContextId, $revenueCode);
                    $lines[] = [
                        'account_id' => $revenueAccount->id,
                        'description' => "{$revenueAccount->name}",
                        'debit' => 0,
                        'credit' => $claim->total_amount,
                        'client_id' => $claim->client_id,
                        'site_id' => $claim->site_id,
                    ];
                }

                if (count($lines) < 2) {
                    throw new RuntimeException(
                        "Funding claim #{$claim->id} ({$claim->claim_reference}) produced fewer than 2 journal lines. Cannot post."
                    );
                }

                $periodStart = $claim->period_start->toDateString();
                $periodEnd = $claim->period_end->toDateString();

                $journal = $this->journalPostingService->createAndPost($storageContextId, [
                    'journal_date' => now()->toDateString(),
                    'type' => 'billing',
                    'source_type' => 'funding_claim',
                    'source_id' => $claim->id,
                    'description' => "Funding claim {$claim->claim_reference} ({$periodStart} to {$periodEnd})",
                    'actor_id' => $claim->submitted_by ?: $claim->created_by,
                    'lines' => $lines,
                ]);
                $this->assertPostedJournal($claim, $journal);

                $claim->forceFill([
                    'journal_id' => $journal->id,
                    'gl_posted_at' => now(),
                    'gl_posting_status' => 'posted',
                    'gl_posting_error' => null,
                ])->saveQuietly();
                AuditLogger::logOrFail(
                    'funding.claim.gl.posted',
                    $claim,
                    $this->auditMeta($claim, [
                        'journal_id' => $journal->id,
                        'gl_posting_attempts' => $claim->gl_posting_attempts,
                    ]),
                );

                return $journal;
            }, 3);
        } catch (Throwable $exception) {
            DB::transaction(function () use ($claim, $exception): void {
                $failed = FundingClaim::query()->lockForUpdate()->find($claim->id);
                if (! $failed || $failed->journal_id !== null) {
                    return;
                }

                $failed->forceFill([
                    'gl_posting_status' => 'failed',
                    'gl_posting_attempts' => ((int) $failed->gl_posting_attempts) + 1,
                    'gl_posting_attempted_at' => now(),
                    'gl_posting_error' => mb_substr($exception->getMessage(), 0, 2000),
                ])->saveQuietly();
                AuditLogger::logOrFail(
                    'funding.claim.gl.failed',
                    $failed,
                    $this->auditMeta($failed, [
                        'gl_posting_attempts' => $failed->gl_posting_attempts,
                        'failure_type' => class_basename($exception),
                    ]),
                );
            }, 3);

            throw $exception;
        }
    }

    private function assertPostedJournal(FundingClaim $claim, FinJournal $journal): void
    {
        if (
            $journal->status !== 'posted'
            || $journal->source_type !== 'funding_claim'
            || (int) $journal->source_id !== (int) $claim->id
            || bccomp((string) $journal->total_amount, (string) $claim->total_amount, 2) !== 0
        ) {
            throw new RuntimeException("Funding claim #{$claim->id} has invalid General Ledger provenance.");
        }
    }

    private function assertReversalJournal(FundingClaim $claim, FinJournal $journal): void
    {
        if (
            $claim->journal_id === null
            || $journal->status !== 'posted'
            || (int) $journal->reversal_of_journal_id !== (int) $claim->journal_id
            || $journal->source_type !== 'funding_claim'
            || (int) $journal->source_id !== (int) $claim->id
            || bccomp((string) $journal->total_amount, (string) $claim->total_amount, 2) !== 0
        ) {
            throw new RuntimeException("Funding claim #{$claim->id} has invalid reversal provenance.");
        }
    }

    /* ------------------------------------------------------------------
     |  Reverse a previously posted funding claim journal
     | ------------------------------------------------------------------ */

    public function reverseFundingClaimJournal(FundingClaim $claim): ?FinJournal
    {
        return DB::transaction(function () use ($claim): ?FinJournal {
            $this->journalPostingService->lockJournalSequence(self::APPLICATION_STORAGE_CONTEXT_ID);

            $claim = FundingClaim::query()
                ->whereHas('client', fn ($clientQuery) => $clientQuery
                    ->whereNotNull('site_id')
                    ->whereHas('site', fn ($siteQuery) => $siteQuery
                        ->active()
                        ->notArchived()
                        ->whereNull('archived_at')))
                ->whereHas('serviceAgreement', fn ($agreementQuery) => $agreementQuery
                    ->whereColumn('service_agreements.client_id', 'funding_claims.client_id'))
                ->lockForUpdate()
                ->findOrFail($claim->id);

            $this->fundingClaims->assertClaimIntegrity($claim);

            if ($claim->reversal_journal_id !== null) {
                $reversal = FinJournal::query()->lockForUpdate()->findOrFail($claim->reversal_journal_id);
                $this->assertReversalJournal($claim, $reversal);

                return $reversal;
            }
            if ($claim->journal_id === null) {
                return null;
            }

            $journal = FinJournal::query()->lockForUpdate()->findOrFail($claim->journal_id);
            $this->assertPostedJournal($claim, $journal);
            $reason = "Reversal of funding claim {$claim->claim_reference}";
            $reversingJournal = $this->journalPostingService->reverse($journal, $reason);
            $this->assertReversalJournal($claim, $reversingJournal);

            $claim->forceFill([
                'reversal_journal_id' => $reversingJournal->id,
                'gl_reversed_at' => now(),
                'gl_reversal_reason' => $reason,
                'gl_posting_status' => 'reversed',
            ])->saveQuietly();
            AuditLogger::logOrFail(
                'funding.claim.gl.reversed',
                $claim,
                $this->auditMeta($claim, [
                    'journal_id' => $journal->id,
                    'reversal_journal_id' => $reversingJournal->id,
                ]),
            );

            return $reversingJournal;
        }, 3);
    }

    /** @return array<string, mixed> */
    private function auditMeta(FundingClaim $claim, array $extra = []): array
    {
        return array_replace([
            'actor_id' => (int) ($claim->submitted_by ?: $claim->created_by),
            'client_id' => $claim->client_id,
            'site_id' => $claim->site_id,
            'service_agreement_id' => $claim->service_agreement_id,
            'provenance_digest' => $claim->provenance_digest,
        ], $extra);
    }

    /* ------------------------------------------------------------------
     |  Resolve the funder type from the service agreement
     | ------------------------------------------------------------------ */

    private function resolveFunderType(FundingClaim $claim): string
    {
        if ($claim->service_agreement_id) {
            $claim->loadMissing('serviceAgreement');

            if ($claim->serviceAgreement && $claim->serviceAgreement->funding_body) {
                return strtolower($claim->serviceAgreement->funding_body);
            }
        }

        // Default to generic accounts receivable / private revenue
        return 'private';
    }

    /* ------------------------------------------------------------------
     |  Helper: find a GL account by code (cached per request)
     | ------------------------------------------------------------------ */

    public function findAccountByCode(int $storageContextId, string $code): FinAccount
    {
        $cacheKey = "{$storageContextId}:{$code}";

        if (isset($this->accountCache[$cacheKey])) {
            return $this->accountCache[$cacheKey];
        }

        $account = FinAccount::where('organization_id', $storageContextId)
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if (! $account) {
            throw new RuntimeException(
                "GL account with code '{$code}' not found (or inactive) for the application storage context."
            );
        }

        $this->accountCache[$cacheKey] = $account;

        return $account;
    }
}
