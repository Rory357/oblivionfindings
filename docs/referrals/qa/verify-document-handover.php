<?php

require __DIR__.'/inspect-delivery.php';

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralCareHandover;
use App\Domain\Referrals\Models\ReferralConversion;
use App\Domain\Referrals\Models\ReferralEvidence;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Domain\Referrals\Services\ReferralDocumentHandovers;
use App\Domain\Referrals\Services\ReferralEvidenceFiles;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\ClientOnboardingWorkflow;
use App\Models\User;

$checks = [];
$check = function ($ok, $label) use (&$checks) {
    if (! $ok) {
        throw new RuntimeException($label);
    }
    $checks[] = $label;
};
$actor = User::where('email', 'reviewer@referrals-preview.test')->sole();
$referral = CareReferral::where('source', 'qa_document_handover')->sole();
$conversion = ReferralConversion::where('referral_id', $referral->id)->sole();
$person = Client::findOrFail($conversion->client_id);
$source = ReferralEvidence::where('referral_id', $referral->id)->sole();
$document = ClientDocument::where('client_id', $person->id)->where('source_referral_evidence_id', $source->id)->sole();
$check($referral->stage === 'converted' && $referral->lock_version === 2, 'Browser initial handover committed once');
$check($person->status === 'onboarding' && $person->service_start_date === null, 'Person remains in onboarding without a service start');
$check($person->date_of_birth === null && $person->phone === null && $person->email === null, 'Unknown identity and contact details were not invented');
$check($document->title === 'Fictional onboarding care document' && $document->portal_visible === false, 'Reviewed title and staff-only visibility persisted');
$check($document->referral_release['version'] === 1 && $document->referral_release['confirmed'], 'Initial whole-file review recorded');
$check($conversion->receipt['document_mappings'][0]['document_id'] === $document->id, 'Original receipt identifies the canonical document');
$check(! str_contains($document->getRawOriginal('referral_release'), 'Fictional whole-file') && ! str_contains($conversion->getRawOriginal('receipt'), $document->title), 'Release purpose and original receipt remain encrypted');
$check(hash('sha256', app(ReferralDocumentHandovers::class)->bytes($actor, $person, $document)) === $source->sha256, 'Canonical download returns the reviewed source bytes');
$check(ReferralWorkItem::where('referral_id', $referral->id)->where('status', 'open')->whereNotNull('owner_id')->count() === 1, 'Funding-assistance follow-up remains assigned and open');
$workflow = ClientOnboardingWorkflow::findOrFail($conversion->workflow_id);
$check($workflow->status === 'in_progress' && $workflow->assigned_to, 'Onboarding remains assigned and in progress');
$existing = CareReferral::where('source', 'qa_contact_handover')->sole();
$existingConversion = ReferralConversion::where('referral_id', $existing->id)->sole();
$versions = ReferralCareHandover::where('conversion_id', $existingConversion->id)->orderBy('version')->get();
$check($versions->count() >= 1 && $versions->first()->version === 2, 'Existing person received a separate care publication');
$check(empty($existingConversion->receipt['document_mappings']), 'Original conversion receipt is unchanged by later publication');
$check($versions->pluck('version')->all() === [2, 3], 'Browser renewal created the next care publication');
$firstMapping = $versions->first()->snapshot['document_mappings'][0];
$lastMapping = $versions->last()->snapshot['document_mappings'][0];
$check($firstMapping['document_id'] === $lastMapping['document_id'] && $firstMapping['version'] === 1 && $lastMapping['version'] === 2, 'Renewal reused the same canonical document with separate review history');
$existingSource = ReferralEvidence::where('referral_id', $existing->id)->sole();
$check(ClientDocument::where('client_id', $existingConversion->client_id)->where('source_referral_evidence_id', $existingSource->id)->doesntExist(), 'Browser removal deleted only the existing person’s canonical link');
$check(app(ReferralEvidenceFiles::class)->bytes($existingSource) !== null, 'Existing referral original evidence remains intact');
echo json_encode(['passed' => $checks, 'count' => count($checks), 'person_id' => $person->id, 'workflow_id' => $workflow->id, 'document_id' => $document->id, 'existing_publication_versions' => $versions->pluck('version')]).PHP_EOL;
