<?php

use App\Domain\Referrals\Models\ReferralDocumentRequest;
use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralEvidence;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\ReferralPermissions;
use App\Domain\Referrals\Services\ReferralDocumentRequests;
use App\Domain\Referrals\Services\ReferralSettings;
use App\Models\Permission;
use App\Models\Site;
use App\Services\Files\MalwareScanDisposition;
use App\Services\Files\MalwareScanner;
use App\Services\Files\MalwareScanResult;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/ReferralFixtures.php';

beforeEach(function () {
    foreach (ReferralPermissions::definitions() as $definition) {
        Permission::firstOrCreate(['key' => $definition['key']], $definition);
    }
    Storage::fake('private');
});

function documentRequestFixture(): array
{
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'evidence.manage', 'disclose', 'communications.manage', 'funding.view', 'funding.manage']);
    $configuration = ReferralSettings::defaults();
    $configuration['document_requests'] = ['enabled' => true, 'maximum_days' => 7];
    $settings = ReferralSettingsVersion::create(['version' => 79, 'state' => 'published', 'configuration' => $configuration, 'published_at' => now(), 'effective_at' => now()]);
    $referral = referralTestRecord($site, ['settings_version_id' => $settings->id, 'owner_id' => $actor->id, 'authority' => ['review' => ['state' => 'current', 'version' => 1,
        'review_due_at' => now()->addMonth()->toISOString(), 'scopes' => ['collect' => true, 'contact' => true]]]]);
    $input = ['request_key' => (string) Str::uuid(), 'audience' => 'funding', 'title' => 'Funding decision letter', 'instructions' => 'Please supply the current decision letter.',
        'category' => 'external_decision', 'recipient' => 'Fictional recipient', 'recipient_verification' => 'Discussed directly using their agreed safe contact channel.',
        'reason' => 'Collect evidence to review support funding.', 'days' => 7, 'separate_channel_confirmed' => true];

    return [$site, $actor, $referral, $input];
}

it('issues an encrypted limited grant with exact retry and audience scoped history', function () {
    [$site, $actor, $referral, $input] = documentRequestFixture();
    $url = route('referrals.document-requests.store', $referral->reference);
    $issued = $this->actingAs($actor)->postJson($url, ['version' => 1, ...$input])->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->json('request');
    expect(strlen($issued['token']))->toBe(64)->and(strlen($issued['passcode']))->toBe(10);
    $this->postJson($url, ['version' => 1, ...$input])->assertCreated()->assertJsonPath('request.token', $issued['token']);
    $this->postJson($url, ['version' => 2, ...$input, 'title' => 'Changed request'])->assertConflict();
    expect(ReferralDocumentRequest::count())->toBe(1)->and($referral->fresh()->lock_version)->toBe(2);
    $raw = DB::table('care_referral_document_requests')->first();
    expect($raw->credentials)->not->toContain($issued['token'])->not->toContain($issued['passcode']);
    expect($raw->details)->not->toContain('Fictional recipient')->and($raw->passcode_hash)->not->toBe($issued['passcode']);
    $history = $this->getJson(route('referrals.document-requests.index', ['reference' => $referral->reference, 'audience' => 'funding']))->assertOk()->json();
    expect(json_encode($history))->not->toContain($issued['token'])->not->toContain($issued['passcode'])->not->toContain('token_hash');
    $denied = referralTestUser($site, ['viewAny']);
    $this->actingAs($denied)->getJson(route('referrals.document-requests.index', ['reference' => $referral->reference, 'audience' => 'funding']))->assertForbidden();
});

it('blocks stale authority identity expiry closure issuer permissions and revoked grants', function () {
    [, $actor, $referral, $input] = documentRequestFixture();
    $service = app(ReferralDocumentRequests::class);
    $issued = $service->issue($actor, $referral, 1, $input);
    $check = fn () => $service->inspect($issued['token'], $issued['passcode']);
    expect($check()['title'])->toBe($input['title']);
    $original = $referral->fresh()->authority;
    $referral->update(['authority' => [...$original, 'review_required' => true]]);
    expect($check)->toThrow(HttpException::class);
    $referral->update(['authority' => $original, 'stage' => 'closed']);
    expect($check)->toThrow(HttpException::class);
    $referral->update(['stage' => 'assessment']);
    $identity = $referral->identity;
    $referral->update(['identity' => [...$identity, 'email' => 'changed@example.test']]);
    expect($check)->toThrow(HttpException::class);
    $referral->update(['identity' => $identity]);
    $permission = Permission::where('key', 'referrals.funding.manage')->firstOrFail();
    $actor->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
    expect($check)->toThrow(HttpException::class);
    $actor->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => true]);
    $request = ReferralDocumentRequest::firstOrFail();
    $expiry = $request->expires_at;
    $request->update(['expires_at' => now()->subSecond()]);
    expect($check)->toThrow(HttpException::class);
    $request->update(['expires_at' => $expiry]);
    $service->revoke($actor->fresh(), $referral->fresh(), $referral->fresh()->lock_version, $request->id, 'Recipient no longer needs to supply this document.');
    expect($check)->toThrow(HttpException::class);
    expect($request->fresh()->credentials)->toBeNull();
});

it('persists five failed code attempts and does not disclose case details before verification', function () {
    [, $actor, $referral, $input] = documentRequestFixture();
    $service = app(ReferralDocumentRequests::class);
    $issued = $service->issue($actor, $referral, 1, $input);
    $wrong = $issued['passcode'] === '0000000000' ? '1111111111' : '0000000000';
    for ($i = 0; $i < 5; $i++) {
        expect(fn () => $service->inspect($issued['token'], $wrong))->toThrow(HttpException::class);
    }
    expect(ReferralDocumentRequest::first()->failed_attempts)->toBe(5)->and(ReferralDocumentRequest::first()->credentials)->toBeNull();
    expect(fn () => $service->inspect($issued['token'], $issued['passcode']))->toThrow(HttpException::class);
    expect(fn () => $service->inspect(str_repeat('a', 64), $issued['passcode']))->toThrow(HttpException::class);
});

it('collects private scanned evidence once with external provenance and quarantines unavailable scans', function () {
    [, $actor, $referral, $input] = documentRequestFixture();
    $this->mock(MalwareScanner::class)->shouldReceive('scanPath')->times(3)->andReturn(new MalwareScanResult(MalwareScanDisposition::Unavailable, 'test-scanner', 'scanner_unavailable'));
    $service = app(ReferralDocumentRequests::class);
    $issued = $service->issue($actor, $referral, 1, $input);
    $file = fn ($text = 'Fictional decision') => UploadedFile::fake()->createWithContent('decision.pdf', "%PDF-1.4\n{$text}\n%%EOF");
    $received = $service->receive($issued['token'], $issued['passcode'], $file());
    expect($received['state'])->toBe('received');
    expect($service->receive($issued['token'], $issued['passcode'], $file()))->toBe($received);
    expect(fn () => $service->receive($issued['token'], $issued['passcode'], $file('Different decision')))->toThrow(HttpException::class);
    expect(ReferralEvidence::count())->toBe(1)->and(Storage::disk('private')->allFiles())->toHaveCount(1);
    $evidence = ReferralEvidence::firstOrFail();
    expect($evidence->state)->toBe('quarantined')->and($evidence->uploaded_by)->toBeNull()->and($evidence->metadata['document_request_reference'])->toBe($issued['reference']);
    expect(ReferralDocumentRequest::first()->credentials)->toBeNull()->and($referral->fresh()->lock_version)->toBe(3);
    expect(ReferralEvent::where('kind', 'document_request.received')->count())->toBe(1);
    expect(ReferralEvent::where('kind', 'document_request.received')->first()->actor_id)->toBeNull();
    $this->actingAs($actor)->get(route('referrals.documents.download', [$referral->reference, $evidence->id]))->assertConflict();
});

it('requires explicit governed configuration and recipient checks before issuance', function () {
    [, $actor, $referral, $input] = documentRequestFixture();
    $service = app(ReferralDocumentRequests::class);
    $settings = ReferralSettingsVersion::findOrFail($referral->settings_version_id);
    $legacy = $settings->configuration;
    unset($legacy['document_requests']);
    $legacyVersion = ReferralSettingsVersion::create(['version' => 80, 'state' => 'published', 'configuration' => $legacy, 'published_at' => now(), 'effective_at' => now()]);
    $referral->update(['settings_version_id' => $legacyVersion->id]);
    expect(fn () => $service->issue($actor, $referral, 1, $input))->toThrow(HttpException::class);
    $enabled = [...$legacy, 'document_requests' => ['enabled' => true, 'maximum_days' => 3]];
    expect(app(ReferralSettings::class)->requiresGovernance($legacy, $enabled))->toBeTrue();
    $enabledVersion = ReferralSettingsVersion::create(['version' => 81, 'state' => 'published', 'configuration' => $enabled, 'published_at' => now(), 'effective_at' => now()]);
    $referral->update(['settings_version_id' => $enabledVersion->id]);
    expect(fn () => $service->issue($actor, $referral, 1, $input))->toThrow(HttpException::class);
    $this->actingAs($actor)->postJson(route('referrals.document-requests.store', $referral->reference), ['version' => 1, ...$input, 'days' => 3, 'separate_channel_confirmed' => false])->assertUnprocessable();
    expect(ReferralDocumentRequest::count())->toBe(0);
});

it('returns private JSON for HTML validation submissions without flashing the code', function () {
    [, $actor, $referral, $input] = documentRequestFixture();
    $issued = app(ReferralDocumentRequests::class)->issue($actor, $referral, 1, $input);
    $this->post(route('referrals.public.documents.check', $issued['token']), ['passcode' => 'private-invalid-code'], ['Accept' => 'text/html'])
        ->assertUnprocessable()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertJsonValidationErrors('passcode')->assertSessionMissing('_old_input.passcode')->assertDontSee('private-invalid-code');
    $page = $this->get(route('referrals.public.documents.show', $issued['token']))->assertOk()
        ->assertHeader('X-Robots-Tag')->assertDontSee($input['recipient'])->assertDontSee($input['instructions']);
    expect($page->headers->get('X-Robots-Tag'))->toContain('noindex')->toContain('nofollow');
});

it('rechecks issuer permission after scanning and removes a denied staged file', function () {
    [, $actor, $referral, $input] = documentRequestFixture();
    $service = app(ReferralDocumentRequests::class);
    $issued = $service->issue($actor, $referral, 1, $input);
    $this->mock(MalwareScanner::class)->shouldReceive('scanPath')->once()->andReturnUsing(function () use ($actor) {
        $permission = Permission::where('key', 'referrals.funding.manage')->firstOrFail();
        $actor->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);

        return new MalwareScanResult(MalwareScanDisposition::Clean, 'test-scanner');
    });
    // Resolve again so the service uses the scanner installed for this race boundary.
    $service = app(ReferralDocumentRequests::class);
    $file = UploadedFile::fake()->createWithContent('decision.pdf', "%PDF-1.4\nFictional decision\n%%EOF");
    expect(fn () => $service->receive($issued['token'], $issued['passcode'], $file))->toThrow(HttpException::class);
    expect(ReferralEvidence::count())->toBe(0)->and(Storage::disk('private')->allFiles())->toBeEmpty();
    expect(ReferralDocumentRequest::first()->state)->toBe('pending')->and($referral->fresh()->lock_version)->toBe(2);
});
