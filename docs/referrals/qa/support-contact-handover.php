<?php

require __DIR__.'/inspect-delivery.php';

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralConversion;
use App\Domain\Referrals\Models\ReferralEvidence;
use App\Domain\Referrals\Services\ReferralCareHandoverRevisions;
use App\Domain\Referrals\Services\ReferralConversionService;
use App\Domain\Referrals\Services\ReferralHandoverPreview;
use App\Domain\Referrals\Services\ReferralPeopleContacts;
use App\Domain\Referrals\Services\ReferralSupportContactHandovers;
use App\Models\Client;
use App\Models\ClientEmergencyContact;
use App\Models\ClientSupportContact;
use App\Models\NextOfKin;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

$checks = [];
$check = function ($ok, $label) use (&$checks) {
    if (! $ok) {
        throw new RuntimeException($label);
    } $checks[] = $label;
};
$denied = function ($action, $status, $label) use ($check) {
    try {
        $action();
        throw new RuntimeException('Unexpected acceptance: '.$label);
    } catch (HttpException $e) {
        $check($e->getStatusCode() === $status, $label);
    }
};
DB::beginTransaction();
try {
    $actor = User::where('email', 'reviewer@referrals-preview.test')->sole();
    Auth::setUser($actor);
    $referral = CareReferral::where('source', 'qa_people_contacts')->sole();
    $source = array_values($referral->intake['people_contacts'])[0];
    $preview = app(ReferralHandoverPreview::class)->inspect($actor, $referral);
    $evidence = ReferralEvidence::where('referral_id', $referral->id)->where('state', 'available')->sole();
    $review = ['contact_id' => $source['id'], 'contact_version' => $source['version'], 'purpose' => 'Fictional support arranging ongoing care appointments', 'confirmed' => true];
    $input = ['target_id' => null, 'target_fingerprint' => null, 'service_context_id' => $preview['service_contexts'][0]['id'], 'onboarding_owner_id' => $actor->id,
        'identity_review' => 'Fictional identity review for support contact QA', 'handover_note' => 'Fictional continuing funding assistance remains assigned',
        'evidence_ids' => [$evidence->id], 'duplicate_decisions' => [], 'acknowledge_pending_work' => true, 'acknowledge_care_handover' => true,
        'acknowledge_separate_start' => true, 'support_contacts' => [$review]];
    $people = Client::count();
    $users = User::count();
    $kins = NextOfKin::count();
    $emergency = ClientEmergencyContact::count();
    $convert = app(ReferralConversionService::class);
    $denied(fn () => $convert->convert($actor, $referral->id, $referral->lock_version, [...$input, 'support_contacts' => [[...$review, 'contact_version' => 999]]]), 409, 'Stale contact selection is rejected');
    $check(Client::count() === $people && ! ClientSupportContact::where('source_referral_id', $referral->id)->exists(), 'Rejected contact release leaves no partial person or contact');
    $result = $convert->convert($actor, $referral->id, $referral->lock_version, $input);
    $check($convert->convert($actor, $referral->id, $referral->lock_version, $input) === $result, 'Exact conversion retry reuses the same person and contact');
    $person = Client::findOrFail($result['person']['id']);
    $contact = ClientSupportContact::where('client_id', $person->id)->sole();
    $service = app(ReferralSupportContactHandovers::class);
    $projection = $service->present($actor, $person);
    $check($projection[0]['review_current'] && $projection[0]['details']['phone'] === $source['details']['phone'], 'Canonical care contact keeps the reviewed phone and current status');
    $check($projection[0]['details']['safe_time'] === $source['details']['safe_time'] && ! $projection[0]['details']['leave_message'], 'Safe-contact and no-message instructions survive handover');
    $check(! str_contains($contact->getRawOriginal('release'), $source['details']['name']) && ! array_key_exists('release', $contact->toArray()), 'Private release is encrypted and excluded from generic serialization');
    $check(User::count() === $users && NextOfKin::count() === $kins && ClientEmergencyContact::count() === $emergency, 'Support contact grants no portal, guardian or emergency status');
    $check($person->status === 'onboarding' && $person->service_start_date === null, 'Contact handover does not start services');
    $conversion = ReferralConversion::where('referral_id', $referral->id)->sole();
    $original = $conversion->receipt;
    $check($original['support_contacts'][0]['id'] === $contact->id, 'Original conversion receipt retains the reviewed contact mapping');
    $ledger = app(ReferralPeopleContacts::class);
    $withdraw = [...$source['details'], 'submission_key' => (string) Str::uuid(), 'contact_id' => $source['id'], 'contact_version' => $source['version'], 'state' => 'withdrawn', 'may_contact' => false, 'leave_message' => false, 'confirmed' => false, 'review_note' => 'Fictional permission withdrawn after handover'];
    $ledger->save($actor, $referral->id, $referral->fresh()->lock_version, $withdraw);
    $stale = $service->present($actor, $person)[0];
    $check(! $stale['review_current'] && $stale['purpose'] === null && ! array_key_exists('phone', $stale['details']) && ! array_key_exists('email', $stale['details']), 'Withdrawal immediately withholds old actionable canonical contact details and care-purpose notes');
    $renew = [...$source['details'], 'submission_key' => (string) Str::uuid(), 'contact_id' => $source['id'], 'contact_version' => $source['version'] + 1, 'review_note' => 'Fictional fresh contact review for continued care'];
    $ledger->save($actor, $referral->id, $referral->fresh()->lock_version, $renew);
    $check(! $service->present($actor, $person)[0]['review_current'], 'Renewing the source alone does not silently republish contact data');
    $care = app(ReferralCareHandoverRevisions::class);
    $carePreview = $care->preview($actor, $referral->fresh());
    $publication = ['submission_key' => (string) Str::uuid(), 'acknowledge_care_handover' => true, 'reason' => 'Fictional contact renewed for current ongoing care', 'assessment_version' => $carePreview['assessment_version'],
        'authority_version' => $carePreview['authority_version'], 'person_fingerprint' => $carePreview['person']['fingerprint'], 'evidence_ids' => [$evidence->id], 'support_contacts' => [[...$review, 'contact_version' => $source['version'] + 2]]];
    $version = $referral->fresh()->lock_version;
    $published = $care->publish($actor, $referral->id, $version, $publication);
    $check($care->publish($actor, $referral->id, $version, $publication) === $published, 'Care publication retry is idempotent');
    $check(ClientSupportContact::where('client_id', $person->id)->count() === 1 && $contact->fresh()->version === 2 && $service->present($actor, $person)[0]['review_current'], 'Fresh handover renews the same canonical contact');
    $check($conversion->fresh()->receipt === $original, 'Later renewal preserves the original conversion receipt');
    $assessment = $referral->fresh()->assessment;
    $referral->refresh()->update(['assessment' => [...$assessment, 'version' => $assessment['version'] + 1]]);
    $check(! $service->present($actor, $person)[0]['review_current'], 'Changed assessment requires a fresh contact handover');
    $referral->update(['assessment' => $assessment]);
    $intake = $referral->fresh()->intake;
    $expired = $intake;
    $expired['people_contacts'][$source['id']]['details']['review_due_at'] = now()->subMinute()->toISOString();
    $referral->update(['intake' => $expired]);
    $check(! $service->present($actor, $person)[0]['review_current'], 'Expired source contact review withholds canonical contact instructions');
    $referral->update(['intake' => $intake]);
    $referral->refresh()->update(['restricted' => true]);
    $actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'referrals.restricted')->sole()->id => ['allowed' => false]]);
    $check($service->present($actor->fresh(), $person) === [], 'Restricted source is hidden from unauthorized canonical viewers');
    echo json_encode(['passed' => $checks, 'count' => count($checks)]).PHP_EOL;
} finally {
    DB::rollBack();
}
