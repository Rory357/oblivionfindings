<?php

require __DIR__.'/inspect-delivery.php';

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralDocumentRequest;
use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralEvidence;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\ReferralPermissions;
use App\Domain\Referrals\Services\ReferralDocumentRequests;
use App\Domain\Referrals\Services\ReferralSettings;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Only the guarded disposable preview above may receive this migration.
if (! Schema::hasTable('care_referral_document_requests')) {
    $exit = Artisan::call('migrate', ['--path' => 'database/migrations/2026_10_18_212500_create_referral_document_requests.php', '--force' => true]);
    if ($exit !== 0) {
        throw new RuntimeException(Artisan::output());
    }
}
$checks = [];
$check = function ($ok, $label) use (&$checks) {
    if (! $ok) {
        throw new RuntimeException($label);
    } $checks[] = $label;
};
$denied = function ($action, $label) use ($check) {
    try {
        $action();
    } catch (HttpException $e) {
        $check(in_array($e->getStatusCode(), [403, 404, 409, 422]), $label);

        return;
    }
    throw new RuntimeException('Expected denial: '.$label);
};
Storage::fake('private');
config(['referral-evidence.malware_scanner.binary' => null]);
DB::beginTransaction();
try {
    $site = Site::factory()->create(['name' => 'Fictional document request QA', 'is_active' => true, 'archived' => false]);
    $actor = User::factory()->create(['name' => 'Fictional document QA staff', 'approved_at' => now(), 'role' => 'support_worker']);
    HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [], 'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
    $allowed = ['viewAny', 'evidence.manage', 'disclose', 'communications.manage', 'funding.view', 'funding.manage'];
    foreach (ReferralPermissions::definitions() as $definition) {
        $permission = Permission::firstOrCreate(['key' => $definition['key']], $definition);
        $actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => in_array(substr($definition['key'], 10), $allowed, true)]]);
    }
    $configuration = $version->configuration;
    $configuration['document_requests'] = ['enabled' => true, 'maximum_days' => 7];
    $settings = ReferralSettingsVersion::create(['version' => ReferralSettingsVersion::max('version') + 1, 'state' => 'published', 'configuration' => $configuration, 'published_at' => now(), 'effective_at' => now()]);
    $referral = CareReferral::create(['settings_version_id' => $settings->id, 'site_id' => $site->id, 'owner_id' => $actor->id, 'stage' => 'assessment', 'source' => 'qa_document_requests', 'entry_kind' => 'support', 'requested_service' => 'supported_living', 'team' => 'Fictional QA', 'identity' => ['first_name' => 'Fictional', 'last_name' => 'DocumentQA'], 'intake' => [], 'assessment' => [],
        'authority' => ['review' => ['state' => 'current', 'version' => 1, 'review_due_at' => now()->addMonth()->toISOString(), 'scopes' => ['collect' => true, 'contact' => true]]], 'next_action' => 'Review requested document', 'next_due_at' => now()->addDay(), 'stage_changed_at' => now()]);
    $input = ['request_key' => (string) Str::uuid(), 'audience' => 'funding', 'title' => 'Fictional funding letter', 'instructions' => 'Please supply the current fictional decision letter.', 'category' => 'external_decision', 'recipient' => 'Fictional recipient', 'recipient_verification' => 'Verified by the agreed safe callback and separate contact channels.', 'reason' => 'Help prepare the fictional funding application.', 'days' => 7, 'separate_channel_confirmed' => true];
    $service = app(ReferralDocumentRequests::class);
    $issued = $service->issue($actor, $referral, 1, $input);
    $request = ReferralDocumentRequest::where('referral_id', $referral->id)->firstOrFail();
    $check($issued['state'] === 'pending' && strlen($issued['token']) === 64 && strlen($issued['passcode']) === 10, 'issue limited credentials');
    $check($service->issue($actor, $referral, 1, $input) === $issued && $referral->fresh()->lock_version === 2, 'exact issue retry recovers same credentials once');
    $denied(fn () => $service->issue($actor, $referral, 2, [...$input, 'title' => 'Altered title']), 'changed issue retry denied');
    $raw = DB::table('care_referral_document_requests')->where('id', $request->id)->first();
    $check(! str_contains($raw->credentials, $issued['token']) && ! str_contains($raw->credentials, $issued['passcode']) && ! str_contains($raw->details, $input['recipient']), 'secrets and recipient encrypted at rest');
    $check(! str_contains(json_encode($service->summary($request)), $issued['token']), 'history omits credentials');
    $check($service->inspect($issued['token'], $issued['passcode'])['title'] === $input['title'], 'correct code reveals only request instructions');
    $denied(fn () => $service->inspect(str_repeat('a', 64), $issued['passcode']), 'unknown token denied');
    $originalAuthority = $referral->authority;
    $referral->update(['authority' => [...$originalAuthority, 'review_required' => true]]);
    $denied(fn () => $service->inspect($issued['token'], $issued['passcode']), 'withdrawn authority denies existing link');
    $referral->update(['authority' => $originalAuthority, 'stage' => 'closed']);
    $denied(fn () => $service->inspect($issued['token'], $issued['passcode']), 'closed parent denies existing link');
    $referral->update(['stage' => 'assessment']);
    $identity = $referral->identity;
    $referral->update(['identity' => [...$identity, 'email' => 'changed@example.test']]);
    $denied(fn () => $service->inspect($issued['token'], $issued['passcode']), 'corrected identity invalidates old request');
    $referral->update(['identity' => $identity]);
    $intake = $referral->intake;
    $referral->update(['intake' => [...$intake, 'submitted_contact' => ['safe_contact' => 'Do not use the previously agreed contact channel.']]]);
    $denied(fn () => $service->inspect($issued['token'], $issued['passcode']), 'changed safe contact invalidates existing request');
    $referral->update(['intake' => $intake]);
    $permission = Permission::where('key', 'referrals.funding.manage')->firstOrFail();
    $actor->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
    $denied(fn () => $service->inspect($issued['token'], $issued['passcode']), 'revoked issuer permission denies existing link');
    $actor->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => true]);
    $referral->update(['restricted' => true]);
    $denied(fn () => $service->inspect($issued['token'], $issued['passcode']), 'new privacy restriction denies existing link');
    $referral->update(['restricted' => false]);
    $otherSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $referral->update(['site_id' => $otherSite->id]);
    $denied(fn () => $service->inspect($issued['token'], $issued['passcode']), 'site transfer denies issuer outside current scope');
    $referral->update(['site_id' => $site->id]);
    $expiry = $request->expires_at;
    $request->update(['expires_at' => now()->subMinute()]);
    $denied(fn () => $service->inspect($issued['token'], $issued['passcode']), 'expired request denied');
    $request->update(['expires_at' => $expiry]);
    $file = fn ($text = 'Fictional funding letter') => UploadedFile::fake()->createWithContent('decision.pdf', "%PDF-1.4\n{$text}\n%%EOF");
    $received = $service->receive($issued['token'], $issued['passcode'], $file());
    $check($received['state'] === 'received', 'external upload receives truthful receipt');
    $evidence = ReferralEvidence::where('referral_id', $referral->id)->firstOrFail();
    $check($evidence->state === 'quarantined' && $evidence->uploaded_by === null && $evidence->metadata['document_request_reference'] === $issued['reference'], 'unavailable scan quarantines with external provenance');
    $check($request->fresh()->credentials === null, 'received request erases recoverable credentials');
    $check($service->receive($issued['token'], $issued['passcode'], $file()) === $received, 'same file retry returns existing receipt');
    $denied(fn () => $service->receive($issued['token'], $issued['passcode'], $file('Different letter')), 'different second file rejected');
    $check(ReferralEvidence::where('referral_id', $referral->id)->count() === 1 && count(Storage::disk('private')->allFiles()) === 1, 'retries leave one evidence row and one stored file');
    $event = ReferralEvent::where('referral_id', $referral->id)->where('kind', 'document_request.received')->firstOrFail();
    $check($event->actor_id === null && $referral->fresh()->lock_version === 3, 'external event and referral version committed once');
    $second = $service->issue($actor->fresh(), $referral->fresh(), 3, [...$input, 'request_key' => (string) Str::uuid()]);
    $secondRow = ReferralDocumentRequest::where('reference', $second['reference'])->firstOrFail();
    $wrong = $second['passcode'] === '0000000000' ? '1111111111' : '0000000000';
    for ($i = 0; $i < 5; $i++) {
        $denied(fn () => $service->inspect($second['token'], $wrong), 'incorrect code '.($i + 1).' denied');
    }
    $check($secondRow->fresh()->failed_attempts === 5 && $secondRow->fresh()->credentials === null, 'attempt counter persists and locks on five failures');
    $denied(fn () => $service->inspect($second['token'], $second['passcode']), 'correct code cannot bypass lockout');
    $service->revoke($actor->fresh(), $referral->fresh(), 4, $secondRow->id, 'Replace the locked request after verifying safe contact.');
    $check($secondRow->fresh()->state === 'revoked', 'locked request remains revocable');
    $legacy = $configuration;
    unset($legacy['document_requests']);
    $check(app(ReferralSettings::class)->requiresGovernance($legacy, $configuration), 'request enablement requires independent governance');
    $legacyVersion = ReferralSettingsVersion::create(['version' => ReferralSettingsVersion::max('version') + 1, 'state' => 'published', 'configuration' => $legacy, 'published_at' => now(), 'effective_at' => now()]);
    $referral->update(['settings_version_id' => $legacyVersion->id]);
    $denied(fn () => $service->issue($actor->fresh(), $referral->fresh(), 5, [...$input, 'request_key' => (string) Str::uuid()]), 'legacy configuration leaves external collection disabled');
    echo json_encode(['passed' => count($checks), 'checks' => $checks], JSON_PRETTY_PRINT).PHP_EOL;
} finally {
    DB::rollBack();
    foreach (Storage::disk('private')->allFiles() as $file) {
        Storage::disk('private')->delete($file);
    }
}
