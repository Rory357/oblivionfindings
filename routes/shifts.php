<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\LegacyRouteRedirectController;
use Illuminate\Support\Facades\Route;

/**
 * Shift-adjacent routes that remain outside the operations namespace.
 *
 * Scheduler/admin Shift and Timesheet endpoints are canonical under
 * routes/operations.php. Legacy GET URLs stay as permanent redirects for
 * deep links only; legacy route names and state-changing mounts have been
 * removed. Attendance remains canonical here per PR 4.5.
 */
Route::middleware(['auth'])->group(function () {
    $legacyRedirect = function (string $uri, string $canonicalName) {
        return Route::get($uri, LegacyRouteRedirectController::class)
            ->defaults('canonical', $canonicalName);
    };

    // Legacy Shift GET URLs redirect to the canonical operations surface.
    $legacyRedirect('/shifts', 'operations.shifts.index');
    $legacyRedirect('/shifts/create', 'operations.shifts.create');
    $legacyRedirect('/shifts/{shift}', 'operations.shifts.show')
        ->whereNumber('shift');
    $legacyRedirect('/shifts/{shift}/edit', 'operations.shifts.edit')
        ->whereNumber('shift');

    // Legacy Timesheet GET URLs redirect to the canonical operations surface.
    $legacyRedirect('/timesheets', 'operations.timesheets.index');
    $legacyRedirect('/timesheets/approvals', 'operations.timesheets.approvals');
    $legacyRedirect('/timesheets/create', 'operations.timesheets.create');
    $legacyRedirect('/timesheets/{timesheet}', 'operations.timesheets.show')
        ->whereNumber('timesheet');
    $legacyRedirect('/timesheets/{timesheet}/edit', 'operations.timesheets.edit')
        ->whereNumber('timesheet');

    Route::get('/attendance', [AttendanceController::class, 'index'])
        ->middleware('permission:timesheets.viewAny|timesheets.viewAssigned')
        ->name('attendance.index');
    Route::post('/attendance/clock-in', [AttendanceController::class, 'clockIn'])
        ->middleware('permission:timesheets.create|shifts.viewAssigned|shifts.update|shifts.manageAny')
        ->name('attendance.clockIn');
    Route::post('/attendance/clock-out', [AttendanceController::class, 'clockOut'])
        ->middleware('permission:timesheets.create|shifts.viewAssigned|shifts.update|shifts.manageAny')
        ->name('attendance.clockOut');
    Route::post('/attendance/break/start', [AttendanceController::class, 'startBreak'])
        ->middleware('permission:timesheets.create|shifts.viewAssigned|shifts.update|shifts.manageAny')
        ->name('attendance.break.start');
    Route::post('/attendance/break/end', [AttendanceController::class, 'endBreak'])
        ->middleware('permission:timesheets.create|shifts.viewAssigned|shifts.update|shifts.manageAny')
        ->name('attendance.break.end');
    Route::post('/attendance/handover', [AttendanceController::class, 'submitHandover'])
        ->middleware('permission:timesheets.create|shifts.viewAssigned|shifts.update|shifts.manageAny')
        ->name('attendance.handover.submit');
    Route::patch('/attendance/handover/{handover}/acknowledge', [AttendanceController::class, 'acknowledgeHandover'])
        ->middleware('permission:timesheets.create|shifts.viewAssigned|shifts.update|shifts.manageAny')
        ->name('attendance.handover.acknowledge');
});
