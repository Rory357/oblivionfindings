<?php

use App\Services\Queclink\Listener\ConnectionState;
use App\Services\Queclink\Listener\ListenerLimits;
use App\Services\Queclink\Listener\ListenerPressureGuard;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config()->set('services.queclink.listener', [
        'max_connections' => 2,
        'max_connections_per_source' => 1,
        'max_tracked_sources' => 64,
        'connection_attempts_per_window' => 2,
        'connection_window_seconds' => 60,
        'idle_timeout_seconds' => 30,
        'max_frame_bytes' => 256,
        'max_buffer_bytes' => 512,
        'frames_per_window' => 2,
        'invalid_frames_per_window' => 2,
        'frame_window_seconds' => 60,
    ]);

    $this->limits = new ListenerLimits;
    $this->guard = new ListenerPressureGuard($this->limits);
});

it('fails closed at global and per-source concurrent connection caps', function () {
    expect($this->guard->connectionRejection('192.0.2.10:5000', 2, 0, 100.0))
        ->toBe('connection_limit')
        ->and($this->guard->connectionRejection('192.0.2.11:5000', 1, 1, 100.0))
        ->toBe('source_connection_limit');
});

it('rate limits repeated source connection attempts without retaining the address', function () {
    expect($this->guard->connectionRejection('[2001:db8::1]:5000', 0, 0, 100.0))->toBeNull()
        ->and($this->guard->connectionRejection('[2001:db8::1]:5001', 0, 0, 101.0))->toBeNull()
        ->and($this->guard->connectionRejection('[2001:db8::1]:5002', 0, 0, 102.0))
        ->toBe('source_rate_limit')
        ->and($this->guard->sourceFingerprint('[2001:db8::1]:5000'))
        ->not->toContain('2001:db8');
});

it('bounds unique source pressure state and rejects new sources when the cap is full', function () {
    foreach (range(1, 64) as $source) {
        expect($this->guard->connectionRejection("192.0.2.{$source}:5000", 0, 0, 100.0))->toBeNull();
    }

    expect($this->guard->connectionRejection('198.51.100.1:5000', 0, 0, 100.0))
        ->toBe('source_tracking_limit');
});

it('caps frame and invalid-frame floods in the configured window', function () {
    $state = new ConnectionState('192.0.2.20:5000');
    $state->frameWindowStartedAt = 100.0;

    expect($this->guard->frameRejection($state, 100.0))->toBeNull()
        ->and($this->guard->frameRejection($state, 101.0))->toBeNull()
        ->and($this->guard->frameRejection($state, 102.0))->toBe('frame_rate_limit')
        ->and($this->guard->invalidFrameRejection($state, 103.0))->toBeNull()
        ->and($this->guard->invalidFrameRejection($state, 104.0))->toBeNull()
        ->and($this->guard->invalidFrameRejection($state, 105.0))->toBe('invalid_frame_limit');
});

it('identifies idle connections for pruning at the configured boundary', function () {
    $state = new ConnectionState('192.0.2.20:5000');
    $state->lastActivityAt = 100.0;

    expect($this->guard->isIdle($state, 129.9))->toBeFalse()
        ->and($this->guard->isIdle($state, 130.0))->toBeTrue();
});

it('rejects invalid listener configuration instead of silently widening a limit', function () {
    config()->set('services.queclink.listener.max_frame_bytes', 'unbounded');

    expect(fn () => new ListenerLimits)->toThrow(InvalidArgumentException::class);
});
