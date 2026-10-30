<?php

namespace Tests\Unit\Medication\DoseSlots;

use App\Services\Medication\DoseSlots\DoseOrderTimeline;
use App\Services\Medication\DoseSlots\DoseOrderVersion;
use App\Services\Medication\DoseSlots\DoseSlot;
use App\Services\Medication\DoseSlots\DoseSlotRules;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The dose-slot rule (P01 foundation C1): order version × dose time × New
 * Zealand calendar day. All instants are asserted in UTC.
 */
class DoseSlotRulesTest extends TestCase
{
    private DoseSlotRules $rules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rules = new DoseSlotRules('Pacific/Auckland');
    }

    // ── Ordinary days ───────────────────────────────────────────────────

    public function test_each_dose_time_is_due_at_its_new_zealand_wall_time_on_the_nz_day(): void
    {
        $timeline = $this->timeline([$this->version(['08:00', '20:00'])]);

        // 15 June is NZ standard time (UTC+12).
        $this->assertSame(
            ['2026-06-14T20:00:00Z', '2026-06-15T08:00:00Z'],
            $this->dueTimes($this->rules->slotsOn($timeline, '2026-06-15')),
        );
        // 5 October is NZ daylight time (UTC+13).
        $this->assertSame(
            ['2026-10-04T19:00:00Z', '2026-10-05T07:00:00Z'],
            $this->dueTimes($this->rules->slotsOn($timeline, '2026-10-05')),
        );
    }

    public function test_doses_at_the_edges_of_the_nz_day_belong_to_that_day(): void
    {
        $slots = $this->rules->slotsOn($this->timeline([$this->version(['00:00', '23:59'])]), '2026-06-15');

        $this->assertSame(['2026-06-14T12:00:00Z', '2026-06-15T11:59:00Z'], $this->dueTimes($slots));
        $this->assertSame(['2026-06-15', '2026-06-15'], array_map(fn (DoseSlot $s) => $s->nzDate, $slots));
    }

    public function test_slot_identity_is_the_order_the_nz_day_and_the_ordered_time(): void
    {
        $slot = $this->rules->slotsOn($this->timeline([$this->version(['08:00'])], orderId: 42), '2026-06-15')[0];

        $this->assertSame('42:2026-06-15:08:00', $slot->key());
        $this->assertSame('v1', $slot->versionKey);
        $this->assertNull($slot->dstAdjustment);
        $this->assertFalse($slot->orderChangePending);
        $this->assertFalse($slot->selfManaged);
        $this->assertFalse($slot->lastDay);
    }

    public function test_slots_between_covers_every_nz_day_inclusive_across_a_month_end(): void
    {
        $slots = $this->rules->slotsBetween($this->timeline([$this->version(['08:00'])]), '2026-06-29', '2026-07-02');

        $this->assertSame(
            ['2026-06-29', '2026-06-30', '2026-07-01', '2026-07-02'],
            array_map(fn (DoseSlot $s) => $s->nzDate, $slots),
        );
    }

    public function test_dose_times_are_deduplicated_and_ordered(): void
    {
        $version = $this->version(['20:00', '08:00', '08:00']);

        $this->assertSame(['08:00', '20:00'], $version->doseTimes);
    }

    // ── Daylight saving: no dose is ever dropped ────────────────────────

    public function test_spring_forward_day_moves_skipped_times_to_three_am_and_keeps_every_dose(): void
    {
        // Sunday 27 September 2026: 02:00 NZST becomes 03:00 NZDT (14:00 UTC on the 26th).
        $slots = $this->rules->slotsOn(
            $this->timeline([$this->version(['01:30', '02:00', '02:30', '03:00', '08:00'])]),
            '2026-09-27',
        );

        $this->assertCount(5, $slots);
        $byTime = $this->byDoseTime($slots);
        $this->assertSame('2026-09-26T13:30:00Z', $this->iso($byTime['01:30']->dueAt));
        $this->assertNull($byTime['01:30']->dstAdjustment);
        foreach (['02:00', '02:30'] as $skipped) {
            $this->assertSame('2026-09-26T14:00:00Z', $this->iso($byTime[$skipped]->dueAt), $skipped);
            $this->assertSame(DoseSlot::DST_GAP, $byTime[$skipped]->dstAdjustment, $skipped);
        }
        $this->assertSame('2026-09-26T14:00:00Z', $this->iso($byTime['03:00']->dueAt));
        $this->assertNull($byTime['03:00']->dstAdjustment);
        $this->assertSame('2026-09-26T19:00:00Z', $this->iso($byTime['08:00']->dueAt));
        // Three doses share an instant but remain three obligations.
        $this->assertCount(5, array_unique(array_map(fn (DoseSlot $s) => $s->key(), $slots)));
    }

    public function test_fall_back_day_gives_a_repeated_time_once_at_its_first_occurrence(): void
    {
        // Sunday 5 April 2026: 03:00 NZDT becomes 02:00 NZST (14:00 UTC on the 4th).
        $slots = $this->rules->slotsOn(
            $this->timeline([$this->version(['01:30', '02:00', '02:30', '03:00', '08:00'])]),
            '2026-04-05',
        );

        $this->assertCount(5, $slots);
        $byTime = $this->byDoseTime($slots);
        $this->assertSame('2026-04-04T12:30:00Z', $this->iso($byTime['01:30']->dueAt));
        $this->assertNull($byTime['01:30']->dstAdjustment);
        $this->assertSame('2026-04-04T13:00:00Z', $this->iso($byTime['02:00']->dueAt));
        $this->assertSame(DoseSlot::DST_REPEAT, $byTime['02:00']->dstAdjustment);
        $this->assertSame('2026-04-04T13:30:00Z', $this->iso($byTime['02:30']->dueAt));
        $this->assertSame(DoseSlot::DST_REPEAT, $byTime['02:30']->dstAdjustment);
        $this->assertSame('2026-04-04T15:00:00Z', $this->iso($byTime['03:00']->dueAt));
        $this->assertNull($byTime['03:00']->dstAdjustment);
        $this->assertSame('2026-04-04T20:00:00Z', $this->iso($byTime['08:00']->dueAt));
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function dstWeeks(): array
    {
        return [
            'week of the spring-forward day' => ['2026-09-24', 4 * 7],
            'week of the fall-back day' => ['2026-04-02', 4 * 7],
            'the next spring-forward day' => ['2027-09-25', 4 * 3],
        ];
    }

    #[DataProvider('dstWeeks')]
    public function test_no_dose_is_lost_across_a_daylight_saving_change(string $from, int $expected): void
    {
        $to = CarbonImmutable::parse($from, 'UTC')->addDays(intdiv($expected, 4) - 1)->toDateString();
        $slots = $this->rules->slotsBetween($this->timeline([$this->version(['02:30', '08:00', '14:00', '21:00'])]), $from, $to);

        $this->assertCount($expected, $slots);
        $this->assertCount($expected, array_unique(array_map(fn (DoseSlot $s) => $s->key(), $slots)));
    }

    // ── Start and last day ──────────────────────────────────────────────

    public function test_the_end_date_is_the_last_day_its_doses_are_owed(): void
    {
        $timeline = $this->timeline([$this->version(['08:00', '20:00'], start: '2026-06-01', end: '2026-06-08')]);

        $lastDay = $this->rules->slotsOn($timeline, '2026-06-08');
        $this->assertCount(2, $lastDay);
        $this->assertTrue($lastDay[0]->lastDay);
        $this->assertTrue($lastDay[1]->lastDay);
        $this->assertFalse($this->rules->slotsOn($timeline, '2026-06-07')[0]->lastDay);
        $this->assertSame([], $this->rules->slotsOn($timeline, '2026-06-09'));
    }

    public function test_nothing_is_owed_before_the_start_date(): void
    {
        $timeline = $this->timeline([$this->version(['08:00'], start: '2026-06-10')]);

        $this->assertSame([], $this->rules->slotsOn($timeline, '2026-06-09'));
        $this->assertCount(1, $this->rules->slotsOn($timeline, '2026-06-10'));
    }

    public function test_the_last_day_is_an_nz_day_even_when_utc_has_moved_on(): void
    {
        // 23:30 on the last day is 11:30 UTC the same day in winter but still owed;
        // 00:30 the next NZ day is not, though it is still the last day in UTC.
        $timeline = $this->timeline([$this->version(['00:30', '23:30'], start: '2026-06-01', end: '2026-06-08')]);

        $this->assertSame(['2026-06-07T12:30:00Z', '2026-06-08T11:30:00Z'], $this->dueTimes($this->rules->slotsOn($timeline, '2026-06-08')));
        $this->assertSame([], $this->rules->slotsOn($timeline, '2026-06-09'));
    }

    // ── PRN and self-managed ────────────────────────────────────────────

    public function test_prn_orders_owe_no_scheduled_doses(): void
    {
        $timeline = $this->timeline([$this->version(['08:00'], prn: true)]);

        $this->assertSame([], $this->rules->slotsOn($timeline, '2026-06-15'));
    }

    public function test_self_managed_slots_are_marked(): void
    {
        $slots = $this->rules->slotsOn($this->timeline([$this->version(['08:00'], selfManaged: true)]), '2026-06-15');

        $this->assertCount(1, $slots);
        $this->assertTrue($slots[0]->selfManaged);
    }

    // ── Verification and changes ────────────────────────────────────────

    public function test_an_order_never_verified_owes_nothing(): void
    {
        $timeline = $this->timeline([$this->version(['08:00'], verifiedAt: null)]);

        $this->assertSame([], $this->rules->slotsBetween($timeline, '2026-06-01', '2026-06-30'));
    }

    public function test_a_new_order_is_owed_from_the_first_dose_due_after_verification(): void
    {
        // Verified at 10:00 NZ on its start day: the 08:00 dose was not yet an obligation.
        $timeline = $this->timeline([$this->version(['08:00', '20:00'], start: '2026-06-15', verifiedAt: $this->nz('2026-06-15 10:00'))]);

        $this->assertSame(['20:00'], $this->doseTimesOf($this->rules->slotsOn($timeline, '2026-06-15')));
        $this->assertSame(['08:00', '20:00'], $this->doseTimesOf($this->rules->slotsOn($timeline, '2026-06-16')));
    }

    public function test_an_unverified_change_keeps_the_old_version_and_flags_its_doses(): void
    {
        $old = $this->version(['08:00', '20:00'], key: 'v1');
        $change = $this->version(['09:00'], key: 'v2', changedAt: $this->nz('2026-06-15 12:00'), verifiedAt: null);
        $timeline = $this->timeline([$old, $change]);

        $day = $this->rules->slotsOn($timeline, '2026-06-15');
        $this->assertSame(['08:00', '20:00'], $this->doseTimesOf($day));
        $this->assertSame(['v1', 'v1'], array_map(fn (DoseSlot $s) => $s->versionKey, $day));
        $this->assertFalse($day[0]->orderChangePending, 'The 08:00 dose was due before the change was made.');
        $this->assertTrue($day[1]->orderChangePending, 'The 20:00 dose follows an unverified change.');

        $nextDay = $this->rules->slotsOn($timeline, '2026-06-16');
        $this->assertSame(['08:00', '20:00'], $this->doseTimesOf($nextDay));
        $this->assertTrue($nextDay[0]->orderChangePending);
    }

    public function test_once_verified_a_change_owns_the_doses_due_from_then_on(): void
    {
        $old = $this->version(['08:00', '20:00'], key: 'v1');
        $change = $this->version(['09:00', '21:00'], key: 'v2', changedAt: $this->nz('2026-06-15 12:00'), verifiedAt: $this->nz('2026-06-15 18:00'));
        $timeline = $this->timeline([$old, $change]);

        // 08:00 under v1 (before the change); 20:00 and 21:00 fall after 18:00, so v2's.
        $day = $this->rules->slotsOn($timeline, '2026-06-15');
        $this->assertSame(['08:00', '21:00'], $this->doseTimesOf($day));
        $this->assertSame(['v1', 'v2'], array_map(fn (DoseSlot $s) => $s->versionKey, $day));
        $this->assertFalse($day[1]->orderChangePending);

        $this->assertSame(['09:00', '21:00'], $this->doseTimesOf($this->rules->slotsOn($timeline, '2026-06-16')));
        // The day before the change is untouched: history isn't rewritten.
        $this->assertSame(['08:00', '20:00'], $this->doseTimesOf($this->rules->slotsOn($timeline, '2026-06-14')));
    }

    public function test_a_dose_between_the_change_and_its_verification_stays_with_the_old_version_and_flagged(): void
    {
        $old = $this->version(['08:00', '14:00', '20:00'], key: 'v1');
        $change = $this->version(['08:00', '20:00'], key: 'v2', changedAt: $this->nz('2026-06-15 12:00'), verifiedAt: $this->nz('2026-06-15 16:00'));
        $day = $this->rules->slotsOn($this->timeline([$old, $change]), '2026-06-15');

        $this->assertSame(['08:00', '14:00', '20:00'], $this->doseTimesOf($day));
        $this->assertSame(['v1', 'v1', 'v2'], array_map(fn (DoseSlot $s) => $s->versionKey, $day));
        $this->assertSame([false, true, false], array_map(fn (DoseSlot $s) => $s->orderChangePending, $day));
    }

    public function test_a_rejected_change_never_takes_effect_and_stops_flagging(): void
    {
        $old = $this->version(['08:00', '20:00'], key: 'v1');
        $change = $this->version(['09:00'], key: 'v2', changedAt: $this->nz('2026-06-15 07:00'), verifiedAt: null, rejectedAt: $this->nz('2026-06-15 12:00'));
        $day = $this->rules->slotsOn($this->timeline([$old, $change]), '2026-06-15');

        $this->assertSame(['08:00', '20:00'], $this->doseTimesOf($day));
        $this->assertSame([true, false], array_map(fn (DoseSlot $s) => $s->orderChangePending, $day));
    }

    public function test_a_change_to_as_needed_ends_the_scheduled_doses_once_verified(): void
    {
        $old = $this->version(['08:00', '20:00'], key: 'v1');
        $change = $this->version([], key: 'v2', changedAt: $this->nz('2026-06-15 12:00'), verifiedAt: $this->nz('2026-06-15 12:30'), prn: true);
        $timeline = $this->timeline([$old, $change]);

        $this->assertSame(['08:00'], $this->doseTimesOf($this->rules->slotsOn($timeline, '2026-06-15')));
        $this->assertSame([], $this->rules->slotsOn($timeline, '2026-06-16'));
    }

    // ── Paused and ceased ───────────────────────────────────────────────

    public function test_doses_due_while_paused_are_not_owed_and_resume_afterwards(): void
    {
        $timeline = $this->timeline(
            [$this->version(['08:00', '14:00', '20:00'])],
            pauses: [[$this->nz('2026-06-15 09:00'), $this->nz('2026-06-16 13:00')]],
        );

        $this->assertSame(['08:00'], $this->doseTimesOf($this->rules->slotsOn($timeline, '2026-06-15')));
        $this->assertSame(['14:00', '20:00'], $this->doseTimesOf($this->rules->slotsOn($timeline, '2026-06-16')));
    }

    public function test_an_ongoing_pause_owes_nothing_from_its_start(): void
    {
        $timeline = $this->timeline([$this->version(['08:00', '20:00'])], pauses: [[$this->nz('2026-06-15 12:00'), null]]);

        $this->assertSame(['08:00'], $this->doseTimesOf($this->rules->slotsOn($timeline, '2026-06-15')));
        $this->assertSame([], $this->rules->slotsBetween($timeline, '2026-06-16', '2026-06-30'));
    }

    public function test_a_dose_due_at_the_moment_of_resuming_is_owed(): void
    {
        $timeline = $this->timeline([$this->version(['08:00'])], pauses: [[$this->nz('2026-06-14 09:00'), $this->nz('2026-06-15 08:00')]]);

        $this->assertSame(['08:00'], $this->doseTimesOf($this->rules->slotsOn($timeline, '2026-06-15')));
    }

    public function test_a_ceased_order_keeps_the_doses_due_before_it_stopped(): void
    {
        $timeline = $this->timeline([$this->version(['08:00', '14:00', '20:00'])], ceasedAt: $this->nz('2026-06-15 14:00'));

        // Ceased exactly at 14:00: that dose is no longer owed.
        $this->assertSame(['08:00'], $this->doseTimesOf($this->rules->slotsOn($timeline, '2026-06-15')));
        $this->assertSame(['08:00', '14:00', '20:00'], $this->doseTimesOf($this->rules->slotsOn($timeline, '2026-06-14')));
        $this->assertSame([], $this->rules->slotsOn($timeline, '2026-06-16'));
    }

    public function test_nothing_due_before_the_order_was_entered_is_owed(): void
    {
        $timeline = new DoseOrderTimeline(7, [$this->version(['08:00', '14:00', '20:00'])], [], null, $this->nz('2026-06-15 10:00'));

        // Entered at 10:00 with an earlier start date: 08:00 today was never owed.
        $this->assertSame(['14:00', '20:00'], $this->doseTimesOf($this->rules->slotsOn($timeline, '2026-06-15')));
        $this->assertSame([], $this->rules->slotsOn($timeline, '2026-06-14'));
        $this->assertSame(['08:00', '14:00', '20:00'], $this->doseTimesOf($this->rules->slotsOn($timeline, '2026-06-16')));

        // Entered exactly at a dose time: that dose is owed.
        $atTwo = new DoseOrderTimeline(7, [$this->version(['14:00'])], [], null, $this->nz('2026-06-15 14:00'));
        $this->assertSame(['14:00'], $this->doseTimesOf($this->rules->slotsOn($atTwo, '2026-06-15')));
    }

    // ── Guards ──────────────────────────────────────────────────────────

    public function test_inputs_are_validated(): void
    {
        foreach ([
            fn () => $this->version(['8:00']),
            fn () => $this->version(['08:00'], start: '2026-02-30'),
            fn () => $this->version(['08:00'], verifiedAt: $this->nz('2026-06-01 09:00'), rejectedAt: $this->nz('2026-06-01 10:00')),
            fn () => $this->rules->slotsOn($this->timeline([$this->version(['08:00'])]), '15/06/2026'),
            fn () => $this->timeline([
                $this->version(['08:00'], key: 'v2', changedAt: $this->nz('2026-06-02 09:00')),
                $this->version(['09:00'], key: 'v1', changedAt: $this->nz('2026-06-01 09:00')),
            ]),
        ] as $i => $invalid) {
            try {
                $invalid();
                $this->fail("Case {$i} should be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * @param  list<string>  $doseTimes
     */
    private function version(
        array $doseTimes,
        string $key = 'v1',
        ?string $start = '2026-01-01',
        ?string $end = null,
        ?CarbonImmutable $changedAt = null,
        ?CarbonImmutable $verifiedAt = new CarbonImmutable('2025-12-31 00:00:00', 'UTC'),
        bool $prn = false,
        bool $selfManaged = false,
        ?CarbonImmutable $rejectedAt = null,
    ): DoseOrderVersion {
        return new DoseOrderVersion(
            key: $key,
            doseTimes: $doseTimes,
            startDate: $start,
            endDate: $end,
            changedAt: $changedAt ?? new CarbonImmutable('2025-12-31 00:00:00', 'UTC'),
            verifiedAt: $verifiedAt,
            isPrn: $prn,
            selfManaged: $selfManaged,
            rejectedAt: $rejectedAt,
        );
    }

    /**
     * @param  list<DoseOrderVersion>  $versions
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable|null}>  $pauses
     */
    private function timeline(array $versions, int $orderId = 7, array $pauses = [], ?CarbonImmutable $ceasedAt = null): DoseOrderTimeline
    {
        return new DoseOrderTimeline($orderId, $versions, $pauses, $ceasedAt);
    }

    private function nz(string $local): CarbonImmutable
    {
        return CarbonImmutable::parse($local, 'Pacific/Auckland')->utc();
    }

    private function iso(CarbonImmutable $instant): string
    {
        return $instant->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @param  list<DoseSlot>  $slots
     * @return list<string>
     */
    private function dueTimes(array $slots): array
    {
        return array_map(fn (DoseSlot $s) => $this->iso($s->dueAt), $slots);
    }

    /**
     * @param  list<DoseSlot>  $slots
     * @return list<string>
     */
    private function doseTimesOf(array $slots): array
    {
        return array_map(fn (DoseSlot $s) => $s->doseTime, $slots);
    }

    /**
     * @param  list<DoseSlot>  $slots
     * @return array<string, DoseSlot>
     */
    private function byDoseTime(array $slots): array
    {
        $byTime = [];
        foreach ($slots as $slot) {
            $byTime[$slot->doseTime] = $slot;
        }

        return $byTime;
    }
}
