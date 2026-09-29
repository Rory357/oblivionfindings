<?php

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Tests\Support\FrozenClockTimezone;

/*
 * Tests\TestCase fails any test whose frozen clock would skew the datetimes
 * Eloquent reads back. These tests need no application boot or database.
 */

beforeEach(function (): void {
    date_default_timezone_set('UTC');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('reads a stored UTC datetime 12 hours off only while time is frozen in worker time', function (): void {
    // Eloquent's asDateTime() parses stored values exactly like this.
    $stored = '2026-05-20 21:00:00';

    Carbon::setTestNow(Carbon::parse('2026-05-21 10:00:00', 'Pacific/Auckland'));
    $readInWorkerTime = Illuminate\Support\Carbon::createFromFormat('Y-m-d H:i:s', $stored)->utc()->toIso8601String();

    Carbon::setTestNow(Carbon::parse('2026-05-21 10:00:00', 'Pacific/Auckland')->utc());
    $readInUtc = Illuminate\Support\Carbon::createFromFormat('Y-m-d H:i:s', $stored)->utc()->toIso8601String();

    expect($readInWorkerTime)->toBe('2026-05-20T09:00:00+00:00')
        ->and($readInUtc)->toBe('2026-05-20T21:00:00+00:00')
        ->and(Carbon::now('Pacific/Auckland')->format('Y-m-d H:i'))->toBe('2026-05-21 10:00');
});

it('accepts a clock that is not frozen or is frozen in UTC', function (?DateTimeInterface $frozenAt): void {
    Carbon::setTestNow($frozenAt);

    expect(FrozenClockTimezone::violation())->toBeNull();
})->with([
    'not frozen' => [null],
    'worker time converted to UTC' => [Carbon::parse('2026-05-21 10:00:00', 'Pacific/Auckland')->utc()],
    'immutable worker time converted to UTC' => [CarbonImmutable::parse('2026-05-21 10:00:00', 'Pacific/Auckland')->utc()],
    'an ISO string with a zero offset' => [Carbon::parse('2026-05-20T22:00:00+00:00', 'Pacific/Auckland')],
    'a Zulu string' => [CarbonImmutable::parse('2026-08-09T12:00:00Z')],
]);

it('names the frozen timezone and the UTC fix', function (DateTimeInterface $frozenAt, string $timezone): void {
    Carbon::setTestNow($frozenAt);

    expect(FrozenClockTimezone::violation())
        ->toStartWith("Time is frozen in {$timezone}, but the default timezone is UTC.")
        ->toContain("Carbon::parse('2026-05-21 10:00:00', 'Pacific/Auckland')->utc()");
})->with([
    'worker time' => [Carbon::parse('2026-05-21 10:00:00', 'Pacific/Auckland'), 'Pacific/Auckland'],
    'immutable worker time' => [CarbonImmutable::parse('2026-05-21 10:00:00', 'Pacific/Auckland'), 'Pacific/Auckland'],
    'now in worker time' => [Carbon::now('Pacific/Auckland'), 'Pacific/Auckland'],
    'a +12:00 offset string' => [Carbon::parse('2026-08-28T09:00:00+12:00'), '+12:00'],
]);
