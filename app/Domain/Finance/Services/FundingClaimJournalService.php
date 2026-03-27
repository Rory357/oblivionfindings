<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinJournal;
use App\Models\FundingClaim;
use InvalidArgumentException;
use RuntimeException;

class FundingClaimJournalService
{
    /**
     * GL account code -> FinAccount cache (per-request, keyed by orgId:code).
     *
     * @var array<string, FinAccount>
     */
    private array $accountCache = [];

    /**
     * Map funding body values to receivable account codes.
     */
    private const RECEIVABLE_ACCOUNT_MAP = [
        'whaikaha'  => '1110',
        'acc'       => '1120',
        'nasc'      => '1100',
    ];

    /**
     * Map funding body values to revenue account codes.
     */
    private const REVENUE_ACCOUNT_MAP = [
        'whaikaha'  => '4000',
        'acc'       => '4010',
        'nasc'      => '4020',
        'private'   => '4030',
    ];

    public function __construct(
        private readonly JournalPostingService $journalPostingService,
    ) {}

    /* ------------------------------------------------------------------
     |  Post a funding claim to the General Ledger
     | ------------------------------------------------------------------ */

    public function postFundingClaimJournal(FundingClaim $claim): FinJournal
    {
        if ($claim->journal_id !== null) {
            throw new InvalidArgumentException(
                "Funding claim #{$claim->id} ({$claim->claim_reference}) has already been posted to journal #{$claim->journal_id}."
            );
        }

        $orgId = $claim->organization_id;

        // Determine the funder type from the linked service agreement
        $funderType = $this->resolveFunderType($claim);

        // Determine receivable and revenue account codes
        $receivableCode = self::RECEIVABLE_ACCOUNT_MAP[$funderType] ?? '1100';
        $revenueCode    = self::REVENUE_ACCOUNT_MAP[$funderType] ?? '4030';

        $lines = [];

        // DR Funder Receivable: total_amount
        if (bccomp((string) $claim->total_amount, '0', 2) > 0) {
            $receivableAccount = $this->findAccountByCode($orgId, $receivableCode);
            $lines[] = [
                'account_id'  => $receivableAccount->id,
                'description' => "{$receivableAccount->name}",
                'debit'       => $claim->total_amount,
                'credit'      => 0,
            ];

            // CR Funding Revenue: total_amount
            $revenueAccount = $this->findAccountByCode($orgId, $revenueCode);
            $lines[] = [
                'account_id'  => $revenueAccount->id,
                'description' => "{$revenueAccount->name}",
                'debit'       => 0,
                'credit'      => $claim->total_amount,
            ];
        }

        if (count($lines) < 2) {
            throw new RuntimeException(
                "Funding claim #{$claim->id} ({$claim->claim_reference}) produced fewer than 2 journal lines. Cannot post."
            );
        }

        $periodStart = $claim->period_start->toDateString();
        $periodEnd   = $claim->period_end->toDateString();

        $journal = $this->journalPostingService->createAndPost($orgId, [
            'journal_date' => now()->toDateString(),
            'type'         => 'billing',
            'source_type'  => 'funding_claim',
            'source_id'    => $claim->id,
            'description'  => "Funding claim {$claim->claim_reference} ({$periodStart} to {$periodEnd})",
            'lines'        => $lines,
        ]);

        $claim->update([
            'journal_id'   => $journal->id,
            'gl_posted_at' => now(),
        ]);

        return $journal;
    }

    /* ------------------------------------------------------------------
     |  Reverse a previously posted funding claim journal
     | ------------------------------------------------------------------ */

    public function reverseFundingClaimJournal(FundingClaim $claim): ?FinJournal
    {
        if ($claim->journal_id === null) {
            return null;
        }

        $journal = FinJournal::findOrFail($claim->journal_id);

        $reversingJournal = $this->journalPostingService->reverse(
            $journal,
            "Reversal of funding claim {$claim->claim_reference}"
        );

        $claim->update([
            'journal_id'   => null,
            'gl_posted_at' => null,
        ]);

        return $reversingJournal;
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
