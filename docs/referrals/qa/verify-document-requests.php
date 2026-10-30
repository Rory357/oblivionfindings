<?php

require __DIR__.'/inspect-delivery.php';

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralDocumentRequest;
use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralEvidence;
use App\Domain\Referrals\Services\ReferralDocumentRequests;
use App\Domain\Referrals\Services\ReferralEvidenceFiles;
use App\Domain\Referrals\Services\ReferralSettings;

$checks = [];
$check = function ($ok, $label) use (&$checks): void {
    if (! $ok) {
        throw new RuntimeException($label);
    } $checks[] = $label;
};
$referral = CareReferral::where('source', 'qa_secure_document_requests')->sole();
$requests = ReferralDocumentRequest::where('referral_id', $referral->id)->get();
$check($requests->count() === 2, 'two reviewed browser requests');
$check($requests->where('state', 'received')->count() === 1 && $requests->where('state', 'revoked')->count() === 1, 'one received and one revoked request');
$check($requests->every(fn ($request) => $request->credentials === null), 'both requests cleared recoverable credentials');
$documents = ReferralEvidence::where('referral_id', $referral->id)->get();
$check($documents->count() === 1, 'revoked upload produced no second evidence');
$document = $documents->sole();
$check($document->uploaded_by === null && $document->audience === 'funding', 'external evidence preserves funding audience and provenance');
$check($document->metadata['scanner'] === 'fictional-preview-fixed-fixture', 'browser scan was the bounded fictional substitute, not launch evidence');
$check(app(ReferralEvidenceFiles::class)->bytes($document) !== null, 'stored bytes retain integrity');
$event = ReferralEvent::where('referral_id', $referral->id)->where('kind', 'document_request.received')->sole();
$check($event->actor_id === null, 'external receipt never attributed to staff upload');
$check($referral->client_id === null && $referral->stage === 'assessment', 'document receipt did not convert the person or advance readiness');
$check(! (app(ReferralSettings::class)->effective()->configuration['document_requests']['enabled'] ?? false), 'global configuration remains disabled');
$check(app(ReferralDocumentRequests::class)->configuration($referral)['enabled'], 'only the fictional case keeps its approved enabled publication');
echo json_encode(['passed' => count($checks), 'checks' => $checks, 'reference' => $referral->reference, 'evidence_id' => $document->id, 'current_settings' => app(ReferralSettings::class)->effective()->version], JSON_PRETTY_PRINT).PHP_EOL;
