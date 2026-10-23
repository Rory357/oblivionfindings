<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Jobs\DeliverChatMessageNotifications;
use App\Jobs\PublishChatPresence;
use App\Models\AppSetting;
use App\Models\ChatCall;
use App\Models\Client;
use App\Models\OpsConversation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Chat\ChatAccess;
use App\Services\Chat\ChatPresence;
use App\Services\Chat\ChatRealtime;
use App\Services\Chat\ChatServiceHealth;
use App\Services\Chat\ChatSettings;
use App\Services\Chat\OblivionLive;
use App\Services\Clients\ClientPortalMembershipService;
use App\Support\SecurityPolicy;
use App\Support\Signing\CompactToken;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

const LIVE_TEST_SECRET = 'oblivion-live-test-secret-0123456789abcdef';

beforeEach(function () {
    config(['chat.shared_experience' => true, 'chat.live' => [
        'enabled' => true, 'path' => '/chat-live', 'internal_url' => 'http://127.0.0.1:8092',
        'secret' => LIVE_TEST_SECRET, 'key_id' => 'k-test', 'previous_secret' => null, 'previous_key_id' => null, 'allowed_origins' => [],
    ]]);
    SecurityPolicy::flushCache();
    Bus::fake([DeliverChatMessageNotifications::class]);
    AppSetting::updateOrCreate(['key' => ChatSettings::KEY], ['value' => ['message_change_window_minutes' => 15]]);
    Http::fake(['127.0.0.1:8092/*' => Http::response(['delivered' => 1])]);
});

function liveStaff(Site $site, array $permissions = []): User
{
    $user = User::factory()->create(['approved_at' => now(), 'role' => 'staff']);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
        'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null,
    ]);
    if ($permissions) {
        $role = Role::create(['name' => 'live_'.Str::random(8), 'label' => 'Live test', 'level' => 50, 'type' => 'custom']);
        foreach ($permissions as $key) {
            $role->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'chat', 'module' => 'System'])->id);
        }
        $user->roles()->attach($role->id);
    }

    return $user->fresh();
}

function liveRelative(Client $client): User
{
    $user = User::factory()->create(['role' => 'next_of_kin', 'approved_at' => now()]);
    $role = Role::firstOrCreate(['name' => 'next_of_kin'], ['label' => 'Next of Kin', 'level' => 1, 'type' => 'system']);
    $user->roles()->syncWithoutDetaching([$role->id]);
    $client->portalUsers()->attach($user->id, ['relation' => 'next_of_kin']);

    return $user;
}

function liveDirect(User ...$people): OpsConversation
{
    $conversation = OpsConversation::create(['conversation_type' => count($people) > 2 ? 'group' : 'direct']);
    foreach ($people as $person) {
        $conversation->participants()->create(['user_id' => $person->id, 'role' => 'member']);
    }

    return $conversation;
}

/** A family conversation with one relative member, a care worker member and a cover lead who is not a member. */
function liveFamily(): array
{
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $relative = liveRelative($client);
    $worker = liveStaff($site, ['family_portal.manage']);
    $cover = liveStaff($site, ['family_portal.manage']);
    $bystander = liveStaff($site);
    $conversation = OpsConversation::create(['conversation_type' => 'family', 'client_id' => $client->id, 'care_team_cover' => true]);
    $conversation->participants()->create(['user_id' => $relative->id, 'role' => 'family']);
    $conversation->participants()->create(['user_id' => $worker->id, 'role' => 'member']);

    return [$conversation, $relative, $worker, $cover, $bystander];
}

function liveAs($test, User $user)
{
    return $test->actingAs($user)->withHeader('X-Chat-Actor', (string) $user->id);
}

/** Every event sent to the gateway's publish endpoint so far, flattened. */
function liveEvents(): array
{
    return collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/internal/publish'))
        ->flatMap(fn ($pair) => $pair[0]->data()['events'])->values()->all();
}

function liveCalls(string $path): array
{
    return collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), $path))
        ->map(fn ($pair) => $pair[0]->data())->values()->all();
}

function liveSigned($test, array $body, ?int $timestamp = null, ?string $secret = null)
{
    $raw = json_encode($body);
    $timestamp ??= now()->getTimestamp();

    return $test->call('POST', route('chat.live.presence'), [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_LIVE_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_LIVE_SIGNATURE' => hash_hmac('sha256', $timestamp."\n".$raw, $secret ?? LIVE_TEST_SECRET),
    ], $raw);
}

it('issues a signed one-minute ticket bound to the sign-in, without the secret', function () {
    $staff = liveStaff(Site::factory()->create());
    $response = liveAs($this, $staff)->postJson(route('chat.live.ticket'))->assertOk()
        ->assertJsonPath('url', '/chat-live')->assertJsonPath('expires_in', 60)->assertHeader('Cache-Control', 'no-store, private');
    expect($response->getContent())->not->toContain(LIVE_TEST_SECRET);
    $claims = CompactToken::verify($response->json('ticket'), ['k-test' => LIVE_TEST_SECRET], 'oblivion-live');
    expect($claims['sub'])->toBe($staff->id)->and($claims['v'])->toBe(1)->and($claims['fam'])->toBeFalse()
        ->and($claims['exp'] - $claims['iat'])->toBe(60)->and($claims['jti'])->toMatch('/\A[A-Za-z0-9_-]{22}\z/')
        ->and($claims['sid'])->toBe(hash('sha256', session()->getId()));
    $again = liveAs($this, $staff)->postJson(route('chat.live.ticket'))->json('ticket');
    expect(CompactToken::verify($again, ['k-test' => LIVE_TEST_SECRET], 'oblivion-live')['jti'])->not->toBe($claims['jti']);
    expect(app(ChatPresence::class)->online($staff->id))->toBeTrue();
});

it('marks family tickets and refuses people who may not use Chat', function () {
    [$conversation, $relative] = liveFamily();
    $ticket = liveAs($this, $relative)->postJson(route('chat.live.ticket'))->assertOk()->json('ticket');
    expect(CompactToken::verify($ticket, ['k-test' => LIVE_TEST_SECRET], 'oblivion-live')['fam'])->toBeTrue();
    $conversation->client->portalUsers()->detach($relative->id);
    liveAs($this, $relative->fresh())->postJson(route('chat.live.ticket'))->assertForbidden();

    $former = liveStaff(Site::factory()->create());
    $former->hrEmployeeProfile()->update(['is_active' => false]);
    liveAs($this, $former->fresh())->postJson(route('chat.live.ticket'))->assertForbidden();
    // A stale tab for another account cannot fetch a ticket.
    $staff = liveStaff(Site::factory()->create());
    $this->actingAs($staff)->withHeader('X-Chat-Actor', (string) ($staff->id + 1))->postJson(route('chat.live.ticket'))->assertForbidden();
    config(['chat.live.enabled' => false]);
    liveAs($this, $staff)->postJson(route('chat.live.ticket'))->assertNotFound();
});

it('tells the browser to use Oblivion Live and no longer signs Reverb channels', function () {
    $staff = liveStaff(Site::factory()->create());
    liveAs($this, $staff)->getJson(route('chat.realtime'))->assertOk()
        ->assertExactJson(['actor_id' => $staff->id, 'enabled' => true, 'transport' => 'live', 'path' => '/chat-live']);
    liveAs($this, $staff)->postJson(route('chat.realtime.auth'), ['socket_id' => '123.456', 'channel_name' => 'private-chat.user.'.$staff->id])->assertForbidden();
});

it('sends conversation.changed with the newest change ID and kinds to members who pass allows()', function () {
    $site = Site::factory()->create();
    [$sender, $recipient, $outsider] = [liveStaff($site), liveStaff($site), liveStaff($site)];
    $conversation = liveDirect($sender, $recipient);
    liveAs($this, $sender)->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), 'content' => 'Medication round done'])->assertCreated();

    $changed = fn () => collect(liveEvents())->where('type', 'conversation.changed')->values();
    expect($changed())->toHaveCount(1);
    expect($changed()[0]['to'])->toEqualCanonicalizing([$sender->id, $recipient->id]);
    expect($changed()[0]['data'])->toBe(['conversation_id' => $conversation->id,
        'change_id' => (int) DB::table('chat_message_changes')->max('id'), 'kinds' => ['message']]);
    // The recipient's badge count follows once their unread cache is cleared.
    expect(collect(liveEvents())->firstWhere('type', 'unread.changed'))->toBe(['to' => [$recipient->id], 'type' => 'unread.changed', 'data' => ['total' => 1]]);
    // Content-free: no message text reaches the gateway.
    collect(Http::recorded())->each(fn ($pair) => expect($pair[0]->body())->not->toContain('Medication round'));

    app(ChatRealtime::class)->publish($conversation->id, $recipient->id, ['reaction']);
    expect($changed()[1]['to'])->toBe([$recipient->id]);
    expect($changed()[1]['data']['kinds'])->toBe(['reaction']);
    $conversation->participants()->where('user_id', $recipient->id)->delete();
    app(ChatRealtime::class)->publish($conversation->id, $recipient->id);
    app(ChatRealtime::class)->publish($conversation->id, $outsider->id);
    expect($changed())->toHaveCount(2);
});

it('reaches the relative, member staff and care-team cover exactly as allows() does', function () {
    [$conversation, $relative, $worker, $cover, $bystander] = liveFamily();
    $other = liveRelative($conversation->client);
    app(ChatRealtime::class)->publish($conversation->id, null, ['message']);
    $to = liveEvents()[0]['to'];

    $expected = collect([$relative, $worker, $cover, $bystander, $other])
        ->filter(fn (User $person) => app(ChatAccess::class)->allows($person->fresh(), $conversation->fresh()))->pluck('id')->all();
    expect($to)->toEqualCanonicalizing($expected);
    expect($to)->toContain($relative->id, $worker->id, $cover->id)->not->toContain($bystander->id);
});

it('names what changed: edits, reactions, calls and list-level changes', function () {
    $site = Site::factory()->create();
    [$sender, $recipient] = [liveStaff($site), liveStaff($site)];
    $conversation = liveDirect($sender, $recipient);
    $message = $conversation->messages()->create(['sender_id' => $sender->id, 'sender_type' => 'user', 'content' => 'Handover at 3']);
    liveAs($this, $recipient)->postJson(route('chat.action', $message), ['action' => 'react', 'emoji' => '👍', 'active' => true])->assertNoContent();
    expect(collect(liveEvents())->last()['data']['kinds'])->toBe(['reaction']);

    app(ChatRealtime::class)->publish($conversation->id, inbox: 'renamed');
    expect(collect(liveEvents())->last())->toMatchArray(['type' => 'inbox.changed', 'data' => ['reason' => 'renamed']]);

    $call = ChatCall::create(['id' => (string) Str::uuid(), 'conversation_id' => $conversation->id, 'started_by' => $sender->id, 'host_id' => $sender->id,
        'mutation_id' => (string) Str::uuid(), 'status' => 'ringing', 'ring_until' => now()->addMinute()]);
    $call->participants()->create(['user_id' => $sender->id, 'media_identity' => (string) Str::uuid(), 'status' => 'accepted']);
    $call->participants()->create(['user_id' => $recipient->id, 'media_identity' => (string) Str::uuid(), 'status' => 'invited']);
    $changed = collect(liveEvents())->where('type', 'call.changed')->last();
    expect($changed['data'])->toBe(['call_id' => $call->id, 'conversation_id' => $conversation->id]);
    expect($changed['to'])->toEqualCanonicalizing([$sender->id, $recipient->id]);
});

it('grants call signalling to admitted people and revokes it when they leave or the call ends', function () {
    $site = Site::factory()->create();
    [$caller, $callee] = [liveStaff($site), liveStaff($site)];
    $conversation = liveDirect($caller, $callee);
    $call = ChatCall::create(['id' => (string) Str::uuid(), 'conversation_id' => $conversation->id, 'started_by' => $caller->id, 'host_id' => $caller->id,
        'mutation_id' => (string) Str::uuid(), 'status' => 'ringing', 'ring_until' => now()->addMinute()]);
    $call->participants()->create(['user_id' => $caller->id, 'media_identity' => (string) Str::uuid(), 'status' => 'accepted']);
    $invited = $call->participants()->create(['user_id' => $callee->id, 'media_identity' => (string) Str::uuid(), 'status' => 'invited']);
    expect(liveCalls('/internal/call-grant'))->toHaveCount(1);
    expect(liveCalls('/internal/call-grant')[0])->toMatchArray(['call_id' => $call->id, 'user_ids' => [$caller->id]]);
    expect(liveCalls('/internal/call-grant')[0]['until'])->toBeGreaterThan(now()->getTimestamp());

    $invited->update(['status' => 'accepted']);
    expect(liveCalls('/internal/call-grant')[1]['user_ids'])->toBe([$callee->id]);
    $invited->update(['status' => 'connected']);
    expect(liveCalls('/internal/call-grant'))->toHaveCount(2);
    $invited->update(['status' => 'left', 'left_at' => now()]);
    expect(liveCalls('/internal/call-revoke')[0])->toMatchArray(['call_id' => $call->id, 'user_ids' => [$callee->id]]);
    $call->update(['status' => 'ended', 'ended_at' => now()]);
    expect(liveCalls('/internal/call-revoke')[1]['user_ids'])->toEqualCanonicalizing([$caller->id, $callee->id]);
});

it('sends unread.changed with the reader own total after reading', function () {
    $site = Site::factory()->create();
    [$sender, $reader] = [liveStaff($site), liveStaff($site)];
    $conversation = liveDirect($sender, $reader);
    $message = $conversation->messages()->create(['sender_id' => $sender->id, 'sender_type' => 'user', 'content' => 'Please call the pharmacy']);
    liveAs($this, $reader)->postJson(route('chat.read', $conversation), ['message_id' => $message->id])->assertNoContent();
    $unread = collect(liveEvents())->firstWhere('type', 'unread.changed');
    expect($unread['to'])->toBe([$reader->id]);
    expect($unread['data'])->toBe(['total' => 0]);
    expect(collect(liveEvents())->firstWhere('type', 'notice'))->toMatchArray(['to' => [$reader->id], 'data' => ['kind' => 'cleared']]);
    $row = collect(liveEvents())->firstWhere('type', 'conversation.changed');
    expect($row['to'])->toBe([$reader->id])->and($row['data']['kinds'])->toBe(['read']);
});

it('tells someone removed from a group by ID, since they no longer pass allows()', function () {
    $site = Site::factory()->create();
    $owner = liveStaff($site);
    [$member, $leaver] = [liveStaff($site), liveStaff($site)];
    $group = OpsConversation::create(['conversation_type' => 'group', 'title' => 'Night team']);
    $group->participants()->create(['user_id' => $owner->id, 'role' => 'admin']);
    $group->participants()->create(['user_id' => $member->id, 'role' => 'member']);
    $group->participants()->create(['user_id' => $leaver->id, 'role' => 'member']);
    // chat_version comes from the database default, so read it back.
    liveAs($this, $owner)->patchJson(route('chat.management', $group), ['action' => 'remove', 'user_id' => $leaver->id, 'version' => (int) $group->fresh()->chat_version])->assertSuccessful();

    $removed = collect(liveEvents())->firstWhere('type', 'inbox.changed');
    expect($removed)->toMatchArray(['to' => [$leaver->id], 'data' => ['reason' => 'removed']]);
    $people = collect(liveEvents())->firstWhere('type', 'conversation.changed');
    expect($people['to'])->toEqualCanonicalizing([$owner->id, $member->id])->and($people['data']['kinds'])->toBe(['people']);
});

it('returns the numeric change position beside the cursor, and one list row on request', function () {
    $site = Site::factory()->create();
    [$sender, $reader, $outsider] = [liveStaff($site), liveStaff($site), liveStaff($site)];
    $conversation = liveDirect($sender, $reader);
    $history = liveAs($this, $reader)->getJson(route('chat.messages', $conversation))->assertOk();
    expect($history->json('sync_change_id'))->toBe(0);
    liveAs($this, $sender)->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), 'content' => 'New note'])->assertCreated();
    $changes = liveAs($this, $reader)->postJson(route('chat.changes', $conversation), ['cursor' => $history->json('sync_cursor')])->assertOk();
    expect($changes->json('sync_change_id'))->toBe((int) DB::table('chat_message_changes')->max('id'))->toBeGreaterThan(0);

    $row = liveAs($this, $reader)->getJson(route('chat.conversations', ['only' => $conversation->id]))->assertOk();
    expect($row->json('conversations'))->toHaveCount(1)->and($row->json('conversations.0.id'))->toBe($conversation->id)
        ->and($row->json('people'))->toBeNull();
    liveAs($this, $outsider)->getJson(route('chat.conversations', ['only' => $conversation->id]))->assertOk()->assertJsonPath('conversations', []);
});

it('sends typing to readers only, with "Care team" for family and nothing stored', function () {
    [$conversation, $relative, $worker, $cover, $bystander] = liveFamily();
    $before = [DB::table('chat_message_changes')->count(), DB::table('ops_messages')->count()];
    liveAs($this, $worker)->postJson(route('chat.typing', $conversation), ['state' => 'start'])->assertNoContent();
    $events = collect(liveEvents())->where('type', 'typing');
    $toFamily = $events->first(fn ($event) => in_array($relative->id, $event['to'], true));
    $toStaff = $events->first(fn ($event) => in_array($cover->id, $event['to'], true));
    expect($toFamily['data'])->toBe(['conversation_id' => $conversation->id, 'state' => 'start', 'who' => 'Care team']);
    expect($toFamily['to'])->toBe([$relative->id]);
    expect($toStaff['data']['who'])->toBe($worker->name);
    expect($events->flatMap(fn ($event) => $event['to'])->all())->not->toContain($worker->id, $bystander->id);

    // At most once every 3 seconds per person, conversation and state; stop still goes through.
    liveAs($this, $worker)->postJson(route('chat.typing', $conversation), ['state' => 'start'])->assertStatus(429);
    liveAs($this, $worker)->postJson(route('chat.typing', $conversation), ['state' => 'stop'])->assertNoContent();
    $this->travel(4)->seconds();
    liveAs($this, $worker)->postJson(route('chat.typing', $conversation), ['state' => 'start'])->assertNoContent();

    // A relative typing is shown to staff by name.
    liveAs($this, $relative)->postJson(route('chat.typing', $conversation), ['state' => 'start'])->assertNoContent();
    expect(collect(liveEvents())->where('type', 'typing')->last()['data']['who'])->toBe($relative->name);

    // People who may not write get nothing sent.
    $count = count(liveEvents());
    liveAs($this, $bystander)->postJson(route('chat.typing', $conversation), ['state' => 'start'])->assertForbidden();
    liveAs($this, $worker)->postJson(route('chat.typing', $conversation), ['state' => 'typing'])->assertUnprocessable();
    expect(liveEvents())->toHaveCount($count);
    expect([DB::table('chat_message_changes')->count(), DB::table('ops_messages')->count()])->toBe($before);
});

it('accepts only signed, fresh, single-use presence reports from the gateway', function () {
    $staff = liveStaff(Site::factory()->create());
    $body = ['user_id' => $staff->id, 'state' => 'online', 'nonce' => 'a1'];
    liveSigned($this, $body, secret: 'wrong-secret-wrong-secret-wrong-secret')->assertUnauthorized();
    liveSigned($this, $body, now()->getTimestamp() - 31)->assertUnauthorized();
    liveSigned($this, $body)->assertNoContent();
    liveSigned($this, $body)->assertUnauthorized();
    expect(DB::table('chat_preferences')->where('user_id', $staff->id)->value('last_seen_at'))->not->toBeNull();
    expect(app(ChatPresence::class)->online($staff->id))->toBeTrue();
    liveSigned($this, ['user_id' => $staff->id, 'state' => 'offline', 'nonce' => 'a2'])->assertNoContent();
    expect(app(ChatPresence::class)->online($staff->id))->toBeFalse();
    liveSigned($this, ['user_id' => $staff->id, 'state' => 'asleep', 'nonce' => 'a3'])->assertUnprocessable();
    // The previous secret still works during a rotation.
    config(['chat.live.previous_secret' => 'previous-live-secret-0123456789abcdef']);
    liveSigned($this, ['user_id' => $staff->id, 'state' => 'online', 'nonce' => 'a4'], secret: 'previous-live-secret-0123456789abcdef')->assertNoContent();
});

it('publishes presence to staff who share a conversation, at most once a minute', function () {
    Bus::fake([PublishChatPresence::class, DeliverChatMessageNotifications::class]);
    [$conversation, $relative, $worker, $cover] = liveFamily();
    // A private chat is visible only to someone who can see every member, so the colleague
    // works at the same Site; a colleague elsewhere is not told.
    $colleague = liveStaff(Site::findOrFail($worker->hrEmployeeProfile->primary_site_id));
    $elsewhere = liveStaff(Site::factory()->create());
    liveDirect($worker, $colleague);
    liveDirect($worker, $elsewhere);
    DB::table('chat_preferences')->insert(['user_id' => $worker->id, 'availability' => 'available', 'created_at' => now(), 'updated_at' => now()]);

    liveSigned($this, ['user_id' => $worker->id, 'state' => 'online', 'nonce' => 'b1'])->assertNoContent();
    $presence = collect(liveEvents())->where('type', 'presence')->values();
    expect($presence)->toHaveCount(1);
    expect($presence[0]['data'])->toBe(['user_id' => $worker->id, 'state' => 'available']);
    // Staff only: the relative in the shared family conversation is not told.
    expect($presence[0]['to'])->toContain($colleague->id)->not->toContain($relative->id, $worker->id, $elsewhere->id);

    liveSigned($this, ['user_id' => $worker->id, 'state' => 'offline', 'nonce' => 'b2'])->assertNoContent();
    liveSigned($this, ['user_id' => $worker->id, 'state' => 'online', 'nonce' => 'b3'])->assertNoContent();
    expect(collect(liveEvents())->where('type', 'presence'))->toHaveCount(1);
    Bus::assertDispatchedTimes(PublishChatPresence::class, 1);
    $this->travel(61)->seconds();
    (new PublishChatPresence($worker->id))->handle(app(ChatPresence::class));
    expect(collect(liveEvents())->where('type', 'presence')->last()['data']['state'])->toBe('available');
});

it('closes only the signed-out session on sign-out', function () {
    $staff = liveStaff(Site::factory()->create());
    // Read the sign-in's session ID at the same moment the revocation does.
    $session = null;
    Event::listen(Logout::class, function () use (&$session) {
        $session = request()->session()->getId();
    });
    $this->actingAs($staff)->post(route('logout'));
    expect($session)->toBeString();
    $revoke = liveCalls('/internal/revoke');
    expect($revoke)->toHaveCount(1);
    expect($revoke[0]['user_ids'])->toBe([$staff->id]);
    expect($revoke[0]['session_ids'])->toBe([hash('sha256', $session)]);
});

it('closes every connection when access is lost', function () {
    $site = Site::factory()->create();
    $staff = liveStaff($site);
    $staff->hrEmployeeProfile->update(['is_active' => false]);
    expect(liveCalls('/internal/revoke'))->toHaveCount(1);
    expect(liveCalls('/internal/revoke')[0])->toMatchArray(['user_ids' => [$staff->id]])->not->toHaveKey('session_ids');

    $approved = liveStaff($site);
    $approved->update(['approved_at' => null]);
    expect(collect(liveCalls('/internal/revoke'))->pluck('user_ids')->last())->toBe([$approved->id]);

    $client = Client::factory()->create(['site_id' => $site->id]);
    $relative = liveRelative($client);
    app(ClientPortalMembershipService::class)->unlink($client, $relative);
    expect(collect(liveCalls('/internal/revoke'))->pluck('user_ids')->last())->toBe([$relative->id]);
    expect(collect(liveEvents())->last())->toMatchArray(['type' => 'inbox.changed', 'to' => [$relative->id]]);
});

it('closes a former staff member connections when they are offboarded', function () {
    $site = Site::factory()->create();
    $admin = liveStaff($site, ['settings.access.manage']);
    [$leaver, $stays] = [liveStaff($site), liveStaff($site)];
    $direct = liveDirect($leaver, $stays);
    $leaver->hrEmployeeProfile()->update(['end_date' => now()->subDays(2)->toDateString()]);
    liveAs($this, $admin)->postJson(route('settings.chat.administration.offboarding.complete', $leaver), ['owner' => 'care_team'])->assertOk();

    expect(collect(liveCalls('/internal/revoke'))->pluck('user_ids')->all())->toContain([$leaver->id]);
    expect(collect(liveEvents())->firstWhere('type', 'inbox.changed'))->toMatchArray(['to' => [$leaver->id], 'data' => ['reason' => 'removed']]);
    expect(collect(liveEvents())->firstWhere('type', 'conversation.changed')['data'])->toMatchArray(['conversation_id' => $direct->id, 'kinds' => ['people']]);
});

it('never fails a send when the gateway is down, and reports it once', function () {
    Exceptions::fake();
    Http::fake(['127.0.0.1:8092/*' => Http::failedConnection('Connection refused')]);
    $site = Site::factory()->create();
    [$sender, $recipient] = [liveStaff($site), liveStaff($site)];
    $conversation = liveDirect($sender, $recipient);
    foreach (['First', 'Second'] as $text) {
        liveAs($this, $sender)->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), 'content' => $text])->assertCreated();
    }
    expect(DB::table('ops_messages')->where('conversation_id', $conversation->id)->count())->toBe(2);
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (RuntimeException $error) => str_contains($error->getMessage(), 'Oblivion Live could not be reached'));

    Http::fake(['127.0.0.1:8092/*' => Http::response(['error' => 'bad signature'], 401)]);
    liveAs($this, $sender)->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), 'content' => 'Third'])->assertCreated();
});

it('signs every gateway call with a fresh timestamp and a nonce', function () {
    app(OblivionLive::class)->publish([3, 3, 4], 'notice', ['kind' => 'message']);
    app(OblivionLive::class)->publish([3], 'notice', ['kind' => 'message']);
    $requests = collect(Http::recorded())->map(fn ($pair) => $pair[0]);
    expect($requests)->toHaveCount(2);
    $requests->each(function (HttpRequest $request) {
        $timestamp = $request->header('X-Live-Timestamp')[0];
        expect(abs(now()->getTimestamp() - (int) $timestamp))->toBeLessThanOrEqual(1);
        expect($request->header('X-Live-Signature')[0])->toBe(hash_hmac('sha256', $timestamp."\n".$request->body(), LIVE_TEST_SECRET));
        expect($request->data()['nonce'])->toMatch('/\A[a-f0-9]{24}\z/');
    });
    expect($requests[0]->data()['nonce'])->not->toBe($requests[1]->data()['nonce']);
    expect($requests[0]->data()['events'][0]['to'])->toBe([3, 4]);
    // More than 500 people are split into several events.
    app(OblivionLive::class)->publish(range(1, 1200), 'notice', ['kind' => 'message']);
    expect(collect(liveEvents())->slice(2)->map(fn ($event) => count($event['to']))->values()->all())->toBe([500, 500, 200]);
});

it('reports gateway health in Settings without publishing anything', function () {
    // The first matching stub wins, so replace the catch-all from beforeEach.
    Http::swap(new HttpFactory);
    Http::fake(['127.0.0.1:8092/internal/health' => Http::response(['status' => 'ok', 'connections' => 12, 'users' => 7, 'version' => '1.0.0', 'uptime_seconds' => 30])]);
    $row = collect(app(ChatServiceHealth::class)->snapshot()['services'])->firstWhere('id', 'realtime');
    expect($row['state'])->toBe('passed')->and($row['detail'])->toContain('1 node, 12 open connections');
    expect(liveCalls('/internal/publish'))->toBe([]);

    Http::fake(['127.0.0.1:8092/*' => Http::failedConnection('down')]);
    $row = collect(app(ChatServiceHealth::class)->snapshot()['services'])->firstWhere('id', 'realtime');
    expect($row['state'])->toBe('unavailable');
    config(['chat.live.secret' => null]);
    $row = collect(app(ChatServiceHealth::class)->snapshot()['services'])->firstWhere('id', 'realtime');
    expect($row['state'])->toBe('not_configured');
});
