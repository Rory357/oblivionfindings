<?php

namespace Tests\Unit\HealthSafety;

use App\Services\HealthSafety\WorkerParticipationTrainingReviewPeriod;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Civil-date boundary regressions; independent of application/database boot. */
final class WorkerParticipationTrainingReviewPeriodTest extends TestCase
{
    public function test_baseline_is_current_position_without_inventing_a_historical_year(): void
    {
        $this->assertSame(['start' => '2026-10-13', 'end' => '2026-10-13', 'due' => '2026-10-13'],
            WorkerParticipationTrainingReviewPeriod::initial('2026-10-13'));
    }

    public function test_annual_periods_have_no_gap_or_overlap_at_leap_day_and_dst_boundaries(): void
    {
        foreach ([
            ['2024-02-29', '2024-03-01', '2025-02-28'],
            ['2025-02-28', '2025-03-01', '2026-02-28'],
            ['2026-09-27', '2026-09-28', '2027-09-27'],
            ['2026-04-05', '2026-04-06', '2027-04-05'],
            ['2026-12-31', '2027-01-01', '2027-12-31'],
        ] as [$previous, $start, $due]) {
            $this->assertSame(['start' => $start, 'end' => $due, 'due' => $due],
                WorkerParticipationTrainingReviewPeriod::next($previous, $previous));
        }
    }

    public function test_leap_anniversary_recovers_after_three_clamped_years(): void
    {
        $root = '2024-02-29';
        $previous = $root;
        foreach (['2025-02-28', '2026-02-28', '2027-02-28', '2028-02-29'] as $due) {
            $period = WorkerParticipationTrainingReviewPeriod::next($previous, $root);
            $this->assertSame($due, $period['due']);
            $this->assertSame($due, $period['end']);
            $previous = $due;
        }
        $this->assertSame('2028-03-01', WorkerParticipationTrainingReviewPeriod::next($previous, $root)['start']);
    }

    public function test_pre_root_off_series_and_invalid_dates_are_rejected(): void
    {
        foreach ([
            ['2023-02-28', '2024-02-29'],
            ['2026-03-01', '2024-02-29'],
            ['2028-02-28', '2024-02-29'],
            ['2026-02-30', '2024-02-29'],
            ['2026-02-28', '2024-02-30'],
            ['9999-10-13', '2026-10-13'],
        ] as [$previous, $root]) {
            try {
                WorkerParticipationTrainingReviewPeriod::next($previous, $root);
                $this->fail('Expected rejection of invalid series dates.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_invalid_or_instant_dates_cannot_become_review_periods(): void
    {
        foreach (['2026-02-30', '2026-2-01', '2026-10-13T00:00:00Z', '0000-01-01', ''] as $value) {
            try {
                WorkerParticipationTrainingReviewPeriod::initial($value);
                $this->fail('Expected rejection of '.$value);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
