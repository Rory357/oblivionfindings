<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Jobs\DeliverChatMessageNotifications;
use App\Models\AppSetting;
use App\Models\Client;
use App\Models\OpsConversation;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Services\Chat\ChatBrowserPushDelivery;
use App\Services\Chat\ChatBrowserPushTransport;
use App\Services\Chat\ChatMessageDelivery;
use App\Support\SecurityPolicy;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->freezeTime();
    config(['chat.shared_experience' => true, 'chat.notifications.browser_push_enabled' => true]);
    Bus::fake([DeliverChatMessageNotifications::class]);
    $site = Site::factory()->create();
    [$this->sender, $this->recipient] = collect(range(1, 2))->map(function () use ($site) {
        $user = User::factory()->create(['approved_at' => now(), 'role' => 'staff']);
        ensureCanonicalHrStaffProfile($user, $site);

        return $user;
    })->all();
    $this->conversation = OpsConversation::create(['conversation_type' => 'direct']);
    foreach ([$this->sender, $this->recipient] as $user) {
        $this->conversation->participants()->create(['user_id' => $user->id, 'role' => 'member']);
    }
    UserNotificationPreference::create(['user_id' => $this->recipient->id, 'key' => 'chat.message.received', 'enabled' => true, 'channel_inapp' => false, 'channel_push' => true]);
    $this->device = $this->recipient->pushSubscriptions()->create(['provider' => 'webpush', 'token' => 'https://push.example.test/'.Str::uuid(),
        'keys' => ['p256dh' => 'fixture-key', 'auth' => 'fixture-auth'], 'enabled' => true,
        'chat_protocol_version' => 2, 'chat_registration_id' => (string) Str::uuid()]);
    $this->requests = [];
    $this->outcome = fn () => 'accepted';
    $transport = Mockery::mock(ChatBrowserPushTransport::class);
    $transport->shouldReceive('send')->andReturnUsing(function ($subscription, $payload) {
        $this->requests[] = ['subscription' => $subscription->id, 'payload' => $payload, 'transaction_level' => DB::transactionLevel()];

        return ($this->outcome)($subscription, $payload);
    });
    app()->instance(ChatBrowserPushTransport::class, $transport);
    $this->send = function () {
        return $this->actingAs($this->sender)->withHeader('X-Chat-Actor', (string) $this->sender->id)->postJson(route('chat.send', $this->conversation), [
            'mutation_id' => (string) Str::uuid(), 'content' => 'Private-care-content-never-in-preview',
        ])->assertCreated()->json('message.id');
    };
});

it('commits per-device browser push independently of in-app and opens only the authorised opaque link', function () {
    $this->recipient->pushSubscriptions()->create(['provider' => 'expo', 'token' => 'ExponentPushToken[fixture]', 'enabled' => true]);
    $message = ($this->send)();
    expect(DB::table('chat_browser_push_deliveries')->count())->toBe(1);
    expect(app(ChatMessageDelivery::class)->recover())->toBe(0);
    $parent = DB::table('chat_message_deliveries')->sole();
    $this->actingAs($this->recipient)->get(route('chat.notifications.open', $parent->notification_id))->assertNotFound();
    $level = DB::transactionLevel();
    $this->outcome = function () use ($parent, $message) {
        // A browser can click before the provider acknowledgement has been committed.
        $this->actingAs($this->recipient)->get(route('chat.notifications.open', $parent->notification_id))
            ->assertRedirect(route('chat.index', ['conversation' => $this->conversation->id, 'message' => $message]));

        return 'accepted';
    };
    expect(app(ChatBrowserPushDelivery::class)->recover())->toBe(1);
    expect(app(ChatBrowserPushDelivery::class)->recover())->toBe(0);
    expect($this->requests)->toHaveCount(1);
    expect($this->requests[0]['transaction_level'])->toBe($level);
    $payload = $this->requests[0]['payload'];
    expect($payload['data']['notification_id'])->toBe($parent->notification_id);
    expect(json_encode($payload))->not->toContain('Private-care-content', $this->sender->name, 'message_id', 'client_id');
    expect($this->recipient->notifications()->count())->toBe(0);
    $this->actingAs($this->sender)->get(route('chat.notifications.open', $parent->notification_id))->assertNotFound();
    $this->conversation->participants()->where('user_id', $this->recipient->id)->delete();
    $this->actingAs($this->recipient)->get(route('chat.notifications.open', $parent->notification_id))->assertNotFound();
});

it('respects both Chat and global sound settings at dispatch and at current browser display', function () {
    DB::table('chat_preferences')->insert(['user_id' => $this->recipient->id, 'sound_enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
    ($this->send)();
    $parent = DB::table('chat_message_deliveries')->sole();
    expect(app(ChatBrowserPushDelivery::class)->recover())->toBe(1);
    expect($this->requests[0]['payload']['data']['silent'])->toBeTrue();
    $url = route('chat.notifications.display', ['notification' => $parent->notification_id, 'binding' => ChatBrowserPushDelivery::fingerprint($this->device)]);
    $this->actingAs($this->recipient)->getJson($url)->assertOk()->assertJsonPath('data.silent', true);
    DB::table('chat_preferences')->where('user_id', $this->recipient->id)->update(['sound_enabled' => true]);
    $this->getJson($url)->assertOk()->assertJsonPath('data.silent', false);
    $this->recipient->update(['notification_sounds_enabled' => false]);
    $this->actingAs($this->recipient->fresh())->getJson($url)->assertOk()->assertJsonPath('data.silent', true);
    ($this->send)();
    expect(app(ChatBrowserPushDelivery::class)->recover())->toBe(1);
    expect($this->requests[1]['payload']['data']['silent'])->toBeTrue();
});

it('retries only the unsuccessful device and retains one stable notification identity', function () {
    $other = $this->recipient->pushSubscriptions()->create(['provider' => 'webpush', 'token' => 'https://push.example.test/other', 'keys' => $this->device->keys, 'enabled' => true,
        'chat_protocol_version' => 2, 'chat_registration_id' => (string) Str::uuid()]);
    ($this->send)();
    $this->outcome = fn ($subscription) => $subscription->id === $other->id ? 'retry' : 'accepted';
    expect(app(ChatBrowserPushDelivery::class)->recover())->toBe(1);
    expect(DB::table('chat_browser_push_deliveries')->where('status', 'accepted')->count())->toBe(1);
    expect(app(ChatBrowserPushDelivery::class)->recover())->toBe(0);
    $this->travel(61)->seconds();
    $this->outcome = fn () => 'accepted';
    expect(app(ChatBrowserPushDelivery::class)->recover())->toBe(1);
    expect(array_column($this->requests, 'subscription'))->toBe([$this->device->id, $other->id, $other->id]);
    expect(collect($this->requests)->pluck('payload.data.notification_id')->unique())->toHaveCount(1);
});

it('rechecks current audience unread state preferences and device identity before browser dispatch', function (string $change) {
    $message = ($this->send)();
    match ($change) {
        'mute' => $this->conversation->participants()->where('user_id', $this->recipient->id)->update(['is_muted' => true]),
        'read' => $this->conversation->participants()->where('user_id', $this->recipient->id)->update(['last_read_message_id' => $message]),
        'membership' => $this->conversation->participants()->where('user_id', $this->recipient->id)->delete(),
        'staff' => HrEmployeeProfile::where('user_id', $this->recipient->id)->update(['is_active' => false]),
        'preference' => UserNotificationPreference::where('user_id', $this->recipient->id)->update(['channel_push' => false]),
        'account' => $this->device->update(['user_id' => $this->sender->id]),
        'keys' => $this->device->update(['keys' => ['p256dh' => 'replacement', 'auth' => 'new-auth']]),
        'device' => $this->device->update(['enabled' => false]),
        'protocol' => $this->device->update(['chat_protocol_version' => 0]),
        'registration' => $this->device->update(['chat_registration_id' => (string) Str::uuid()]),
        'history' => $this->conversation->participants()->where('user_id', $this->recipient->id)->update(['history_after_id' => $message]),
    };
    expect(app(ChatBrowserPushDelivery::class)->recover())->toBe(0);
    expect($this->requests)->toBe([]);
    expect(DB::table('chat_browser_push_deliveries')->value('status'))->toBe('suppressed');
})->with(['mute', 'read', 'membership', 'staff', 'preference', 'account', 'keys', 'device', 'history', 'protocol', 'registration']);

it('defers quiet time and suppresses stale push without deleting the canonical message', function () {
    $this->recipient->update(['dnd_enabled' => true, 'dnd_until' => now()->addMinutes(5)]);
    ($this->send)();
    expect(app(ChatBrowserPushDelivery::class)->recover())->toBe(0);
    expect(DB::table('chat_browser_push_deliveries')->value('attempts'))->toBe(0);
    $this->travel(6)->minutes();
    expect(app(ChatBrowserPushDelivery::class)->recover())->toBe(1);
    ($this->send)();
    $this->travel(25)->hours();
    expect(app(ChatBrowserPushDelivery::class)->recover())->toBe(0);
    expect($this->requests)->toHaveCount(1);
    expect($this->conversation->messages()->count())->toBe(2);
});

it('recovers an abandoned claim and refuses a stale worker acknowledgement', function () {
    ($this->send)();
    DB::table('chat_browser_push_deliveries')->update(['status' => 'sending', 'claim_token' => (string) Str::uuid(), 'lease_until' => now()->addMinute()]);
    expect(app(ChatBrowserPushDelivery::class)->recover())->toBe(0);
    $this->travel(61)->seconds();
    $replacement = (string) Str::uuid();
    $this->outcome = function () use ($replacement) {
        expect(app(ChatBrowserPushDelivery::class)->recover())->toBe(0);
        DB::table('chat_browser_push_deliveries')->update(['claim_token' => $replacement]);

        return 'accepted';
    };
    expect(app(ChatBrowserPushDelivery::class)->recover())->toBe(0);
    expect(DB::table('chat_browser_push_deliveries')->value('claim_token'))->toBe($replacement);
    expect(DB::table('chat_browser_push_deliveries')->value('status'))->toBe('sending');
    $this->travel(121)->seconds();
    $this->outcome = fn () => 'accepted';
    expect(app(ChatBrowserPushDelivery::class)->recover())->toBe(1);
});

it('does not disable a newly rebound subscription when an old provider attempt expires', function () {
    ($this->send)();
    $this->outcome = function () {
        $this->device->update(['user_id' => $this->sender->id]);

        return 'expired';
    };
    expect(app(ChatBrowserPushDelivery::class)->recover())->toBe(0);
    expect($this->device->fresh()->enabled)->toBeTrue();
    expect(DB::table('chat_browser_push_deliveries')->value('status'))->toBe('expired');
});

it('retires the exact expired subscription and records an interrupted attempt without private diagnostics', function () {
    ($this->send)();
    $this->outcome = fn () => throw new RuntimeException('Secret endpoint keys and private data');
    expect(app(ChatBrowserPushDelivery::class)->recover())->toBe(0);
    expect(DB::table('chat_browser_push_deliveries')->value('last_error'))->toBe('Push attempt interrupted');
    $this->travel(61)->seconds();
    $this->outcome = fn () => 'expired';
    expect(app(ChatBrowserPushDelivery::class)->recover())->toBe(0);
    expect($this->device->fresh()->enabled)->toBeFalse();
});

it('commits push work atomically and does not send or backfill when rollout is disabled', function () {
    try {
        DB::transaction(function () {
            $message = $this->conversation->messages()->create(['sender_id' => $this->sender->id, 'content' => 'Rolled back']);
            app(ChatMessageDelivery::class)->enqueue($message, $this->conversation);
            expect(DB::table('chat_browser_push_deliveries')->count())->toBe(1);
            throw new RuntimeException('Rollback');
        });
    } catch (RuntimeException) {
    }
    expect(DB::table('chat_message_deliveries')->count())->toBe(0);
    expect(DB::table('chat_browser_push_deliveries')->count())->toBe(0);
    config(['chat.notifications.browser_push_enabled' => false]);
    ($this->send)();
    expect(DB::table('chat_browser_push_deliveries')->count())->toBe(0);
    config(['chat.notifications.browser_push_enabled' => true]);
    expect(app(ChatBrowserPushDelivery::class)->recover())->toBe(0);
    ($this->send)();
    config(['chat.notifications.browser_push_enabled' => false]);
    expect(app(ChatBrowserPushDelivery::class)->recover())->toBe(0);
    expect($this->requests)->toBe([]);
});

it('suppresses a pending family reply after canonical portal access is removed', function () {
    $client = Client::factory()->create();
    $relative = User::factory()->create(['role' => 'next_of_kin', 'approved_at' => now()]);
    $role = Role::firstOrCreate(['name' => 'next_of_kin'], ['label' => 'Next of Kin', 'level' => 1, 'type' => 'system']);
    $relative->roles()->attach($role->id);
    $client->portalUsers()->attach($relative->id, ['relation' => 'next_of_kin']);
    $conversation = OpsConversation::create(['conversation_type' => 'family', 'client_id' => $client->id]);
    $conversation->participants()->create(['user_id' => $relative->id, 'role' => 'family']);
    UserNotificationPreference::create(['user_id' => $relative->id, 'key' => 'chat.family.reply', 'enabled' => true, 'channel_push' => true]);
    $this->device->update(['user_id' => $relative->id]);
    DB::transaction(function () use ($conversation, $client) {
        $message = $conversation->messages()->create(['sender_id' => $this->sender->id, 'sender_type' => 'staff', 'client_id' => $client->id, 'content' => 'Private family reply']);
        app(ChatMessageDelivery::class)->enqueue($message, $conversation);
    });
    expect(DB::table('chat_browser_push_deliveries')->count())->toBe(1);
    $client->portalUsers()->detach($relative->id);
    expect(app(ChatBrowserPushDelivery::class)->recover())->toBe(0);
    expect($this->requests)->toBe([]);
});

it('authorises a private browser preview without changing delivery or message read state', function () {
    ($this->send)();
    $parent = DB::table('chat_message_deliveries')->sole();
    $binding = ChatBrowserPushDelivery::fingerprint($this->device);
    $url = route('chat.notifications.display', ['notification' => $parent->notification_id, 'binding' => $binding]);
    $this->actingAs($this->recipient)->getJson($url)->assertNotFound();
    $this->outcome = function () use ($url, $binding, $parent) {
        $this->actingAs($this->recipient)->getJson($url)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['title' => 'New Chat message', 'body' => 'Open Chat to view the message.',
                'actor_id' => $this->recipient->id, 'conversation_id' => $this->conversation->id,
                'data' => ['type' => 'chat', 'notification_id' => $parent->notification_id, 'device_binding' => $binding, 'silent' => false]]);

        return 'accepted';
    };
    expect(app(ChatBrowserPushDelivery::class)->recover())->toBe(1);
    expect($this->requests[0]['payload']['data']['device_binding'])->toBe($binding);
    $before = DB::table('chat_browser_push_deliveries')->sole();
    $this->actingAs($this->recipient)->getJson($url)->assertOk();
    expect(DB::table('chat_browser_push_deliveries')->sole())->toEqual($before);
    expect($this->recipient->notifications()->count())->toBe(0);
    expect((int) $this->conversation->participants()->where('user_id', $this->recipient->id)->value('last_read_message_id'))->toBe(0);
    $this->actingAs($this->sender)->getJson($url)->assertNotFound();
    auth()->logout();
    $this->getJson($url)->assertUnauthorized();
});

it('rejects an already sent browser preview when current context changes', function (string $change) {
    $message = ($this->send)();
    expect(app(ChatBrowserPushDelivery::class)->recover())->toBe(1);
    $parent = DB::table('chat_message_deliveries')->sole();
    $binding = ChatBrowserPushDelivery::fingerprint($this->device);
    switch ($change) {
        case 'owner': $this->device->update(['user_id' => $this->sender->id]);
            break;
        case 'keys': $this->device->update(['keys' => ['p256dh' => 'different', 'auth' => 'new']]);
            break;
        case 'disabled': $this->device->update(['enabled' => false]);
            break;
        case 'protocol': $this->device->update(['chat_protocol_version' => 0]);
            break;
        case 'registration': $this->device->update(['chat_registration_id' => (string) Str::uuid()]);
            break;
        case 'membership': $this->conversation->participants()->where('user_id', $this->recipient->id)->delete();
            break;
        case 'read': $this->conversation->participants()->where('user_id', $this->recipient->id)->update(['last_read_message_id' => $message]);
            break;
        case 'muted': $this->conversation->participants()->where('user_id', $this->recipient->id)->update(['is_muted' => true]);
            break;
        case 'quiet': $this->recipient->update(['dnd_enabled' => true, 'dnd_until' => now()->addHour()]);
            break;
        case 'preference': UserNotificationPreference::where('user_id', $this->recipient->id)->update(['channel_push' => false]);
            break;
        case 'removed': $this->conversation->messages()->findOrFail($message)->delete();
            break;
        case 'expired': $this->travel(25)->hours();
            break;
        case 'gate': config(['chat.notifications.browser_push_enabled' => false]);
            break;
        case 'rollout': config(['chat.shared_experience' => false]);
            break;
        case 'binding': $binding = str_repeat('f', 64);
            break;
        case 'malformed': $binding = ['not-a-string'];
            break;
    }
    $this->actingAs($this->recipient)->getJson(route('chat.notifications.display', [
        'notification' => $parent->notification_id, 'binding' => $binding,
    ]))->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
})->with(['owner', 'keys', 'disabled', 'membership', 'read', 'muted', 'quiet', 'preference', 'removed', 'expired', 'gate', 'rollout', 'binding', 'malformed', 'protocol', 'registration']);

it('only enqueues future Chat messages after an explicit legacy device upgrade', function () {
    $this->device->update(['chat_protocol_version' => 0, 'chat_registration_id' => null]);
    ($this->send)();
    expect(DB::table('chat_browser_push_deliveries')->count())->toBe(0);
    $this->actingAs($this->recipient)->postJson(route('settings.notifications.push-subscriptions.store'), [
        'provider' => 'webpush', 'actor_id' => $this->recipient->id, 'token' => $this->device->token,
        'keys' => $this->device->keys, 'chat_protocol_version' => 2,
    ])->assertCreated();
    expect(app(ChatBrowserPushDelivery::class)->recover())->toBe(0);
    expect(DB::table('chat_browser_push_deliveries')->count())->toBe(0);
    $future = ($this->send)();
    expect(DB::table('chat_browser_push_deliveries')->count())->toBe(1);
    expect(app(ChatBrowserPushDelivery::class)->recover())->toBe(1);
    $receipt = DB::table('chat_browser_push_deliveries')->sole();
    expect((int) DB::table('chat_message_deliveries')->find($receipt->delivery_id)->message_id)->toBe($future);
});

it('does not revive queued work after a downgrade and re-upgrade with the same browser keys', function () {
    ($this->send)();
    $old = DB::table('chat_browser_push_deliveries')->sole();
    $payload = ['provider' => 'webpush', 'actor_id' => $this->recipient->id,
        'token' => $this->device->token, 'keys' => $this->device->keys];
    $this->actingAs($this->recipient)->postJson(route('settings.notifications.push-subscriptions.store'), $payload)->assertCreated();
    $this->postJson(route('settings.notifications.push-subscriptions.store'), [...$payload, 'chat_protocol_version' => 2])->assertCreated();
    ($this->send)();
    expect(app(ChatBrowserPushDelivery::class)->recover())->toBe(1);
    expect(DB::table('chat_browser_push_deliveries')->find($old->id)->status)->toBe('suppressed');
    expect($this->requests)->toHaveCount(1);
});

it('does not renew idle activity from a background notification and still expires the session', function () {
    ($this->send)();
    app(ChatBrowserPushDelivery::class)->recover();
    $parent = DB::table('chat_message_deliveries')->sole();
    $url = route('chat.notifications.display', ['notification' => $parent->notification_id,
        'binding' => ChatBrowserPushDelivery::fingerprint($this->device)]);
    AppSetting::updateOrCreate(['key' => 'session_timeout_minutes'], ['value' => 30]);
    Cache::forget(SecurityPolicy::CACHE_KEY);
    $activity = now()->subMinutes(20)->timestamp;
    $this->actingAs($this->recipient)->withSession(['last_activity_at' => $activity])->getJson($url)->assertOk();
    expect(session('last_activity_at'))->toBe($activity);
    $this->travel(11)->minutes();
    $this->getJson($url)->assertRedirect(route('login'));
    $this->assertGuest();
});
