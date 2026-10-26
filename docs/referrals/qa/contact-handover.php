<?php

require __DIR__.'/inspect-delivery.php';

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralConversion;
use App\Domain\Referrals\Models\ReferralEvidence;
use App\Domain\Referrals\Services\ReferralConversionService;
use App\Domain\Referrals\Services\ReferralHandoverPreview;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Clients\ClientContactPreferences;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

$checks = [];
$check = function ($ok, $name) use (&$checks) {
    if (! $ok) {
        throw new RuntimeException($name);
    }
    $checks[] = $name;
};
DB::beginTransaction();
try {
    $actor = User::where('email', 'reviewer@referrals-preview.test')->sole();
    $referral = CareReferral::where('source', 'qa_profile_handover_layout')->sole();
    $preview = app(ReferralHandoverPreview::class)->inspect($actor, $referral);
    $check($preview['preparation_checks_passed'] && $preview['matches']['candidates'] === [], 'Fixture passes real handover checks without identity matches');
    $review = ['phone' => '021 555 0100', 'email' => 'person@example.test', 'channel' => 'email', 'safe_time' => 'Fictional weekday mornings only',
        'leave_message' => false, 'communication' => 'Fictional interpreter preference', 'verification_note' => 'Fictional person reviewed their own contact details', 'confirmed' => true];
    $input = ['target_id' => null, 'target_fingerprint' => null, 'service_context_id' => $preview['service_contexts'][0]['id'], 'onboarding_owner_id' => $actor->id,
        'identity_review' => 'Fictional identity verification for contact QA', 'handover_note' => 'Fictional funding follow-up remains with its existing owner',
        'evidence_ids' => ReferralEvidence::where('referral_id', $referral->id)->where('state', 'available')->pluck('id')->all(), 'duplicate_decisions' => [],
        'acknowledge_pending_work' => true, 'acknowledge_care_handover' => true, 'acknowledge_separate_start' => true, 'contact_handover' => $review];
    $convert = app(ReferralConversionService::class);
    $receipt = $convert->convert($actor, $referral->id, $referral->lock_version, $input);
    $check($convert->convert($actor, $referral->id, $referral->lock_version, $input) === $receipt, 'Exact retry returns the same conversion receipt');
    $person = Client::findOrFail($receipt['person']['id']);
    $contacts = app(ClientContactPreferences::class);
    $check($person->phone === $review['phone'] && $person->email === $review['email'], 'Reviewed personal contact values reach canonical encrypted fields');
    $check($person->status === 'onboarding' && $person->service_start_date === null, 'Contact handover does not commence support');
    $check($person->contact_preferences['leave_message'] === false && $contacts->preview($person)['review_current'], 'Instructions retain explicit no-message choice and match their reviewed contact values');
    $check(! str_contains($person->getRawOriginal('contact_preferences'), 'Fictional weekday'), 'Contact instructions are encrypted at rest');
    $check(! array_key_exists('contact_preferences', $person->toArray()), 'Generic model serialization does not expose private instructions');
    $original = ReferralConversion::where('referral_id', $referral->id)->sole();
    $check($original->receipt['contact_mapping']['email'] === $review['email'] && ! str_contains($original->getRawOriginal('receipt'), 'person@example.test'), 'Original handover mapping is held in the encrypted receipt');
    $fingerprint = ClientContactPreferences::fingerprint($person);
    $updated = $contacts->update($actor, $person->id, $fingerprint, [...$review, 'safe_time' => 'Fictional afternoons only']);
    $check($updated['review_current'] && $updated['preferences']['safe_time'] === 'Fictional afternoons only', 'Current permitted profile correction succeeds');
    try {
        $contacts->update($actor, $person->id, $fingerprint, $review);
        throw new RuntimeException('Stale review accepted');
    } catch (HttpException $e) {
        $check($e->getStatusCode() === 409, 'Stale fingerprint cannot overwrite a later review');
    }
    $check($original->fresh()->receipt['contact_mapping']['safe_time'] === $review['safe_time'], 'Later profile correction preserves the original handover history');
    $audit = AuditLog::where('client_id', $person->id)->pluck('meta')->toJson();
    $check(! str_contains($audit, 'Fictional weekday') && ! str_contains($audit, 'Fictional afternoons') && ! str_contains($audit, 'person@example.test') && ! str_contains($audit, '021 555 0100'), 'Generic audit history contains no plaintext contact values or instructions');
    $check(AuditLog::where('client_id', $person->id)->where('action', 'client.contact_reviewed')->count() === 2, 'Both reviews retain a separate attributed audit event');
    $person->refresh()->update(['phone' => '021 changed elsewhere']);
    $check(! $contacts->preview($person->fresh())['review_current'], 'Contact change elsewhere makes old instructions explicitly need review');
    $actor->permissionOverrides()->updateExistingPivot(Permission::where('key', 'clients.update')->sole()->id, ['allowed' => false]);
    try {
        $contacts->update($actor->fresh(), $person->id, ClientContactPreferences::fingerprint($person->fresh()), $review);
        throw new RuntimeException('Revoked update accepted');
    } catch (HttpException $e) {
        $check($e->getStatusCode() === 403, 'Current canonical update permission is required');
    }
    $actor->permissionOverrides()->updateExistingPivot(Permission::where('key', 'clients.update')->sole()->id, ['allowed' => true]);
    foreach (['clinical.accessAllSites', 'sites.viewAll'] as $key) {
        if ($permission = Permission::where('key', $key)->first()) {
            $actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
        }
        $check(! $actor->fresh()->canDo($key), 'Site-bypass permission is absent: '.$key);
    }
    $unapproved = Site::factory()->create(['name' => 'Fictional unapproved contact QA', 'is_active' => true, 'archived' => false]);
    DB::table('clients')->where('id', $person->id)->update(['site_id' => $unapproved->id]);
    try {
        $contacts->update($actor->fresh(), $person->id, ClientContactPreferences::fingerprint($person->fresh()), $review);
        throw new RuntimeException('Unapproved site accepted');
    } catch (HttpException $e) {
        $check($e->getStatusCode() === 404, 'Direct person id cannot cross an unapproved site boundary');
    }
    foreach ([['channel' => 'none', 'leave_message' => true], ['channel' => 'phone', 'phone' => null], ['confirmed' => false], ['verification_note' => '   ']] as $invalid) {
        try {
            $contacts->validate([...$review, ...$invalid]);
            throw new RuntimeException('Invalid contact review accepted');
        } catch (ValidationException) {
            $check(true, 'Invalid or unconfirmed contact review rejected: '.implode(',', array_keys($invalid)));
        }
    }
    $check($contacts->validate([...$review, 'channel' => 'none', 'phone' => null, 'email' => null])['channel'] === 'none', 'No direct contact can be explicitly recorded without inventing a number');
    echo json_encode(['passed' => $checks, 'count' => count($checks)]).PHP_EOL;
} finally {
    DB::rollBack();
}
