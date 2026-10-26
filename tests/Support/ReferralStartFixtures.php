<?php

use App\Domain\Referrals\Models\ReferralConversion;
use App\Domain\Referrals\Models\ReferralEvidence;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Domain\Referrals\Services\InternalSupportPreparation;
use App\Domain\Referrals\Services\ReferralAgreementTerms;
use App\Domain\Referrals\Services\ReferralConversionService;
use App\Domain\Referrals\Services\ReferralHandoverPreview;
use App\Domain\Referrals\Services\ReferralProposals;
use App\Models\CarePlan;
use App\Models\Client;
use App\Models\ClientOnboardingWorkflow;
use App\Models\ServiceAgreement;
use App\Models\ServiceContext;
use App\Services\Operations\CarePlanAttestationService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/ReferralProposalFixtures.php';
require_once __DIR__.'/ReferralHandoverFixtures.php';

function referralStartFixture(bool $existingActive = false, string $contextType = 'home_support', ?Client $existingPerson = null, ?ServiceContext $existingContext = null): array
{
    Storage::fake('private');
    [$site, $author, $manager, $referral, $tax, $case, $decision, $evidence] = referralProposalFixture(false, $existingPerson?->site);
    handoverCanonicalPermissions($author, ['referrals.convert', 'referrals.assessment.view', 'referrals.duplicates.manage', 'referrals.commence', 'referrals.funding.manage',
        'clients.create', 'clients.viewAny', 'clients.update', 'onboarding.view', 'care_plans.viewAny', 'care_plans.update', 'service_agreements.viewAny']);
    handoverCanonicalPermissions($manager, ['referrals.commence', 'referrals.assessment.view', 'clients.viewAny', 'clients.update', 'care_plans.viewAny', 'service_agreements.viewAny']);
    $referral->update(['team' => 'Community support', 'requested_service' => 'supported_living', 'identity' => ['first_name' => 'Casey', 'last_name' => 'Example'],
        'assessment' => ['version' => 1, 'authority_version' => 1, 'identity_status' => 'verified', 'intention' => 'proceed', 'suitability' => 'suitable',
            'capacity' => 'available', 'review_due_at' => now()->addMonths(2)->toISOString(), 'goals' => 'Daily living choices', 'participation' => 'Plain language and supported decisions', 'safety_needs' => 'Review mobility support together']]);
    if ($existingPerson) {
        $referral->update(['source' => 'internal', 'entry_kind' => InternalSupportPreparation::KIND, 'client_id' => $existingPerson->id,
            'identity' => ['first_name' => $existingPerson->first_name, 'last_name' => $existingPerson->last_name]]);
    }
    $work = ReferralWorkItem::create(['referral_id' => $referral->id, 'owner_id' => $author->id, 'kind' => 'follow_up', 'audience' => 'funding',
        'status' => 'open', 'due_at' => now()->addDay(), 'details' => ['title' => 'Follow up with the funding navigator']]);
    $referral->update(['primary_work_item_id' => $work->id]);
    $proposals = app(ReferralProposals::class);
    $proposal = $proposals->save($author, $referral->id, 1, referralProposalInput($tax, $case, $decision));
    $proposals->accept($author, $referral->id, 2, $proposal->id, referralProposalAcceptance($evidence));
    $proposals->approve($manager, $referral->id, 3, $proposal->id, referralProposalApproval());
    $context = $existingContext ?? ServiceContext::create(['site_id' => $site->id, 'name' => 'Community support', 'type' => $contextType, 'is_active' => true]);
    $document = ReferralEvidence::create(['referral_id' => $referral->id, 'audience' => 'assessment', 'state' => 'available', 'disk' => $evidence->disk,
        'storage_path' => $evidence->storage_path, 'sha256' => $evidence->sha256, 'size_bytes' => $evidence->size_bytes, 'metadata' => ['title' => 'Reviewed care evidence', 'mime' => 'application/pdf']]);
    $person = $existingPerson ?? ($existingActive ? Client::factory()->create(['site_id' => $site->id, 'service_context_id' => $context->id, 'first_name' => 'Casey', 'last_name' => 'Example', 'status' => 'active']) : null);
    $provision = app(ReferralConversionService::class)->convert($author, $referral->id, 4, [
        'target_id' => $person?->id, 'target_fingerprint' => $person ? ReferralHandoverPreview::fingerprint($person) : null,
        'service_context_id' => $context->id, 'onboarding_owner_id' => $author->id, 'identity_review' => 'Identity reviewed with the person',
        'handover_note' => 'Prepare the current agreed support safely', 'evidence_ids' => [$document->id],
        'duplicate_decisions' => $person ? [['client_id' => $person->id, 'conclusion' => 'same_person', 'reason' => 'The person confirmed their existing identity record']] : [],
        'acknowledge_care_handover' => true, 'acknowledge_pending_work' => true, 'acknowledge_separate_start' => true,
    ]);
    $person = Client::findOrFail($provision['person']['id']);
    $conversion = ReferralConversion::where('referral_id', $referral->id)->firstOrFail();
    $workflow = ClientOnboardingWorkflow::findOrFail($conversion->workflow_id);
    $workflow->steps()->update(['status' => 'completed']);
    $workflow->update(['status' => 'completed', 'completed_by' => $author->id, 'completed_at' => now()]);
    $person->supportWorkers()->attach($author->id);
    $agreement = ServiceAgreement::create(['client_id' => $person->id, 'title' => 'Agreed community support', 'agreement_type' => 'community', 'status' => 'active',
        'starts_at' => today('Pacific/Auckland'), 'ends_at' => today('Pacific/Auckland')->addMonths(2), 'total_budget' => '10000', 'gst_inclusive' => false,
        'created_by' => $author->id, 'submitted_for_approval_by' => $author->id, 'submitted_for_approval_at' => now()->subHours(2), 'approved_by' => $manager->id, 'approved_at' => now()->subHour()]);
    $line = $agreement->lineItems()->create(['description' => 'Daily living support', 'unit_price' => '60', 'unit' => 'hour', 'quantity' => '100', 'budget_allocated' => '6000']);
    $plan = CarePlan::create(['client_id' => $person->id, 'title' => 'Agreed care and safety plan', 'status' => 'active', 'plan_type' => 'support_plan', 'version' => 1,
        'starts_at' => today('Pacific/Auckland'), 'ends_at' => today('Pacific/Auckland')->addMonths(2), 'next_review_at' => today('Pacific/Auckland')->addMonth(),
        'created_by' => $author->id, 'content' => ['domains' => [['key' => 'daily_living', 'label' => 'Daily living', 'strategies' => [['text' => 'Review mobility support together']]]]]]);
    app(CarePlanAttestationService::class)->record($plan, $author, ['attestation_state' => 'witnessed', 'party_role' => 'client', 'signer_client_id' => $person->id,
        'agreed_on' => today()->toDateString(), 'method' => 'in_person', 'witness_declaration' => true, 'evidence_type' => 'witness_statement',
        'evidence_reference' => 'Witnessed agreement with the identified person', 'acknowledgement' => 'The person reviewed and accepted their current support plan'], 'operations');
    $input = ['submission_key' => (string) Str::uuid(), 'proposal_id' => $proposal->id, 'service_agreement_id' => $agreement->id, 'care_plan_id' => $plan->id,
        'agreement_fingerprint' => app(ReferralAgreementTerms::class)->fingerprint($agreement->fresh()), 'care_plan_digest' => app(CarePlanAttestationService::class)->currentDigest($plan->fresh()),
        'agreed_date' => today('Pacific/Auckland')->toDateString(), 'staff_ids' => [$author->id], 'agreement_lines' => [['line_key' => 'daily-support', 'line_item_id' => $line->id]],
        'agreement_evidence_ids' => [$evidence->id], 'signatory' => $person->full_name, 'signed_at' => now()->subMinutes(30)->toISOString(),
        'agreement_review' => 'The signed evidence matches these exact terms and the mapped proposal lines.',
        'care_safety_review' => 'The current care plan covers the reviewed goals, participation and safety needs.',
        'staffing_review' => 'The named worker and cover arrangements are confirmed for the start date.',
        'acknowledge_current_terms' => true, 'acknowledge_separate_commencement' => true];

    return compact('site', 'author', 'manager', 'referral', 'person', 'context', 'conversion', 'workflow', 'proposal', 'agreement', 'line', 'plan', 'evidence', 'work', 'input');
}

function referralCommenceInput(): array
{
    return ['submission_key' => (string) Str::uuid(), 'commenced_at' => now()->toISOString(),
        'note' => 'Support actually began with the person and assigned worker.', 'acknowledge_actual_start' => true];
}
