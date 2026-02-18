<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\ShiftSeriesController;
use App\Http\Controllers\ShiftTaskController;
use App\Http\Controllers\TimesheetController;

/**
 * Shift & Timesheet Management Routes
 *
 * Handles shift scheduling, recurring shifts, shift tasks,
 * and timesheet workflow.
 */

Route::middleware(['auth'])->group(function () {
    // Shifts
    Route::get('/shifts', [ShiftController::class, 'index'])
        ->middleware('permission:shifts.viewAny|shifts.viewAssigned')
        ->name('shifts.index');

    Route::get('/shifts/{shift}', [ShiftController::class, 'show'])
        ->whereNumber('shift')
        ->middleware('permission:shifts.viewAny|shifts.viewAssigned')
        ->name('shifts.show');

    // Shift creation
    Route::get('/shifts/create', [ShiftController::class, 'create'])
        ->middleware('permission:shifts.create')
        ->name('shifts.create');
    Route::post('/shifts', [ShiftController::class, 'store'])
        ->middleware('permission:shifts.create')
        ->name('shifts.store');

    // Recurring shifts (weekly series)
    Route::post('/shifts/series', [ShiftSeriesController::class, 'store'])
        ->middleware('permission:shifts.create')
        ->name('shifts.series.store');

    // Shift updates
    Route::get('/shifts/{shift}/edit', [ShiftController::class, 'edit'])
        ->middleware('permission:shifts.update')
        ->name('shifts.edit');
    Route::put('/shifts/{shift}', [ShiftController::class, 'update'])
        ->middleware('permission:shifts.update')
        ->name('shifts.update');

    // Roster planning (assign/unassign)
    Route::post('/shifts/{shift}/assign', [ShiftController::class, 'assign'])
        ->middleware('permission:shifts.manageAny')
        ->name('shifts.assign');
    Route::post('/shifts/{shift}/unassign', [ShiftController::class, 'unassign'])
        ->middleware('permission:shifts.manageAny')
        ->name('shifts.unassign');

    // Shift lifecycle
    Route::patch('/shifts/{shift}/start', [ShiftController::class, 'start'])
        ->middleware('permission:shifts.update|shifts.manageAny')
        ->name('shifts.start');
    Route::patch('/shifts/{shift}/complete', [ShiftController::class, 'complete'])
        ->middleware('permission:shifts.update|shifts.manageAny')
        ->name('shifts.complete');

    // Shift tasks
    Route::patch('/shifts/{shift}/tasks/{task}', [ShiftTaskController::class, 'update'])
        ->middleware('permission:shifts.update|shifts.tasks.updateSelf|shifts.manageAny')
        ->name('shifts.tasks.update');

    // Timesheets
    Route::get('/timesheets', [TimesheetController::class, 'index'])
        ->middleware('permission:timesheets.viewAny|timesheets.viewAssigned')
        ->name('timesheets.index');

    // Manager/Admin approval queue
    Route::get('/timesheets/approvals', [TimesheetController::class, 'approvals'])
        ->middleware('permission:timesheets.approve|timesheets.manageAny')
        ->name('timesheets.approvals');
    Route::post('/timesheets/bulk-approve', [TimesheetController::class, 'bulkApprove'])
        ->middleware('permission:timesheets.approve|timesheets.manageAny')
        ->name('timesheets.bulkApprove');
    Route::post('/timesheets/bulk-return', [TimesheetController::class, 'bulkReturnForChanges'])
        ->middleware('permission:timesheets.approve|timesheets.manageAny')
        ->name('timesheets.bulkReturn');
    Route::post('/timesheets/bulk-reject', [TimesheetController::class, 'bulkReject'])
        ->middleware('permission:timesheets.approve|timesheets.manageAny')
        ->name('timesheets.bulkReject');

    // Timesheet creation
    Route::get('/timesheets/create', [TimesheetController::class, 'create'])
        ->middleware('permission:timesheets.create')
        ->name('timesheets.create');
    Route::post('/timesheets', [TimesheetController::class, 'store'])
        ->middleware('permission:timesheets.create')
        ->name('timesheets.store');

    // Timesheet updates
    Route::get('/timesheets/{timesheet}', [TimesheetController::class, 'show'])
        ->middleware('permission:timesheets.viewAny|timesheets.viewAssigned')
        ->name('timesheets.show');
    Route::get('/timesheets/{timesheet}/edit', [TimesheetController::class, 'edit'])
        ->middleware('permission:timesheets.viewAny|timesheets.viewAssigned')
        ->name('timesheets.edit');
    Route::put('/timesheets/{timesheet}', [TimesheetController::class, 'update'])
        ->middleware('permission:timesheets.update')
        ->name('timesheets.update');

    // Timesheet workflow
    Route::post('/timesheets/{timesheet}/submit', [TimesheetController::class, 'submit'])
        ->middleware('permission:timesheets.submit|timesheets.manageAny')
        ->name('timesheets.submit');
    Route::post('/timesheets/{timesheet}/approve', [TimesheetController::class, 'approve'])
        ->middleware('permission:timesheets.approve|timesheets.manageAny')
        ->name('timesheets.approve');
    Route::post('/timesheets/{timesheet}/reject', [TimesheetController::class, 'reject'])
        ->middleware('permission:timesheets.approve|timesheets.manageAny')
        ->name('timesheets.reject');
    Route::post('/timesheets/{timesheet}/return', [TimesheetController::class, 'returnForChanges'])
        ->middleware('permission:timesheets.approve|timesheets.manageAny')
        ->name('timesheets.return');

    // Attendance (clock in/out)
    Route::get('/attendance', [AttendanceController::class, 'index'])
        ->middleware('permission:timesheets.viewAny|timesheets.viewAssigned')
        ->name('attendance.index');
    Route::post('/attendance/clock-in', [AttendanceController::class, 'clockIn'])
        ->middleware('permission:timesheets.create')
        ->name('attendance.clockIn');
    Route::post('/attendance/clock-out', [AttendanceController::class, 'clockOut'])
        ->middleware('permission:timesheets.create')
        ->name('attendance.clockOut');
});
