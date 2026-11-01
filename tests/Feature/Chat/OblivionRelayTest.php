<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Jobs\SendChatRelayRevocation;
use App\Models\AuditLog;
use App\Models\ChatCall;
use App\Models\ChatCallParticipant;
use App\Models\OpsConversation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Chat\ChatCallReconciler;
use App\Services\Chat\ChatMembershipGaps;
use App\Services\Chat\ChatOffboarding;
use App\Services\Chat\ChatRelayCredentials;
use App\Services\Chat\ChatRelayProbe;
use App\Services\Chat\ChatServiceHealth;
use App\Services\Chat\OblivionRelay;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Oblivion Relay, app side (spec 3.6, 3.7 and section 9 "In the app"). The vectors are the spec's
// shared known-answer vectors, also used by relay/test.
const RELAY_KAV = [
    'secret' => 'oblivion-relay-test-secret-0123456789',
    'username' => '1798761600:obli-chat-b0d51c58c8b9c1f458fadf16c7d375630ef51da4df81915893b05c0fa4ed8bc6',
    'realm' => 'turn.example.org',
    'password' => 'mnNManMB1KVphfDcfsHwp6Ly9LI=',
    'key' => '77ab678c8fe33bbc924f657e74cf8b14',
];
const RELAY_CONTROL_KAV = [
    'secret' => 'oblivion-relay-control-test-0123456789',
    'key_id' => 'k1',
    'timestamp' => '1798761600',
    'nonce' => '00112233445566778899aabbccddeeff',
    'body' => '{"revocations":[{"id":"obli-chat-b0d51c58c8b9c1f458fadf16c7d375630ef51da4df81915893b05c0fa4ed8bc6","expires_up_to":1798765200}],"reason":"offboarded"}',
    'signature' => 'ORL-3hdG48NXinOxKS55SnorOgyU3fULooysZTgFcOw',
];
const RELAY_NODE = 'http://127.0.0.1:9479';

beforeEach(function () {
    config([
        'chat.shared_experience' => true,
        'chat.relay.urls' => ['turns:relay.example.test:443?transport=tcp', 'turn:relay.example.test:3479?transport=udp'],
        'chat.relay.secret' => RELAY_KAV['secret'],
        'chat.relay.credential_seconds' => 3600,
        'chat.relay.health_url' => '',
        'chat.relay.control' => ['urls' => [RELAY_NODE], 'secret' => RELAY_CONTROL_KAV['secret'], 'key_id' => 'k1'],
        // The reconciler tears media down on the voice server; a faked one answers.
        'chat.calling' => ['url' => 'wss://voice.example.test', 'api_url' => 'https://voice.example.test', 'api_key' => 'test',
            'api_secret' => str_repeat('test', 12), 'verified' => true, 'authorization_gateway_verified' => true],
    ]);
    $this->freezeSecond();
});

function relayStaff(Site $site, array $permissions = []): User
{
    $user = User::factory()->create(['approved_at' => now(), 'role' => 'staff']);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
        'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null,
    ]);
    if ($permissions) {
        $role = Role::create(['name' => 'relay_'.Str::random(8), 'label' => 'Relay test', 'level' => 50, 'type' => 'custom']);
        foreach ($permissions as $key) {
            $role->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'chat', 'module' => 'System'])->id);
        }
        $user->roles()->attach($role->id);
    }

    return $user->fresh();
}

function relayGroup(User ...$people): OpsConversation
{
    $conversation = OpsConversation::create(['conversation_type' => 'group', 'title' => 'Night team']);
    foreach ($people as $index => $person) {
        $conversation->participants()->create(['user_id' => $person->id, 'role' => $index === 0 ? 'admin' : 'member']);
    }

    return $conversation;
}

/** A call with these people in it; `$statuses` maps user id to participant status. */
function relayCall(OpsConversation $conversation, array $people, array $statuses = [], array $call = []): ChatCall
{
    $model = ChatCall::create(['id' => (string) Str::uuid(), 'conversation_id' => $conversation->id, 'started_by' => $people[0]->id,
        'host_id' => $people[0]->id, 'mutation_id' => (string) Str::uuid(), 'status' => 'active', 'ring_until' => now()->addMinute(),
        'connected_at' => now(), ...$call]);
    foreach ($people as $person) {
        $model->participants()->create(['user_id' => $person->id, 'media_identity' => (string) Str::uuid(),
            'status' => $statuses[$person->id] ?? 'connected', 'joined_at' => now(), 'device_id' => (string) Str::uuid()]);
    }

    return $model;
}

function relayFakeNode(int $status = 200): void
{
    Http::fake([RELAY_NODE.'/*' => Http::response(['ended' => 1, 'held' => 1], $status), 'https://voice.example.test/*' => Http::response([])]);
}

/** Every revoke the app sent: [reason, [id => expires_up_to]] per request. */
function relaySent(): array
{
    return collect(Http::recorded())->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $request) => str_ends_with($request->url(), '/control/revoke'))
        ->map(function (Request $request) {
            $body = json_decode($request->body(), true);

            return [$body['reason'], collect($body['revocations'])->pluck('expires_up_to', 'id')->all()];
        })->values()->all();
}

function relayId(User $user, ChatCall $call): string
{
    return ChatRelayCredentials::opaqueId($user->id, $call->id);
}

it('issues the TURN REST username and credential the relay checks, one id per person per call', function () {
    $user = User::factory()->create();
    $callA = (string) Str::uuid();
    $entry = app(ChatRelayCredentials::class)->for($user, $callA);
    $id = 'obli-chat-'.hash_hmac('sha256', 'chat|'.$callA.'|'.$user->id, (string) config('app.key'));
    expect($entry['username'])->toBe((now()->getTimestamp() + 3600).':'.$id)
        ->and($entry['username'])->toMatch('/\A[1-9][0-9]{9,10}:obli-chat-[0-9a-f]{64}\z/')
        ->and($entry['credential'])->toBe(base64_encode(hash_hmac('sha1', $entry['username'], RELAY_KAV['secret'], true)))
        ->and($entry['expires_at'])->toBe(CarbonImmutable::createFromTimestamp(now()->getTimestamp() + 3600, 'UTC')->format('Y-m-d\TH:i:s\Z'))
        // UDP first, then TCP, then TLS.
        ->and($entry['urls'])->toBe(['turn:relay.example.test:3479?transport=udp', 'turns:relay.example.test:443?transport=tcp']);
    expect(explode(':', app(ChatRelayCredentials::class)->for($user, $callA)['username'])[1])->toBe($id)
        ->and(explode(':', app(ChatRelayCredentials::class)->for($user, (string) Str::uuid())['username'])[1])->not->toBe($id)
        ->and(explode(':', app(ChatRelayCredentials::class)->for(User::factory()->create(), $callA)['username'])[1])->not->toBe($id);
    // The expiry never goes past an hour, whatever the setting says.
    config(['chat.relay.credential_seconds' => 7200]);
    expect((int) explode(':', app(ChatRelayCredentials::class)->for($user, $callA)['username'])[0])->toBe(now()->getTimestamp() + 3600);
    config(['chat.relay.credential_seconds' => 600]);
    expect((int) explode(':', app(ChatRelayCredentials::class)->for($user, $callA)['username'])[0])->toBe(now()->getTimestamp() + 600);
});

it('matches the known-answer vector in spec 3.1', function () {
    $credential = base64_encode(hash_hmac('sha1', RELAY_KAV['username'], RELAY_KAV['secret'], true));
    expect($credential)->toBe(RELAY_KAV['password'])
        ->and(md5(RELAY_KAV['username'].':'.RELAY_KAV['realm'].':'.$credential))->toBe(RELAY_KAV['key']);
});

it('returns no relay entry for missing or invalid settings, and never an outside server', function (array $settings) {
    config($settings);
    $entry = app(ChatRelayCredentials::class)->for(User::factory()->create(), (string) Str::uuid());
    expect($entry)->toBe([])->and(app(ChatRelayCredentials::class)->configured())->toBeFalse();
})->with([
    'no URLs' => [['chat.relay.urls' => []]],
    'an http URL' => [['chat.relay.urls' => ['http://relay.example.test:3478']]],
    'five URLs' => [['chat.relay.urls' => array_fill(0, 5, 'turn:relay.example.test:3478')]],
    'turns over UDP' => [['chat.relay.urls' => ['turns:relay.example.test:443?transport=udp']]],
    'no secret' => [['chat.relay.secret' => '']],
    'a short secret' => [['chat.relay.secret' => 'short']],
    'a long secret' => [['chat.relay.secret' => str_repeat('x', 513)]],
]);

it('never puts the secret in the output, the logs or audit rows', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged[] = $event->message.json_encode($event->context);
    });
    relayFakeNode(500);
    config(['queue.default' => 'database']);
    Queue::fake();
    $site = Site::factory()->create();
    [$alex, $sam] = [relayStaff($site), relayStaff($site)];
    $call = relayCall(relayGroup($alex, $sam), [$alex, $sam]);
    $entry = app(ChatRelayCredentials::class)->for($sam, $call->id);
    app(OblivionRelay::class)->revokePerson($sam, 'hand');
    $this->travel(6)->minutes();
    app(OblivionRelay::class)->reportStale();
    $everything = json_encode($entry).implode('', $logged).AuditLog::all()->toJson().json_encode(app(ChatServiceHealth::class)->snapshot());
    expect($everything)->not->toContain(RELAY_KAV['secret'])->not->toContain(RELAY_CONTROL_KAV['secret'])
        ->and(AuditLog::all()->toJson())->not->toContain($entry['credential'])->not->toContain($entry['username']);
    expect($logged)->not->toBeEmpty();
});

it('signs the control vector in spec 3.7 exactly', function () {
    expect(OblivionRelay::sign(RELAY_CONTROL_KAV['secret'], 'POST', '/control/revoke', 'k1', RELAY_CONTROL_KAV['timestamp'], RELAY_CONTROL_KAV['nonce'], RELAY_CONTROL_KAV['body']))
        ->toBe(RELAY_CONTROL_KAV['signature'])
        ->and(strlen(RELAY_CONTROL_KAV['body']))->toBe(150);
});

it('refuses control URLs that are public, hostnames, https or have a path', function () {
    foreach (['http://8.8.8.8:9479', 'http://203.0.113.9:9479', 'http://relay.internal:9479', 'https://127.0.0.1:9479',
        'http://127.0.0.1:9479/control', 'http://127.0.0.1:9479?x=1', 'http://127.0.0.1', 'http://0.0.0.0:9479', 'http://[2001:db8::1]:9479',
        'http://[::ffff:10.0.0.1]:9479', 'http://172.32.0.1:9479'] as $url) {
        expect(OblivionRelay::isValidControlUrl($url))->toBeFalse($url);
        config(['chat.relay.control.urls' => [RELAY_NODE, $url]]);
        expect(app(OblivionRelay::class)->configured())->toBeFalse($url);
    }
    foreach (['http://127.0.0.1:9479', 'http://10.0.0.5:9479', 'http://172.16.4.2:9479', 'http://192.168.1.9:9479', 'http://100.64.0.2:9479',
        'http://[::1]:9479', 'http://[fd00::5]:9479'] as $url) {
        expect(OblivionRelay::isValidControlUrl($url))->toBeTrue($url);
    }
});

it('sends a signed revoke after the commit, with the expiry the app could have issued', function () {
    relayFakeNode();
    $site = Site::factory()->create();
    [$alex, $sam] = [relayStaff($site), relayStaff($site)];
    $call = relayCall(relayGroup($alex, $sam), [$alex, $sam]);
    DB::transaction(function () use ($sam) {
        app(OblivionRelay::class)->revokePerson($sam, 'hand');
        expect(Http::recorded())->toBeEmpty();
    });
    $request = collect(Http::recorded())->sole()[0];
    $expected = OblivionRelay::sign(RELAY_CONTROL_KAV['secret'], 'POST', '/control/revoke', 'k1', $request->header('X-Relay-Timestamp')[0], $request->header('X-Relay-Nonce')[0], $request->body());
    expect($request->url())->toBe(RELAY_NODE.'/control/revoke')
        ->and($request->header('X-Relay-Key')[0])->toBe('k1')
        ->and($request->header('X-Relay-Nonce')[0])->toMatch('/\A[0-9a-f]{32}\z/')
        ->and($request->header('X-Relay-Signature')[0])->toBe($expected)
        ->and(json_decode($request->body(), true))->toBe(['revocations' => [['id' => relayId($sam, $call), 'expires_up_to' => now()->getTimestamp() + 3600]], 'reason' => 'hand']);
    // A rolled-back transaction sends nothing.
    try {
        DB::transaction(function () use ($sam) {
            app(OblivionRelay::class)->revokePerson($sam, 'hand');
            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }
    expect(Http::recorded())->toHaveCount(1);
});

it('sends the person’s ids for live and recent calls from Chat offboarding, and no one else’s', function () {
    relayFakeNode();
    $site = Site::factory()->create();
    $admin = relayStaff($site, ['settings.access.manage']);
    [$leaver, $stays, $third] = [relayStaff($site), relayStaff($site), relayStaff($site)];
    $group = relayGroup($stays, $leaver, $third);
    $live = relayCall($group, [$stays, $leaver, $third]);
    $recent = relayCall($group, [$stays, $leaver], [], ['status' => 'ended', 'ended_at' => now()->subHours(2)]);
    $old = relayCall($group, [$stays, $leaver], [], ['status' => 'ended', 'ended_at' => now()->subHours(6)]);
    $leaver->hrEmployeeProfile()->update(['end_date' => now()->subDays(2)->toDateString()]);
    $before = AuditLog::where('action', 'chat.relay.revoked')->count();

    app(ChatOffboarding::class)->complete($admin, $leaver, ['owner' => 'care_team']);

    $sent = relaySent();
    $ids = collect($sent)->flatMap(fn ($request) => array_keys($request[1]))->unique()->values();
    expect($ids->all())->toEqualCanonicalizing([relayId($leaver, $live), relayId($leaver, $recent)])
        ->and($ids)->not->toContain(relayId($leaver, $old), relayId($stays, $live), relayId($third, $live))
        ->and(collect($sent)->pluck(0)->all())->toContain('offboarded');
    // One audit row per revoke sent.
    $rows = AuditLog::where('action', 'chat.relay.revoked')->get();
    expect($rows->count() - $before)->toBe(count($sent));
    $offboarded = $rows->first(fn ($row) => $row->meta['reason'] === 'offboarded');
    expect($offboarded->user_id)->toBe($admin->id)
        ->and($offboarded->meta)->toMatchArray(['actor' => 'user', 'person_id' => $leaver->id, 'nodes_confirmed' => [RELAY_NODE], 'nodes_waiting' => []])
        ->and($offboarded->meta['call_ids'])->toEqualCanonicalizing([$live->id, $recent->id]);
});

it('sends the leaver’s ids when the leaver rule opens a gap', function () {
    relayFakeNode();
    $site = Site::factory()->create();
    [$alex, $sam, $kim] = [relayStaff($site), relayStaff($site), relayStaff($site)];
    $group = relayGroup($alex, $sam, $kim);
    $call = relayCall($group, [$alex, $sam, $kim]);
    expect(app(ChatMembershipGaps::class)->sync())->toBe(0)->and(relaySent())->toBe([]);
    HrEmployeeProfile::where('user_id', $sam->id)->update(['end_date' => now()->subDay()->toDateString()]);

    expect(app(ChatMembershipGaps::class)->sync())->toBe(1);

    expect(relaySent())->toBe([['offboarded', [relayId($sam, $call) => now()->getTimestamp() + 3600]]])
        ->and(AuditLog::where('action', 'chat.relay.revoked')->sole()->meta)->toMatchArray(['actor' => 'system', 'person_id' => $sam->id, 'call_ids' => [$call->id]]);
    // A second sync opens nothing, so it sends nothing.
    app(ChatMembershipGaps::class)->sync();
    expect(relaySent())->toHaveCount(1);
});

it('sends one id when a participant becomes left, removed, revoked or expired in the reconciler', function (string $kind, string $status, string $reason) {
    relayFakeNode();
    $site = Site::factory()->create();
    [$host, $other, $person] = [relayStaff($site), relayStaff($site), relayStaff($site)];
    $group = relayGroup($host, $other, $person);
    $call = relayCall($group, [$host, $other, $person], [$person->id => $kind === 'expired' ? 'invited' : ($kind === 'removed' ? 'removing' : 'connected')]);
    $participant = ChatCallParticipant::where('call_id', $call->id)->where('user_id', $person->id)->first();
    match ($kind) {
        'left' => ChatCallParticipant::whereKey($participant->id)->update(['updated_at' => now()->subMinute()]),
        'expired' => $participant->update(['invitation_expires_at' => now()->subSecond()]),
        // Losing the conversation takes away access to its call for this person only.
        'revoked' => $group->participants()->where('user_id', $person->id)->delete(),
        default => null,
    };
    // The others keep their heartbeat fresh, so only this person changes.
    ChatCallParticipant::where('call_id', $call->id)->where('user_id', '!=', $person->id)->update(['updated_at' => now()]);

    app(ChatCallReconciler::class)->reconcile($call, true);

    expect($participant->fresh()->status)->toBe($status)
        ->and(relaySent())->toBe([[$reason, [relayId($person, $call) => now()->getTimestamp() + 3600]]])
        ->and(AuditLog::where('action', 'chat.relay.revoked')->count())->toBe(1);
})->with([
    'left' => ['left', 'left', 'left_call'],
    'removed' => ['removed', 'removed', 'access_removed'],
    'revoked' => ['revoked', 'revoked', 'access_removed'],
    'expired' => ['expired', 'expired', 'left_call'],
]);

it('sends every participant’s id when a call ends', function () {
    relayFakeNode();
    $site = Site::factory()->create();
    [$alex, $sam] = [relayStaff($site), relayStaff($site)];
    $call = relayCall(relayGroup($alex, $sam), [$alex, $sam]);
    ChatCallParticipant::where('call_id', $call->id)->where('user_id', $sam->id)->update(['updated_at' => now()->subMinute()]);
    ChatCallParticipant::where('call_id', $call->id)->where('user_id', $alex->id)->update(['updated_at' => now()]);

    app(ChatCallReconciler::class)->reconcile($call, true);

    expect($call->fresh()->ended_at)->not->toBeNull();
    $sent = relaySent();
    // Sam's left_call revoke came first, so the call_ended one also covers anything issued after it.
    expect($sent[0])->toBe(['left_call', [relayId($sam, $call) => now()->getTimestamp() + 3600]])
        ->and(collect($sent)->firstWhere(0, 'call_ended')[1])->toEqualCanonicalizing([
            relayId($alex, $call) => now()->getTimestamp() + 3600, relayId($sam, $call) => now()->getTimestamp() + 3601,
        ]);
});

it('sends the person’s ids when their account is suspended', function () {
    relayFakeNode();
    $site = Site::factory()->create();
    [$alex, $sam] = [relayStaff($site), relayStaff($site)];
    $call = relayCall(relayGroup($alex, $sam), [$alex, $sam]);
    $alex->update(['name' => 'Renamed']);
    expect(relaySent())->toBe([]);

    $sam->update(['approved_at' => null]);

    expect(relaySent())->toBe([['access_removed', [relayId($sam, $call) => now()->getTimestamp() + 3600]]]);
});

it('revokes by hand with chat:relay-revoke, for one call or all live calls', function () {
    relayFakeNode();
    $site = Site::factory()->create();
    [$alex, $sam] = [relayStaff($site), relayStaff($site)];
    $group = relayGroup($alex, $sam);
    $one = relayCall($group, [$alex, $sam]);
    $two = relayCall($group, [$alex, $sam]);

    $this->artisan('chat:relay-revoke', ['user' => $sam->id, '--call' => $one->id])->assertSuccessful();
    expect(relaySent())->toBe([['hand', [relayId($sam, $one) => now()->getTimestamp() + 3600]]]);
    $this->artisan('chat:relay-revoke', ['user' => $sam->id, '--reason' => 'access_removed'])->assertSuccessful();
    expect(relaySent()[1][0])->toBe('access_removed')
        ->and(array_keys(relaySent()[1][1]))->toEqualCanonicalizing([relayId($sam, $one), relayId($sam, $two)]);
    $this->artisan('chat:relay-revoke', ['user' => $sam->id, '--reason' => 'because'])->assertFailed();
    $this->artisan('chat:relay-revoke', ['user' => $sam->id, '--call' => (string) Str::uuid()])->assertFailed();
    expect(AuditLog::where('action', 'chat.relay.revoked')->count())->toBe(2)
        ->and(AuditLog::where('action', 'chat.relay.revoked')->first()->meta['actor'])->toBe('system');
});

it('lets the action succeed when the relay can’t be reached, retrying at 5, 15 and 30 s then every minute until expiry plus grace', function () {
    config(['queue.default' => 'database']);
    Queue::fake();
    Http::fake([RELAY_NODE.'/*' => fn () => throw new ConnectionException('relay down'), 'https://voice.example.test/*' => Http::response([])]);
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged[] = [$event->level, $event->message];
    });
    $site = Site::factory()->create();
    [$alex, $sam] = [relayStaff($site), relayStaff($site)];
    $call = relayCall(relayGroup($alex, $sam), [$alex, $sam]);

    $sam->update(['approved_at' => null]);

    expect($sam->fresh()->approved_at)->toBeNull();
    $audit = AuditLog::where('action', 'chat.relay.revoked')->sole();
    expect($audit->meta['nodes_confirmed'])->toBe([])->and($audit->meta['nodes_waiting'])->toBe([RELAY_NODE]);
    $delays = [];
    $job = null;
    Queue::assertPushed(SendChatRelayRevocation::class, function (SendChatRelayRevocation $pushed) use (&$job, &$delays) {
        $job = $pushed;
        $delays[] = $pushed->delay;

        return true;
    });
    expect($delays)->toBe([5])->and($job->entries)->toBe([['id' => relayId($sam, $call), 'expires_up_to' => now()->getTimestamp() + 3600]])
        ->and($job->giveUpAt)->toBe(now()->getTimestamp() + 3600 + OblivionRelay::GRACE_SECONDS);
    expect(app(OblivionRelay::class)->waiting())->toBe(['count' => 1, 'oldest_age_seconds' => 0]);

    // Each failed retry queues the next: 15 s, 30 s, then every minute.
    foreach ([15, 30, 60, 60] as $expected) {
        Queue::fake();
        $job->handle(app(OblivionRelay::class));
        Queue::assertPushed(SendChatRelayRevocation::class, function (SendChatRelayRevocation $next) use (&$job, $expected) {
            expect($next->delay)->toBe($expected);
            $job = $next;

            return true;
        });
    }
    // The scheduler logs an error once the revocation has waited more than 5 minutes.
    $this->travel(4)->minutes();
    expect(app(OblivionRelay::class)->reportStale())->toBe(0);
    $this->travel(2)->minutes();
    expect(app(OblivionRelay::class)->reportStale())->toBe(1)
        ->and(app(OblivionRelay::class)->reportStale())->toBe(0)
        ->and(collect($logged)->where(0, 'error')->pluck(1)->all())->toContain('A Chat relay revocation has waited more than 5 minutes for a relay node.');
    // Service health shows it.
    $row = collect(app(ChatServiceHealth::class)->snapshot()['services'])->firstWhere('id', 'relay');
    expect($row['detail'])->toContain('1 revocation waiting');
    // Past expires_up_to plus grace, retries stop.
    $this->travel(5)->hours();
    Queue::fake();
    $job->handle(app(OblivionRelay::class));
    Queue::assertNothingPushed();
    expect(app(OblivionRelay::class)->waiting()['count'])->toBe(0);
});

it('settles a waiting revocation once a retry lands', function () {
    config(['queue.default' => 'database']);
    Queue::fake();
    Http::fake([RELAY_NODE.'/*' => Http::sequence()->pushStatus(503)->push(['ended' => 1, 'held' => 1])]);
    $site = Site::factory()->create();
    [$alex, $sam] = [relayStaff($site), relayStaff($site)];
    relayCall(relayGroup($alex, $sam), [$alex, $sam]);
    app(OblivionRelay::class)->revokePerson($sam, 'hand');
    $job = null;
    Queue::assertPushed(SendChatRelayRevocation::class, function ($pushed) use (&$job) {
        $job = $pushed;

        return true;
    });
    Queue::fake();
    $job->handle(app(OblivionRelay::class));
    Queue::assertNothingPushed();
    expect(app(OblivionRelay::class)->waiting()['count'])->toBe(0);
});

it('never issues an expiry at or before a sent expires_up_to', function () {
    relayFakeNode();
    $site = Site::factory()->create();
    [$alex, $sam] = [relayStaff($site), relayStaff($site)];
    $call = relayCall(relayGroup($alex, $sam), [$alex, $sam]);
    app(OblivionRelay::class)->revokePerson($sam, 'hand');
    $until = relaySent()[0][1][relayId($sam, $call)];
    // The clock hasn't moved, so a fresh credential would expire exactly at expires_up_to.
    $issued = (int) explode(':', app(ChatRelayCredentials::class)->for($sam, $call->id)['username'])[0];
    expect($issued)->toBeGreaterThan($until);
    $this->travel(10)->minutes();
    expect((int) explode(':', app(ChatRelayCredentials::class)->for($sam, $call->id)['username'])[0])->toBeGreaterThan($until);
    // A second revoke covers that later credential too.
    app(OblivionRelay::class)->revokePerson($sam, 'hand');
    expect(relaySent()[1][1][relayId($sam, $call)])->toBeGreaterThanOrEqual($issued);
});

describe('the Service health relay row', function () {
    beforeEach(function () {
        $probe = Mockery::mock(ChatRelayProbe::class);
        $probe->shouldReceive('allocateChallenge')->with('turn:relay.example.test:3479?transport=udp')->andReturn(401);
        $probe->shouldReceive('certificateExpiry')->with('turns:relay.example.test:443?transport=tcp')->andReturn(now()->addDays(60)->getTimestamp());
        app()->instance(ChatRelayProbe::class, $probe);
    });
    $health = fn () => ['status' => 'ok', 'memory' => ['rss_mb' => 57, 'heap_used_mb' => 12]];
    $row = fn () => collect(app(ChatServiceHealth::class)->snapshot()['services'])->firstWhere('id', 'relay');

    it('reads /health on localhost when the relay runs beside the app', function () use ($health, $row) {
        config(['chat.relay.health_url' => 'http://127.0.0.1:9478']);
        Http::fake(['http://127.0.0.1:9478/health' => Http::response($health())]);
        $result = $row();
        expect($result['state'])->toBe('passed')->and($result['name'])->toBe('Calling relay')
            ->and($result['detail'])->toContain('answered a test request as expected', 'expires in 60 days', 'Memory 57 MB', 'no revocations waiting');
        Http::assertSent(fn (Request $request) => $request->url() === 'http://127.0.0.1:9478/health');
    });

    it('reads the signed control status when the relay has its own server', function () use ($health, $row) {
        config(['chat.relay.control.urls' => ['http://10.0.0.5:9479']]);
        Http::fake(['http://10.0.0.5:9479/control/status' => Http::response([...$health(), 'revocations_held' => 0, 'control' => ['key_id' => 'k1', 'previous_key' => null]])]);
        expect($row()['state'])->toBe('passed');
        Http::assertSent(function (Request $request) {
            return $request->method() === 'GET' && $request->header('X-Relay-Signature')[0] === OblivionRelay::sign(RELAY_CONTROL_KAV['secret'], 'GET', '/control/status', 'k1',
                $request->header('X-Relay-Timestamp')[0], $request->header('X-Relay-Nonce')[0], '');
        });
    });

    it('is partial when a public probe fails or the certificate is near expiry, and unavailable when the status can’t be read', function () use ($health, $row) {
        config(['chat.relay.health_url' => 'http://127.0.0.1:9478']);
        Http::fake(['http://127.0.0.1:9478/health' => Http::response($health())]);
        $probe = Mockery::mock(ChatRelayProbe::class);
        $probe->shouldReceive('allocateChallenge')->andReturn(null);
        $probe->shouldReceive('certificateExpiry')->andReturn(now()->addDays(5)->getTimestamp());
        app()->instance(ChatRelayProbe::class, $probe);
        $result = $row();
        expect($result['state'])->toBe('partial')->and($result['detail'])->toContain('did not answer a test request', 'expires in 5 days');
        config(['chat.relay.health_url' => '']);
        Http::fake(['http://127.0.0.1:9479/control/status' => fn () => throw new ConnectionException('down')]);
        expect($row()['state'])->toBe('unavailable');
        config(['chat.relay.health_url' => 'http://203.0.113.5:9478']);
        expect($row()['state'])->toBe('unavailable');
    });

    it('says when the relay or its control channel isn’t configured', function () use ($row) {
        config(['chat.relay.urls' => [], 'chat.relay.control.urls' => []]);
        expect($row()['state'])->toBe('not_configured');
        config(['chat.relay.urls' => ['turn:relay.example.test:3479'], 'chat.relay.control.urls' => ['https://127.0.0.1:9479']]);
        expect($row())->toMatchArray(['state' => 'not_configured'])->and($row()['detail'])->toContain('control channel');
    });
});

it('checks the public probe’s request and answer bytes', function () {
    $transactionId = random_bytes(12);
    $request = ChatRelayProbe::allocateRequest($transactionId);
    expect(strlen($request))->toBe(28)->and(bin2hex(substr($request, 0, 8)))->toBe('000300082112a442')
        ->and(bin2hex(substr($request, 20)))->toBe('0019000411000000');
    // A 401 Allocate error: ERROR-CODE, REALM and NONCE, as the relay sends it.
    $error = pack('nn', 0x0009, 19)."\x00\x00\x04\x01".'Unauthenticated'."\x00"
        .pack('nn', 0x0014, 16).'turn.example.org'.pack('nn', 0x0015, 27).str_repeat('n', 27)."\x00";
    $response = pack('nn', 0x0113, strlen($error))."\x21\x12\xA4\x42".$transactionId.$error;
    expect(ChatRelayProbe::errorCode($response, $transactionId))->toBe(401)
        ->and(ChatRelayProbe::errorCode($response, random_bytes(12)))->toBeNull()
        ->and(ChatRelayProbe::errorCode(substr($response, 0, 30), $transactionId))->toBeNull()
        ->and(ChatRelayProbe::target('turns:relay.example.test:443?transport=tcp'))->toBe(['transport' => 'tls', 'host' => 'relay.example.test', 'port' => 443]);
});
