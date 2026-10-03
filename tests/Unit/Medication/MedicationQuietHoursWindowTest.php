<?php

namespace Tests\Unit\Medication;

use App\Services\Medication\Alerts\MedicationQuietHours;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * eMAR P11 B2 chunk 5: quiet hours are New Zealand wall-clock times — "9:00
 * pm to 7:00 am" every night, across midnight and across the daylight-saving
 * changes (last Sunday in September, first Sunday in April).
 */
class MedicationQuietHoursWindowTest extends TestCase
{
    private const NIGHT = ['from' => '21:00', 'until' => '07:00'];

    private static function nz(string $local): Carbon
    {
        return Carbon::parse($local, 'Pacific/Auckland');
    }

    private static function endsAt(array $window, string $local): ?string
    {
        return MedicationQuietHours::endsAt($window, self::nz($local)->utc())?->utc()->format('Y-m-d H:i');
    }

    public function test_an_overnight_window_runs_from_its_start_until_the_next_morning(): void
    {
        $this->assertSame('2026-10-14 18:00', self::endsAt(self::NIGHT, '2026-10-14 23:00'), '23:00 NZDT holds until 07:00 NZDT the next day.');
        $this->assertSame('2026-10-14 18:00', self::endsAt(self::NIGHT, '2026-10-15 00:30'), 'After midnight it ends at 07:00 the same day.');
        $this->assertSame('2026-10-14 18:00', self::endsAt(self::NIGHT, '2026-10-15 06:59'));
        $this->assertSame('2026-10-14 18:00', self::endsAt(self::NIGHT, '2026-10-14 21:00'), 'It starts at 21:00 exactly.');
        $this->assertNull(self::endsAt(self::NIGHT, '2026-10-15 07:00'), 'It has ended at 07:00 exactly.');
        $this->assertNull(self::endsAt(self::NIGHT, '2026-10-14 20:59'));
        $this->assertNull(self::endsAt(self::NIGHT, '2026-10-15 12:00'));
    }

    public function test_a_daytime_window_ends_the_same_day(): void
    {
        $day = ['from' => '13:00', 'until' => '15:00'];

        $this->assertSame('2026-10-14 02:00', self::endsAt($day, '2026-10-14 14:00'));
        $this->assertSame('2026-10-14 02:00', self::endsAt($day, '2026-10-14 13:00'));
        $this->assertNull(self::endsAt($day, '2026-10-14 15:00'));
        $this->assertNull(self::endsAt($day, '2026-10-14 12:59'));
        $this->assertNull(self::endsAt($day, '2026-10-14 23:00'));
    }

    public function test_the_night_daylight_saving_starts_ends_at_seven_by_the_new_clock(): void
    {
        // Sunday 27 September 2026: 2:00 am NZST becomes 3:00 am NZDT. The
        // night is an hour shorter, and still ends at 7:00 am on the clock.
        $this->assertSame('2026-09-26 18:00', self::endsAt(self::NIGHT, '2026-09-26 21:00'), '21:00 NZST (09:00 UTC) holds until 07:00 NZDT (18:00 UTC).');
        $this->assertSame('2026-09-26 18:00', self::endsAt(self::NIGHT, '2026-09-26 22:00'));
        $this->assertSame('2026-09-26 18:00', self::endsAt(self::NIGHT, '2026-09-27 01:30'), 'Before the change (NZST).');
        $this->assertSame('2026-09-26 18:00', self::endsAt(self::NIGHT, '2026-09-27 06:30'), 'After the change (NZDT).');
        $this->assertNull(self::endsAt(self::NIGHT, '2026-09-27 07:00'));
        $start = self::nz('2026-09-26 21:00');
        $this->assertSame(9, (int) $start->utc()->diffInHours(MedicationQuietHours::endsAt(self::NIGHT, $start)), 'Nine real hours, not ten.');
    }

    public function test_the_night_daylight_saving_ends_ends_at_seven_by_the_new_clock(): void
    {
        // Sunday 5 April 2026: 3:00 am NZDT becomes 2:00 am NZST. The night
        // is an hour longer, and still ends at 7:00 am on the clock.
        $this->assertSame('2026-04-04 19:00', self::endsAt(self::NIGHT, '2026-04-04 21:00'), '21:00 NZDT (08:00 UTC) holds until 07:00 NZST (19:00 UTC).');
        $this->assertSame('2026-04-04 19:00', self::endsAt(self::NIGHT, '2026-04-04 22:00'));
        $this->assertSame('2026-04-04 19:00', self::endsAt(self::NIGHT, '2026-04-05 06:59'));
        $this->assertNull(self::endsAt(self::NIGHT, '2026-04-05 07:00'));
        // Both 2:30 am readings that night are inside it.
        $this->assertTrue(MedicationQuietHours::within(self::NIGHT, Carbon::parse('2026-04-04 13:30', 'UTC')), '2:30 am NZDT.');
        $this->assertTrue(MedicationQuietHours::within(self::NIGHT, Carbon::parse('2026-04-04 14:30', 'UTC')), '2:30 am NZST.');
        $start = self::nz('2026-04-04 21:00');
        $this->assertSame(11, (int) $start->utc()->diffInHours(MedicationQuietHours::endsAt(self::NIGHT, $start)), 'Eleven real hours, not ten.');
    }

    public function test_an_end_inside_the_skipped_hour_is_when_the_clock_passes_it(): void
    {
        // 2:30 am doesn't happen on 27 September 2026: the hold ends at 3:30
        // am NZDT, the moment the clock passes 2:30.
        $this->assertSame('2026-09-26 14:30', self::endsAt(['from' => '21:00', 'until' => '02:30'], '2026-09-26 23:00'));
    }

    public function test_a_window_that_starts_and_ends_at_the_same_time_holds_nothing(): void
    {
        $this->assertNull(self::endsAt(['from' => '21:00', 'until' => '21:00'], '2026-10-14 21:00'));
        $this->assertNull(self::endsAt(['from' => '21:00', 'until' => '21:00'], '2026-10-14 03:00'));
    }
}
