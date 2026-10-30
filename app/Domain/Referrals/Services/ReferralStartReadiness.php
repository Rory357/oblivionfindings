<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralCareHandover;
use App\Domain\Referrals\Models\ReferralConversion;
use App\Domain\Referrals\Models\ReferralEvidence;
use App\Domain\Referrals\Models\ReferralProposal;
use App\Models\CarePlan;
use App\Models\CarePlanSignOff;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ClientOnboardingWorkflow;
use App\Models\ConsentType;
use App\Models\ServiceAgreement;
use App\Models\ServiceContext;
use App\Models\User;
use App\Services\Clients\ClientWorkerEligibility;
use App\Services\Consents\CurrentConsentEvidence;
use App\Services\ConsentValidationService;
use App\Services\CurrentAuthorizationReads;
use App\Services\Operations\CarePlanAttestationService;
use Carbon\CarbonImmutable;

/** Readiness is evaluated again at actual commencement, using the same canonical records. */
final class ReferralStartReadiness
{
    public function inspect(CareReferral $referral, ReferralConversion $conversion, Client $person, array $input, ?CurrentAuthorizationReads $reads = null): array
    {
        $issues = [];
        if (app(ReferralChecklists::class)->missing($referral, 'handover') > 0) {
            $issues[] = 'Complete the required referral preparation checklists before approving the start.';
        }
        if (app(ReferralForms::class)->missing($referral) > 0) {
            $issues[] = 'Complete the required referral form answers before approving the start.';
        }
        $date = CarbonImmutable::parse($input['agreed_date'], 'Pacific/Auckland')->startOfDay();
        $checkAt = $date->max(CarbonImmutable::now());
        $settings = app(ReferralSettings::class)->effective($reads);
        if (! collect($settings?->configuration['services'] ?? [])->contains(fn ($service) => $service['enabled'] && $service['id'] === $referral->requested_service)) {
            $issues[] = 'The requested service is no longer enabled for a new start.';
        }
        $proposal = ReferralProposal::query()->where('referral_id', $referral->id)->findOrFail($input['proposal_id']);
        $agreements = ServiceAgreement::query()->where('client_id', $person->id);
        $agreement = ($reads ? $agreements->lockForUpdate() : $agreements)->findOrFail($input['service_agreement_id']);
        $plans = CarePlan::query()->where('client_id', $person->id);
        $plan = ($reads ? $plans->lockForUpdate() : $plans)->findOrFail($input['care_plan_id']);
        $contextQuery = ReferralServiceSettings::contexts(ServiceContext::query()->availableToSite($referral->site_id)->where('is_active', true),
            $settings?->configuration ?? [], $referral->requested_service)->whereKey($conversion->receipt['service_context_id'] ?? 0);
        if (! ($reads ? $reads->query($contextQuery) : $contextQuery)->exists()) {
            $issues[] = 'The service setting must be active, available at this site and allowed for the requested service in published settings.';
        }
        if ($referral->stage !== 'converted' || (int) $person->id !== (int) $conversion->client_id
            || (int) $referral->site_id !== (int) ($conversion->receipt['site_id'] ?? 0)
            || ! in_array($person->status, ['active', 'onboarding'], true)) {
            $issues[] = 'Review the canonical person and service destination before starting.';
        }
        if (($conversion->receipt['workflow_scope'] ?? 'person') === 'person'
            && ((int) $person->site_id !== (int) $referral->site_id || (int) $person->service_context_id !== (int) ($conversion->receipt['service_context_id'] ?? 0))) {
            $issues[] = 'The person’s primary site or service setting differs from this handover. Use Open person to review their placement with the responsible manager, then refresh this review. Only update the profile if the handover destination is correct; changing the profile does not start support.';
        }
        $workflows = ClientOnboardingWorkflow::query()->where('client_id', $person->id)->whereKey($conversion->workflow_id);
        $workflow = ($reads ? $reads->query($workflows) : $workflows)->first();
        if (! $workflow || $workflow->status !== 'completed'
            || (int) $workflow->site_id !== (int) $referral->site_id
            || (int) $workflow->service_context_id !== (int) ($conversion->receipt['service_context_id'] ?? 0)) {
            $issues[] = 'Complete the onboarding workflow for this service and site before approving the start.';
        }
        $revisions = ReferralCareHandover::query()->where('conversion_id', $conversion->id)->orderByDesc('version');
        $packet = ($reads ? $reads->query($revisions) : $revisions)->first()?->snapshot ?? $conversion->receipt;
        if (($packet['assessment_version'] ?? null) !== ($referral->assessment['version'] ?? null)
            || ($packet['authority_version'] ?? null) !== ($referral->authority['review']['version'] ?? null)) {
            $issues[] = 'Publish the current reviewed care handover to the onboarding team.';
        }
        $types = ConsentType::query()->active()->mandatory();
        foreach (($reads ? $reads->query($types) : $types)->get() as $type) {
            $query = ClientConsent::query()->where('client_id', $person->id)->where('consent_type_id', $type->id);
            $consents = ($reads ? $reads->query($query) : $query)->get();
            $usable = $consents->contains(function ($consent) use ($person, $type, $checkAt, $reads) {
                $evidence = $reads ? CurrentConsentEvidence::lock($consent->id) : null;
                $consent = $evidence?->consent ?? $consent;

                return (! $consent->expires_at || $consent->expires_at->gt($checkAt))
                    && ($evidence ? ConsentValidationService::isConsumableFromCurrentEvidence($evidence, $person, $type->id)
                        : ConsentValidationService::isConsumable($consent, $person, $type->id));
            });
            if (! $usable) {
                $issues[] = 'Complete current mandatory consent decisions for this person before approving support.';
            }
        }
        if (! ReferralCareHandoverRevisions::reviewReady($referral) || ($referral->assessment['capacity'] ?? '') !== 'available'
            || CarbonImmutable::parse($referral->assessment['review_due_at'] ?? '1970-01-01')->lte($checkAt)
            || CarbonImmutable::parse($referral->authority['review']['review_due_at'] ?? '1970-01-01')->lte($checkAt)) {
            $issues[] = 'Current identity, service authority, care assessment and available capacity must cover the agreed start date.';
        }
        $proposalReadiness = app(ReferralProposalReadiness::class)->inspect($referral, $proposal, $date->toDateString(), $reads);
        $issues = [...$issues, ...$proposalReadiness['issues']];
        if ($agreement->status !== 'active' || ! $agreement->approved_by || ! $agreement->approved_at || ! $agreement->submitted_for_approval_by || ! $agreement->submitted_for_approval_at
            || (int) $agreement->approved_by === (int) $agreement->submitted_for_approval_by || $agreement->approved_at->lt($agreement->submitted_for_approval_at)) {
            $issues[] = 'The canonical agreement needs current independent approval.';
        }
        if ($agreement->approved_at?->isFuture() || ReferralDates::parse($input['signed_at'], 'signed_at')->isFuture()) {
            $issues[] = 'Agreement approval and signature evidence cannot be dated in the future.';
        }
        $terms = app(ReferralAgreementTerms::class)->match($proposal, $agreement, $input['agreement_lines'], $reads !== null);
        $issues = [...$issues, ...$terms['issues']];
        $careDigest = app(CarePlanAttestationService::class)->currentDigest($plan, $reads);
        $policy = app(CarePlanAttestationService::class)->policyFor($plan);
        $attestations = CarePlanSignOff::query()->where('care_plan_id', $plan->id)->where('plan_version_digest', $careDigest)
            ->whereNull('revoked_at')->whereNull('superseded_at')->where('gate_satisfying', true)->whereIn('attestation_state', $policy['satisfying_states']);
        if ($plan->status !== 'active' || ! $plan->isMutableVersion($reads) || ! $plan->starts_at || $plan->starts_at->toDateString() > $date->toDateString()
            || ($plan->ends_at && $plan->ends_at->toDateString() < $date->toDateString()) || ! $plan->next_review_at || $plan->next_review_at->toDateString() <= $date->toDateString()
            || ! ($reads ? $reads->query($attestations) : $attestations)->exists()) {
            $issues[] = 'Select a current active care plan with eligible agreement evidence and a review date after the proposed start.';
        }
        $staff = [];
        foreach ($input['staff_ids'] as $id) {
            $query = User::query()->whereKey($id)->whereHas('hrEmployeeProfile', fn ($profile) => $profile
                ->where(fn ($dates) => $dates->whereNull('start_date')->orWhereDate('start_date', '<=', $date->toDateString()))
                ->where(fn ($dates) => $dates->whereNull('end_date')->orWhereDate('end_date', '>=', $date->toDateString())));
            $worker = ($reads ? $reads->query($query) : $query)->first();
            $assigned = $person->supportWorkers()->where('users.id', $id);
            if (! $worker || ! app(ClientWorkerEligibility::class)->isEligible(new Client(['site_id' => $referral->site_id]), $worker, $reads)
                || ! ($reads ? $reads->query($assigned->getQuery()) : $assigned)->exists()) {
                $issues[] = 'Every selected worker must be current, eligible at the service site, employed on the start date and assigned to this person.';

                continue;
            }
            $staff[] = (int) $worker->id;
        }
        if ($staff === []) {
            $issues[] = 'Confirm at least one named assigned worker and the staffing arrangements.';
        }
        $evidence = ReferralEvidence::query()->where('referral_id', $referral->id)->where('audience', 'funding')->whereIn('id', $input['agreement_evidence_ids']);
        $documents = ($reads ? $evidence->lockForUpdate() : $evidence)->get();
        if ($documents->count() !== count($input['agreement_evidence_ids']) || $documents->isEmpty() || ! $documents->every(fn ($document) => $document->state === 'available'
            && empty($document->metadata['superseded_by_id']) && (empty($document->metadata['expires_at']) || CarbonImmutable::parse($document->metadata['expires_at'])->gt($checkAt))
            && app(ReferralEvidenceFiles::class)->bytes($document) !== null)) {
            $issues[] = 'Link current intact evidence of acceptance of the exact canonical agreement terms.';
        }

        return ['ready' => $issues === [], 'issues' => array_values(array_unique($issues)), 'agreement_mapping' => $terms,
            'prerequisite_token' => app(ReferralProposals::class)->token($referral, $proposal, $reads),
            'agreement_fingerprint' => app(ReferralAgreementTerms::class)->fingerprint($agreement, $reads !== null),
            'care_plan_digest' => $careDigest, 'staff_ids' => $staff];
    }
}
