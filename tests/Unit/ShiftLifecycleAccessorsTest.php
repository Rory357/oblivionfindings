<?php

use App\Models\Shift;
use Illuminate\Support\Carbon;

uses(Tests\TestCase::class);

afterEach(function () {
    Carbon::setTestNow();
});

it('marks a shift late when it starts after the threshold', function () {
    Carbon::setTestNow('2026-04-28 09:00:00');

    $shift = new Shift([
        'starts_at' => Carbon::parse('2026-04-28 08:00:00'),
        'ends_at' => Carbon::parse('2026-04-28 12:00:00'),
        'actual_starts_at' => Carbon::parse('2026-04-28 08:06:00'),
        'status' => 'in_progress',
    ]);

    expect($shift->is_late)->toBeTrue();
});

it('marks an unstarted scheduled shift late after the threshold', function () {
    Carbon::setTestNow('2026-04-28 08:06:00');

    $shift = new Shift([
        'starts_at' => Carbon::parse('2026-04-28 08:00:00'),
        'ends_at' => Carbon::parse('2026-04-28 12:00:00'),
        'actual_starts_at' => null,
        'status' => 'scheduled',
    ]);

    expect($shift->is_late)->toBeTrue();
});

it('does not mark on-time starts as late', function () {
    Carbon::setTestNow('2026-04-28 09:00:00');

    $shift = new Shift([
        'starts_at' => Carbon::parse('2026-04-28 08:00:00'),
        'ends_at' => Carbon::parse('2026-04-28 12:00:00'),
        'actual_starts_at' => Carbon::parse('2026-04-28 08:05:00'),
        'status' => 'in_progress',
    ]);

    expect($shift->is_late)->toBeFalse();
});

it('marks a scheduled shift missed after its end when nobody clocked in', function () {
    Carbon::setTestNow('2026-04-28 12:01:00');

    $shift = new Shift([
        'starts_at' => Carbon::parse('2026-04-28 08:00:00'),
        'ends_at' => Carbon::parse('2026-04-28 12:00:00'),
        'actual_starts_at' => null,
        'status' => 'scheduled',
    ]);

    expect($shift->is_missed)->toBeTrue();
});

it('does not mark started shifts missed', function () {
    Carbon::setTestNow('2026-04-28 12:01:00');

    $shift = new Shift([
        'starts_at' => Carbon::parse('2026-04-28 08:00:00'),
        'ends_at' => Carbon::parse('2026-04-28 12:00:00'),
        'actual_starts_at' => Carbon::parse('2026-04-28 08:02:00'),
        'status' => 'completed',
    ]);

    expect($shift->is_missed)->toBeFalse();
});
