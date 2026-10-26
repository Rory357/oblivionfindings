<?php

use App\Domain\Referrals\Models\ReferralEvidence;
use App\Models\ServiceContext;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/ReferralHandoverFixtures.php';

function conversionFixture(): array
{
    Storage::fake('private');
    [$site, $actor, $referral] = handoverPreviewFixture();
    $context = ServiceContext::create(['site_id' => $site->id, 'name' => 'Community support', 'type' => 'home_support', 'is_active' => true]);
    $bytes = "%PDF-1.4\nFictional reviewed identity and authority\n%%EOF";
    $path = 'referral-evidence/'.Str::uuid().'.bin';
    Storage::disk('private')->put($path, $bytes);
    $document = ReferralEvidence::create(['referral_id' => $referral->id, 'audience' => 'assessment', 'state' => 'available', 'disk' => 'private', 'storage_path' => $path,
        'sha256' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes), 'metadata' => ['title' => 'Reviewed identity and authority', 'mime' => 'application/pdf']]);
    $data = ['target_id' => null, 'target_fingerprint' => null, 'service_context_id' => $context->id, 'onboarding_owner_id' => $actor->id,
        'identity_review' => 'Identity and participation reviewed with the person', 'handover_note' => 'Continue funding assistance and arrange the care plan review',
        'evidence_ids' => [$document->id], 'duplicate_decisions' => [], 'acknowledge_care_handover' => true, 'acknowledge_pending_work' => true, 'acknowledge_separate_start' => true];

    return [$site, $actor, $referral, $context, $document, $data];
}
