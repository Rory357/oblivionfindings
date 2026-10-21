<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Finance\Models\FinTaxRate;
use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralFundingCase;
use App\Domain\Referrals\Models\ReferralFundingDecision;
use App\Domain\Referrals\Models\ReferralProposal;
use App\Domain\Referrals\Models\ReferralProposalReview;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ReferralProposals
{
    public function __construct(private readonly ReferralCommand $command, private readonly ReferralAccess $access, private readonly ReferralProposalPricing $pricing, private readonly ReferralProposalCoverage $coverage) {}

    public function save(User $actor, int $id, int $version, array $input): ReferralProposal
    {
        $decimal = ['present', 'nullable', 'regex:/^\d{1,10}(\.\d{1,4})?$/'];
        $data = Validator::make($input, [
            'previous_proposal_id' => 'present|nullable|integer|min:1', 'title' => 'required|string|min:3|max:200',
            'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on',
            'support_rationale' => 'required|string|min:5|max:4000', 'funding_position' => 'required|string|min:5|max:4000',
            'capacity_plan' => 'required|string|min:5|max:4000', 'person_participation' => 'required|string|min:5|max:4000', 'reason' => 'required|string|min:5|max:2000',
            'lines' => 'required|array|min:1|max:30', 'lines.*' => 'required|array:key,service_id,description,quantity,unit,unit_label,period,currency,unit_rate,tax_rate_id,price_basis,price_source',
            'lines.*.key' => 'required|string|max:64|regex:/^[a-zA-Z0-9_-]+$/|distinct', 'lines.*.service_id' => 'required|string|max:64', 'lines.*.description' => 'required|string|min:3|max:500',
            'lines.*.quantity' => $decimal, 'lines.*.unit_rate' => $decimal, 'lines.*.unit' => ['required', Rule::in(['hours', 'days', 'visits', 'other'])],
            'lines.*.unit_label' => 'present|nullable|string|max:80', 'lines.*.period' => ['required', Rule::in(['week', 'fortnight', 'month', 'year', 'total'])],
            'lines.*.currency' => 'required|string|regex:/^[A-Z]{3}$/', 'lines.*.tax_rate_id' => 'present|nullable|integer|min:1',
            'lines.*.price_basis' => ['required', Rule::in(['unknown', 'exclusive', 'inclusive'])], 'lines.*.price_source' => 'required|string|min:5|max:2000',
            'allocations' => 'present|array|max:100', 'allocations.*' => 'required|array:line_key,funding_case_id,decision_id,decision_line,quantity,support_quantity',
            'allocations.*.line_key' => 'required|string|max:64', 'allocations.*.funding_case_id' => 'required|integer|min:1', 'allocations.*.decision_id' => 'required|integer|min:1', 'allocations.*.decision_line' => 'required|integer|min:0|max:29',
            'allocations.*.quantity' => ['required', 'regex:/^\d{1,10}(\.\d{1,4})?$/'], 'allocations.*.support_quantity' => ['required', 'regex:/^\d{1,10}(\.\d{1,4})?$/'],
        ])->validate();

        return $this->command->run($actor, $id, $version, 'proposals.manage', function (CareReferral $referral, User $actor) use ($data): ReferralProposal {
            $this->editable($referral);
            abort_unless(ReferralAssessment::scope($referral, 'collect'), 422, 'Review current authority before preparing personal support terms.');
            $previous = $this->latest($referral);
            abort_unless(($previous?->id) === ($data['previous_proposal_id'] === null ? null : (int) $data['previous_proposal_id']), 409, 'This proposal has been revised. Review its current version first.');
            $settings = app(ReferralSettings::class)->effective();
            $services = collect($settings?->configuration['services'] ?? [])->where('enabled', true)->pluck('id')->all();
            $lines = [];
            foreach ($data['lines'] as $line) {
                abort_unless(in_array($line['service_id'], $services, true), 422, 'Choose a currently offered service for every support line.');
                abort_if($line['unit'] === 'other' && empty($line['unit_label']), 422, 'Name the original support unit.');
                abort_if($line['quantity'] !== null && BigDecimal::of($line['quantity'])->isZero(), 422, 'Use a positive quantity or leave an unknown quantity blank.');
                $lines[] = $this->pricing->snapshot($line);
            }
            foreach ($data['allocations'] as $allocation) {
                abort_unless(in_array($allocation['line_key'], array_column($lines, 'key'), true), 422, 'Link every allocation to a support line.');
                $case = ReferralFundingCase::query()->where('referral_id', $referral->id)->lockForUpdate()->findOrFail($allocation['funding_case_id']);
                abort_if($case->personal_income, 422, 'Personal income assistance cannot fund a provider support proposal.');
                $decision = ReferralFundingDecision::query()->where('funding_case_id', $case->id)->findOrFail($allocation['decision_id']);
                abort_unless(isset($decision->decision['lines'][$allocation['decision_line']]), 422, 'Select an existing authorization line.');
                abort_if(BigDecimal::of($allocation['quantity'])->isZero() || BigDecimal::of($allocation['support_quantity'])->isZero(), 422, 'Use positive funding and support quantities.');
            }
            $body = [...$data, 'lines' => $lines, 'totals' => $this->pricing->totals($lines), 'settings_version_id' => $settings?->id,
                'authority_version' => $referral->authority['review']['version'] ?? null, 'site_id' => $referral->site_id];
            $proposal = ReferralProposal::create(['referral_id' => $referral->id, 'version' => ($previous?->version ?? 0) + 1,
                'proposal' => $body, 'authored_by' => $actor->id, 'authored_at' => now()]);
            app(ReferralAssessment::class)->invalidateReadiness($referral, $actor, 'support proposal');
            $this->command->record($referral, $actor, 'proposal.revised', 'funding', ['proposal_id' => $proposal->id, 'proposal_version' => $proposal->version,
                'previous_proposal_id' => $previous?->id, 'title' => $data['title'], 'reason' => $data['reason']]);

            return $proposal;
        });
    }

    public function accept(User $actor, int $id, int $version, int $proposalId, array $input): void
    {
        $data = Validator::make($input, [
            'kind' => ['required', Rule::in(['person_acceptance', 'payer_acceptance'])], 'funding_case_id' => 'present|nullable|integer|min:1',
            'result' => ['required', Rule::in(['accepted', 'declined', 'withdrawn'])], 'signatory' => 'required|string|min:3|max:200',
            'capacity' => ['required', Rule::in(['person', 'authorized_representative', 'payer'])], 'authority_note' => 'required|string|min:5|max:2000',
            'participation' => 'required|string|min:5|max:2000', 'occurred_at' => 'required|date', 'note' => 'required|string|min:5|max:4000',
            'evidence_ids' => 'present|array|max:40', 'evidence_ids.*' => 'integer|min:1|distinct',
        ])->validate();
        $this->command->run($actor, $id, $version, 'proposals.manage', function (CareReferral $referral, User $actor) use ($proposalId, $data): void {
            $this->editable($referral);
            $proposal = $this->current($referral, $proposalId);
            $at = ReferralDates::parse($data['occurred_at'], 'occurred_at');
            $payer = null;
            abort_if($at->isFuture() || $at->lt($proposal->authored_at->startOfMinute()), 422, 'Use the actual response time, after this proposal version was prepared.');
            if ($data['kind'] === 'person_acceptance') {
                abort_unless($data['funding_case_id'] === null && $data['capacity'] !== 'payer', 422, 'Record the person’s response separately from a private payer’s response.');
                if ($data['result'] === 'accepted') {
                    abort_unless(ReferralAssessment::scope($referral, 'service'), 422, 'Review current authority for the proposed service before recording acceptance.');
                }
                if ($data['result'] === 'accepted' && $data['capacity'] === 'authorized_representative') {
                    abort_unless(($referral->authority['review']['basis'] ?? null) === 'representative' && ! empty($referral->authority['review']['representative']), 422, 'Review this representative’s authority for the proposed service before recording acceptance.');
                }
            } else {
                $case = ReferralFundingCase::query()->where('referral_id', $referral->id)->where('pathway', 'private')->lockForUpdate()->findOrFail($data['funding_case_id']);
                abort_unless($data['capacity'] === 'payer' && in_array((int) $case->id, array_map('intval', array_column($proposal->proposal['allocations'], 'funding_case_id')), true), 422, 'Select a private payer used by this proposal.');
                abort_if(empty($case->details['roles']['invoice_payer']), 422, 'Identify the private payer before recording their response.');
                $payer = $case->details['roles']['invoice_payer'];
            }
            if ($data['result'] === 'accepted') {
                abort_if($data['evidence_ids'] === [], 422, 'Link evidence of acceptance for this exact proposal version.');
            }
            app(ReferralFunding::class)->evidence($referral, $data['evidence_ids'], true);
            $details = [...$data, 'occurred_at' => $at->toISOString(), 'authority_version' => $referral->authority['review']['version'] ?? null, 'payer_snapshot' => $payer];
            $review = ReferralProposalReview::create(['proposal_id' => $proposal->id, 'kind' => $data['kind'], 'funding_case_id' => $data['funding_case_id'],
                'result' => $data['result'], 'details' => $details, 'recorded_by' => $actor->id, 'recorded_at' => now()]);
            app(ReferralAssessment::class)->invalidateReadiness($referral, $actor, 'proposal acceptance');
            $this->command->record($referral, $actor, 'proposal.acceptance_recorded', 'funding', ['proposal_id' => $proposal->id, 'review_id' => $review->id, 'proposal_version' => $proposal->version, 'kind' => $data['kind'], 'result' => $data['result'], 'summary' => $data['note']]);
        });
    }

    public function approve(User $actor, int $id, int $version, int $proposalId, array $input): void
    {
        $data = Validator::make($input, [
            'result' => ['required', Rule::in(['approved', 'conditional', 'rejected'])], 'note' => 'required|string|min:5|max:4000',
            'conditions' => 'required|string|min:5|max:4000', 'review_due_at' => 'required|date',
        ])->validate();
        $this->command->run($actor, $id, $version, 'proposals.approve', function (CareReferral $referral, User $actor, CurrentAuthorizationReads $reads) use ($proposalId, $data): void {
            $this->editable($referral);
            $proposal = $this->current($referral, $proposalId);
            abort_if((int) $proposal->authored_by === (int) $actor->id, 422, 'A separate authorized colleague must approve the support proposal.');
            $due = ReferralDates::parse($data['review_due_at'], 'review_due_at');
            abort_if($due->isPast(), 422, 'Choose a future internal approval review date.');
            $coverage = $this->coverage->inspect($referral, $proposal->proposal);
            $delegation = [];
            if ($data['result'] !== 'rejected') {
                FinTaxRate::query()->whereIn('id', array_filter(array_column($proposal->proposal['lines'], 'tax_rate_id')))->orderBy('id')->lockForUpdate()->get();
                foreach ($proposal->proposal['lines'] as $line) {
                    abort_unless($line['estimate']['known'] && $this->pricing->taxCurrent($line, $reads), 422, 'Resolve unknown prices and current canonical tax treatment before internal approval.');
                }
                $delegation = $this->delegation($actor, $proposal->proposal['totals'], $reads);
                abort_if($data['result'] === 'approved' && ! $coverage['usable'], 422, 'Funding gaps remain. Record a conditional decision or resolve the current coverage issues. Conditional approval cannot start services.');
            }
            $review = ReferralProposalReview::create(['proposal_id' => $proposal->id, 'kind' => 'internal_approval', 'result' => $data['result'],
                'details' => [...$data, 'review_due_at' => $due->toISOString(), 'prerequisite_token' => $this->token($referral, $proposal, $reads), 'delegation' => $delegation, 'coverage' => $coverage],
                'recorded_by' => $actor->id, 'recorded_at' => now()]);
            app(ReferralAssessment::class)->invalidateReadiness($referral, $actor, 'internal proposal approval');
            $this->command->record($referral, $actor, 'proposal.approval_recorded', 'funding', ['proposal_id' => $proposal->id, 'review_id' => $review->id, 'proposal_version' => $proposal->version, 'result' => $data['result'], 'summary' => $data['note']]);
        });
    }

    public function latest(CareReferral $referral): ?ReferralProposal
    {
        return ReferralProposal::query()->where('referral_id', $referral->id)->orderByDesc('version')->first();
    }

    public function current(CareReferral $referral, int $id): ReferralProposal
    {
        $proposal = ReferralProposal::query()->where('referral_id', $referral->id)->findOrFail($id);
        abort_unless($this->latest($referral)?->id === $proposal->id, 409, 'This proposal has been replaced. Review the current version.');

        return $proposal;
    }

    /** Approval binds the actual prerequisite state, not unrelated follow-up edits. */
    public function token(CareReferral $referral, ReferralProposal $proposal, ?CurrentAuthorizationReads $reads = null): string
    {
        $sources = [];
        foreach (array_unique(array_column($proposal->proposal['allocations'], 'funding_case_id')) as $caseId) {
            $case = ReferralFundingCase::query()->where('referral_id', $referral->id)->find($caseId);
            // Completing assistance does not cancel a verified external award. Withdrawal does.
            $sources[] = [$caseId, $case?->details['latest_decision_id'] ?? null, $case?->details['verification'] ?? null, $case?->details['roles'] ?? null, $case?->status === 'withdrawn'];
        }
        $settings = app(ReferralSettings::class)->effective($reads);

        $internalService = app(ReferralInternalFunding::class);
        $arrangement = $internalService->arrangement($proposal);
        $internal = $arrangement ? [$arrangement->id, $internalService->review($arrangement)?->id] : null;

        return hash('sha256', json_encode(['proposal' => $proposal->id, 'authority' => $referral->authority, 'assessment' => $referral->assessment,
            'site' => $referral->site_id, 'service' => $referral->requested_service, 'sources' => $sources, ...($internal ? ['internal_arrangement' => $internal] : []), 'approval_policy' => $settings?->configuration['proposals'],
            'delivery_types' => ReferralServiceSettings::contextTypes($settings?->configuration ?? [], $referral->requested_service)], JSON_THROW_ON_ERROR));
    }

    public function delegation(User $actor, array $totals, CurrentAuthorizationReads $reads): array
    {
        $settings = app(ReferralSettings::class)->effective($reads);
        $policy = $settings?->configuration['proposals'] ?? [];
        $roles = $reads->query($actor->roles()->getQuery())->pluck('roles.id')->map(fn ($id) => (int) $id)->all();
        $limits = $policy['approval_limits'] ?? [];
        // The original mockup limit is specifically a weekly NZD delegation, never an FX/period conversion.
        $limits[] = ['currency' => 'NZD', 'period' => 'week', 'role_id' => null, 'limit_cents' => (int) ($policy['approval_limit_cents'] ?? 0)];
        $used = [];
        foreach ($totals as $total) {
            abort_unless($total['known'], 422, 'Unknown costs cannot pass internal approval.');
            $matching = array_values(array_filter($limits, fn ($limit) => $limit['currency'] === $total['currency'] && $limit['period'] === $total['period'] && ($limit['role_id'] === null || in_array((int) $limit['role_id'], $roles, true))));
            $cap = $matching ? max(array_column($matching, 'limit_cents')) : -1;
            abort_if($cap < 0 || BigDecimal::of($total['known_gross'])->multipliedBy('100')->isGreaterThan($cap), 403, 'This proposal exceeds your configured approval delegation for '.$total['currency'].' per '.$total['period'].'. Arrange review by an authorized delegate.');
            $used[] = ['currency' => $total['currency'], 'period' => $total['period'], 'amount' => $total['known_gross'], 'limit_cents' => $cap, 'settings_version_id' => $settings?->id];
        }

        return $used;
    }

    private function editable(CareReferral $referral): void
    {
        abort_if($referral->stage === 'closed' || $referral->general_enquiry, 422, 'Complete the person’s intake and keep the referral open before preparing or reviewing support terms.');
    }
}
