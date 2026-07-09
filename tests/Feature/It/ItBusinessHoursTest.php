<?php

use App\Support\It\BusinessHours;
use Carbon\CarbonImmutable;

/*
 * §P-S1 — the working-minutes calculator behind business-hours SLA clocks.
 * Pure computation (no DB), but lives in the It suite so the loop verifies it.
 */

beforeEach(function () {
    config(['app.worker_timezone' => 'Pacific/Auckland']);

    // Anchor every case on a known Monday 00:00 NZ so weekday behaviour is
    // deterministic regardless of the calendar date the suite runs on.
    $this->monday = CarbonImmutable::parse('2026-07-06 00:00', 'Pacific/Auckland')->startOfWeek();
    $this->cal = BusinessHours::nzDefault(); // Mon–Fri 08:00–17:00
});

test('a null calendar is plain continuous time — the 24/7 default never regresses', function () {
    $start = $this->monday->setTime(17, 0); // Friday-evening-ish, but any instant

    expect(BusinessHours::addWorkingMinutes($start, 1440, null)->equalTo($start->addMinutes(1440)))->toBeTrue();
    expect(BusinessHours::workingMinutesBetween($start, $start->addMinutes(120), null))->toBe(120);
    expect(BusinessHours::hasWindows(null))->toBeFalse();
});

test('a calendar with only empty windows also falls back to continuous time', function () {
    $empty = ['business_hours' => ['mon' => [], 'tue' => [], 'wed' => [], 'thu' => [], 'fri' => [], 'sat' => [], 'sun' => []], 'holiday_dates' => []];
    $start = $this->monday->setTime(9, 0);

    expect(BusinessHours::hasWindows($empty))->toBeFalse();
    expect(BusinessHours::addWorkingMinutes($start, 90, $empty)->equalTo($start->addMinutes(90)))->toBeTrue();
});

test('adding minutes inside a single working day stays inside the window', function () {
    $start = $this->monday->setTime(8, 0);
    // 60 working minutes from Mon 08:00 => Mon 09:00.
    expect(BusinessHours::addWorkingMinutes($start, 60, $this->cal)->equalTo($this->monday->setTime(9, 0)))->toBeTrue();
});

test('time before a window opens is not counted', function () {
    $start = $this->monday->setTime(6, 0); // before 08:00 open
    // The 60 minutes start accruing at 08:00 => Mon 09:00.
    expect(BusinessHours::addWorkingMinutes($start, 60, $this->cal)->equalTo($this->monday->setTime(9, 0)))->toBeTrue();
});

test('adding minutes rolls into the next working day', function () {
    $start = $this->monday->setTime(16, 0); // 60 min left in the day
    // 120 working minutes: 16:00->17:00 (60) then Tue 08:00->09:00 (60) => Tue 09:00.
    $expected = $this->monday->addDay()->setTime(9, 0);
    expect(BusinessHours::addWorkingMinutes($start, 120, $this->cal)->equalTo($expected))->toBeTrue();
});

test('weekends are skipped when adding working minutes', function () {
    $friday = $this->monday->addDays(4)->setTime(16, 0); // Fri 16:00
    // 120 min: Fri 16:00->17:00 (60), skip Sat+Sun, Mon 08:00->09:00 (60) => next Mon 09:00.
    $expected = $this->monday->addDays(7)->setTime(9, 0);
    expect(BusinessHours::addWorkingMinutes($friday, 120, $this->cal)->equalTo($expected))->toBeTrue();
});

test('public holidays are skipped when adding working minutes', function () {
    $wednesday = $this->monday->addDays(2)->setTime(16, 0); // Wed 16:00
    $thursday = $this->monday->addDays(3);                  // make Thu a holiday
    $cal = $this->cal;
    $cal['holiday_dates'] = [$thursday->format('Y-m-d')];

    // 120 min: Wed 16:00->17:00 (60), skip Thu (holiday), Fri 08:00->09:00 (60) => Fri 09:00.
    $expected = $this->monday->addDays(4)->setTime(9, 0);
    expect(BusinessHours::addWorkingMinutes($wednesday, 120, $cal)->equalTo($expected))->toBeTrue();
});

test('working-minutes-between counts only time inside windows', function () {
    $from = $this->monday->setTime(9, 0);
    $to = $this->monday->setTime(12, 0);
    expect(BusinessHours::workingMinutesBetween($from, $to, $this->cal))->toBe(180);
});

test('working-minutes-between spans a weekend without counting it', function () {
    $from = $this->monday->addDays(4)->setTime(16, 0); // Fri 16:00
    $to = $this->monday->addDays(7)->setTime(9, 0);     // next Mon 09:00
    // Fri 16:00->17:00 (60) + Mon 08:00->09:00 (60) = 120; Sat/Sun contribute nothing.
    expect(BusinessHours::workingMinutesBetween($from, $to, $this->cal))->toBe(120);
});

test('the NZ default calendar works weekdays and rests weekends', function () {
    $cal = BusinessHours::nzDefault();
    expect(BusinessHours::hasWindows($cal))->toBeTrue();
    expect($cal['business_hours']['sat'])->toBe([]);
    expect($cal['business_hours']['mon'])->toBe([['08:00', '17:00']]);
});
