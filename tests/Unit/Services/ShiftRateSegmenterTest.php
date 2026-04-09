<?php

namespace Tests\Unit\Services;

use App\Services\Payroll\ShiftRateSegmenter;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ShiftRateSegmenterTest extends TestCase
{
    private ShiftRateSegmenter $segmenter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->segmenter = new ShiftRateSegmenter();
    }

    // ── Core segmentation tests ─────────────────────────────────────────

    public function test_overnight_shift_17_to_03_produces_three_segments(): void
    {
        $start = CarbonImmutable::parse('2026-04-09 17:00');
        $end = CarbonImmutable::parse('2026-04-10 03:00');

        $segments = $this->segmenter->segment($start, $end);

        $this->assertCount(3, $segments);

        $byType = $this->indexByType($segments);

        // 17:00–18:00 = 60 min standard
        $this->assertEquals(60, $byType['standard']);
        // 18:00–20:00 = 120 min evening
        $this->assertEquals(120, $byType['evening']);
        // 20:00–03:00 = 420 min night
        $this->assertEquals(420, $byType['night']);
    }

    public function test_evening_to_night_shift_19_to_21_produces_two_segments(): void
    {
        $start = CarbonImmutable::parse('2026-04-09 19:00');
        $end = CarbonImmutable::parse('2026-04-09 21:00');

        $segments = $this->segmenter->segment($start, $end);

        $this->assertCount(2, $segments);

        $byType = $this->indexByType($segments);

        $this->assertEquals(60, $byType['evening']);
        $this->assertEquals(60, $byType['night']);
    }

    public function test_early_morning_shift_05_to_07_produces_two_segments(): void
    {
        $start = CarbonImmutable::parse('2026-04-09 05:00');
        $end = CarbonImmutable::parse('2026-04-09 07:00');

        $segments = $this->segmenter->segment($start, $end);

        $this->assertCount(2, $segments);

        $byType = $this->indexByType($segments);

        $this->assertEquals(60, $byType['night']);
        $this->assertEquals(60, $byType['standard']);
    }

    public function test_full_day_shift_produces_all_segments(): void
    {
        $start = CarbonImmutable::parse('2026-04-09 00:00');
        $end = CarbonImmutable::parse('2026-04-10 00:00');

        $segments = $this->segmenter->segment($start, $end);

        $this->assertCount(3, $segments);

        $byType = $this->indexByType($segments);

        // night: 00:00–06:00 (360) + 20:00–00:00 (240) = 600 min
        $this->assertEquals(600, $byType['night']);
        // standard: 06:00–18:00 = 720 min
        $this->assertEquals(720, $byType['standard']);
        // evening: 18:00–20:00 = 120 min
        $this->assertEquals(120, $byType['evening']);

        $totalMinutes = array_sum(array_column($segments, 'minutes'));
        $this->assertEquals(1440, $totalMinutes);
    }

    // ── Boundary tests ──────────────────────────────────────────────────

    public function test_exact_standard_boundary_06_to_18(): void
    {
        $start = CarbonImmutable::parse('2026-04-09 06:00');
        $end = CarbonImmutable::parse('2026-04-09 18:00');

        $segments = $this->segmenter->segment($start, $end);

        $this->assertCount(1, $segments);
        $this->assertEquals('standard', $segments[0]['type']);
        $this->assertEquals(720, $segments[0]['minutes']);
    }

    public function test_exact_evening_boundary_18_to_20(): void
    {
        $start = CarbonImmutable::parse('2026-04-09 18:00');
        $end = CarbonImmutable::parse('2026-04-09 20:00');

        $segments = $this->segmenter->segment($start, $end);

        $this->assertCount(1, $segments);
        $this->assertEquals('evening', $segments[0]['type']);
        $this->assertEquals(120, $segments[0]['minutes']);
    }

    public function test_exact_night_boundary_20_to_06(): void
    {
        $start = CarbonImmutable::parse('2026-04-09 20:00');
        $end = CarbonImmutable::parse('2026-04-10 06:00');

        $segments = $this->segmenter->segment($start, $end);

        $this->assertCount(1, $segments);
        $this->assertEquals('night', $segments[0]['type']);
        $this->assertEquals(600, $segments[0]['minutes']);
    }

    public function test_midnight_to_06_is_night_only(): void
    {
        $start = CarbonImmutable::parse('2026-04-09 00:00');
        $end = CarbonImmutable::parse('2026-04-09 06:00');

        $segments = $this->segmenter->segment($start, $end);

        $this->assertCount(1, $segments);
        $this->assertEquals('night', $segments[0]['type']);
        $this->assertEquals(360, $segments[0]['minutes']);
    }

    // ── Single-band shifts ──────────────────────────────────────────────

    public function test_standard_day_shift_is_single_segment(): void
    {
        $start = CarbonImmutable::parse('2026-04-09 09:00');
        $end = CarbonImmutable::parse('2026-04-09 17:00');

        $segments = $this->segmenter->segment($start, $end);

        $this->assertCount(1, $segments);
        $this->assertEquals('standard', $segments[0]['type']);
        $this->assertEquals(480, $segments[0]['minutes']);
    }

    public function test_pure_night_shift_22_to_04(): void
    {
        $start = CarbonImmutable::parse('2026-04-09 22:00');
        $end = CarbonImmutable::parse('2026-04-10 04:00');

        $segments = $this->segmenter->segment($start, $end);

        $this->assertCount(1, $segments);
        $this->assertEquals('night', $segments[0]['type']);
        $this->assertEquals(360, $segments[0]['minutes']);
    }

    // ── Multi-day spans ─────────────────────────────────────────────────

    public function test_shift_spanning_more_than_24_hours(): void
    {
        $start = CarbonImmutable::parse('2026-04-09 06:00');
        $end = CarbonImmutable::parse('2026-04-10 18:00');

        $segments = $this->segmenter->segment($start, $end);

        $totalMinutes = array_sum(array_column($segments, 'minutes'));
        $this->assertEquals(36 * 60, $totalMinutes);

        $byType = $this->indexByType($segments);

        $this->assertEquals(1440, $byType['standard']);
        $this->assertEquals(120, $byType['evening']);
        $this->assertEquals(600, $byType['night']);
    }

    // ── Edge cases ──────────────────────────────────────────────────────

    public function test_zero_duration_returns_empty(): void
    {
        $time = CarbonImmutable::parse('2026-04-09 10:00');
        $this->assertCount(0, $this->segmenter->segment($time, $time));
    }

    public function test_end_before_start_returns_empty(): void
    {
        $start = CarbonImmutable::parse('2026-04-09 17:00');
        $end = CarbonImmutable::parse('2026-04-09 09:00');

        $this->assertCount(0, $this->segmenter->segment($start, $end));
    }

    public function test_one_minute_shift_at_boundary(): void
    {
        $start = CarbonImmutable::parse('2026-04-09 18:00');
        $end = CarbonImmutable::parse('2026-04-09 18:01');

        $segments = $this->segmenter->segment($start, $end);

        $this->assertCount(1, $segments);
        $this->assertEquals('evening', $segments[0]['type']);
        $this->assertEquals(1, $segments[0]['minutes']);
    }

    public function test_shift_ending_exactly_at_boundary(): void
    {
        $start = CarbonImmutable::parse('2026-04-09 09:00');
        $end = CarbonImmutable::parse('2026-04-09 18:00');

        $segments = $this->segmenter->segment($start, $end);

        $this->assertCount(1, $segments);
        $this->assertEquals('standard', $segments[0]['type']);
        $this->assertEquals(540, $segments[0]['minutes']);
    }

    public function test_shift_starting_one_minute_before_boundary(): void
    {
        $start = CarbonImmutable::parse('2026-04-09 17:59');
        $end = CarbonImmutable::parse('2026-04-09 18:01');

        $segments = $this->segmenter->segment($start, $end);

        $this->assertCount(2, $segments);

        $byType = $this->indexByType($segments);
        $this->assertEquals(1, $byType['standard']);
        $this->assertEquals(1, $byType['evening']);
    }

    // ── Helper method tests ─────────────────────────────────────────────

    public function test_is_mixed_returns_true_for_multi_band(): void
    {
        $start = CarbonImmutable::parse('2026-04-09 17:00');
        $end = CarbonImmutable::parse('2026-04-10 03:00');

        $this->assertTrue($this->segmenter->isMixed($start, $end));
    }

    public function test_is_mixed_returns_false_for_single_band(): void
    {
        $start = CarbonImmutable::parse('2026-04-09 09:00');
        $end = CarbonImmutable::parse('2026-04-09 17:00');

        $this->assertFalse($this->segmenter->isMixed($start, $end));
    }

    public function test_dominant_type_returns_largest_segment(): void
    {
        $start = CarbonImmutable::parse('2026-04-09 17:00');
        $end = CarbonImmutable::parse('2026-04-10 03:00');

        $this->assertEquals('night', $this->segmenter->dominantType($start, $end));
    }

    public function test_dominant_type_returns_standard_for_day_shift(): void
    {
        $start = CarbonImmutable::parse('2026-04-09 09:00');
        $end = CarbonImmutable::parse('2026-04-09 17:00');

        $this->assertEquals('standard', $this->segmenter->dominantType($start, $end));
    }

    public function test_segments_are_sorted_by_minutes_descending(): void
    {
        $start = CarbonImmutable::parse('2026-04-09 17:00');
        $end = CarbonImmutable::parse('2026-04-10 03:00');

        $segments = $this->segmenter->segment($start, $end);

        for ($i = 1; $i < count($segments); $i++) {
            $this->assertGreaterThanOrEqual(
                $segments[$i]['minutes'],
                $segments[$i - 1]['minutes'],
                'Segments should be sorted by minutes descending'
            );
        }
    }

    public function test_total_minutes_always_equals_shift_duration(): void
    {
        $cases = [
            ['2026-04-09 05:00', '2026-04-09 23:00'],
            ['2026-04-09 19:30', '2026-04-10 06:30'],
            ['2026-04-09 00:00', '2026-04-11 00:00'],
            ['2026-04-09 17:59', '2026-04-09 18:01'],
        ];

        foreach ($cases as [$startStr, $endStr]) {
            $start = CarbonImmutable::parse($startStr);
            $end = CarbonImmutable::parse($endStr);
            $segments = $this->segmenter->segment($start, $end);

            $totalMinutes = array_sum(array_column($segments, 'minutes'));
            $expectedMinutes = $start->diffInMinutes($end);

            $this->assertEquals(
                $expectedMinutes,
                $totalMinutes,
                "Total segment minutes should equal shift duration for {$startStr} to {$endStr}"
            );
        }
    }

    private function indexByType(array $segments): array
    {
        $result = [];
        foreach ($segments as $seg) {
            $result[$seg['type']] = $seg['minutes'];
        }

        return $result;
    }
}
