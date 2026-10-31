<?php

namespace Tests\Feature\Hr;

use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\Hr\Services\TimeTrackingService;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WeeklyTimeSummaryTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland']);
        Carbon::setTestNow(Carbon::parse('2026-10-21 14:00', 'Pacific/Auckland')->utc());
        $this->worker = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_cast_dates_produce_nonzero_rounded_daily_values_from_the_same_rows_as_the_total(): void
    {
        $entry = $this->entry('2026-10-19', 6.25);
        $this->entry('2026-10-19', 0.75);
        $this->entry('2026-10-20', 1.10);
        $this->entry('2026-10-20', 2.20);
        $this->assertInstanceOf(CarbonInterface::class, $entry->fresh()->entry_date);
        $summary = $this->summary();
        $this->assertSame($this->days('2026-10-19'), array_keys($summary['daily_hours']));
        $this->assertSame(7.0, $summary['daily_hours']['2026-10-19']);
        $this->assertSame(3.3, $summary['daily_hours']['2026-10-20']);
        $this->assertSame(0.0, $summary['daily_hours']['2026-10-21']);
        $this->assertSame(10.3, $summary['total_hours']);
        $this->assertSame(10.3, round(array_sum($summary['daily_hours']), 2));
        $this->assertSame(4, $summary['total_entries']);
    }

    #[DataProvider('localWeekEdges')]
    public function test_default_week_uses_the_worker_local_monday_and_sunday(string $now): void
    {
        Carbon::setTestNow(Carbon::parse($now, 'Pacific/Auckland')->utc());
        $this->entry('2026-10-18', 9);
        $this->entry('2026-10-19', 2);
        $this->entry('2026-10-25', 3);
        $this->entry('2026-10-26', 9);
        $summary = $this->summary();
        $this->assertSame('2026-10-19', $summary['week_start']);
        $this->assertSame('2026-10-25', $summary['week_end']);
        $this->assertSame(2.0, $summary['daily_hours']['2026-10-19']);
        $this->assertSame(3.0, $summary['daily_hours']['2026-10-25']);
        $this->assertSame(5.0, $summary['total_hours']);
        $this->assertSame(2, $summary['total_entries']);
    }

    public static function localWeekEdges(): array
    {
        return [['2026-10-19 00:30'], ['2026-10-25 23:30']];
    }

    #[DataProvider('explicitWeeks')]
    public function test_optional_anchor_is_interpreted_or_converted_into_the_same_worker_timezone(string $anchor, string $monday, string $sunday): void
    {
        $this->entry($monday, 1.25);
        $summary = $this->summary($anchor);
        $this->assertSame($monday, $summary['week_start']);
        $this->assertSame($sunday, $summary['week_end']);
        $this->assertSame($this->days($monday), array_keys($summary['daily_hours']));
        $this->assertSame(1.25, $summary['daily_hours'][$monday]);
        $this->assertSame(1.25, $summary['total_hours']);
    }

    public static function explicitWeeks(): array
    {
        return [
            'local Sunday date' => ['2026-10-25', '2026-10-19', '2026-10-25'],
            'UTC Sunday is local Monday' => ['2026-10-18T12:30:00Z', '2026-10-19', '2026-10-25'],
            'explicit offset converts to following week' => ['2026-10-25T23:30:00-04:00', '2026-10-26', '2026-11-01'],
        ];
    }

    public function test_overnight_hours_stay_on_the_stored_entry_date_without_splitting_or_reattribution(): void
    {
        $in = Carbon::parse('2026-10-19 22:00', 'Pacific/Auckland')->utc();
        $this->entry('2026-10-19', 8, ['clock_in' => $in, 'clock_out' => $in->copy()->addHours(8)]);
        $beforeWeek = Carbon::parse('2026-10-18 22:00', 'Pacific/Auckland')->utc();
        $this->entry('2026-10-18', 8, ['clock_in' => $beforeWeek, 'clock_out' => $beforeWeek->copy()->addHours(8)]);
        $summary = $this->summary();
        $this->assertSame(8.0, $summary['daily_hours']['2026-10-19']);
        $this->assertSame(0.0, $summary['daily_hours']['2026-10-20']);
        $this->assertSame(8.0, $summary['total_hours']);
        $this->assertSame(1, $summary['total_entries']);
    }

    public function test_existing_closed_marker_status_owner_and_soft_delete_semantics_are_preserved(): void
    {
        // Summary status semantics remain unchanged; these are recorded ledger
        // totals, without a new approval/payroll or payable-hours claim.
        foreach (['active', 'submitted', 'approved', 'rejected', 'draft', 'voided'] as $status) {
            $this->entry('2026-10-19', 1, ['status' => $status, 'entry_type' => 'manual']);
        }
        $this->entry('2026-10-19', null);
        $this->entry('2026-10-19', 99, ['clock_out' => null, 'status' => 'submitted']);
        $other = User::factory()->create();
        $this->entry('2026-10-19', 99, ['user_id' => $other->id]);
        $this->entry('2026-10-19', 99)->delete();
        $summary = $this->summary();
        $this->assertSame(6.0, $summary['daily_hours']['2026-10-19']);
        $this->assertSame(6.0, $summary['total_hours']);
        $this->assertSame(7, $summary['total_entries']);
    }

    public function test_timezone_fallback_and_explicit_week_edges_do_not_depend_on_locale(): void
    {
        config(['app.worker_timezone' => null, 'app.timezone' => 'UTC']);
        Carbon::setTestNow(Carbon::parse('2026-10-19 00:30', 'UTC'));
        $locale = Carbon::getLocale();
        Carbon::setLocale('ar');
        try {
            $summary = $this->summary();
            $this->assertSame('2026-10-19', $summary['week_start']);
            $this->assertSame('2026-10-25', $summary['week_end']);
            $this->assertSame(array_fill_keys($this->days('2026-10-19'), 0.0), $summary['daily_hours']);
            $this->assertSame(0.0, $summary['total_hours']);
            $this->assertSame(0, $summary['total_entries']);
        } finally {
            Carbon::setLocale($locale);
        }
    }

    private function summary(?string $anchor = null): array
    {
        return app(TimeTrackingService::class)->getWeeklySummary($this->worker->id, $anchor);
    }

    private function entry(string $date, ?float $hours, array $extra = []): HrTimeEntry
    {
        $in = Carbon::parse($date.' 09:00', 'Pacific/Auckland')->utc();
        return HrTimeEntry::create(['user_id' => $this->worker->id, 'entry_date' => $date,
            'clock_in' => $in, 'clock_out' => $in->copy()->addHours(8), 'total_hours' => $hours,
            'break_minutes' => 0, 'entry_type' => 'manual', 'status' => 'submitted', 'created_by' => $this->worker->id, ...$extra]);
    }

    private function days(string $monday): array
    {
        $start = Carbon::parse($monday, 'UTC');
        return array_map(fn ($offset) => $start->copy()->addDays($offset)->toDateString(), range(0, 6));
    }
}
