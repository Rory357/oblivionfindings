<?php

require __DIR__.'/inspect-delivery.php';

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralConversion;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientOnboardingWorkflow;
use App\Services\Clients\ClientContactPreferences;

$referral = CareReferral::where('source', 'qa_contact_handover')->sole();
$conversion = ReferralConversion::where('referral_id', $referral->id)->sole();
$person = Client::findOrFail($conversion->client_id);
$preferences = $person->contact_preferences;
$checks = [];
$check = function ($ok, $label) use (&$checks) {
    if (! $ok) {
        throw new RuntimeException($label);
    }
    $checks[] = $label;
};
$check($referral->stage === 'converted' && $referral->lock_version >= 2, 'Browser handover produced one committed conversion; later care revisions are permitted');
$check($person->status === 'onboarding' && $person->service_start_date === null, 'Canonical person remains unstarted');
$check($person->date_of_birth === null, 'Unknown birth date is not fabricated');
$check($person->phone === '021 555 0199' && $person->email === 'person@referrals-preview.test', 'Explicitly reviewed personal contacts persisted');
$check($preferences['channel'] === 'email' && $preferences['leave_message'] === false, 'Preferred email and no-message instruction persisted');
$check($preferences['safe_time'] === 'Fictional QA: afternoons only, after checking availability.', 'Browser profile correction persisted');
$check($preferences['communication'] === 'Fictional QA: use plain language and allow time for a reply.', 'Correction retained communication preferences');
$check(app(ClientContactPreferences::class)->preview($person)['review_current'], 'Saved instructions match the current contact values');
$check($preferences['source'] === 'person_profile', 'Latest verification records the canonical correction source');
$check($conversion->receipt['contact_mapping']['safe_time'] === 'Fictional QA: weekday mornings only; check availability first.', 'Original handover contact history remains unchanged');
$check($conversion->receipt['contact_mapping']['source'] === 'referral_handover', 'Original mapping retains its handover provenance');
$check(! str_contains($person->getRawOriginal('contact_preferences'), 'Fictional QA') && ! str_contains($conversion->getRawOriginal('receipt'), 'person@referrals-preview.test'), 'Private instructions and handover receipt remain encrypted');
$check(ReferralWorkItem::where('referral_id', $referral->id)->where('status', 'open')->whereNotNull('owner_id')->count() === 1, 'Assigned funding-assistance follow-up remains open');
$workflow = ClientOnboardingWorkflow::findOrFail($conversion->workflow_id);
$check($workflow->status === 'in_progress' && $workflow->assigned_to, 'Onboarding remains assigned and in progress');
$audit = AuditLog::where('client_id', $person->id)->pluck('meta')->toJson();
$check(! str_contains($audit, 'person@referrals-preview.test') && ! str_contains($audit, '021 555 0199') && ! str_contains($audit, 'Fictional QA:'), 'Generic audit events do not disclose contact values or instructions');
echo json_encode(['passed' => $checks, 'count' => count($checks), 'person_id' => $person->id, 'workflow_id' => $workflow->id]).PHP_EOL;
