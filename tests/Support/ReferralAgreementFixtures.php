<?php

use App\Domain\Referrals\Models\ReferralEvidence;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Domain\Referrals\Services\ReferralConversionService;
use App\Domain\Referrals\Services\ReferralProposals;
use App\Models\Client;
use App\Models\ServiceContext;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/ReferralProposalFixtures.php';
require_once __DIR__.'/ReferralHandoverFixtures.php';

/**
 * A referral whose support proposal (8 hours a week at $60, GST exclusive, DSS / NASC) has been
 * accepted by the person and independently approved, then handed over to a new person in
 * onboarding. The author may create service agreements; the approver may not.
 */
function referralAgreementFixture(bool $accept = true, bool $approve = true, bool $convert = true, array $agreementPermissions = ['service_agreements.create', 'service_agreements.viewAny']): array
{
    Storage::fake('private');
    [$site, $author, $approver, $referral, $tax, $case, $decision, $evidence] = referralProposalFixture();
    // The author also navigates the open funding case, and handover needs that navigator to manage funding.
    handoverCanonicalPermissions($author, ['referrals.convert', 'referrals.assessment.view', 'referrals.duplicates.manage', 'referrals.funding.manage',
        'clients.create', 'clients.viewAny', 'clients.update', 'onboarding.view', ...$agreementPermissions]);
    $referral->update(['team' => 'Community support', 'requested_service' => 'supported_living', 'identity' => ['first_name' => 'Casey', 'last_name' => 'Example'],
        'assessment' => ['version' => 1, 'authority_version' => 1, 'identity_status' => 'verified', 'intention' => 'proceed', 'suitability' => 'suitable',
            'capacity' => 'available', 'review_due_at' => now()->addMonths(2)->toISOString(), 'goals' => 'Daily living choices', 'participation' => 'Plain language and supported decisions', 'safety_needs' => 'Review mobility support together']]);
    $work = ReferralWorkItem::create(['referral_id' => $referral->id, 'owner_id' => $author->id, 'kind' => 'follow_up', 'audience' => 'funding',
        'status' => 'open', 'due_at' => now()->addDay(), 'details' => ['title' => 'Follow up with the funding navigator']]);
    $referral->update(['primary_work_item_id' => $work->id]);

    $proposals = app(ReferralProposals::class);
    $proposal = $proposals->save($author, $referral->id, 1, referralProposalInput($tax, $case, $decision));
    if ($accept) {
        $proposals->accept($author, $referral->id, $referral->fresh()->lock_version, $proposal->id, referralProposalAcceptance($evidence));
    }
    if ($approve) {
        $proposals->approve($approver, $referral->id, $referral->fresh()->lock_version, $proposal->id, referralProposalApproval());
    }

    $person = null;
    if ($convert) {
        $context = ServiceContext::create(['site_id' => $site->id, 'name' => 'Community support', 'type' => 'home_support', 'is_active' => true]);
        $document = ReferralEvidence::create(['referral_id' => $referral->id, 'audience' => 'assessment', 'state' => 'available', 'disk' => $evidence->disk,
            'storage_path' => $evidence->storage_path, 'sha256' => $evidence->sha256, 'size_bytes' => $evidence->size_bytes, 'metadata' => ['title' => 'Reviewed care evidence', 'mime' => 'application/pdf']]);
        $provision = app(ReferralConversionService::class)->convert($author, $referral->id, $referral->fresh()->lock_version, [
            'target_id' => null, 'target_fingerprint' => null, 'service_context_id' => $context->id, 'onboarding_owner_id' => $author->id,
            'identity_review' => 'Identity reviewed with the person', 'handover_note' => 'Prepare the current agreed support safely', 'evidence_ids' => [$document->id],
            'duplicate_decisions' => [], 'acknowledge_care_handover' => true, 'acknowledge_pending_work' => true, 'acknowledge_separate_start' => true,
        ]);
        $person = Client::findOrFail($provision['person']['id']);
    }

    return compact('site', 'author', 'approver', 'referral', 'tax', 'case', 'decision', 'evidence', 'proposal', 'person');
}

function referralAgreementInput(array $f, array $overrides = []): array
{
    return ['version' => $f['referral']->fresh()->lock_version, 'proposal_id' => $f['proposal']->id, 'proposal_version' => $f['proposal']->version,
        'acknowledge_draft' => true, ...$overrides];
}
