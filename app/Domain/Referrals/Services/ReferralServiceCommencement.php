<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralConversion;
use App\Domain\Referrals\Models\ReferralProposal;
use App\Domain\Referrals\Models\ReferralServiceStart;
use App\Domain\Referrals\Models\ReferralStartDecision;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Models\Client;
use App\Models\ServiceAgreement;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Services\CurrentAuthorizationReads;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

final class ReferralServiceCommencement
{
    public const PERMISSIONS = [...ReferralConversionService::CANONICAL_PERMISSIONS, 'service_agreements.viewAny', 'care_plans.viewAny'];

    public function __construct(private readonly ReferralCommand $command, private readonly ReferralStartReadiness $readiness) {}

    public static function selectionRules(): array
    {
        return [
            'proposal_id' => 'required|integer|min:1', 'service_agreement_id' => 'required|integer|min:1', 'care_plan_id' => 'required|integer|min:1',
            'agreement_fingerprint' => 'required|string|size:64', 'care_plan_digest' => 'required|string|size:64',
            'agreed_date' => 'required|date_format:Y-m-d', 'staff_ids' => 'required|array|min:1|max:30', 'staff_ids.*' => 'required|integer|min:1|distinct',
            'agreement_lines' => 'required|array|min:1|max:30', 'agreement_lines.*' => 'required|array:line_key,line_item_id',
            'agreement_lines.*.line_key' => 'required|string|max:64|distinct', 'agreement_lines.*.line_item_id' => 'required|integer|min:1',
            'agreement_evidence_ids' => 'required|array|min:1|max:40', 'agreement_evidence_ids.*' => 'required|integer|min:1|distinct',
            'signatory' => 'required|string|min:3|max:200', 'signed_at' => 'required|date',
            'agreement_review' => 'required|string|min:10|max:4000', 'care_safety_review' => 'required|string|min:10|max:4000', 'staffing_review' => 'required|string|min:10|max:4000',
            'acknowledge_current_terms' => 'accepted', 'acknowledge_separate_commencement' => 'accepted',
        ];
    }

    public function schedule(User $actor, int $id, int $version, array $input): array
    {
        $data = Validator::make($input, ['submission_key' => 'required|uuid', ...self::selectionRules()])->validate();

        return $this->change($actor, $id, $version, 'schedule', $data, function (CareReferral $referral, User $actor, CurrentAuthorizationReads $reads, ReferralConversion $conversion, Client $person) use ($data): ReferralServiceStart {
            abort_if($data['agreed_date'] < today('Pacific/Auckland')->toDateString(), 422, 'Approve a start date today or in the future. Do not backdate service approval.');
            $proposal = ReferralProposal::query()->where('referral_id', $referral->id)->findOrFail($data['proposal_id']);
            $agreement = ServiceAgreement::query()->where('client_id', $person->id)->lockForUpdate()->findOrFail($data['service_agreement_id']);
            abort_if((int) $proposal->authored_by === (int) $actor->id || (int) $agreement->created_by === (int) $actor->id, 403, 'A manager independent of the proposal and agreement author must approve commencement.');
            $start = ReferralServiceStart::query()->where('referral_id', $referral->id)->lockForUpdate()->first();
            abort_if($start?->commenced_at !== null, 422, 'This service has already commenced. Use a reviewed service change instead of another start.');
            $other = ReferralServiceStart::query()->where('service_agreement_id', $agreement->id)->where('referral_id', '!=', $referral->id)
                ->whereIn('status', ['scheduled', 'commenced', 'review_required'])->lockForUpdate()->get();
            foreach ($other as $commitment) {
                $period = $commitment->handover['proposal_period'] ?? null;
                abort_if(! $period || ($period['starts_on'] <= $proposal->proposal['ends_on'] && $period['ends_on'] >= $proposal->proposal['starts_on']), 422,
                    'This agreement already supports an overlapping referral commitment. Ask finance to reconcile the combined commitment before approving another start.');
            }
            $readiness = $this->readiness->inspect($referral, $conversion, $person, $data, $reads);
            foreach (['agreement_fingerprint', 'care_plan_digest'] as $key) {
                abort_unless(hash_equals($data[$key], $readiness[$key]), 409, 'The agreement or care plan changed since your review. Reload and review the current terms before approving.');
            }
            abort_unless($readiness['ready'], 422, implode(' ', $readiness['issues']));
            $handover = ['selection' => Arr::except($data, ['submission_key']), ...Arr::only($readiness, ['prerequisite_token', 'agreement_fingerprint', 'care_plan_digest', 'staff_ids', 'agreement_mapping']),
                'proposal_period' => Arr::only($proposal->proposal, ['starts_on', 'ends_on']), 'approved_at' => now()->toISOString(), 'review_required' => false,
                'workflow_scope' => $conversion->receipt['workflow_scope'], 'site_id' => $conversion->receipt['site_id'], 'service_context_id' => $conversion->receipt['service_context_id']];
            $fields = ['referral_id' => $referral->id, 'client_id' => $person->id, 'proposal_id' => $proposal->id, 'service_agreement_id' => $agreement->id,
                'approved_by' => $actor->id, 'status' => 'scheduled', 'agreed_date' => $data['agreed_date'], 'handover' => $handover, 'lock_version' => ($start?->lock_version ?? 0) + 1];
            if ($start) {
                $this->finishStartAction($start, $actor, 'Replaced by a reviewed service-start decision');
                $start->update($fields);
            } else {
                $start = ReferralServiceStart::create($fields);
            }
            $work = ReferralWorkItem::create(['referral_id' => $referral->id, 'owner_id' => $actor->id, 'kind' => 'internal_review', 'audience' => 'assessment',
                'due_at' => CarbonImmutable::parse($data['agreed_date'].' 09:00', 'Pacific/Auckland'), 'status' => 'open',
                'details' => ['title' => 'Confirm actual service commencement', 'description' => 'Recheck the approved agreement, current care readiness and staffing before recording the actual service start.']]);
            $start->update(['handover' => [...$handover, 'commencement_work_item_id' => $work->id]]);

            return $start;
        });
    }

    public function commence(User $actor, int $id, int $version, array $input): array
    {
        $data = Validator::make($input, ['submission_key' => 'required|uuid', 'commenced_at' => 'required|date', 'note' => 'required|string|min:10|max:4000', 'acknowledge_actual_start' => 'accepted'])->validate();

        return $this->change($actor, $id, $version, 'commence', $data, function (CareReferral $referral, User $actor, CurrentAuthorizationReads $reads, ReferralConversion $conversion, Client $person) use ($data): ReferralServiceStart {
            $start = ReferralServiceStart::query()->where('referral_id', $referral->id)->lockForUpdate()->firstOrFail();
            abort_unless($start->status === 'scheduled' && ! $start->commenced_at && ! ($start->handover['review_required'] ?? true), 422, 'A current scheduled approval is required before actual commencement.');
            $at = ReferralDates::parse($data['commenced_at'], 'commenced_at');
            abort_if($at->isFuture() || $at->setTimezone('Pacific/Auckland')->toDateString() < $start->agreed_date->toDateString()
                || $at->lt(CarbonImmutable::parse($start->handover['approved_at'])), 422, 'Record an actual start after approval and no earlier than the agreed date.');
            $readiness = $this->readiness->inspect($referral, $conversion, $person, [...$start->handover['selection'], 'agreed_date' => $at->setTimezone('Pacific/Auckland')->toDateString()], $reads);
            abort_unless($readiness['ready'], 422, implode(' ', $readiness['issues']));
            foreach (['prerequisite_token', 'agreement_fingerprint', 'care_plan_digest'] as $key) {
                abort_unless(hash_equals($start->handover[$key] ?? '', $readiness[$key]), 409, 'The approved terms or care evidence changed. Review and approve the start again.');
            }
            $start->update(['status' => 'commenced', 'commenced_at' => $at, 'lock_version' => $start->lock_version + 1,
                'handover' => [...$start->handover, 'commenced_by' => $actor->id, 'commencement_note' => $data['note']]]);
            if ($person->status !== 'active') {
                abort_unless($person->status === 'onboarding', 422, 'Review the person’s current status before starting support.');
                $person->update(['status' => 'active', 'service_start_date' => $at->setTimezone('Pacific/Auckland')->toDateString()]);
            }
            $this->finishStartAction($start, $actor, 'Actual service commencement recorded');

            return $start;
        });
    }

    public function cancel(User $actor, int $id, int $version, array $input): array
    {
        $data = Validator::make($input, ['submission_key' => 'required|uuid', 'reason' => 'required|string|min:10|max:4000'])->validate();

        return $this->change($actor, $id, $version, 'cancel', $data, function (CareReferral $referral, User $actor) use ($data): ReferralServiceStart {
            $start = ReferralServiceStart::query()->where('referral_id', $referral->id)->lockForUpdate()->firstOrFail();
            abort_if($start->commenced_at || $start->status === 'cancelled', 422, 'Only a pending service start can be cancelled here. Existing active support is preserved.');
            $start->update(['status' => 'cancelled', 'approved_by' => null, 'lock_version' => $start->lock_version + 1, 'handover' => [...$start->handover, 'cancellation_reason' => $data['reason']]]);
            $this->finishStartAction($start, $actor, 'Scheduled start cancelled after review');

            return $start;
        });
    }

    private function change(User $actor, int $id, int $version, string $operation, array $data, callable $mutation): array
    {
        $intent = hash('sha256', json_encode(Arr::sortRecursive([$operation, $data]), JSON_THROW_ON_ERROR));
        $replay = function (CareReferral $referral, User $actor, CurrentAuthorizationReads $reads) use ($data, $intent): ?array {
            $this->context($actor, $referral, $reads);
            $existing = ReferralStartDecision::query()->where('referral_id', $referral->id)->where('submission_key', $data['submission_key'])->lockForUpdate()->first();
            if (! $existing) {
                return null;
            }
            abort_unless(hash_equals($existing->intent_hash, $intent), 409, 'That request already recorded a different start decision. Refresh before continuing.');

            return $existing->decision['receipt'];
        };

        return $this->command->run($actor, $id, $version, 'commence', function (CareReferral $referral, User $actor, CurrentAuthorizationReads $reads) use ($data, $intent, $operation, $mutation): array {
            [$conversion, $person] = $this->context($actor, $referral, $reads);
            $start = $mutation($referral, $actor, $reads, $conversion, $person);
            $receipt = ['saved' => true, 'status' => $start->status, 'agreed_date' => $start->agreed_date->toDateString(), 'commenced_at' => $start->commenced_at?->toISOString()];
            $decision = ReferralStartDecision::create(['referral_id' => $referral->id, 'service_start_id' => $start->id, 'submission_key' => $data['submission_key'], 'intent_hash' => $intent, 'operation' => $operation,
                'decision' => ['request' => $data, 'handover' => $start->handover, 'receipt' => $receipt], 'recorded_by' => $actor->id, 'recorded_at' => now()]);
            $this->command->record($referral, $actor, 'service_start.'.$operation, 'assessment', ['decision_id' => $decision->id, ...$receipt]);

            return $receipt;
        }, self::PERMISSIONS, $replay);
    }

    private function context(User $actor, CareReferral $referral, CurrentAuthorizationReads $reads): array
    {
        abort_unless(app(ReferralAccess::class)->can($actor, 'assessment.view') && app(ReferralAccess::class)->can($actor, 'funding.view')
            && $actor->canDo('service_agreements.viewAny') && $actor->canDo('care_plans.viewAny'), 403);
        $conversion = ReferralConversion::query()->where('referral_id', $referral->id)->lockForUpdate()->firstOrFail();
        $person = Client::query()->lockForUpdate()->findOrFail($conversion->client_id);
        abort_unless(app(ClientPolicy::class)->viewFromCurrentEvidence($actor, $person, $reads), 404);
        abort_unless(app(ClientPolicy::class)->updateFromCurrentEvidence($actor, $person, $reads), 403);

        return [$conversion, $person];
    }

    /** Same completion evidence as other finished follow-ups (ReferralCasework::finish). */
    private function finishStartAction(ReferralServiceStart $start, User $actor, string $outcome): void
    {
        $work = ReferralWorkItem::query()->where('referral_id', $start->referral_id)->whereKey($start->handover['commencement_work_item_id'] ?? 0)->where('status', 'open')->lockForUpdate()->first();
        if ($work) {
            $work->update(['status' => 'completed', 'lock_version' => $work->lock_version + 1, 'details' => [...$work->details, 'outcome_note' => $outcome, 'finished_at' => now()->toISOString(), 'finished_by' => $actor->id]]);
        }
    }
}
