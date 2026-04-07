<?php

use App\Models\Timesheet;
use Carbon\Carbon;

uses(Tests\TestCase::class);

it('blocks operational edits once a timesheet is approved', function () {
    $timesheet = new Timesheet([
        'id' => 44,
        'status' => 'approved',
        'notes' => 'Original approved note',
        'starts_at' => Carbon::parse('2026-04-03 08:00:00'),
        'ends_at' => Carbon::parse('2026-04-03 16:00:00'),
        'break_minutes' => 30,
    ]);

    $timesheet->exists = true;
    $timesheet->syncOriginal();
    $timesheet->notes = 'Attempted drift';

    expect(fn () => $timesheet->save())
        ->toThrow(LogicException::class, 'Approved or payroll-linked timesheets are immutable.');
});

it('blocks workflow state drift once payroll export has linked the timesheet', function () {
    $timesheet = new Timesheet([
        'id' => 51,
        'status' => 'approved',
        'payroll_reference' => 'operations-payroll-export:123',
        'starts_at' => Carbon::parse('2026-04-03 22:00:00'),
        'ends_at' => Carbon::parse('2026-04-04 06:00:00'),
        'break_minutes' => 30,
    ]);

    $timesheet->exists = true;
    $timesheet->syncOriginal();
    $timesheet->status = 'draft';

    expect(fn () => $timesheet->save())
        ->toThrow(LogicException::class, 'Payroll-linked timesheets cannot change workflow state after export confirmation.');
});

it('reports snapshot and payroll segment diagnostics from the model', function () {
    $timesheet = new Timesheet([
        'status' => 'approved',
        'client_name_snapshot' => 'Jamie Carter',
        'staff_name_snapshot' => 'Morgan Smith',
        'shift_type_snapshot' => 'overnight',
        'starts_at' => Carbon::parse('2026-04-03 22:00:00'),
        'ends_at' => Carbon::parse('2026-04-04 06:00:00'),
        'payroll_segments_exported' => [
            ['segment_minutes' => 120],
            ['segment_minutes' => 360],
        ],
    ]);

    expect($timesheet->is_snapshot_complete)->toBeTrue()
        ->and($timesheet->is_payroll_segment_complete)->toBeTrue()
        ->and($timesheet->is_protected_from_changes)->toBeTrue();
});
