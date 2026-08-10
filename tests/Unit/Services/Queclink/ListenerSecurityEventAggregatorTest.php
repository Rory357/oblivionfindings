<?php

use App\Services\Queclink\Listener\ListenerSecurityEventAggregator;

it('keeps separate bounded categories and flushes them after the fixed window', function () {
    $events = new ListenerSecurityEventAggregator;

    $events->record('invalid_frame', 100.0);
    $events->record('idle_timeout', 101.0);
    $events->record('invalid_frame', 102.0);

    expect($events->drain(159.9))->toBe([])
        ->and($events->drain(160.0))->toBe([
            'idle_timeout' => 1,
            'invalid_frame' => 2,
        ])
        ->and($events->drain(220.0))->toBe([]);
});

it('normalises unknown categories and force flushes a short terminal burst', function () {
    $events = new ListenerSecurityEventAggregator;

    $events->record('attacker supplied value', 100.0);
    $events->record('source_rate_limit', 101.0);

    expect($events->drain(102.0, true))->toBe([
        'invalid_frame' => 1,
        'source_rate_limit' => 1,
    ]);
});
