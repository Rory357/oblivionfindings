<?php

require __DIR__.'/inspect-delivery.php';

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralConversion;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Domain\Referrals\Services\ReferralProfileHandover;
use App\Models\Client;
use App\Models\ClientOnboardingWorkflow;

$referral = CareReferral::where('source', 'qa_profile_handover')->sole();
$conversion = ReferralConversion::where('referral_id', $referral->id)->sole();
$person = Client::findOrFail($conversion->client_id);
$workflow = ClientOnboardingWorkflow::findOrFail($conversion->workflow_id);
$checks = [];
$check = function ($ok, $label) use (&$checks): void {
    if (! $ok) {
        throw new RuntimeException($label);
    }
    $checks[] = $label;
};
$check($referral->stage === 'converted' && $conversion->created_person, 'Browser committed one new-person handover');
$check($person->status === 'onboarding' && $person->service_start_date === null, 'Canonical person remains unstarted');
$check($workflow->status === 'in_progress' && $workflow->assigned_to === $referral->owner_id, 'Onboarding remains open with the selected accountable owner');
$check(ReferralWorkItem::where('referral_id', $referral->id)->where('status', 'open')->where('owner_id', $referral->owner_id)->count() === 1, 'Pending funding follow-up remains assigned and open');
$check($person->phone === null && $person->email === null, 'Callback contacts are not inferred as personal contacts');
$mappings = collect($conversion->receipt['profile_mappings'])->keyBy('key');
$check($mappings->count() === count(ReferralProfileHandover::LABELS), 'All eight explicitly reviewed cultural fields have recorded mappings');
foreach (array_keys(ReferralProfileHandover::LABELS) as $key) {
    $corrected = ($argv[2] ?? '') === 'after-correction' && $key === 'marae';
    $expected = $corrected ? 'Fictional community connection reviewed again' : $mappings[$key]['value'];
    $check($person->getAttribute($key) === $expected, 'Canonical '.$key.' equals its browser-reviewed '.($corrected ? 'correction' : 'handover value'));
}
if (($argv[2] ?? '') === 'after-correction') {
    $check($person->date_of_birth === null && $mappings['marae']['value'] === 'Fictional community connection', 'Profile correction preserves unknown birth date and original handover history');
}
$check(! str_contains($conversion->getRawOriginal('receipt'), 'Fictional affiliation'), 'Mapping receipt is encrypted at rest');
echo json_encode(['passed' => $checks, 'count' => count($checks), 'person_id' => $person->id, 'workflow_id' => $workflow->id, 'version' => $referral->lock_version]).PHP_EOL;
