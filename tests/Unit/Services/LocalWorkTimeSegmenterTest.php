<?php

namespace Tests\Unit\Services;

use App\Services\Eligibility\LocalWorkTimeSegmenter;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LocalWorkTimeSegmenterTest extends TestCase
{
    #[DataProvider('workerCalendarBoundaries')]
    public function test_intervals_are_segmented_by_worker_local_day_and_week(
        string $startsAt,
        string $endsAt,
        string $inputTimezone,
        array $expectedDays,
        array $expectedWeeks,
    ): void {
        config(['app.worker_timezone' => 'Pacific/Auckland']);

        $segmenter = app(LocalWorkTimeSegmenter::class);
        $days = $segmenter->byDay(
            CarbonImmutable::parse($startsAt, $inputTimezone),
            CarbonImmutable::parse($endsAt, $inputTimezone),
        );
        $weeks = $segmenter->byWeek(
            CarbonImmutable::parse($startsAt, $inputTimezone),
            CarbonImmutable::parse($endsAt, $inputTimezone),
        );

        $this->assertSegments($expectedDays, $days);
        $this->assertSegments($expectedWeeks, $weeks);
    }

    public static function workerCalendarBoundaries(): array
    {
        return [
            'NZ midnight differs from UTC midnight' => [
                '2026-06-01 11:30:00',
                '2026-06-01 12:30:00',
                'UTC',
                ['2026-06-01' => 0.5, '2026-06-02' => 0.5],
                ['2026-06-01' => 1.0],
            ],
            'Sunday overnight crosses the local ISO week' => [
                '2026-06-07 23:00:00',
                '2026-06-08 03:00:00',
                'Pacific/Auckland',
                ['2026-06-07' => 1.0, '2026-06-08' => 3.0],
                ['2026-06-01' => 1.0, '2026-06-08' => 3.0],
            ],
            'spring DST day has twenty three elapsed hours' => [
                '2026-09-27 00:00:00',
                '2026-09-27 04:00:00',
                'Pacific/Auckland',
                ['2026-09-27' => 3.0],
                ['2026-09-21' => 3.0],
            ],
            'autumn DST day has twenty five elapsed hours' => [
                '2026-04-05 00:00:00',
                '2026-04-05 04:00:00',
                'Pacific/Auckland',
                ['2026-04-05' => 5.0],
                ['2026-03-30' => 5.0],
            ],
        ];
    }

    private function assertSegments(array $expected, array $actual): void
    {
        $this->assertSame(array_keys($expected), array_keys($actual));
        foreach ($expected as $key => $hours) {
            $this->assertEqualsWithDelta($hours, $actual[$key], 0.000001, $key);
        }
    }
}
