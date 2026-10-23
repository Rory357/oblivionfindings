<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Notifications\Channels\PushChannel;
use App\Notifications\ShiftTaskDueNotification;
use App\Services\Notifications\ExpoPushProvider;
use App\Services\Notifications\PushSendResult;
use App\Services\Notifications\WebPushProvider;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

it('stores browser web-push subscriptions with endpoint and keys', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('settings.notifications.push-subscriptions.store'), [
            'provider' => 'webpush',
            'actor_id' => $user->id,
            'token' => 'https://push.example.test/subscription/abc123',
            'keys' => [
                'p256dh' => 'p256dh-key',
                'auth' => 'auth-key',
            ],
            'platform' => 'web',
        ])
        ->assertCreated()
        ->assertJsonPath('provider', 'webpush');

    $subscription = $user->pushSubscriptions()->where('provider', 'webpush')->firstOrFail();

    expect($subscription->token)->toBe('https://push.example.test/subscription/abc123')
        ->and($subscription->keys)->toBe(['p256dh' => 'p256dh-key', 'auth' => 'auth-key'])
        ->and($subscription->platform)->toBe('web')
        ->and($subscription->enabled)->toBeTrue();
});

it('fans a Laravel notification out to Expo and browser push subscriptions', function () {
    $user = User::factory()->create();
    $user->pushSubscriptions()->create([
        'provider' => 'expo',
        'token' => 'ExponentPushToken[test]',
        'platform' => 'ios',
        'enabled' => true,
    ]);
    $user->pushSubscriptions()->create([
        'provider' => 'webpush',
        'token' => 'https://push.example.test/subscription/abc123',
        'keys' => ['p256dh' => 'p256dh-key', 'auth' => 'auth-key'],
        'platform' => 'web',
        'enabled' => true,
    ]);

    $expo = new class
    {
        public array $calls = [];

        public function send(array $tokens, string $message, ?string $title = null, array $data = []): PushSendResult
        {
            $this->calls[] = compact('tokens', 'message', 'title', 'data');

            return PushSendResult::sent(['expo-ticket']);
        }
    };
    $web = new class
    {
        public array $calls = [];

        public function sendToSubscriptions($subscriptions, array $payload): void
        {
            $this->calls[] = [
                'tokens' => $subscriptions->pluck('token')->values()->all(),
                'payload' => $payload,
            ];
        }
    };

    $this->app->instance(ExpoPushProvider::class, $expo);
    $this->app->instance(WebPushProvider::class, $web);

    $notification = new class extends Notification
    {
        public function toPush(object $notifiable): array
        {
            return [
                'title' => 'Task due',
                'body' => 'Give medication prompt is due now',
                'data' => ['url' => '/my-day'],
            ];
        }
    };

    app(PushChannel::class)->send($user, $notification);

    expect($expo->calls)->toHaveCount(1)
        ->and($expo->calls[0]['tokens'])->toBe(['ExponentPushToken[test]'])
        ->and($expo->calls[0]['message'])->toBe('Give medication prompt is due now')
        ->and($expo->calls[0]['title'])->toBe('Task due')
        ->and($web->calls)->toHaveCount(1)
        ->and($web->calls[0]['tokens'])->toBe(['https://push.example.test/subscription/abc123'])
        ->and($web->calls[0]['payload']['data']['url'])->toBe('/my-day');
});

it('honours shift task due notification channel preferences before push dispatch', function () {
    $site = Site::factory()->create();
    $user = User::factory()->frontlineWorker()->create();
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
        'start_date' => '2025-01-01',
        'end_date' => null,
    ]);
    $client = Client::factory()->create(['site_id' => $site->id]);
    $shift = Shift::factory()->create([
        'client_id' => $client->id,
        'site_id' => $site->id,
        'user_id' => $user->id,
        'starts_at' => Carbon::parse('2026-06-01 09:00:00', 'Pacific/Auckland')->utc(),
        'ends_at' => Carbon::parse('2026-06-01 13:00:00', 'Pacific/Auckland')->utc(),
    ]);
    $task = $shift->tasks()->create([
        'label' => 'Medication prompt',
        'scheduled_time' => '10:00',
        'sort_order' => 0,
    ]);

    UserNotificationPreference::query()->create([
        'user_id' => $user->id,
        'key' => 'shift_task_due',
        'enabled' => true,
        'channel_inapp' => false,
        'channel_email' => true,
        'channel_push' => false,
    ]);

    $channels = (new ShiftTaskDueNotification($task))->via($user);

    expect($channels)
        ->toBe(['mail'])
        ->not->toContain(PushChannel::class)
        ->not->toContain('database');

    UserNotificationPreference::query()
        ->where('user_id', $user->id)
        ->where('key', 'shift_task_due')
        ->update(['enabled' => false]);

    expect((new ShiftTaskDueNotification($task))->via($user))->toBe([]);
});

/** @param  list<string>|null  $hosts  null keeps the shipped push-host list */
function webPushTestKeys(?array $hosts = ['fcm.googleapis.com']): array
{
    $vapid = App\Services\Push\VapidKeys::generate();
    config([
        'services.webpush.public_key' => $vapid->publicKey,
        'services.webpush.private_key' => $vapid->privateKey,
        'services.webpush.subject' => 'mailto:push@oblivion.example',
    ]);
    if ($hosts !== null) {
        config(['chat.notifications.push_hosts' => $hosts]);
    }

    return ['p256dh' => App\Services\Push\VapidKeys::generate()->publicKey, 'auth' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=')];
}

it('sends notification pushes through Oblivion\'s sender to allowed push services only', function () {
    $keys = webPushTestKeys();
    $user = User::factory()->create();
    $allowed = $user->pushSubscriptions()->create(['provider' => 'webpush', 'token' => 'https://fcm.googleapis.com/fcm/send/allowed', 'keys' => $keys, 'platform' => 'web', 'enabled' => true]);
    $user->pushSubscriptions()->create(['provider' => 'webpush', 'token' => 'https://internal.example.test/fcm/send/x', 'keys' => $keys, 'platform' => 'web', 'enabled' => true]);
    $user->pushSubscriptions()->create(['provider' => 'webpush', 'token' => 'https://fcm.googleapis.com/fcm/send/no-keys', 'keys' => [], 'platform' => 'web', 'enabled' => true]);
    Illuminate\Support\Facades\Http::fake(['*' => Illuminate\Support\Facades\Http::response('', 201)]);

    app(WebPushProvider::class)->sendToSubscriptions($user->pushSubscriptions()->get(), [
        'title' => 'Medication due', 'body' => 'Open Oblivion to record the dose.', 'data' => ['url' => '/emar'],
    ]);

    Illuminate\Support\Facades\Http::assertSentCount(1);
    Illuminate\Support\Facades\Http::assertSent(fn (Illuminate\Http\Client\Request $request) => $request->url() === $allowed->token
        && $request->header('Content-Encoding') === ['aes128gcm']
        && $request->header('TTL') === [(string) WebPushProvider::TTL_SECONDS]
        && $request->header('Urgency') === [WebPushProvider::URGENCY]
        && str_starts_with($request->header('Authorization')[0] ?? '', 'vapid t=')
        && ! str_contains($request->body(), 'Medication'));
});

it('reaches Chrome, Firefox, Safari and Edge push services with the shipped host list', function () {
    $keys = webPushTestKeys(hosts: null);
    $user = User::factory()->create();
    $endpoints = [
        'https://fcm.googleapis.com/fcm/send/chrome',
        'https://updates.push.services.mozilla.com/wpush/v2/firefox',
        'https://web.push.apple.com/safari',
        'https://wns2-bl2p.notify.windows.com/w/?token=edge',
    ];
    foreach ($endpoints as $endpoint) {
        $user->pushSubscriptions()->create(['provider' => 'webpush', 'token' => $endpoint, 'keys' => $keys, 'platform' => 'web', 'enabled' => true]);
    }
    Illuminate\Support\Facades\Http::fake(['*' => Illuminate\Support\Facades\Http::response('', 201)]);

    app(WebPushProvider::class)->sendToSubscriptions($user->pushSubscriptions()->get(), ['title' => 'Medication due', 'body' => 'Open Oblivion.', 'data' => []]);

    expect(config('chat.notifications.push_hosts'))->toContain('.notify.windows.com')
        ->and(Illuminate\Support\Facades\Http::recorded()->map(fn ($pair) => $pair[0]->url())->sort()->values()->all())->toBe(collect($endpoints)->sort()->values()->all());
});

it('turns off a browser subscription once its push service says it has gone', function () {
    $keys = webPushTestKeys();
    $user = User::factory()->create();
    $gone = $user->pushSubscriptions()->create(['provider' => 'webpush', 'token' => 'https://fcm.googleapis.com/fcm/send/gone', 'keys' => $keys, 'platform' => 'web', 'enabled' => true]);
    $busy = $user->pushSubscriptions()->create(['provider' => 'webpush', 'token' => 'https://fcm.googleapis.com/fcm/send/busy', 'keys' => $keys, 'platform' => 'web', 'enabled' => true]);
    Illuminate\Support\Facades\Http::fake([
        'fcm.googleapis.com/fcm/send/gone' => Illuminate\Support\Facades\Http::response('', 410),
        'fcm.googleapis.com/fcm/send/busy' => Illuminate\Support\Facades\Http::response('', 503),
    ]);

    app(WebPushProvider::class)->sendToSubscriptions($user->pushSubscriptions()->get(), ['title' => 'Task due', 'body' => 'Check the roster.', 'data' => []]);

    expect($gone->fresh()->enabled)->toBeFalse()
        ->and($busy->fresh()->enabled)->toBeTrue();
});

it('prints a fresh VAPID pair in the stored form with webpush:vapid --show', function () {
    Illuminate\Support\Facades\Artisan::call('webpush:vapid', ['--show' => true]);
    $output = Illuminate\Support\Facades\Artisan::output();

    // Console lines end in \r\n on Windows.
    expect(preg_match('/^VAPID_PUBLIC_KEY=([A-Za-z0-9_-]{87})\r?$/m', $output, $public))->toBe(1)
        ->and(preg_match('/^VAPID_PRIVATE_KEY=([A-Za-z0-9_-]{43})\r?$/m', $output, $private))->toBe(1)
        ->and(App\Services\Push\VapidKeys::fromStored($public[1], $private[1])->signingKey())->toBeInstanceOf(OpenSSLAsymmetricKey::class);
});
