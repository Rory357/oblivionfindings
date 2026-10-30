<?php

namespace Tests\Unit\FleetAssets;

use App\Http\Controllers\FleetAssets\ReportController;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class FleetReportPeriodTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_index_periods_are_canonical_named_tokens(): void
    {
        $normalise = new ReflectionMethod(ReportController::class, 'normaliseReportPeriod');
        $controller = $this->controller();

        foreach (['7d', '30d', '90d', '1y'] as $period) {
            $this->assertSame($period, $normalise->invoke($controller, $period, false));
        }

        foreach ([null, '', 'bogus', 30, '30', 0, '-1', '1.5', '1e2', [], '999999999999999999999'] as $period) {
            $this->assertSame('30d', $normalise->invoke($controller, $period, false));
        }
    }

    public function test_export_only_accepts_strict_legacy_day_counts_between_one_and_365(): void
    {
        $normalise = new ReflectionMethod(ReportController::class, 'normaliseReportPeriod');
        $controller = $this->controller();

        $this->assertSame(1, $normalise->invoke($controller, '1', true));
        $this->assertSame(365, $normalise->invoke($controller, '365', true));

        foreach ([null, '', 'bogus', '0', '-1', '1.5', '1e2', '366', [], '999999999999999999999'] as $period) {
            $this->assertSame('30d', $normalise->invoke($controller, $period, true));
        }
    }

    public function test_normalised_periods_resolve_to_the_expected_start_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-11 12:00:00'));

        $startDate = new ReflectionMethod(ReportController::class, 'reportStartDate');
        $controller = $this->controller();

        $this->assertSame('2026-07-04', $startDate->invoke($controller, '7d')->toDateString());
        $this->assertSame('2025-07-11', $startDate->invoke($controller, '1y')->toDateString());
        $this->assertSame('2025-07-11', $startDate->invoke($controller, 365)->toDateString());
    }

    /** The period helpers are pure, so the Site-access services the controller injects are not needed. */
    private function controller(): ReportController
    {
        return (new ReflectionClass(ReportController::class))->newInstanceWithoutConstructor();
    }
}
