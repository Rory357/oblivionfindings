<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralEvidence;
use App\Domain\Referrals\Models\ReferralInternalArrangement;
use App\Domain\Referrals\Models\ReferralProposal;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** A company commitment for one exact proposal, never a government or private-payer award. */
final class ReferralInternalFunding
{
    public function __construct(private readonly ReferralCommand $command) {}

    public function enabled(?CurrentAuthorizationReads $reads = null): bool
    {
        return (bool) (app(ReferralSettings::class)->effective($reads)?->configuration['proposals']['temporary_funding_enabled'] ?? false);
    }

    public function arrangement(ReferralProposal $proposal): ?ReferralInternalArrangement
    {
        return ReferralInternalArrangement::where('proposal_id', $proposal->id)->where('referral_id', $proposal->referral_id)->first();
    }

    public function review(ReferralInternalArrangement $arrangement): ?ReferralEvent
    {
        return ReferralEvent::where('referral_id', $arrangement->referral_id)->where('kind', 'internal_funding.reviewed')->orderByDesc('id')->get()
            ->first(fn ($event) => (int) ($event->payload['arrangement_id'] ?? 0) === (int) $arrangement->id);
    }

    /** Never infer a recurring period conversion: bounded internal commitments use total-term lines. */
    public function gap(CareReferral $referral, ReferralProposal $proposal): array
    {
        $external = app(ReferralProposalCoverage::class)->inspect($referral, $proposal->proposal, false);
        $issues = array_values(array_filter($external['issues'], fn ($issue) => ! str_ends_with($issue, ': part of the proposed support remains unfunded.')));
        $allocations = [];
        $amount = BigDecimal::zero();
        $currency = null;
        foreach ($proposal->proposal['lines'] as $line) {
            $coverage = collect($external['lines'])->firstWhere('line_key', $line['key']);
            if ($line['quantity'] === null || BigDecimal::of($line['quantity'])->isZero()) {
                continue;
            }
            $gap = BigDecimal::of($line['quantity'])->minus($coverage['covered_quantity']);
            if ($gap->isLessThanOrEqualTo(0)) {
                continue;
            }
            if ($line['period'] !== 'total' || ! $line['estimate']['known']) {
                $issues[] = $line['description'].': record the full temporary period as a costed total-term support line before arranging company funding.';

                continue;
            }
            if ($currency !== null && $currency !== $line['currency']) {
                $issues[] = 'A temporary arrangement must use one currency; no exchange rate is assumed.';
            }
            $currency = $line['currency'];
            $gross = BigDecimal::of($line['estimate']['gross'])->multipliedBy($gap)->dividedBy($line['quantity'], 2, RoundingMode::HALF_UP);
            $amount = $amount->plus($gross);
            $allocations[] = ['line_key' => $line['key'], 'service_id' => $line['service_id'], 'support_quantity' => (string) $gap, 'gross' => (string) $gross];
        }
        if ($allocations === [] || $amount->isLessThanOrEqualTo(0)) {
            $issues[] = 'There is no positive, costed total-term gap to fund internally.';
        }

        return ['ready' => $issues === [], 'issues' => array_values(array_unique($issues)), 'currency' => $currency,
            'gross' => (string) $amount, 'allocations' => $allocations];
    }

    public function prepare(User $actor, int $id, int $version, int $proposalId, array $input): ReferralInternalArrangement
    {
        $data = Validator::make($input, ['payer' => 'required|string|min:3|max:200', 'budget_reference' => 'required|string|min:3|max:200',
            'cost_cap' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'], 'reason' => 'required|string|min:10|max:4000',
            'evidence_ids' => 'required|array|min:1|max:20', 'evidence_ids.*' => 'integer|min:1|distinct',
            'review_due_at' => 'required|date'])->validate();

        return $this->command->run($actor, $id, $version, 'funding.manage', function (CareReferral $referral, User $actor, CurrentAuthorizationReads $reads) use ($data, $proposalId): ReferralInternalArrangement {
            abort_unless($this->enabled($reads), 422, 'Temporary company funding is not enabled in the current published settings.');
            abort_if($referral->stage === 'closed' || $referral->general_enquiry, 422, 'An open personal support referral is required.');
            abort_unless(ReferralAssessment::scope($referral, 'collect'), 422, 'Review current authority before preparing an internal arrangement.');
            $proposal = app(ReferralProposals::class)->current($referral, $proposalId);
            abort_if($this->arrangement($proposal), 409, 'This proposal already has an internal arrangement. Revise the proposal to change its terms.');
            $gap = $this->gap($referral, $proposal);
            abort_unless($gap['ready'], 422, implode(' ', $gap['issues']));
            abort_if(BigDecimal::of($data['cost_cap'])->isLessThan($gap['gross']), 422, 'The full-period cost cap must cover the recorded gap.');
            $due = ReferralDates::parse($data['review_due_at'], 'review_due_at');
            $end = CarbonImmutable::parse($proposal->proposal['ends_on'], 'Pacific/Auckland')->endOfDay();
            abort_if($due->isPast() || $due->gt($end), 422, 'Schedule the review before this temporary support ends and after now.');
            app(ReferralFunding::class)->evidence($referral, $data['evidence_ids'], true);
            $arrangement = ReferralInternalArrangement::create(['referral_id' => $referral->id, 'proposal_id' => $proposal->id,
                'authored_by' => $actor->id, 'authored_at' => now(), 'terms' => [...$data, ...$gap, 'starts_on' => $proposal->proposal['starts_on'],
                    'ends_on' => $proposal->proposal['ends_on'], 'review_due_at' => $due->toISOString(), 'settings_version_id' => app(ReferralSettings::class)->effective($reads)->id]]);
            app(ReferralAssessment::class)->invalidateReadiness($referral, $actor, 'temporary company funding');
            $this->command->record($referral, $actor, 'internal_funding.prepared', 'funding', ['arrangement_id' => $arrangement->id, 'proposal_id' => $proposal->id, 'summary' => $data['reason']]);

            return $arrangement;
        });
    }

    public function decide(User $actor, int $id, int $version, int $arrangementId, array $input): void
    {
        $data = Validator::make($input, ['result' => ['required', Rule::in(['approved', 'rejected', 'revoked'])], 'note' => 'required|string|min:10|max:4000'])->validate();
        $this->command->run($actor, $id, $version, 'proposals.approve', function (CareReferral $referral, User $actor, CurrentAuthorizationReads $reads) use ($arrangementId, $data): void {
            $arrangement = ReferralInternalArrangement::where('referral_id', $referral->id)->findOrFail($arrangementId);
            abort_if((int) $arrangement->authored_by === (int) $actor->id, 422, 'A separate authorized colleague must review the company commitment.');
            $delegation = [];
            if ($data['result'] === 'approved') {
                abort_if(($this->review($arrangement)?->payload['result'] ?? '') === 'approved', 409, 'This arrangement is already approved. Review or revoke the existing decision.');
                abort_if($referral->stage === 'closed', 422, 'Reopen the referral before approving new commitments.');
                abort_unless($this->enabled($reads), 422, 'Temporary company funding is not enabled.');
                $proposal = app(ReferralProposals::class)->current($referral, $arrangement->proposal_id);
                $gap = $this->gap($referral, $proposal);
                abort_unless($gap['ready'] && $gap['allocations'] === $arrangement->terms['allocations'], 409, 'The funding gap changed. Prepare a revised proposal and arrangement.');
                abort_if(CarbonImmutable::parse($arrangement->terms['review_due_at'])->isPast(), 422, 'The arrangement needs a new review date. Prepare a revised proposal.');
                app(ReferralFunding::class)->evidence($referral, $arrangement->terms['evidence_ids'], true);
                $delegation = app(ReferralProposals::class)->delegation($actor, [['known' => true, 'currency' => $arrangement->terms['currency'],
                    'period' => 'total', 'known_gross' => $arrangement->terms['cost_cap']]], $reads);
            }
            $event = $this->command->record($referral, $actor, 'internal_funding.reviewed', 'funding', ['arrangement_id' => $arrangement->id,
                'proposal_id' => $arrangement->proposal_id, ...$data, 'delegation' => $delegation]);
            if ($data['result'] === 'approved') {
                // Named reviewing delegate owns this commitment's review, independently of the assistance navigator.
                $work = ReferralWorkItem::create(['referral_id' => $referral->id, 'owner_id' => $actor->id, 'kind' => 'funding_follow_up', 'audience' => 'funding',
                    'status' => 'open', 'lock_version' => 1, 'due_at' => $arrangement->terms['review_due_at'],
                    'details' => ['title' => 'Review temporary company funding', 'arrangement_id' => $arrangement->id, 'approval_event_id' => $event->id]]);
                $this->command->record($referral, $actor, 'work.created', 'funding', ['work_id' => $work->id, 'summary' => 'Review temporary company funding']);
            }
            app(ReferralAssessment::class)->invalidateReadiness($referral, $actor, 'temporary company funding decision');
        });
    }

    public function availability(CareReferral $referral, ReferralInternalArrangement $arrangement, ?string $date = null): array
    {
        $issues = [];
        $at = CarbonImmutable::parse($date ?? $arrangement->terms['starts_on'], 'Pacific/Auckland')->startOfDay()->max(CarbonImmutable::now());
        $review = $this->review($arrangement);
        if (! $this->enabled() || ($review?->payload['result'] ?? '') !== 'approved') {
            $issues[] = 'Current independent approval of temporary company funding is required.';
        }
        if ($arrangement->referral_id !== $referral->id || app(ReferralProposals::class)->latest($referral)?->id !== $arrangement->proposal_id) {
            $issues[] = 'The company arrangement belongs to a superseded proposal.';
        }
        if (CarbonImmutable::parse($arrangement->terms['review_due_at'])->lte($at)
            || CarbonImmutable::parse($arrangement->terms['ends_on'], 'Pacific/Auckland')->endOfDay()->lt($at)) {
            $issues[] = 'The temporary arrangement has expired or its review is overdue.';
        }
        try {
            app(ReferralFunding::class)->evidence($referral, $arrangement->terms['evidence_ids'], true);
            $evidence = ReferralEvidence::where('referral_id', $referral->id)->whereIn('id', $arrangement->terms['evidence_ids'])->get();
            if ($evidence->contains(fn ($document) => ! empty($document->metadata['expires_at']) && CarbonImmutable::parse($document->metadata['expires_at'])->lte($at))) {
                $issues[] = 'Company funding evidence expires before the requested service date.';
            }
        } catch (HttpException $error) {
            if (! in_array($error->getStatusCode(), [404, 422], true)) {
                throw $error;
            }
            $issues[] = 'The company arrangement evidence needs review.';
        }

        return ['usable' => $issues === [], 'issues' => $issues];
    }
}
