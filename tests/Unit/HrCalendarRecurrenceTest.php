<?php

use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Services\HrCalendarExport;
use App\Domain\Hr\Services\HrCalendarRecurrence;
use Illuminate\Support\Carbon;

function recurrenceEvent(string $start, string $rule = 'FREQ=WEEKLY;COUNT=3', string $zone = 'Pacific/Auckland'): HrCalendarEvent
{
    $instant = Carbon::parse($start)->utc();
    $event = new HrCalendarEvent;
    $event->setDateFormat('Y-m-d H:i:s');
    $event->setRawAttributes(['starts_at' => $instant->format('Y-m-d H:i:s'), 'ends_at' => $instant->copy()->addHour()->format('Y-m-d H:i:s'),
        'rrule' => $rule, 'recurrence_timezone' => $zone, 'is_all_day' => false]);

    return $event;
}

test('NZ weekly repeats keep local time across spring and autumn while old UTC schedules keep their keys', function () {
    $recurrence = new HrCalendarRecurrence;
    foreach (['2026-09-20T10:00:00+12:00', '2027-03-28T10:00:00+13:00'] as $start) {
        $event = recurrenceEvent($start);
        $dates = $recurrence->between($event, $event->starts_at->copy()->subDay(), $event->starts_at->copy()->addMonth());
        expect(array_map(fn ($d) => $d['start']->copy()->tz('Pacific/Auckland')->format('H:i'), $dates))->toBe(['10:00', '10:00', '10:00']);
        expect($dates[0]['start']->diffInHours($dates[1]['start']))->not->toEqual(168);
    }
    $legacy = recurrenceEvent('2026-09-20T00:30:00Z', zone: 'UTC');
    $dates = $recurrence->between($legacy, Carbon::parse('2026-09-01'), Carbon::parse('2026-10-10'));
    expect(array_column($dates, 'date'))->toBe(['2026-09-20', '2026-09-27', '2026-10-04']);
    expect($dates[1]['start']->copy()->tz('Pacific/Auckland')->format('H:i'))->toBe('13:30');
});

test('a skipped clock-change hour is omitted without shifting the following repeats or consuming COUNT', function () {
    $recurrence = new HrCalendarRecurrence;
    $event = recurrenceEvent('2026-09-26T02:30:00+12:00', 'FREQ=DAILY;COUNT=3');
    $dates = $recurrence->between($event, Carbon::parse('2026-09-24'), Carbon::parse('2026-10-01'));
    expect(array_map(fn ($d) => $d['start']->copy()->tz('Pacific/Auckland')->format('Y-m-d H:i'), $dates))
        ->toBe(['2026-09-26 02:30', '2026-09-28 02:30', '2026-09-29 02:30']);
    expect($recurrence->resolve('2027-04-04 02:30:00', 'Pacific/Auckland')->toIso8601String())->toBe('2027-04-03T13:30:00+00:00');
});

test('monthly local repeats return to the original day after short months and legacy months retain existing behaviour', function () {
    $recurrence = new HrCalendarRecurrence;
    $event = recurrenceEvent('2027-01-31T10:00:00+13:00', 'FREQ=MONTHLY;COUNT=3');
    $dates = $recurrence->between($event, Carbon::parse('2027-01-01'), Carbon::parse('2027-05-01'));
    expect(array_map(fn ($d) => $d['start']->copy()->tz('Pacific/Auckland')->format('m-d'), $dates))->toBe(['01-31', '02-28', '03-31']);
    $old = recurrenceEvent('2027-01-31T10:00:00Z', 'FREQ=MONTHLY;COUNT=3', 'UTC');
    expect(array_column($recurrence->between($old, Carbon::parse('2027-01-01'), Carbon::parse('2027-05-01')), 'date'))->toBe(['2027-01-31', '2027-02-28', '2027-03-28']);
});

test('long-running series do not disappear after 400 dates and range edges include overlapping events', function () {
    $recurrence = new HrCalendarRecurrence;
    $event = recurrenceEvent('2020-01-01T10:00:00Z', 'FREQ=DAILY', 'UTC');
    $dates = $recurrence->between($event, Carbon::parse('2030-05-01'), Carbon::parse('2030-05-03 23:59:59'));
    expect(array_column($dates, 'date'))->toBe(['2030-05-01', '2030-05-02', '2030-05-03']);
    expect($recurrence->between($event, Carbon::parse('2030-05-01 10:30:00'), Carbon::parse('2030-05-01 10:40:00')))->toHaveCount(1);
});

test('finite series exports preserve exact DST dates and distinguish replacements from cancellations', function () {
    $root = recurrenceEvent('2026-09-20T10:00:00+12:00');
    $root->setRawAttributes([...$root->getAttributes(), 'id' => 17, 'title' => "Team, together\nBEGIN:BAD"]);
    $moved = recurrenceEvent('2026-09-28T11:00:00+13:00');
    $moved->setRawAttributes([...$moved->getAttributes(), 'is_exception' => true, 'exception_date' => '2026-09-26', 'title' => 'Moved session']);
    $cancelled = recurrenceEvent('2026-10-04T10:00:00+13:00');
    $cancelled->setRawAttributes([...$cancelled->getAttributes(), 'is_exception' => true, 'exception_date' => '2026-10-03', 'archived_at' => '2026-09-01 00:00:00', 'title' => 'Private cancellation details']);
    $ics = (new HrCalendarExport(new HrCalendarRecurrence))->series($root, [$moved, $cancelled], fn () => true, 15);
    expect($ics)->toContain('RDATE:20260926T210000Z', 'RDATE:20261003T210000Z', 'RECURRENCE-ID:20260926T210000Z',
        'DTSTART:20260927T220000Z', 'EXDATE:20261003T210000Z', 'TRIGGER:-PT15M', 'SUMMARY:Team\\, together\\nBEGIN:BAD')
        ->not->toContain('EXDATE:20260926T210000Z', 'Private cancellation details', "\r\nBEGIN:BAD");
    expect(substr_count($ics, 'UID:calendar-series-17-0@oblivion-findings'))->toBe(2);
    $hidden = (new HrCalendarExport(new HrCalendarRecurrence))->series($root, [$moved], fn () => false);
    expect($hidden)->toContain('EXDATE:20260926T210000Z')->not->toContain('Moved session');
});

test('unbounded exports use local timezone rules and keep legacy monthly phase changes', function () {
    $export = new HrCalendarExport(new HrCalendarRecurrence);
    $local = $export->series(recurrenceEvent('2027-01-31T10:00:00+13:00', 'FREQ=MONTHLY'), [], fn () => true);
    $unfolded = str_replace("\r\n ", '', $local);
    expect($unfolded)->toContain('TZID:Pacific/Auckland', 'DTSTART;TZID=Pacific/Auckland:20270131T100000',
        'RRULE:FREQ=YEARLY;BYMONTH=4;BYDAY=1SU', 'RRULE:FREQ=YEARLY;BYMONTH=9;BYDAY=-1SU',
        'BYMONTHDAY='.implode(',', range(1, 31)).';BYSETPOS=-1');
    $old = $export->series(recurrenceEvent('2027-01-31T10:00:00Z', 'FREQ=MONTHLY', 'UTC'), [], fn () => true);
    expect(substr_count($old, 'BEGIN:VEVENT'))->toBe(2);
    expect($old)->toContain('RRULE:FREQ=MONTHLY;INTERVAL=1;UNTIL=20270228T095959Z', 'DTSTART:20270228T100000Z');
    $folded = $export->fold(['SUMMARY:'.str_repeat('Māori, ', 40)]);
    expect(mb_check_encoding($folded, 'UTF-8'))->toBeTrue();
    foreach (explode("\r\n", $folded) as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75);
    }
});

test('until dates include the local final day and unchanged legacy cutoffs are preserved', function () {
    $recurrence = new HrCalendarRecurrence;
    expect($recurrence->normalizeUntil('2026-10-04', 'Pacific/Auckland')->format('Y-m-d H:i:s'))->toBe('2026-10-04 10:59:59');
    $old = recurrenceEvent('2026-09-20T10:00:00Z', zone: 'UTC');
    $old->setRawAttributes([...$old->getAttributes(), 'recurrence_until' => '2026-10-04 00:00:00']);
    expect($recurrence->normalizeUntil('2026-10-04', 'UTC', $old)->format('H:i:s'))->toBe('00:00:00');
});

test('an unbounded series beginning in the second repeated hour preserves its chosen instant and exact duration', function () {
    $event = recurrenceEvent('2027-04-04T02:30:00+12:00', 'FREQ=WEEKLY');
    $ics = (new HrCalendarExport(new HrCalendarRecurrence))->series($event, [], fn () => true);
    expect($ics)->toContain('DTSTART;TZID=Pacific/Auckland:20270404T023000', 'DTSTART:20270403T143000Z',
        'RECURRENCE-ID;TZID=Pacific/Auckland:20270404T023000', 'DURATION:PT3600S');
    expect(substr_count($ics, 'BEGIN:VEVENT'))->toBe(2);
});
