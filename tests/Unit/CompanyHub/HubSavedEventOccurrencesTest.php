<?php

use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Services\HrCalendarRecurrence;
use Illuminate\Support\Carbon;

test('saved history resolves the latest date of a century long unlimited series without a lifetime expansion', function () {
    $event = new HrCalendarEvent;
    $event->setDateFormat('Y-m-d H:i:s');
    $event->setRawAttributes(['starts_at' => '1900-01-01 03:00:00', 'ends_at' => '1900-01-01 04:00:00',
        'rrule' => 'FREQ=DAILY', 'recurrence_until' => '2020-12-31 23:59:59']);
    $recurrence = new HrCalendarRecurrence;
    $last = $recurrence->latestBefore($event, Carbon::parse('2026-10-16T00:00:00Z'), ['2020-12-31', '2020-12-30']);
    expect($last['date'])->toBe('2020-12-29')->and($last['start']->format('H:i'))->toBe('03:00');
});

test('saved history retains finite count and all suppressed date semantics', function () {
    $event = new HrCalendarEvent;
    $event->setDateFormat('Y-m-d H:i:s');
    $event->setRawAttributes(['starts_at' => '2024-01-01 03:00:00', 'ends_at' => '2024-01-01 04:00:00', 'rrule' => 'FREQ=WEEKLY;COUNT=3']);
    $recurrence = new HrCalendarRecurrence;
    $now = Carbon::parse('2026-10-16T00:00:00Z');
    expect($recurrence->latestBefore($event, $now, ['2024-01-15'])['date'])->toBe('2024-01-08');
    expect($recurrence->latestBefore($event, $now, ['2024-01-15', '2024-01-08', '2024-01-01']))->toBeNull();
    expect($recurrence->latestBefore($event, Carbon::parse('2023-01-01T00:00:00Z')))->toBeNull();
});
