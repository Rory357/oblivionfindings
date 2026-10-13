<?php

use App\Domain\SecurityDevices\Endpoints\Models\EndpointAgent;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointRemoteSession;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointAgentIdentity;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointCommandSigner;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

function remoteSessionUser(Site $site, array $keys): User
{
    $role = Role::query()->create(['name' => 'remote-session-'.Str::uuid(), 'label' => 'Remote session test', 'level' => 10, 'type' => 'custom']);
    $user = User::factory()->create(['approved_at' => now(), 'email_verified_at' => now(), 'role' => $role->name]);
    $user->roles()->attach($role);
    foreach ($keys as $key) {
        $permission = Permission::query()->firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'it', 'module' => 'Operations']);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
    }
    ensureCanonicalHrStaffProfile($user, $site);

    return $user->fresh();
}
function remoteSessionDevice(Site $site): Device
{
    $device = Device::factory()->create(['domain' => 'it_infrastructure', 'category' => 'endpoint', 'provider' => 'oblivion_native', 'status' => 'active']);
    DeviceAssignment::query()->create(['device_id' => $device->id, 'assignable_type' => 'site', 'assignable_id' => $site->id,
        'assignment_type' => 'permanent', 'assigned_at' => now()->subMinute()]);

    return $device;
}
function remoteSessionPolicy($test, array $changes = []): array
{
    return [...['expected_actor_id' => $test->actor->id, 'expected_version' => 0, 'enabled' => true, 'consent_mode' => 'attended',
        'allow_control' => true, 'max_minutes' => 30, 'reason' => 'Approved reception support policy'], ...$changes];
}
function remoteSessionRequest($test, array $changes = []): array
{
    return [...['request_uuid' => (string) Str::uuid(), 'expected_actor_id' => $test->actor->id, 'expected_policy_version' => 1,
        'access_mode' => 'view', 'purpose' => 'Investigate the printer connection'], ...$changes];
}
function remoteSignedPayload(array $envelope): array
{
    $bytes = base64_decode($envelope['payload'], true);
    expect(sodium_crypto_sign_verify_detached(base64_decode($envelope['signature'], true), $bytes,
        base64_decode(app(EndpointCommandSigner::class)->publicKey(), true)))->toBeTrue();

    return json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
}
function remoteSessionCreate($test, array $changes = []): array
{
    $test->actingAs($test->actor)->putJson($test->policyUrl, remoteSessionPolicy($test))->assertOk();

    return $test->postJson($test->createUrl, remoteSessionRequest($test, $changes))->assertCreated()->json();
}
function remoteSessionAllow($test, string $id): array
{
    $offer = remoteSignedPayload($test->withToken($test->credential)->getJson('/api/endpoints/agent/remote/offer')->assertOk()->json('offer'));
    $test->postJson("/api/endpoints/agent/remote/$id/decision", [
        'decision' => 'allow', 'challenge' => $offer['challenge'], 'desktop_session' => $test->desktop,
    ])->assertOk()->assertJsonPath('status', 'allowed')->assertJsonPath('started_at', null);

    return $offer;
}
beforeEach(function () {
    Http::preventStrayRequests();
    Notification::fake();
    config()->set('endpoint_management.enabled', true);
    config()->set('endpoint_management.remote_support.enabled', true);
    config()->set('endpoint_management.server_url', 'https://localhost');
    config()->set('endpoint_management.command_signing_secret', base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(str_repeat('r', 32)))));
    URL::forceScheme('https');
    $this->withServerVariables(['HTTPS' => 'on']);
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $this->keys = ['it.view', 'it.manage', 'securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.devices.update', 'securityDevices.commands.admin',
        'securityDevices.remote.view', 'securityDevices.remote.control', 'securityDevices.remote.unattended', 'securityDevices.remote.managePolicy'];
    $this->actor = remoteSessionUser($this->site, $this->keys);
    $this->device = remoteSessionDevice($this->site);
    $issued = app(EndpointAgentIdentity::class)->issue($this->actor, $this->device, 'windows');
    $this->credential = bin2hex(random_bytes(32));
    app(EndpointAgentIdentity::class)->enrol($issued['enrolment_token'], $this->credential, 'windows');
    $this->agent = EndpointAgent::query()->where('device_id', $this->device->id)->firstOrFail();
    $this->agent->forceFill(['last_seen_at' => now(), 'observed_at' => now()])->save();
    $this->policyUrl = "/it/remote-support/devices/{$this->device->id}/policy";
    $this->createUrl = "/it/remote-support/devices/{$this->device->id}/sessions";
    $this->desktop = (string) Str::uuid();
});

it('defaults to disabled attended access and versions policy changes against the current editor', function () {
    $this->actingAs($this->actor)->getJson($this->policyUrl)->assertOk()
        ->assertJsonPath('policy.enabled', false)->assertJsonPath('policy.consent_mode', 'attended');
    $this->postJson($this->createUrl, remoteSessionRequest($this))->assertConflict();
    $this->putJson($this->policyUrl, remoteSessionPolicy($this, ['expected_actor_id' => $this->actor->id + 1]))->assertConflict();
    $this->putJson($this->policyUrl, remoteSessionPolicy($this, ['recording' => true]))->assertUnprocessable();
    $this->putJson($this->policyUrl, remoteSessionPolicy($this))->assertOk()->assertJsonPath('policy.version', 1);
    $this->putJson($this->policyUrl, remoteSessionPolicy($this))->assertConflict();
    expect(EndpointRemoteSession::query()->count())->toBe(0);
});

it('separates signed offer, consent and activation with bounded revocable leases', function () {
    $request = remoteSessionRequest($this, ['access_mode' => 'control']);
    $this->actingAs($this->actor)->putJson($this->policyUrl, remoteSessionPolicy($this))->assertOk();
    $response = $this->postJson($this->createUrl, $request)->assertCreated()->assertJsonPath('status', 'requested')
        ->assertHeader('Pragma', 'no-cache');
    $id = $response->json('uuid');
    $this->postJson($this->createUrl, $request)->assertCreated()->assertJsonPath('uuid', $id);
    $this->postJson($this->createUrl, [...$request, 'purpose' => 'A different support purpose'])->assertConflict();
    $this->withToken($this->credential)->postJson("/api/endpoints/agent/remote/$id/lease", ['desktop_session' => $this->desktop, 'activate' => true])->assertConflict();
    $offer = remoteSignedPayload($this->getJson('/api/endpoints/agent/remote/offer')->assertOk()->json('offer'));
    expect($offer)->toMatchArray(['type' => 'remote_support_offer', 'access_mode' => 'control', 'consent_mode' => 'attended', 'recording' => false]);
    $this->postJson("/api/endpoints/agent/remote/$id/decision", ['decision' => 'allow', 'challenge' => str_repeat('0', 64), 'desktop_session' => $this->desktop])->assertForbidden();
    remoteSessionAllow($this, $id);
    $this->postJson("/api/endpoints/agent/remote/$id/decision", ['decision' => 'allow', 'challenge' => $offer['challenge'], 'desktop_session' => $this->desktop])->assertOk();
    $this->postJson("/api/endpoints/agent/remote/$id/decision", ['decision' => 'allow', 'challenge' => $offer['challenge'], 'desktop_session' => (string) Str::uuid()])->assertConflict();
    $lease = remoteSignedPayload($this->postJson("/api/endpoints/agent/remote/$id/lease", ['desktop_session' => $this->desktop, 'activate' => true])
        ->assertOk()->assertJsonPath('status', 'active')->json('lease'));
    expect($lease)->toMatchArray(['type' => 'remote_support_lease', 'session_uuid' => $id, 'access_mode' => 'control', 'desktop_session' => $this->desktop]);
    expect(strtotime($lease['expires_at']) - strtotime($lease['issued_at']))->toBe(15);
    expect(DeviceEvent::query()->where('event_type', 'remote_allowed')->count())->toBe(1);
    expect(DB::table('endpoint_remote_sessions')->value('consent_challenge'))->not->toContain($offer['challenge']);
    expect(json_encode(DeviceEvent::query()->where('source', 'oblivion_remote_support')->pluck('payload')))->not->toContain($offer['challenge'], $this->credential);
    $this->actingAs($this->actor)->postJson("/it/remote-support/sessions/$id/stop")->assertOk()->assertJsonPath('status', 'stopped');
    $this->withToken($this->credential)->postJson("/api/endpoints/agent/remote/$id/lease", ['desktop_session' => $this->desktop, 'activate' => true])->assertOk()->assertJsonPath('lease', null);
    expect(EndpointRemoteSession::query()->sole()->consent_challenge)->toBeNull();
    Http::assertNothingSent();
});

it('requires distinct viewing, control, unattended and policy permissions', function () {
    remoteSessionCreate($this);
    $viewer = remoteSessionUser($this->site, array_diff($this->keys, ['securityDevices.remote.view', 'securityDevices.remote.managePolicy']));
    $this->actingAs($viewer)->postJson($this->createUrl, remoteSessionRequest($this, ['expected_actor_id' => $viewer->id]))->assertForbidden();
    $this->putJson($this->policyUrl, remoteSessionPolicy($this, ['expected_actor_id' => $viewer->id, 'expected_version' => 1]))->assertForbidden();
    $this->actingAs($this->actor)->putJson($this->policyUrl, remoteSessionPolicy($this, ['expected_version' => 1, 'consent_mode' => 'unattended']))->assertOk();
    $attended = remoteSessionUser($this->site, array_diff($this->keys, ['securityDevices.remote.unattended']));
    $this->actingAs($attended)->postJson($this->createUrl, remoteSessionRequest($this, ['expected_actor_id' => $attended->id, 'expected_policy_version' => 2]))->assertForbidden();
    $this->actingAs($this->actor)->postJson($this->createUrl, remoteSessionRequest($this, ['expected_policy_version' => 2]))->assertCreated()->assertJsonPath('consent_mode', 'unattended');
});

it('binds the request to current device and ticket scope and conceals other technicians sessions', function () {
    $hidden = remoteSessionDevice(Site::factory()->create(['is_active' => true, 'archived' => false]));
    $this->actingAs($this->actor)->getJson("/it/remote-support/devices/{$hidden->id}/policy")->assertNotFound();
    $session = remoteSessionCreate($this);
    $other = remoteSessionUser($this->site, $this->keys);
    $this->actingAs($other)->getJson('/it/remote-support/sessions/'.$session['uuid'])->assertNotFound();
    $this->postJson('/it/remote-support/sessions/'.$session['uuid'].'/stop')->assertNotFound();
    $this->actingAs($this->actor)->postJson('/it/remote-support/sessions/'.$session['uuid'].'/stop')->assertOk();
    $ticket = ItTicket::factory()->create(['site_id' => $this->site->id, 'is_sensitive' => false, 'status' => 'open', 'work_type' => 'incident', 'merged_into_ticket_id' => null]);
    $this->postJson($this->createUrl, remoteSessionRequest($this, ['ticket_id' => $ticket->id]))->assertNotFound();
    $ticket->links()->create(['relationship' => 'affected_device', 'linkable_type' => $this->device->getMorphClass(), 'linkable_id' => $this->device->id]);
    $this->postJson($this->createUrl, remoteSessionRequest($this, ['ticket_id' => $ticket->id]))->assertCreated();
    $ticket->forceFill(['is_sensitive' => true])->save();
    $this->withToken($this->credential)->getJson('/api/endpoints/agent/remote/offer')->assertOk()->assertJsonPath('offer', null);
    expect(EndpointRemoteSession::query()->whereNull('ended_at')->count())->toBe(0);
});

it('makes decline terminal and expires inactive technicians without reviving old requests', function () {
    $session = remoteSessionCreate($this);
    $id = $session['uuid'];
    $offer = remoteSignedPayload($this->withToken($this->credential)->getJson('/api/endpoints/agent/remote/offer')->json('offer'));
    $this->postJson("/api/endpoints/agent/remote/$id/decision", ['decision' => 'decline', 'challenge' => $offer['challenge'], 'desktop_session' => $this->desktop])
        ->assertOk()->assertJsonPath('status', 'declined');
    $this->postJson("/api/endpoints/agent/remote/$id/decision", ['decision' => 'allow', 'challenge' => $offer['challenge'], 'desktop_session' => $this->desktop])
        ->assertOk()->assertJsonPath('status', 'declined');
    $next = $this->actingAs($this->actor)->postJson($this->createUrl, remoteSessionRequest($this))->assertCreated()->json('uuid');
    $this->travel(31)->seconds();
    $this->getJson("/it/remote-support/sessions/$next")->assertOk()->assertJsonPath('status', 'expired');
    $this->withToken($this->credential)->getJson('/api/endpoints/agent/remote/offer')->assertOk()->assertJsonPath('offer', null);
});

it('invalidates active leases when policy, account security or current permissions change', function () {
    $id = remoteSessionCreate($this)['uuid'];
    remoteSessionAllow($this, $id);
    $this->actingAs($this->actor)->putJson($this->policyUrl, remoteSessionPolicy($this, ['expected_version' => 1, 'allow_control' => false]))->assertOk();
    $this->withToken($this->credential)->postJson("/api/endpoints/agent/remote/$id/lease", ['desktop_session' => $this->desktop, 'activate' => true])
        ->assertOk()->assertJsonPath('lease', null)->assertJsonPath('status', 'stopped');
    $next = $this->actingAs($this->actor)->postJson($this->createUrl, remoteSessionRequest($this, ['expected_policy_version' => 2]))->assertCreated()->json('uuid');
    $this->actor->forceFill(['password' => bcrypt('Changed-test-password-only')])->save();
    $this->withToken($this->credential)->getJson('/api/endpoints/agent/remote/offer')->assertOk()->assertJsonPath('offer', null);
    expect(EndpointRemoteSession::query()->where('uuid', $next)->value('end_reason'))->toBe('access_changed');
    $next = $this->actingAs($this->actor->fresh())->postJson($this->createUrl, remoteSessionRequest($this, ['expected_policy_version' => 2]))->assertCreated()->json('uuid');
    $permission = Permission::query()->where('key', 'securityDevices.remote.view')->firstOrFail();
    $this->actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
    $this->withToken($this->credential)->getJson('/api/endpoints/agent/remote/offer')->assertOk()->assertJsonPath('offer', null);
    expect(EndpointRemoteSession::query()->where('uuid', $next)->value('end_reason'))->toBe('access_changed');
    $this->actingAs($this->actor->fresh())->postJson("/it/remote-support/sessions/$next/stop")->assertOk();
});

it('denies revoked or reassigned computers and cannot renew an expired lease', function () {
    $id = remoteSessionCreate($this)['uuid'];
    remoteSessionAllow($this, $id);
    $this->travel(16)->seconds();
    $this->withToken($this->credential)->postJson("/api/endpoints/agent/remote/$id/lease", ['desktop_session' => $this->desktop, 'activate' => true])
        ->assertOk()->assertJsonPath('lease', null)->assertJsonPath('status', 'expired');
    $this->agent->forceFill(['revoked_at' => now(), 'credential_hash' => null])->save();
    $this->getJson('/api/endpoints/agent/remote/offer')->assertUnauthorized();
});

it('protects remote support routes with deployment origin, HTTPS and private bounded errors', function () {
    $this->actingAs($this->actor)->getJson($this->policyUrl)->assertOk()->assertHeader('Pragma', 'no-cache');
    $this->putJson($this->policyUrl, remoteSessionPolicy($this, ['private_unknown' => 'never-echo-this']))->assertUnprocessable()->assertDontSee('never-echo-this');
    $this->getJson('https://different.example.test'.$this->policyUrl)->assertStatus(400);
    $this->withServerVariables(['HTTPS' => 'off'])->getJson('http://localhost'.$this->policyUrl)->assertStatus(400);
    Http::assertNothingSent();
});

it('records timeout events without a participant and invalidates withdrawn ticket evidence', function () {
    $ticket = ItTicket::factory()->create(['site_id' => $this->site->id, 'is_sensitive' => false, 'status' => 'open', 'work_type' => 'incident', 'merged_into_ticket_id' => null]);
    $ticket->links()->create(['relationship' => 'affected_device', 'linkable_type' => $this->device->getMorphClass(), 'linkable_id' => $this->device->id]);
    $id = remoteSessionCreate($this, ['ticket_id' => $ticket->id])['uuid'];
    remoteSessionAllow($this, $id);
    $ticket->links()->delete();
    $this->withToken($this->credential)->postJson("/api/endpoints/agent/remote/$id/lease", ['desktop_session' => $this->desktop, 'activate' => true])
        ->assertOk()->assertJsonPath('lease', null)->assertJsonPath('status', 'stopped');
    $next = $this->actingAs($this->actor)->postJson($this->createUrl, remoteSessionRequest($this))->assertCreated()->json('uuid');
    $this->travel(31)->seconds();
    $this->artisan('endpoints:expire-remote-sessions')->assertSuccessful();
    expect(EndpointRemoteSession::query()->where('uuid', $next)->value('status'))->toBe('expired');
    $before = DeviceEvent::query()->where('event_type', 'remote_expired')->count();
    $this->artisan('endpoints:expire-remote-sessions')->assertSuccessful();
    expect(DeviceEvent::query()->where('event_type', 'remote_expired')->count())->toBe($before);
});

it('denies a separate control grant and binds decisions to the enrolled computer', function () {
    $id = remoteSessionCreate($this)['uuid'];
    $viewer = remoteSessionUser($this->site, array_diff($this->keys, ['securityDevices.remote.control']));
    $this->actingAs($this->actor)->postJson("/it/remote-support/sessions/$id/stop")->assertOk();
    $this->actingAs($viewer)->postJson($this->createUrl, remoteSessionRequest($this, ['expected_actor_id' => $viewer->id, 'access_mode' => 'control']))->assertForbidden();
    $next = $this->actingAs($this->actor)->postJson($this->createUrl, remoteSessionRequest($this))->assertCreated()->json('uuid');
    $other = remoteSessionDevice($this->site);
    $issued = app(EndpointAgentIdentity::class)->issue($this->actor, $other, 'windows');
    $otherCredential = bin2hex(random_bytes(32));
    app(EndpointAgentIdentity::class)->enrol($issued['enrolment_token'], $otherCredential, 'windows');
    $this->withToken($otherCredential)->postJson("/api/endpoints/agent/remote/$next/decision", [
        'decision' => 'allow', 'challenge' => str_repeat('a', 64), 'desktop_session' => $this->desktop,
    ])->assertNotFound();
    $this->device->assignments()->firstOrFail()->forceFill(['released_at' => now()])->save();
    $this->withToken($this->credential)->getJson('/api/endpoints/agent/remote/offer')->assertUnauthorized();
    Http::assertNothingSent();
});

function remoteMediaDescription(string $label = 'fixture'): string
{
    return "v=0\r\no=- 123 1 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\n"
        ."m=application 9 UDP/DTLS/SCTP webrtc-datachannel\r\n"
        .'a=fingerprint:sha-256 '.implode(':', array_fill(0, 32, 'AB'))."\r\n"
        .'a=ice-ufrag:'.$label."\r\na=ice-pwd:fixture-password-for-rtc-tests\r\n"
        ."a=sctp-port:5000\r\n";
}

it('binds encrypted media setup to consent desktop and one immutable offer and viewer', function () {
    $session = remoteSessionCreate($this);
    $id = $session['uuid'];
    $offerUrl = "/api/endpoints/agent/remote/$id/media/offer";
    $agentUrl = "/api/endpoints/agent/remote/$id/media?desktop_session={$this->desktop}";
    $viewerUrl = "/it/remote-support/sessions/$id/media";
    $offer = ['desktop_session' => $this->desktop, 'offer_uuid' => (string) Str::uuid(), 'sdp' => remoteMediaDescription()];
    $this->withToken($this->credential)->postJson($offerUrl, $offer)->assertConflict();
    $this->actingAs($this->actor)->getJson($viewerUrl)->assertConflict();
    remoteSessionAllow($this, $id);
    $this->postJson($offerUrl, [...$offer, 'desktop_session' => (string) Str::uuid()])->assertConflict();
    $this->postJson($offerUrl, $offer)->assertOk()->assertJsonPath('offer_uuid', $offer['offer_uuid']);
    $this->postJson($offerUrl, $offer)->assertOk();
    $this->postJson($offerUrl, [...$offer, 'sdp' => remoteMediaDescription('changed')])->assertConflict();
    $this->getJson($agentUrl)->assertOk()->assertJsonPath('signal', null);
    $this->actingAs($this->actor)->getJson($viewerUrl)->assertOk()->assertJsonPath('signal.sdp', $offer['sdp'])->assertJsonPath('signal.desktop_session', $this->desktop);
    $answer = ['offer_uuid' => $offer['offer_uuid'], 'viewer_uuid' => (string) Str::uuid(), 'sdp' => remoteMediaDescription('answer')];
    $this->postJson("$viewerUrl/answer", $answer)->assertOk()->assertJsonPath('viewer_uuid', $answer['viewer_uuid']);
    $this->postJson("$viewerUrl/answer", $answer)->assertOk();
    $this->postJson("$viewerUrl/answer", [...$answer, 'viewer_uuid' => (string) Str::uuid()])->assertConflict();
    $this->postJson("$viewerUrl/answer", [...$answer, 'sdp' => remoteMediaDescription('replacement')])->assertConflict();
    $reply = remoteSignedPayload($this->withToken($this->credential)->getJson($agentUrl)->assertOk()->json('signal'));
    expect($reply)->toMatchArray(['type' => 'remote_media_answer', 'agent_uuid' => $this->agent->uuid,
        'session_uuid' => $id, 'desktop_session' => $this->desktop, 'offer_uuid' => $offer['offer_uuid'],
        'viewer_uuid' => $answer['viewer_uuid'], 'sdp' => $answer['sdp'], 'access_mode' => 'view']);
    expect(strtotime($reply['expires_at']) - strtotime($reply['issued_at']))->toBeLessThanOrEqual(15);
    $stored = DB::table('endpoint_remote_signals')->first();
    expect($stored->offer_sdp)->not->toContain('v=0')->and($stored->answer_sdp)->not->toContain('a=fingerprint');
    expect(json_encode(DeviceEvent::query()->where('device_id', $this->device->id)->pluck('payload')->all()))->not->toContain('ice-pwd');
    $this->actingAs($this->actor)->postJson("/it/remote-support/sessions/$id/stop")->assertOk();
    expect(DB::table('endpoint_remote_signals')->count())->toBe(0);
    $this->withToken($this->credential)->getJson($agentUrl)->assertOk()->assertJsonPath('signal', null)->assertJsonPath('status', 'stopped');
});

it('conceals media descriptions from other technicians agents and withdrawn access', function () {
    $id = remoteSessionCreate($this)['uuid'];
    remoteSessionAllow($this, $id);
    $offer = ['desktop_session' => $this->desktop, 'offer_uuid' => (string) Str::uuid(), 'sdp' => remoteMediaDescription()];
    $this->postJson("/api/endpoints/agent/remote/$id/media/offer", $offer)->assertOk();
    $other = remoteSessionUser($this->site, $this->keys);
    $this->actingAs($other)->getJson("/it/remote-support/sessions/$id/media")->assertNotFound();
    $this->withToken(str_repeat('f', 64))->getJson("/api/endpoints/agent/remote/$id/media?desktop_session={$this->desktop}")->assertUnauthorized();
    $this->actingAs($this->actor)->putJson($this->policyUrl, remoteSessionPolicy($this, ['expected_version' => 1, 'enabled' => false]))->assertOk();
    expect(DB::table('endpoint_remote_signals')->count())->toBe(0);
    $this->withToken($this->credential)->getJson("/api/endpoints/agent/remote/$id/media?desktop_session={$this->desktop}")
        ->assertOk()->assertJsonPath('signal', null)->assertJsonPath('status', 'stopped');
});

it('rejects unexpected media modes malformed descriptions and oversized setup without leaking them', function () {
    $id = remoteSessionCreate($this)['uuid'];
    remoteSessionAllow($this, $id);
    $url = "/api/endpoints/agent/remote/$id/media/offer";
    $body = ['desktop_session' => $this->desktop, 'offer_uuid' => (string) Str::uuid(), 'sdp' => remoteMediaDescription()];
    foreach ([str_repeat('x', 32769), str_replace('m=application', 'm=video', $body['sdp']),
        $body['sdp']."m=application 9 UDP/DTLS/SCTP webrtc-datachannel\r\n",
        str_replace('sha-256', 'sha-1', $body['sdp']), str_replace("\r\n", "\n", $body['sdp']),
        str_replace('s=-', "s=\0secret", $body['sdp']),
    ] as $bad) {
        $this->postJson($url, [...$body, 'sdp' => $bad])->assertUnprocessable()->assertJsonMissing(['sdp' => $bad]);
    }
    $this->postJson($url, [...$body, 'execute' => 'anything'])->assertUnprocessable();
    $this->postJson($url, [...$body, 'sdp' => str_repeat('secret', 12000)])->assertStatus(413);
    expect(DB::table('endpoint_remote_signals')->count())->toBe(0);
});

it('removes ephemeral connection setup when the lease expires even if both sides disappear', function () {
    $id = remoteSessionCreate($this)['uuid'];
    remoteSessionAllow($this, $id);
    $this->postJson("/api/endpoints/agent/remote/$id/media/offer", [
        'desktop_session' => $this->desktop, 'offer_uuid' => (string) Str::uuid(), 'sdp' => remoteMediaDescription(),
    ])->assertOk();
    $this->travel(16)->seconds();
    $this->artisan('endpoints:expire-remote-sessions')->assertSuccessful();
    expect(DB::table('endpoint_remote_signals')->count())->toBe(0);
    $this->actingAs($this->actor)->getJson("/it/remote-support/sessions/$id/media")->assertOk()
        ->assertJsonPath('signal', null)->assertJsonPath('status', 'expired');
});

it('separates enrolled-computer polling limits behind a shared Site IP and reserves Stop capacity', function () {
    config()->set('endpoint_management.agent_requests_per_minute', 3);
    $limiter = app(RateLimiter::class)->limiter('api');
    $poll = Request::create('https://localhost/api/endpoints/agent/remote/offer');
    $stop = Request::create('https://localhost/api/endpoints/agent/remote/'.Str::uuid().'/stop', 'POST');
    foreach ([$poll, $stop] as $request) {
        $request->headers->set('Authorization', 'Bearer '.$this->credential);
    }
    expect(array_intersect(array_map(fn ($limit) => $limit->key, $limiter($poll)),
        array_map(fn ($limit) => $limit->key, $limiter($stop))))->toBeEmpty();

    $otherDevice = remoteSessionDevice($this->site);
    $issued = app(EndpointAgentIdentity::class)->issue($this->actor, $otherDevice, 'windows');
    $secondCredential = bin2hex(random_bytes(32));
    app(EndpointAgentIdentity::class)->enrol($issued['enrolment_token'], $secondCredential, 'windows');
    $secondAgent = EndpointAgent::query()->where('device_id', $otherDevice->id)->firstOrFail();
    $secondAgent->forceFill(['last_seen_at' => now(), 'observed_at' => now()])->save();
    $this->withServerVariables(['HTTPS' => 'on', 'REMOTE_ADDR' => '192.0.2.99']);
    for ($i = 0; $i < 3; $i++) {
        $this->withToken($this->credential)->getJson('/api/endpoints/agent/remote/offer')->assertOk();
    }
    $this->getJson('/api/endpoints/agent/remote/offer')->assertTooManyRequests();
    $this->withToken($secondCredential)->getJson('/api/endpoints/agent/remote/offer')->assertOk();
    // A nonexistent owned-session lookup reaches authorization instead of being
    // swallowed by the exhausted polling budget.
    $this->withToken($this->credential)->postJson('/api/endpoints/agent/remote/'.Str::uuid().'/stop')
        ->assertNotFound();
});

it('issues separate expiring relay credentials only after consent and keeps the deployment secret private', function () {
    config()->set('endpoint_management.remote_support.relay_urls', ['turns:relay.example.test:5349?transport=tcp', 'turn:relay.example.test:3478?transport=udp']);
    config()->set('endpoint_management.remote_support.relay_secret', str_repeat('relay-fixture-', 4));
    $id = remoteSessionCreate($this)['uuid'];
    $agentUrl = "/api/endpoints/agent/remote/$id/relay?desktop_session={$this->desktop}";
    $technicianUrl = "/it/remote-support/sessions/$id/relay";
    $this->withToken($this->credential)->getJson($agentUrl)->assertConflict();
    $this->actingAs($this->actor)->getJson($technicianUrl)->assertConflict();
    remoteSessionAllow($this, $id);
    $this->getJson("/api/endpoints/agent/remote/$id/relay?desktop_session=".Str::uuid())->assertConflict();
    $response = $this->getJson($agentUrl)->assertOk()->assertHeader('Pragma', 'no-cache');
    $agentGrant = remoteSignedPayload($response->json('relay'));
    expect($agentGrant)->toMatchArray(['type' => 'remote_relay', 'version' => 1, 'role' => 'agent',
        'session_uuid' => $id, 'agent_uuid' => $this->agent->uuid, 'desktop_session' => $this->desktop,
        'access_mode' => 'view']);
    expect($agentGrant['credential'])->toBe(base64_encode(hash_hmac('sha1', $agentGrant['username'], str_repeat('relay-fixture-', 4), true)));
    expect((int) explode(':', $agentGrant['username'])[0])->toBe(EndpointRemoteSession::query()->sole()->session_expires_at->getTimestamp());
    expect(strtotime($agentGrant['expires_at']) - strtotime($agentGrant['issued_at']))->toBeLessThanOrEqual(15);
    expect(json_encode($agentGrant))->not->toContain(str_repeat('relay-fixture-', 4));
    $viewerGrant = $this->actingAs($this->actor)->getJson($technicianUrl)->assertOk()->json('relay');
    expect($viewerGrant['role'])->toBe('technician')->and($viewerGrant['username'])->not->toBe($agentGrant['username'])
        ->and($viewerGrant['credential'])->not->toBe($agentGrant['credential']);
    expect(json_encode(DeviceEvent::query()->where('device_id', $this->device->id)->pluck('payload')->all()))
        ->not->toContain($agentGrant['credential'], $viewerGrant['credential'], 'relay-fixture');
    $this->postJson("/it/remote-support/sessions/$id/stop")->assertOk();
    $this->getJson($technicianUrl)->assertOk()->assertJsonPath('relay', null);
    $this->withToken($this->credential)->getJson($agentUrl)->assertOk()->assertJsonPath('relay', null);
    Http::assertNothingSent();
});

it('denies relay credentials to other participants and after current site authority is withdrawn', function () {
    config()->set('endpoint_management.remote_support.relay_urls', ['turn:relay.example.test:3478']);
    config()->set('endpoint_management.remote_support.relay_secret', str_repeat('s', 64));
    $id = remoteSessionCreate($this)['uuid'];
    remoteSessionAllow($this, $id);
    $other = remoteSessionUser($this->site, $this->keys);
    $url = "/it/remote-support/sessions/$id/relay";
    $this->actingAs($other)->getJson($url)->assertNotFound();
    $otherDevice = remoteSessionDevice($this->site);
    $issued = app(EndpointAgentIdentity::class)->issue($this->actor, $otherDevice, 'windows');
    $credential = bin2hex(random_bytes(32));
    app(EndpointAgentIdentity::class)->enrol($issued['enrolment_token'], $credential, 'windows');
    $this->withToken($credential)->getJson("/api/endpoints/agent/remote/$id/relay?desktop_session={$this->desktop}")->assertNotFound();
    $this->device->assignments()->firstOrFail()->forceFill(['released_at' => now()])->save();
    $this->actingAs($this->actor)->getJson($url)->assertNotFound();
    $this->withToken($this->credential)->getJson("/api/endpoints/agent/remote/$id/relay?desktop_session={$this->desktop}")->assertUnauthorized();
});

it('rejects absent or malformed relay configuration without exposing secrets or trying another server', function () {
    $id = remoteSessionCreate($this)['uuid'];
    remoteSessionAllow($this, $id);
    $url = "/api/endpoints/agent/remote/$id/relay?desktop_session={$this->desktop}";
    config()->set('endpoint_management.remote_support.relay_secret', str_repeat('private-relay-secret-', 3));
    foreach ([[], ['stun:public.example.test:3478'], ['turn:relay.example.test:0'],
        ['turn:relay.example.test:99999'], ['turn:user:secret@relay.example.test:3478'],
        ['turn:relay.example.test:3478/path'], ['turn:relay.example.test:3478?secret=private'],
        ['turns:relay.example.test:5349?transport=udp'], ['turn:[invalid]:3478'],
    ] as $urls) {
        config()->set('endpoint_management.remote_support.relay_urls', $urls);
        $this->getJson($url)->assertStatus(503)->assertDontSee('private-relay-secret');
    }
    config()->set('endpoint_management.remote_support.relay_urls', ['turn:relay.example.test:3478']);
    config()->set('endpoint_management.remote_support.relay_secret', 'short');
    $this->getJson($url)->assertStatus(503);
    Http::assertNothingSent();
});

it('offers connection choices to current scoped viewers without granting policy management', function () {
    $this->actingAs($this->actor)->putJson($this->policyUrl, remoteSessionPolicy($this))->assertOk();
    $viewer = remoteSessionUser($this->site, array_diff($this->keys, ['securityDevices.remote.control', 'securityDevices.remote.managePolicy']));
    $url = "/it/remote-support/devices/{$this->device->id}/connection-options";
    $this->actingAs($viewer)->getJson($url)->assertOk()->assertJson([
        'actor_id' => $viewer->id, 'device_id' => $this->device->id, 'policy_version' => 1,
        'enabled' => true, 'consent_mode' => 'attended', 'allow_control' => false, 'max_minutes' => 30,
    ])->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    $this->getJson($this->policyUrl)->assertForbidden();
    $this->actingAs($this->actor)->getJson($url)->assertOk()->assertJsonPath('allow_control', true);
    $noView = remoteSessionUser($this->site, array_diff($this->keys, ['securityDevices.remote.view']));
    $this->actingAs($noView)->getJson($url)->assertForbidden();
    $this->device->assignments()->firstOrFail()->forceFill(['released_at' => now()])->save();
    $this->actingAs($viewer)->getJson($url)->assertNotFound();
    Http::assertNothingSent();
});
