<?php

use App\Models\Timesheet;
use App\Services\Operations\PayrollExportService;
use App\Services\Operations\PayrollRateResolver;
use Carbon\Carbon;
use Tests\TestCase;

uses(TestCase::class);

class FixedPayrollRateResolver extends PayrollRateResolver
{
    public function resolve(Timesheet $timesheet): array
    {
        return [
            'pay_type' => 'standard',
            'pay_rate' => 32.50,
            'payroll_cost' => 0,
        ];
    }
}

class TestablePayrollExportService extends PayrollExportService
{
    public function exposeSegmentsForPeriod(Timesheet $timesheet, Carbon $periodStartAt, Carbon $periodEndAt): array
    {
        return $this->segmentsForPeriod($timesheet, $periodStartAt, $periodEndAt);
    }

    public function exposeBuildRow(Timesheet $timesheet, array $segment): array
    {
        return $this->buildRow($timesheet, $segment);
    }
}

it('splits overnight timesheets across payroll boundaries without duplicating time', function () {
    $service = new TestablePayrollExportService(new FixedPayrollRateResolver());

    $timesheet = new Timesheet([
        'id' => 45,
        'user_id' => 12,
        'starts_at' => Carbon::parse('2026-04-01 22:00:00'),
        'ends_at' => Carbon::parse('2026-04-02 06:00:00'),
        'break_minutes' => 60,
        'status' => 'approved',
        'client_name_snapshot' => 'Casey Jones',
        'staff_name_snapshot' => 'Morgan Smith',
        'shift_site_name_snapshot' => 'Kauri House',
        'shift_location_snapshot' => 'Kauri House',
        'shift_type_snapshot' => 'overnight',
        'service_context_name_snapshot' => 'Supported living',
    ]);

    $firstPeriodSegments = $service->exposeSegmentsForPeriod(
        $timesheet,
        Carbon::parse('2026-04-01 00:00:00'),
        Carbon::parse('2026-04-02 00:00:00'),
    );

    expect($firstPeriodSegments)->toHaveCount(1)
        ->and($firstPeriodSegments[0]['segment_minutes'])->toEqual(120)
        ->and($firstPeriodSegments[0]['allocated_break_minutes'])->toEqual(15);

    $timesheet->payroll_segments_exported = [[
        'segment_start_at' => $firstPeriodSegments[0]['segment_start_at'],
        'segment_end_at' => $firstPeriodSegments[0]['segment_end_at'],
        'segment_minutes' => $firstPeriodSegments[0]['segment_minutes'],
        'allocated_break_minutes' => $firstPeriodSegments[0]['allocated_break_minutes'],
    ]];

    $secondPeriodSegments = $service->exposeSegmentsForPeriod(
        $timesheet,
        Carbon::parse('2026-04-02 00:00:00'),
        Carbon::parse('2026-04-03 00:00:00'),
    );

    expect($secondPeriodSegments)->toHaveCount(1)
        ->and($secondPeriodSegments[0]['segment_minutes'])->toEqual(360)
        ->and($secondPeriodSegments[0]['allocated_break_minutes'])->toEqual(45);
});

it('does not re-export a payroll segment that has already been confirmed', function () {
    $service = new TestablePayrollExportService(new FixedPayrollRateResolver());

    $timesheet = new Timesheet([
        'id' => 77,
        'starts_at' => Carbon::parse('2026-04-01 22:00:00'),
        'ends_at' => Carbon::parse('2026-04-02 06:00:00'),
        'break_minutes' => 30,
        'status' => 'approved',
    ]);

    $confirmedSegment = [
        'segment_start_at' => '2026-04-01T22:00:00+00:00',
        'segment_end_at' => '2026-04-02T00:00:00+00:00',
        'segment_minutes' => 120,
        'allocated_break_minutes' => 8,
    ];

    $timesheet->payroll_segments_exported = [$confirmedSegment];

    $segments = $service->exposeSegmentsForPeriod(
        $timesheet,
        Carbon::parse('2026-04-01 00:00:00'),
        Carbon::parse('2026-04-02 00:00:00'),
    );

    expect($segments)->toBe([]);
});

it('uses snapshot site and client labels even if related records drift later', function () {
    $service = new TestablePayrollExportService(new FixedPayrollRateResolver());

    $timesheet = new Timesheet([
        'id' => 88,
        'user_id' => 9,
        'starts_at' => Carbon::parse('2026-04-03 09:00:00'),
        'ends_at' => Carbon::parse('2026-04-03 17:00:00'),
        'break_minutes' => 30,
        'status' => 'approved',
        'client_name_snapshot' => 'Jamie Carter',
        'staff_name_snapshot' => 'Alex Lee',
        'shift_site_name_snapshot' => 'Rimu House',
        'shift_location_snapshot' => 'Rimu House',
        'shift_type_snapshot' => 'standard',
        'service_context_name_snapshot' => 'Residential support',
    ]);

    $segment = $service->exposeSegmentsForPeriod(
        $timesheet,
        Carbon::parse('2026-04-03 00:00:00'),
        Carbon::parse('2026-04-04 00:00:00'),
    )[0];

    $row = $service->exposeBuildRow($timesheet, $segment);

    expect($row['client'])->toBe('Jamie Carter')
        ->and($row['site'])->toBe('Rimu House')
        ->and($row['location'])->toBe('Rimu House');
});
