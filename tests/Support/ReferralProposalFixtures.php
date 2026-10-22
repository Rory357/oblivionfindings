<?php

use App\Domain\Finance\Models\FinTaxRate;
use App\Domain\Referrals\Models\ReferralEvidence;
use App\Domain\Referrals\Models\ReferralFundingCase;
use App\Domain\Referrals\Models\ReferralFundingDecision;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Services\ReferralSettings;
use App\Models\Site;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/ReferralFixtures.php';

function referralProposalFixture(bool $private = false, ?Site $site = null): array
{
    $site ??= Site::factory()->create(['is_active' => true, 'archived' => false]);
    $author = referralTestUser($site, ['viewAny', 'funding.view', 'proposals.manage']);
    $approver = referralTestUser($site, ['viewAny', 'funding.view', 'proposals.approve']);
    $record = referralTestRecord($site, ['owner_id' => $author->id, 'authority' => ['review' => ['state' => 'current', 'basis' => 'self', 'version' => 1,
        'review_due_at' => now()->addMonths(3)->toISOString(), 'scopes' => ['collect' => true, 'service' => true, 'assist' => true]]], 'assessment' => ['version' => 1]]);
    $configuration = ReferralSettings::defaults();
    $configuration['proposals']['approval_limit_cents'] = 100000;
    $settings = ReferralSettingsVersion::create(['version' => 2, 'state' => 'published', 'configuration' => $configuration, 'published_at' => now(), 'effective_at' => now()]);
    // This is the canonical finance table's required legacy storage field, not an access boundary.
    $tax = FinTaxRate::create(['organization_id' => 1, 'name' => 'Configured test GST', 'code' => 'REF-TEST', 'rate' => '0.1500', 'type' => 'gst', 'is_active' => true]);
    $bytes = "%PDF-1.4\nFictional signed support evidence\n%%EOF";
    $path = 'referral-evidence/'.Str::uuid().'.bin';
    Storage::disk('private')->put($path, $bytes);
    $evidence = ReferralEvidence::create(['referral_id' => $record->id, 'audience' => 'funding', 'state' => 'available', 'disk' => 'private', 'storage_path' => $path,
        'sha256' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes), 'metadata' => ['title' => 'Signed support evidence', 'mime' => 'application/pdf']]);
    $case = ReferralFundingCase::create(['referral_id' => $record->id, 'navigator_id' => $author->id, 'settings_version_id' => $settings->id, 'pathway' => $private ? 'private' : 'dss_nasc',
        'status' => 'decision_received', 'personal_income' => false, 'details' => ['roles' => $private ? ['invoice_payer' => 'Example private payer'] : [], 'guide' => ['service_ids' => ['supported_living']]]]);
    $decision = ReferralFundingDecision::create(['funding_case_id' => $case->id, 'version' => 1, 'outcome' => $private ? 'private_confirmed' : 'approved', 'recorded_by' => $author->id, 'recorded_at' => now(),
        'decision' => ['evidence_ids' => [$evidence->id], 'valid_from' => today('Pacific/Auckland')->toDateString(), 'valid_until' => today('Pacific/Auckland')->addMonths(2)->toDateString(),
            'lines' => [['service_id' => 'supported_living', 'quantity' => $private ? '1000' : '8', 'provider_quantity' => $private ? '1000' : '8', 'unit' => $private ? 'money' : 'hours', 'unit_label' => null, 'period' => 'week', 'currency' => 'NZD', 'restrictions' => null]]]]);
    $case->update(['details' => [...$case->details, 'latest_decision_id' => $decision->id, 'verification' => ['status' => 'verified', 'decision_id' => $decision->id, 'conditions_resolved' => true,
        'provider_compatible' => true, 'review_due_at' => now()->addMonths(2)->toISOString(), 'evidence_ids' => [$evidence->id], 'verified_by' => $approver->id]]]);

    return [$site, $author, $approver, $record, $tax, $case, $decision, $evidence];
}

function referralProposalInput(FinTaxRate $tax, ReferralFundingCase $case, ReferralFundingDecision $decision): array
{
    return ['previous_proposal_id' => null, 'title' => 'Supported living plan', 'starts_on' => today('Pacific/Auckland')->toDateString(), 'ends_on' => today('Pacific/Auckland')->addMonth()->toDateString(),
        'support_rationale' => 'Support independent daily living', 'funding_position' => 'External award provided for review', 'capacity_plan' => 'A named team can provide this support',
        'person_participation' => 'The person reviewed their goals together', 'reason' => 'Initial support proposal prepared',
        'lines' => [['key' => 'daily-support', 'service_id' => 'supported_living', 'description' => 'Daily living support', 'quantity' => '8', 'unit' => 'hours', 'unit_label' => null, 'period' => 'week',
            'currency' => 'NZD', 'unit_rate' => '60', 'tax_rate_id' => $tax->id, 'price_basis' => 'exclusive', 'price_source' => 'Explicit agreed example rate for this proposal']],
        'allocations' => [['line_key' => 'daily-support', 'funding_case_id' => $case->id, 'decision_id' => $decision->id, 'decision_line' => 0, 'quantity' => $case->pathway === 'private' ? '552.00' : '8', 'support_quantity' => '8']]];
}

function referralProposalAcceptance(ReferralEvidence $evidence, array $overrides = []): array
{
    return ['kind' => 'person_acceptance', 'funding_case_id' => null, 'result' => 'accepted', 'signatory' => 'Example person', 'capacity' => 'person', 'authority_note' => 'Current service authority reviewed',
        'participation' => 'Terms reviewed together in plain language', 'occurred_at' => now()->toISOString(), 'note' => 'The person accepts this proposal version', 'evidence_ids' => [$evidence->id], ...$overrides];
}

function referralProposalApproval(array $overrides = []): array
{
    return ['result' => 'approved', 'note' => 'Cost, capacity and purchasing scope reviewed independently', 'conditions' => 'No additional internal conditions', 'review_due_at' => now()->addMonths(2)->toISOString(), ...$overrides];
}
