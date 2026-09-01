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
