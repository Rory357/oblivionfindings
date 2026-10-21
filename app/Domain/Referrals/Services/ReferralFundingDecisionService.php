<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralEvidence;
use App\Domain\Referrals\Models\ReferralFundingCase;
use App\Domain\Referrals\Models\ReferralFundingDecision;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ReferralFundingDecisionService
{
    public function __construct(private readonly ReferralCommand $command, private readonly ReferralFunding $funding, private readonly ReferralAssessment $assessment) {}

    public function record(User $actor, int $id, int $version, int $caseId, array $input): ReferralFundingDecision
    {
        $data = Validator::make($input, [
            'case_version' => 'required|integer|min:1', 'outcome' => ['required', Rule::in(['approved', 'partially_approved', 'conditional', 'declined', 'private_confirmed'])],
            'decision_maker' => 'required|string|min:3|max:300', 'external_reference' => 'present|nullable|string|max:160',
            'decision_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Pacific/Auckland')->toDateString()],
            'evidence_ids' => 'required|array|min:1|max:40', 'evidence_ids.*' => 'integer|min:1|distinct',
            'existing_authorization' => 'required|boolean', 'conditions' => 'required|string|min:3|max:4000',
            'valid_from' => 'present|nullable|date_format:Y-m-d', 'valid_until' => 'present|nullable|date_format:Y-m-d|after_or_equal:valid_from',
            'review_date' => 'present|nullable|date_format:Y-m-d', 'deadline_source' => 'present|nullable|string|max:1000',
            'lines' => 'present|array|max:30', 'lines.*' => 'required|array:service_id,quantity,provider_quantity,unit,unit_label,period,currency,restrictions',
            'lines.*.service_id' => 'present|nullable|string|max:64', 'lines.*.quantity' => ['present', 'nullable', 'regex:/^\d{1,10}(\.\d{1,4})?$/'],
            'lines.*.provider_quantity' => ['present', 'nullable', 'regex:/^\d{1,10}(\.\d{1,4})?$/'],
            'lines.*.unit' => ['required', Rule::in(['money', 'hours', 'days', 'visits', 'other'])], 'lines.*.unit_label' => 'present|nullable|string|max:80',
            'lines.*.period' => ['required', Rule::in(['week', 'fortnight', 'month', 'year', 'total'])],
            'lines.*.currency' => 'required|string|size:3|regex:/^[A-Z]{3}$/', 'lines.*.restrictions' => 'present|nullable|string|max:2000',
            'reason' => 'required|string|min:5|max:2000', 'next_action' => 'required|string|max:500', 'next_due_at' => 'required|date',
        ])->validate();

        return $this->command->run($actor, $id, $version, 'funding.manage', function (CareReferral $referral, User $actor, CurrentAuthorizationReads $reads) use ($caseId, $data): ReferralFundingDecision {
            abort_if($referral->stage === 'closed', 422, 'Reopen the referral before recording new funding work.');
            $case = $this->funding->case($referral, $caseId, (int) $data['case_version']);
            abort_if(in_array($case->status, ['withdrawn', 'closed'], true), 422, 'Open a linked attempt before recording a new decision.');
            abort_unless($data['existing_authorization'] || ! empty($case->details['last_submission']), 422, 'Record the external submission or identify this as an existing authorization.');
            $private = ReferralFunding::privatePayer($case);
            abort_if($private && ! in_array($data['outcome'], ['private_confirmed', 'declined'], true), 422, 'Use private arrangement confirmed for a private payer; this is not government funding approval.');
            abort_if(! $private && $data['outcome'] === 'private_confirmed', 422, 'Private arrangement confirmation belongs to a private-payment case.');
            $this->funding->evidence($referral, $data['evidence_ids'], false);
            if ($data['outcome'] !== 'declined') {
                abort_unless($data['lines'] !== [], 422, 'Record the awarded support lines, leaving unknown quantities blank.');
            }
            foreach ($data['lines'] as $line) {
                if ($case->personal_income) {
                    abort_unless($line['service_id'] === null && $line['provider_quantity'] === null, 422, 'Personal income assistance cannot allocate funding to provider services.');
                } else {
                    abort_unless(in_array($line['service_id'], $case->details['guide']['service_ids'] ?? [], true), 422, 'The decision line must match a service in this case’s pathway configuration.');
                }
                abort_if($line['unit'] === 'other' && empty($line['unit_label']), 422, 'Name the original authorization unit.');
                abort_if($line['quantity'] !== null && $line['provider_quantity'] !== null && BigDecimal::of($line['provider_quantity'])->compareTo($line['quantity']) > 0, 422, 'Provider allocation cannot exceed the original award.');
            }
            $number = (int) ReferralFundingDecision::query()->where('funding_case_id', $case->id)->max('version') + 1;
            $decision = ReferralFundingDecision::query()->create(['funding_case_id' => $case->id, 'version' => $number, 'outcome' => $data['outcome'],
                'decision' => $data, 'recorded_by' => $actor->id, 'recorded_at' => now()]);
            $work = $this->funding->next($referral, $case, $actor, $reads, $data['next_action'], $data['next_due_at']);
            $previousVerification = $case->details['verification'] ?? null;
            $case->update(['status' => 'decision_received', 'lock_version' => $case->lock_version + 1,
                'details' => [...$case->details, 'latest_decision_id' => $decision->id, 'primary_work_item_id' => $work->id,
                    'verification' => ['decision_id' => $decision->id, 'status' => 'unverified']]]);
            $this->assessment->invalidateReadiness($referral, $actor, 'funding decision');
            $this->command->record($referral, $actor, 'funding.decision_recorded', 'funding', ['case_id' => $case->id, 'decision_id' => $decision->id, 'decision_version' => $number,
                'outcome' => $decision->outcome, 'reason' => $data['reason'], 'superseded_verification' => $previousVerification]);

            return $decision;
        });
    }

    public function verify(User $actor, int $id, int $version, int $caseId, array $input): void
    {
        $data = Validator::make($input, [
            'case_version' => 'required|integer|min:1', 'decision_id' => 'required|integer|min:1',
            'status' => ['required', Rule::in(['verified', 'needs_clarification'])], 'conditions_resolved' => 'required|boolean',
            'provider_compatible' => 'required|boolean', 'scope_note' => 'required|string|min:5|max:4000',
            'evidence_ids' => 'required|array|min:1|max:40', 'evidence_ids.*' => 'integer|min:1|distinct',
            'review_due_at' => 'required|date', 'next_action' => 'required|string|max:500', 'next_due_at' => 'required|date',
        ])->validate();
        $this->command->run($actor, $id, $version, 'funding.verify', function (CareReferral $referral, User $actor, CurrentAuthorizationReads $reads) use ($caseId, $data): void {
            abort_if($referral->stage === 'closed', 422, 'Reopen the referral before recording new funding verification.');
            $case = $this->funding->case($referral, $caseId, (int) $data['case_version']);
            $decision = ReferralFundingDecision::query()->where('funding_case_id', $case->id)->findOrFail($data['decision_id']);
            abort_unless(($case->details['latest_decision_id'] ?? null) === $decision->id, 409, 'Verify the latest external decision; historical decisions cannot become current again.');
            abort_if((int) $decision->recorded_by === (int) $actor->id || (int) $case->navigator_id === (int) $actor->id, 422, 'A separate authorized reviewer must verify this decision.');
            abort_if(in_array($case->status, ['withdrawn', 'closed'], true), 422, 'Complete verification before closing assistance.');
            $this->funding->evidence($referral, array_values(array_unique([...$decision->decision['evidence_ids'], ...$data['evidence_ids']])), true);
            $reviewDue = ReferralDates::parse($data['review_due_at'], 'review_due_at');
            abort_if($reviewDue->isPast(), 422, 'Choose a future verification review date.');
            if ($data['status'] === 'verified' && ! $case->personal_income && $decision->outcome !== 'declined') {
                $terms = $decision->decision;
                abort_if(ReferralFunding::privatePayer($case) && empty(trim($case->details['roles']['invoice_payer'] ?? '')), 422, 'Identify the private payer before verifying their arrangement.');
                abort_unless(! empty($terms['valid_from']) && ! empty($terms['valid_until']), 422, 'Clarify the authorization’s validity period before verification.');
                foreach ($terms['lines'] as $line) {
                    abort_if($line['quantity'] === null || $line['provider_quantity'] === null, 422, 'Clarify award quantities and provider allocation before verification.');
                }
            }
            $previous = $case->details['verification'] ?? null;
            $verification = [...$data, 'review_due_at' => $reviewDue->toISOString(), 'verified_by' => $actor->id, 'verified_at' => now()->toISOString()];
            $work = $this->funding->next($referral, $case, $actor, $reads, $data['next_action'], $data['next_due_at']);
            $case->update(['lock_version' => $case->lock_version + 1, 'details' => [...$case->details, 'verification' => $verification, 'primary_work_item_id' => $work->id]]);
            $this->assessment->invalidateReadiness($referral, $actor, 'funding verification');
            $this->command->record($referral, $actor, 'funding.verification_recorded', 'funding', ['case_id' => $case->id, 'decision_id' => $decision->id, 'previous' => $previous, 'current' => $verification, 'summary' => $data['scope_note']]);
        });
    }

    /** No unit conversion, invoices, budget consumption or eligibility inference. */
    public function availability(ReferralFundingCase $case, ?string $serviceId = null, ?string $date = null): array
    {
        $date ??= now('Pacific/Auckland')->toDateString();
        $checkAt = CarbonImmutable::parse($date, 'Pacific/Auckland')->startOfDay()->max(CarbonImmutable::now());
        $issues = [];
        if ($case->personal_income) {
            return ['usable' => false, 'issues' => ['Personal income assistance is not a provider service budget.'], 'lines' => []];
        }
        $decision = ReferralFundingDecision::query()->where('funding_case_id', $case->id)->find($case->details['latest_decision_id'] ?? 0);
        if (! $decision) {
            return ['usable' => false, 'issues' => ['No external decision recorded.'], 'lines' => []];
        }
        if ($case->status === 'withdrawn') {
            $issues[] = 'Assistance was withdrawn; review the arrangement before use.';
        }
        if ($decision->outcome === 'declined') {
            $issues[] = 'The external decision declined support.';
        }
        $verification = $case->details['verification'] ?? [];
        if (($verification['status'] ?? '') !== 'verified' || ($verification['decision_id'] ?? null) !== $decision->id) {
            $issues[] = 'The current decision is not independently verified.';
        }
        if (empty($verification['review_due_at']) || CarbonImmutable::parse($verification['review_due_at'])->lte($checkAt)) {
            $issues[] = 'Funding verification needs review.';
        }
        if (! ($verification['conditions_resolved'] ?? false)) {
            $issues[] = 'Funding conditions remain unresolved.';
        }
        if (! ($verification['provider_compatible'] ?? false)) {
            $issues[] = 'Provider and purchasing scope have not been confirmed.';
        }
        $terms = $decision->decision;
        if (empty($terms['valid_from']) || empty($terms['valid_until']) || $date < $terms['valid_from'] || $date > $terms['valid_until']) {
            $issues[] = 'Funding is not valid on the proposed date.';
        }
        $evidenceIds = array_values(array_unique([...($terms['evidence_ids'] ?? []), ...($verification['evidence_ids'] ?? [])]));
        $evidence = ReferralEvidence::query()->where('referral_id', $case->referral_id)->where('audience', 'funding')->whereIn('id', $evidenceIds)->get();
        if ($evidenceIds === [] || $evidence->count() !== count($evidenceIds) || $evidence->contains(fn ($item) => $item->state !== 'available' || ! empty($item->metadata['superseded_by_id']) || app(ReferralEvidenceFiles::class)->bytes($item) === null || (! empty($item->metadata['expires_at']) && CarbonImmutable::parse($item->metadata['expires_at'])->lte($checkAt)))) {
            $issues[] = 'Funding evidence is unavailable or expired.';
        }
        $lines = array_values(array_filter($terms['lines'] ?? [], fn ($line) => $serviceId === null || $line['service_id'] === $serviceId));
        if ($lines === []) {
            $issues[] = 'No authorization line matches the requested service.';
        }
        foreach ($lines as $line) {
            if ($line['quantity'] === null || $line['provider_quantity'] === null || BigDecimal::of($line['provider_quantity'])->compareTo('0') <= 0) {
                $issues[] = 'Provider allocation is unknown or zero.';
            }
        }

        return ['usable' => $issues === [], 'issues' => array_values(array_unique($issues)), 'decision_id' => $decision->id, 'decision_version' => $decision->version, 'lines' => $lines];
    }
}
