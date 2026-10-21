<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralEvidence;
use App\Domain\Referrals\Models\ReferralFundingCase;
use App\Domain\Referrals\Models\ReferralProposal;
use App\Domain\Referrals\Models\ReferralProposalReview;
use App\Services\CurrentAuthorizationReads;
use Carbon\CarbonImmutable;

/** Proposal checks are only one prerequisite: they never authorize actual service commencement. */
final class ReferralProposalReadiness
{
    public function __construct(private readonly ReferralProposals $proposals, private readonly ReferralProposalCoverage $coverage, private readonly ReferralProposalPricing $pricing) {}

    public function inspect(CareReferral $referral, ReferralProposal $proposal, ?string $date = null, ?CurrentAuthorizationReads $reads = null): array
    {
        $checkAt = CarbonImmutable::parse($date ?? $proposal->proposal['starts_on'], 'Pacific/Auckland')->startOfDay()->max(CarbonImmutable::now());
        $issues = [];
        $serviceDate = CarbonImmutable::parse($date ?? today('Pacific/Auckland')->toDateString(), 'Pacific/Auckland')->startOfDay();
        if ($serviceDate->gt(CarbonImmutable::parse($proposal->proposal['ends_on'], 'Pacific/Auckland')->startOfDay())) {
            $issues[] = 'The proposal period has ended; prepare a current support proposal.';
        }
        if ($date !== null && $serviceDate->lt(CarbonImmutable::parse($proposal->proposal['starts_on'], 'Pacific/Auckland')->startOfDay())) {
            $issues[] = 'The requested service date is before the proposal period.';
        }
        if ($this->proposals->latest($referral)?->id !== $proposal->id) {
            $issues[] = 'This proposal has been replaced by a newer version.';
        }
        foreach ($proposal->proposal['lines'] as $line) {
            if (! $line['estimate']['known']) {
                $issues[] = $line['description'].': the cost is not yet known.';
            } elseif (! $this->pricing->taxCurrent($line, $reads)) {
                $issues[] = $line['description'].': the canonical tax treatment changed; prepare a reviewed revision.';
            }
        }
        $coverage = $this->coverage->inspect($referral, $proposal->proposal);
        $issues = [...$issues, ...$coverage['issues']];
        $internal = app(ReferralInternalFunding::class)->arrangement($proposal);
        if ($internal) {
            $issues = [...$issues, ...app(ReferralInternalFunding::class)->availability($referral, $internal, $date)['issues']];
        }
        $approval = $this->review($proposal, 'internal_approval');
        $approved = $approval && $approval->result === 'approved' && ! empty($approval->details['review_due_at'])
            && CarbonImmutable::parse($approval->details['review_due_at'])->gt($checkAt)
            && hash_equals($approval->details['prerequisite_token'] ?? '', $this->proposals->token($referral, $proposal, $reads));
        if (! $approved) {
            $issues[] = 'Current independent internal approval is required for this proposal and its prerequisites.';
        }
        $person = $this->review($proposal, 'person_acceptance');
        $accepted = $person && $person->result === 'accepted' && ReferralAssessment::scope($referral, 'service')
            && ($person->details['authority_version'] ?? null) === ($referral->authority['review']['version'] ?? null)
            && CarbonImmutable::parse($referral->authority['review']['review_due_at'])->gt($checkAt)
            && $this->evidenceCurrent($referral, $person->details['evidence_ids'] ?? [], $checkAt);
        if (! $accepted) {
            $issues[] = 'Current acceptance by the person or their authorized representative is required for this version.';
        }
        $payers = [];
        foreach (array_unique(array_column($proposal->proposal['allocations'], 'funding_case_id')) as $id) {
            $case = ReferralFundingCase::query()->where('referral_id', $referral->id)->where('pathway', 'private')->find($id);
            if (! $case) {
                continue;
            }
            $review = $this->review($proposal, 'payer_acceptance', $case->id);
            $payerAccepted = $review && $review->result === 'accepted' && ($review->details['payer_snapshot'] ?? null) === ($case->details['roles']['invoice_payer'] ?? null)
                && $this->evidenceCurrent($referral, $review->details['evidence_ids'] ?? [], $checkAt);
            if (! $payerAccepted) {
                $issues[] = 'Private payer acceptance is missing or needs review for this proposal version.';
            }
            $payers[] = ['funding_case_id' => $case->id, 'payer' => $case->details['roles']['invoice_payer'] ?? 'Payer not identified', 'accepted' => (bool) $payerAccepted];
        }

        return ['ready' => $issues === [], 'issues' => array_values(array_unique($issues)), 'coverage' => $coverage,
            'internal_approval_current' => (bool) $approved, 'person_acceptance_current' => (bool) $accepted, 'private_payers' => $payers];
    }

    private function review(ReferralProposal $proposal, string $kind, ?int $caseId = null): ?ReferralProposalReview
    {
        return ReferralProposalReview::query()->where('proposal_id', $proposal->id)->where('kind', $kind)->where('funding_case_id', $caseId)->orderByDesc('id')->first();
    }

    private function evidenceCurrent(CareReferral $referral, array $ids, CarbonImmutable $date): bool
    {
        if ($ids === []) {
            return false;
        }
        $documents = ReferralEvidence::query()->where('referral_id', $referral->id)->where('audience', 'funding')->whereIn('id', $ids)->get();

        return $documents->count() === count($ids) && $documents->every(fn ($document) => $document->state === 'available'
            && empty($document->metadata['superseded_by_id']) && (empty($document->metadata['expires_at']) || CarbonImmutable::parse($document->metadata['expires_at'])->gt($date))
            && app(ReferralEvidenceFiles::class)->bytes($document) !== null);
    }
}
