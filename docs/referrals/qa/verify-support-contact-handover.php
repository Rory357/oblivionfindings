<?php

require __DIR__.'/inspect-delivery.php';

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralCareHandover;
use App\Domain\Referrals\Models\ReferralConversion;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Domain\Referrals\Services\ReferralSupportContactHandovers;
use App\Models\Client;
use App\Models\ClientEmergencyContact;
use App\Models\ClientOnboardingWorkflow;
use App\Models\ClientSupportContact;
use App\Models\NextOfKin;
use App\Models\User;

$referral = CareReferral::where('source', 'qa_support_contact_handover')->sole();
$conversion = ReferralConversion::where('referral_id', $referral->id)->sole();
$person = Client::findOrFail($conversion->client_id);
$workflow = ClientOnboardingWorkflow::findOrFail($conversion->workflow_id);
$contact = ClientSupportContact::where('client_id', $person->id)->sole();
$publication = ReferralCareHandover::where('conversion_id', $conversion->id)->sole();
$actor = User::where('email', 'reviewer@referrals-preview.test')->sole();
$projection = app(ReferralSupportContactHandovers::class)->present($actor, $person);
$checks = [];
$check = function ($ok, $label) use (&$checks) {
    if (! $ok) {
        throw new RuntimeException($label);
    } $checks[] = $label;
};
$check($referral->stage === 'converted' && $referral->lock_version === 6, 'Initial handover, withdrawal, renewal and care publication have committed versions');
$check($person->status === 'onboarding' && $person->service_start_date === null, 'Person remains in onboarding without service commencement');
$check($workflow->status === 'in_progress' && $workflow->assigned_to === $actor->id, 'Named onboarding owner remains responsible');
$check($person->date_of_birth === null && $person->phone === null && $person->email === null, 'Supporter details did not become the person’s own identity or contact details');
$check(ReferralWorkItem::where('referral_id', $referral->id)->where('status', 'open')->where('owner_id', $actor->id)->count() === 1, 'Funding follow-up stays open and assigned');
$check($contact->version === 2 && $contact->release['source_version'] === 3, 'Second release renews the same canonical contact after the third source review');
$check($conversion->receipt['support_contacts'][0]['version'] === 1 && $conversion->receipt['support_contacts'][0]['source_version'] === 1, 'Original conversion retains the first reviewed contact');
$check($publication->version === 2 && $publication->snapshot['support_contacts'][0]['id'] === $contact->id, 'Care publication maps to the existing canonical contact');
$check(count($projection) === 1 && $projection[0]['review_current'] && $projection[0]['details']['phone'] === '021 555 0188', 'Freshly renewed contact is available in the canonical profile');
$check($projection[0]['details']['leave_message'] === false && str_contains($projection[0]['details']['safe_time'], 'weekday afternoons'), 'Safe time and no-message limits remain intact');
$check(ClientEmergencyContact::where('client_id', $person->id)->doesntExist() && NextOfKin::where('client_id', $person->id)->doesntExist(), 'No emergency or portal relationship was created');
$check(! str_contains($contact->getRawOriginal('release'), 'Fictional Morgan') && ! str_contains($conversion->getRawOriginal('receipt'), 'Fictional Morgan'), 'Both current release and original receipt are encrypted');
echo json_encode(['passed' => $checks, 'count' => count($checks), 'reference' => $referral->reference, 'person_id' => $person->id, 'workflow_id' => $workflow->id, 'contact_id' => $contact->id]).PHP_EOL;
