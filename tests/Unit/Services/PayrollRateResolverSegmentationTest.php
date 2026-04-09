<?php

namespace Tests\Unit\Services;

use App\Models\Timesheet;
use App\Services\Operations\PayrollRateResolver;
use App\Services\Payroll\ShiftRateSegmenter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for PayrollRateResolver's mapPayType() segmentation logic.
 *
 * Verifies that time-of-day classification uses the segmenter's dominant
 * band instead of the old start-hour-only approach.
 */
class PayrollRateResolverSegmentationTest extends TestCase
{
    use RefreshDatabase;

    private PayrollRateResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new PayrollRateResolver(new ShiftRateSegmenter());
    }

    // ── Special types take precedence ───────────────────────────────────

    public function test_sleepover_takes_precedence_over_time_bands(): void
    {
        $ts = $this->makeTimesheet(sleepover: true, starts_at: '2026-04-09 22:00', ends_at: '2026-04-10 06:00');
        $this->assertEquals('sleepover', $this->resolver->mapPayType($ts));
    }

    public function test_on_call_takes_precedence_over_time_bands(): void
    {
        $ts = $this->makeTimesheet(on_call: true, starts_at: '2026-04-09 17:00', ends_at: '2026-04-10 03:00');
        $this->assertEquals('on_call', $this->resolver->mapPayType($ts));
    }

    public function test_public_holiday_takes_precedence(): void
    {
        $ts = $this->makeTimesheet(public_holiday: true, starts_at: '2026-04-09 09:00', ends_at: '2026-04-09 17:00', work_date: '2026-04-09');
        $this->assertEquals('public_holiday', $this->resolver->mapPayType($ts));
    }

    public function test_weekend_takes_precedence_over_time_bands(): void
    {
        // 2026-04-11 is a Saturday (dayOfWeek = 6)
        $ts = $this->makeTimesheet(starts_at: '2026-04-11 22:00', ends_at: '2026-04-12 06:00', work_date: '2026-04-11');
        $this->assertEquals('weekend', $this->resolver->mapPayType($ts));
    }

    // ── Time-of-day band classification ─────────────────────────────────

    public function test_day_shift_returns_standard(): void
    {
        $ts = $this->makeTimesheet(starts_at: '2026-04-09 09:00', ends_at: '2026-04-09 17:00', work_date: '2026-04-09');
        $this->assertEquals('standard', $this->resolver->mapPayType($ts));
    }

    public function test_pure_night_shift_returns_night(): void
    {
        $ts = $this->makeTimesheet(starts_at: '2026-04-09 22:00', ends_at: '2026-04-10 04:00', work_date: '2026-04-09');
        $this->assertEquals('night', $this->resolver->mapPayType($ts));
    }

    public function test_overnight_shift_returns_dominant_type_not_start_hour(): void
    {
        // 17:00–03:00: night 420m > evening 120m > standard 60m → night
        // Old logic returned 'standard' (start hour = 17). Now fixed.
        $ts = $this->makeTimesheet(starts_at: '2026-04-09 17:00', ends_at: '2026-04-10 03:00', work_date: '2026-04-09');
        $this->assertEquals('night', $this->resolver->mapPayType($ts));
    }

    public function test_evening_shift_returns_evening_when_dominant(): void
    {
        // 17:00–20:00: standard 60m, evening 120m → evening
        $ts = $this->makeTimesheet(starts_at: '2026-04-09 17:00', ends_at: '2026-04-09 20:00', work_date: '2026-04-09');
        $this->assertEquals('evening', $this->resolver->mapPayType($ts));
    }

    public function test_early_morning_shift_returns_valid_dominant(): void
    {
        // 04:00–08:00: night 120m, standard 120m → tie, either valid
        $ts = $this->makeTimesheet(starts_at: '2026-04-09 04:00', ends_at: '2026-04-09 08:00', work_date: '2026-04-09');
        $this->assertContains($this->resolver->mapPayType($ts), ['night', 'standard']);
    }

    public function test_no_times_returns_standard(): void
    {
        $ts = $this->makeTimesheet(work_date: '2026-04-09');
        $this->assertEquals('standard', $this->resolver->mapPayType($ts));
    }

    public function test_pure_evening_returns_evening(): void
    {
        $ts = $this->makeTimesheet(starts_at: '2026-04-09 18:30', ends_at: '2026-04-09 19:30', work_date: '2026-04-09');
        $this->assertEquals('evening', $this->resolver->mapPayType($ts));
    }

    // ── Precedence ordering ─────────────────────────────────────────────

    public function test_sleepover_beats_on_call(): void
    {
        $ts = $this->makeTimesheet(sleepover: true, on_call: true);
        $this->assertEquals('sleepover', $this->resolver->mapPayType($ts));
    }

    public function test_on_call_beats_public_holiday(): void
    {
        $ts = $this->makeTimesheet(on_call: true, public_holiday: true, work_date: '2026-04-09');
        $this->assertEquals('on_call', $this->resolver->mapPayType($ts));
    }

    public function test_public_holiday_beats_weekend(): void
    {
        $ts = $this->makeTimesheet(public_holiday: true, work_date: '2026-04-11'); // Saturday
        $this->assertEquals('public_holiday', $this->resolver->mapPayType($ts));
    }

    // ── resolve() output shape (requires no rate rules → uses defaults) ─

    public function test_single_band_resolve_returns_null_segments(): void
    {
        $ts = $this->makeTimesheet(starts_at: '2026-04-09 09:00', ends_at: '2026-04-09 17:00', work_date: '2026-04-09');
        $result = $this->resolver->resolve($ts);

        $this->assertEquals('standard', $result['pay_type']);
        $this->assertNull($result['segments']);
        $this->assertNull($result['dominant_type']);
        $this->assertArrayHasKey('pay_rate', $result);
        $this->assertArrayHasKey('payroll_cost', $result);
    }

    public function test_mixed_resolve_returns_segments(): void
    {
        $ts = $this->makeTimesheet(starts_at: '2026-04-09 17:00', ends_at: '2026-04-10 03:00', work_date: '2026-04-09');
        $result = $this->resolver->resolve($ts);

        $this->assertEquals('mixed', $result['pay_type']);
        $this->assertIsArray($result['segments']);
        $this->assertCount(3, $result['segments']);
        $this->assertEquals('night', $result['dominant_type']);

        // Each segment has required keys
        foreach ($result['segments'] as $seg) {
            $this->assertArrayHasKey('type', $seg);
            $this->assertArrayHasKey('minutes', $seg);
            $this->assertArrayHasKey('break_minutes', $seg);
            $this->assertArrayHasKey('payable_minutes', $seg);
            $this->assertArrayHasKey('rate', $seg);
            $this->assertArrayHasKey('cost', $seg);
        }
    }

    public function test_sleepover_resolve_returns_null_segments(): void
    {
        $ts = $this->makeTimesheet(sleepover: true, starts_at: '2026-04-09 22:00', ends_at: '2026-04-10 06:00', work_date: '2026-04-09');
        $result = $this->resolver->resolve($ts);

        $this->assertEquals('sleepover', $result['pay_type']);
        $this->assertNull($result['segments']);
    }

    public function test_breaks_distributed_proportionally(): void
    {
        $ts = $this->makeTimesheet(
            starts_at: '2026-04-09 17:00',
            ends_at: '2026-04-10 03:00',
            work_date: '2026-04-09',
            break_minutes: 60,
        );
        $result = $this->resolver->resolve($ts);

        $totalBreak = array_sum(array_column($result['segments'], 'break_minutes'));

        // Should be approximately 60 (±1 from rounding)
        $this->assertGreaterThanOrEqual(59, $totalBreak);
        $this->assertLessThanOrEqual(61, $totalBreak);
    }

    public function test_resolve_always_returns_all_keys(): void
    {
        $cases = [
            $this->makeTimesheet(starts_at: '2026-04-09 09:00', ends_at: '2026-04-09 17:00', work_date: '2026-04-09'),
            $this->makeTimesheet(starts_at: '2026-04-09 17:00', ends_at: '2026-04-10 03:00', work_date: '2026-04-09'),
            $this->makeTimesheet(sleepover: true, starts_at: '2026-04-09 22:00', ends_at: '2026-04-10 06:00', work_date: '2026-04-09'),
            $this->makeTimesheet(on_call: true, starts_at: '2026-04-09 22:00', ends_at: '2026-04-10 06:00', work_date: '2026-04-09'),
        ];

        foreach ($cases as $ts) {
            $result = $this->resolver->resolve($ts);
            $this->assertArrayHasKey('pay_type', $result);
            $this->assertArrayHasKey('pay_rate', $result);
            $this->assertArrayHasKey('payroll_cost', $result);
            $this->assertArrayHasKey('segments', $result);
            $this->assertArrayHasKey('dominant_type', $result);
        }
    }

    /**
     * Build a Timesheet model instance (not persisted) with given attributes.
     */
    private function makeTimesheet(
        bool $sleepover = false,
        bool $on_call = false,
        bool $public_holiday = false,
        int $break_minutes = 0,
        ?string $work_date = null,
        ?string $starts_at = null,
        ?string $ends_at = null,
    ): Timesheet {
        $ts = new Timesheet();
        $ts->sleepover = $sleepover;
        $ts->on_call = $on_call;
        $ts->public_holiday = $public_holiday;
        $ts->break_minutes = $break_minutes;
        $ts->work_date = $work_date ? Carbon::parse($work_date) : null;
        $ts->starts_at = $starts_at ? Carbon::parse($starts_at) : null;
        $ts->ends_at = $ends_at ? Carbon::parse($ends_at) : null;

        return $ts;
    }
}
