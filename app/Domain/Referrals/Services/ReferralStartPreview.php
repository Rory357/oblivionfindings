<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralConversion;
use App\Domain\Referrals\Models\ReferralServiceStart;
use App\Models\CarePlan;
use App\Models\Client;
use App\Models\ClientOnboardingWorkflow;
use App\Models\ServiceAgreement;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Services\Clients\ClientOnboardingAccess;
use App\Services\Clients\ClientWorkerEligibility;
use App\Services\Operations\CarePlanAttestationService;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/** A bounded review projection. Commands independently recheck current evidence. */
final class ReferralStartPreview
{
    public function context(User $actor, CareReferral $referral): array
    {
        $access = app(ReferralAccess::class);
        $access->authorize($actor, $referral, 'commence');
        abort_unless($access->can($actor, 'assessment.view') && $access->can($actor, 'funding.view')
            && $actor->canDo('care_plans.viewAny') && $actor->canDo('service_agreements.viewAny'), 403);
        $conversion = ReferralConversion::query()->where('referral_id', $referral->id)->firstOrFail();
        $person = Client::query()->findOrFail($conversion->client_id);
        abort_unless(app(ClientPolicy::class)->view($actor, $person), 404);
        abort_unless(app(ClientPolicy::class)->update($actor, $person), 403);

        return [$conversion, $person];
    }

    public function inspect(User $actor, CareReferral $referral): array
    {
        [$conversion, $person] = $this->context($actor, $referral);
        $proposal = app(ReferralProposals::class)->latest($referral);
        $agreements = ServiceAgreement::query()->where('client_id', $person->id)->orderByDesc('id')->limit(51)->get();
        $plans = CarePlan::query()->where('client_id', $person->id)->whereIn('status', ['active', 'review', 'draft'])->orderByDesc('id')->limit(51)->get();
        $staff = app(ClientWorkerEligibility::class)->query(new Client(['site_id' => $referral->site_id]))
            ->whereIn('users.id', $person->supportWorkers()->select('users.id'))->orderBy('name')->limit(101)->get(['users.id', 'users.name']);
        $start = ReferralServiceStart::query()->where('referral_id', $referral->id)->first();
        $workflow = ClientOnboardingWorkflow::query()->where('client_id', $person->id)->find($conversion->workflow_id);

        return ['version' => $referral->lock_version, 'checked_at' => now()->toISOString(),
            'person' => ['id' => $person->id, 'name' => $person->full_name, 'status' => $person->status],
            'workflow' => ['status' => $workflow?->status, 'url' => $workflow && app(ClientOnboardingAccess::class)->canViewWorkflows($actor) ? '/operations/onboarding/'.$workflow->id : null],
            'proposal' => $proposal ? ['id' => $proposal->id, 'version' => $proposal->version, 'title' => $proposal->proposal['title'] ?? 'Support proposal',
                'starts_on' => $proposal->proposal['starts_on'], 'ends_on' => $proposal->proposal['ends_on'],
                'lines' => array_map(fn ($line) => Arr::only($line, ['key', 'description', 'quantity', 'unit', 'unit_label', 'period', 'currency', 'unit_rate', 'price_basis']), $proposal->proposal['lines'])] : null,
            'agreements' => $agreements->take(50)->map(fn ($agreement) => ['id' => $agreement->id, 'title' => $agreement->title, 'status' => $agreement->status,
                'starts_on' => $agreement->starts_at?->toDateString(), 'ends_on' => $agreement->ends_at?->toDateString(), 'total_budget' => $agreement->total_budget,
                'fingerprint' => app(ReferralAgreementTerms::class)->fingerprint($agreement), 'gst_inclusive' => $agreement->gst_inclusive,
                'lines' => $agreement->lineItems()->orderBy('id')->get(['id', 'description', 'unit', 'unit_price', 'quantity', 'budget_allocated', 'budget_used'])->toArray()])->values()->all(),
            'care_plans' => $plans->take(50)->map(fn ($plan) => ['id' => $plan->id, 'title' => $plan->title, 'status' => $plan->status, 'version' => $plan->version,
                'next_review_on' => $plan->next_review_at?->toDateString(), 'digest' => $this->careDigest($plan)])->values()->all(),
            'staff' => $staff->take(100)->toArray(), 'more_options' => $agreements->count() > 50 || $plans->count() > 50 || $staff->count() > 100,
            'start' => $start ? ['status' => $start->status, 'agreed_date' => $start->agreed_date?->toDateString(), 'commenced_at' => $start->commenced_at?->toISOString(),
                'approved_at' => $start->handover['approved_at'] ?? null, 'review_required' => $start->handover['review_required'] ?? false] : null];
    }

    private function careDigest(CarePlan $plan): ?string
    {
        try {
            return app(CarePlanAttestationService::class)->currentDigest($plan);
        } catch (ValidationException) {
            // Legacy plans without a governed agreement policy remain visible for repair.
            return null;
        }
    }
}
