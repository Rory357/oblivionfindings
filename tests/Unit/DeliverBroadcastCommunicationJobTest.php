<?php

use App\Jobs\Notifications\DeliverBroadcastCommunicationJob;
use App\Models\ControlRoom\Communication;
use App\Services\Notifications\PushProvider;
use App\Services\Notifications\PushSendResult;
use App\Services\Notifications\SmsProvider;
use App\Services\Notifications\SmsSendResult;

uses(Tests\TestCase::class);

function createSmsCommunication(array $overrides = []): Communication
{
    $communication = new class extends Communication
    {
        public function save(array $options = []): bool
        {
            $this->syncOriginal();

            return true;
        }
    };

    $communication->forceFill(array_merge([
        'channel' => 'sms',
        'direction' => 'outbound',
        'purpose' => 'broadcast',
        'target_phone' => '+64210000000',
        'content' => 'Emergency drill at 10:00.',
        'status' => 'pending',
        'sent_at' => now(),
    ], $overrides));

    return $communication;
}

function sendSmsForTest(Communication $communication): void
{
    $method = new \ReflectionMethod(DeliverBroadcastCommunicationJob::class, 'sendSms');
    $method->invoke(new DeliverBroadcastCommunicationJob(1), $communication);
}

function sendPushForTest(Communication $communication): void
{
    $method = new \ReflectionMethod(DeliverBroadcastCommunicationJob::class, 'sendPush');
    $method->invoke(new DeliverBroadcastCommunicationJob(1), $communication);
}

test('broadcast sms delivery calls the configured provider and marks the row delivered', function () {
    $provider = new class implements SmsProvider
    {
        public array $messages = [];

        public function send(string $to, string $message): SmsSendResult
        {
            $this->messages[] = compact('to', 'message');

            return SmsSendResult::sent('SM123');
        }
    };

    $this->app->instance(SmsProvider::class, $provider);
    $communication = createSmsCommunication();

    sendSmsForTest($communication);

    expect($provider->messages)->toBe([
        ['to' => '+64210000000', 'message' => 'Emergency drill at 10:00.'],
    ]);

    expect($communication->status)->toBe('delivered')
        ->and($communication->delivered_at)->not->toBeNull()
        ->and($communication->status_detail)->toBeNull();
});

test('broadcast sms delivery records provider failures', function () {
    $this->app->instance(SmsProvider::class, new class implements SmsProvider
    {
        public function send(string $to, string $message): SmsSendResult
        {
            return SmsSendResult::failed('Invalid recipient number.');
        }
    });

    $communication = createSmsCommunication();

    sendSmsForTest($communication);

    expect($communication->status)->toBe('failed')
        ->and($communication->status_detail)->toBe('Invalid recipient number.');
});

test('broadcast push delivery calls the configured provider and marks the row delivered', function () {
    $provider = new class implements PushProvider
    {
        public array $messages = [];

        public function send(array $tokens, string $message, ?string $title = null, array $data = []): PushSendResult
        {
            $this->messages[] = compact('tokens', 'message', 'title', 'data');

            return PushSendResult::sent(['expo-ticket-1']);
        }
    };

    $this->app->instance(PushProvider::class, $provider);
    $communication = createSmsCommunication([
        'channel' => 'push',
        'target_phone' => null,
        'target_external' => 'ExponentPushToken[test-token]',
    ]);

    sendPushForTest($communication);

    expect($provider->messages)->toHaveCount(1)
        ->and($provider->messages[0]['tokens'])->toBe(['ExponentPushToken[test-token]'])
        ->and($provider->messages[0]['message'])->toBe('Emergency drill at 10:00.')
        ->and($provider->messages[0]['title'])->toBe('Broadcast alert')
        ->and($communication->status)->toBe('delivered')
        ->and($communication->delivered_at)->not->toBeNull()
        ->and($communication->status_detail)->toBeNull();
});

test('broadcast push delivery records provider failures', function () {
    $this->app->instance(PushProvider::class, new class implements PushProvider
    {
        public function send(array $tokens, string $message, ?string $title = null, array $data = []): PushSendResult
        {
            return PushSendResult::failed('No push token on record for the recipient.');
        }
    });

    $communication = createSmsCommunication([
        'channel' => 'push',
        'target_phone' => null,
        'target_external' => null,
    ]);

    sendPushForTest($communication);

    expect($communication->status)->toBe('failed')
        ->and($communication->status_detail)->toBe('No push token on record for the recipient.');
});
