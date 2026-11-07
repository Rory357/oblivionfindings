<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

function chatScheduledEvent(string $command): Event
{
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, $command));

    expect($event)->not->toBeNull();

    return $event;
}

it('runs the chat delivery sweep only while shared Chat is on', function () {
    $event = chatScheduledEvent('chat:deliver-notifications');

    config(['chat.shared_experience' => false]);
    expect($event->filtersPass(app()))->toBeFalse();

    config(['chat.shared_experience' => true]);
    expect($event->filtersPass(app()))->toBeTrue()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->repeatSeconds)->toBe(10)
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('runs the call sweep only once a voice server is configured', function () {
    $event = chatScheduledEvent('chat:reconcile-calls');

    config(['chat.calling' => ['url' => null, 'api_url' => null, 'api_key' => null, 'api_secret' => null]]);
    expect($event->filtersPass(app()))->toBeFalse();

    config(['chat.calling' => [
        'url' => 'wss://voice.example.test',
        'api_url' => 'https://voice-api.example.test',
        'api_key' => 'test-only',
        'api_secret' => str_repeat('test', 12),
    ]]);
    expect($event->filtersPass(app()))->toBeTrue()
        ->and($event->repeatSeconds)->toBe(10);
});
