<?php

use App\Services\Integration\AlertRoutingService;
use Carbon\CarbonImmutable;

/*
 * Quiet hours are New Zealand wall-clock times. They used to be compared with
 * UTC, so 22:00-06:00 muted alerts from 11:00 to 19:00 NZDT. These tests need
 * no application boot or database.
 */

function alertRoutingQuietAt(string $utcInstant, ?string $start = '22:00', ?string $end = '06:00'): bool
{
    return AlertRoutingService::withinQuietHours(
        $start,
        $end,
        CarbonImmutable::parse($utcInstant, 'UTC'),
        'Pacific/Auckland',
    );
}

it('applies an overnight range in NZ daylight time (NZDT, UTC+13)', function (string $utc, bool $quiet): void {
    expect(alertRoutingQuietAt($utc))->toBe($quiet);
})->with([
    '21:59 NZDT, before the start' => ['2026-01-15T08:59:00Z', false],
    '22:00 NZDT, the start' => ['2026-01-15T09:00:00Z', true],
    '23:30 NZDT' => ['2026-01-15T10:30:00Z', true],
    '00:00 NZDT, just after midnight' => ['2026-01-15T11:00:00Z', true],
    '05:59 NZDT' => ['2026-01-15T16:59:00Z', true],
    '06:00 NZDT, the end is not quiet' => ['2026-01-15T17:00:00Z', false],
    '13:00 NZDT (00:00 UTC, quiet under the old UTC check)' => ['2026-01-15T00:00:00Z', false],
    '18:30 NZDT (05:30 UTC, quiet under the old UTC check)' => ['2026-01-15T05:30:00Z', false],
]);

it('applies an overnight range in NZ standard time (NZST, UTC+12)', function (string $utc, bool $quiet): void {
    expect(alertRoutingQuietAt($utc))->toBe($quiet);
})->with([
    '21:59 NZST, before the start' => ['2026-07-15T09:59:00Z', false],
    '22:00 NZST, the start' => ['2026-07-15T10:00:00Z', true],
    '00:30 NZST, after midnight' => ['2026-07-15T12:30:00Z', true],
    '05:59 NZST' => ['2026-07-15T17:59:00Z', true],
    '06:00 NZST, the end is not quiet' => ['2026-07-15T18:00:00Z', false],
    '15:00 NZST (03:00 UTC, quiet under the old UTC check)' => ['2026-07-15T03:00:00Z', false],
]);

it('follows the clock change when daylight saving starts overnight', function (string $utc, bool $quiet): void {
    // 27 September 2026: at 02:00 NZST the clocks go forward to 03:00 NZDT.
    expect(alertRoutingQuietAt($utc))->toBe($quiet);
})->with([
    '01:30 NZST' => ['2026-09-26T13:30:00Z', true],
    '03:30 NZDT, just after the change' => ['2026-09-26T14:30:00Z', true],
    '05:59 NZDT' => ['2026-09-26T16:59:00Z', true],
    '06:30 NZDT (would be 05:30 if still NZST)' => ['2026-09-26T17:30:00Z', false],
]);

it('follows the clock change when daylight saving ends overnight', function (string $utc, bool $quiet): void {
    // 5 April 2026: at 03:00 NZDT the clocks go back to 02:00 NZST.
    expect(alertRoutingQuietAt($utc))->toBe($quiet);
})->with([
    '02:30 NZDT, before the change' => ['2026-04-04T13:30:00Z', true],
    '02:30 NZST, the repeated hour' => ['2026-04-04T14:30:00Z', true],
    '05:59 NZST' => ['2026-04-04T17:59:00Z', true],
    '06:00 NZST (would be 07:00 if still NZDT)' => ['2026-04-04T18:00:00Z', false],
]);

it('applies a same-day range in local time', function (): void {
    expect(alertRoutingQuietAt('2026-01-15T00:00:00Z', '09:00', '17:00'))->toBeTrue() // 13:00 NZDT
        ->and(alertRoutingQuietAt('2026-01-15T05:00:00Z', '09:00', '17:00'))->toBeFalse() // 18:00 NZDT
        ->and(alertRoutingQuietAt('2026-01-14T19:59:00Z', '09:00', '17:00'))->toBeFalse(); // 08:59 NZDT
});

it('never mutes alerts for a half-set, identical or malformed range', function (?string $start, ?string $end): void {
    foreach (['2026-01-15T10:30:00Z', '2026-01-15T00:00:00Z', '2026-07-15T12:30:00Z'] as $utc) {
        expect(alertRoutingQuietAt($utc, $start, $end))->toBeFalse();
    }
})->with([
    'start only' => ['22:00', null],
    'end only' => [null, '06:00'],
    'both empty' => ['', ''],
    'same start and end' => ['22:00', '22:00'],
    'malformed start' => ['10pm', '06:00'],
    'seconds included' => ['22:00:00', '06:00:00'],
    'out of range' => ['24:00', '06:00'],
]);
