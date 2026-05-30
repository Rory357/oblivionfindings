<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\LegacyRouteRedirectController;
use Illuminate\Support\Facades\Route;

/**
 * Shift-adjacent routes that remain outside the operations namespace.
 *
 * Scheduler/admin Shift and Timesheet endpoints are canonical under
 * routes/operations.php. Legacy URLs are kept as compatibility redirects:
 *   - GET URLs use 301 (deep links from emails/bookmarks).
 *   - POST/PATCH/PUT URLs use 308 so clients re-issue the same method+body
 *     against the canonical URL transparently.
 * Legacy route NAMES (e.g. shifts.start, timesheets.approve) remain removed
 * — callers must use operations.* names. Attendance remains canonical here
 * per PR 4.5.
 */
Route::middleware(['auth'])->group(function () {
    $legacyRedirect = function (string $uri, string $canonicalName) {
        return Route::get($uri, LegacyRouteRedirectController::class)
            ->defaults('canonical', $canonicalName);
    };

    $legacyWriteRedirect = function (array $methods, string $uri, string $canonicalName) {
        return Route::match($methods, $uri, LegacyRouteRedirectController::class)
            ->defaults('canonical', $canonicalName)
            ->defaults('status', 308);
    };

    // Legacy Shift GET URLs redirect to the canonical operations surface.
    $legacyRedirect('/shifts', 'operations.shifts.index');
    $legacyRedirect('/shifts/create', 'operations.shifts.create');
    $legacyRedirect('/shifts/{shift}', 'operations.shifts.show')
        ->whereNumber('shift');

    // Legacy Shift write URLs redirect (308 preserves method+body) so any
    // straggling external POSTer keeps working. No controller logic here —
    // every match bounces to the canonical operations endpoint.
    $legacyWriteRedirect(['POST'], '/shifts', 'operations.shifts.store');
    $legacyWriteRedirect(['POST'], '/shifts/series', 'operations.shifts.series.store');
    $legacyWriteRedirect(['PUT'], '/shifts/{shift}', 'operations.shifts.update')
        ->whereNumber('shift');
    $legacyWriteRedirect(['POST'], '/shifts/{shift}/assign', 'operations.shifts.assign')
        ->whereNumber('shift');
    $legacyWriteRedirect(['POST'], '/shifts/{shift}/unassign', 'operations.shifts.unassign')
        ->whereNumber('shift');
    $legacyWriteRedirect(['PATCH'], '/shifts/{shift}/start', 'operations.shifts.start')
        ->whereNumber('shift');
    $legacyWriteRedirect(['PATCH'], '/shifts/{shift}/complete', 'operations.shifts.complete')
        ->whereNumber('shift');
    $legacyWriteRedirect(['PATCH'], '/shifts/{shift}/cancel', 'operations.shifts.cancel')
        ->whereNumber('shift');
    $legacyWriteRedirect(['PATCH'], '/shifts/{shift}/reopen', 'operations.shifts.reopen')
        ->whereNumber('shift');
    $legacyWriteRedirect(['POST'], '/shifts/{shift}/replacement-request', 'operations.shifts.replacement.request')
        ->whereNumber('shift');
    $legacyWriteRedirect(['PATCH'], '/shifts/{shift}/replacement-request/cancel', 'operations.shifts.replacement.cancel')
        ->whereNumber('shift');
    $legacyWriteRedirect(['PATCH'], '/shifts/{shift}/tasks/{task}', 'operations.shifts.tasks.update')
        ->whereNumber('shift')
        ->whereNumber('task');

    // Legacy Timesheet GET URLs redirect to the canonical operations surface.
    $legacyRedirect('/timesheets', 'operations.timesheets.index');
    $legacyRedirect('/timesheets/approvals', 'operations.timesheets.approvals');
    $legacyRedirect('/timesheets/create', 'operations.timesheets.create');
    $legacyRedirect('/timesheets/{timesheet}', 'operations.timesheets.show')
        ->whereNumber('timesheet');
    $legacyRedirect('/timesheets/{timesheet}/edit', 'operations.timesheets.edit')
        ->whereNumber('timesheet');

    // Legacy Timesheet write URLs (308 redirects to operations).
    $legacyWriteRedirect(['POST'], '/timesheets', 'operations.timesheets.store');
    $legacyWriteRedirect(['PUT'], '/timesheets/{timesheet}', 'operations.timesheets.update')
        ->whereNumber('timesheet');
    $legacyWriteRedirect(['POST'], '/timesheets/{timesheet}/submit', 'operations.timesheets.submit')
        ->whereNumber('timesheet');
    $legacyWriteRedirect(['POST'], '/timesheets/{timesheet}/resubmit', 'operations.timesheets.resubmit')
        ->whereNumber('timesheet');
    $legacyWriteRedirect(['POST'], '/timesheets/{timesheet}/approve', 'operations.timesheets.approve')
        ->whereNumber('timesheet');
    $legacyWriteRedirect(['POST'], '/timesheets/{timesheet}/reject', 'operations.timesheets.reject')
        ->whereNumber('timesheet');
    $legacyWriteRedirect(['POST'], '/timesheets/{timesheet}/return', 'operations.timesheets.return')
        ->whereNumber('timesheet');
    $legacyWriteRedirect(['POST'], '/timesheets/bulk-approve', 'operations.timesheets.bulkApprove');
    $legacyWriteRedirect(['POST'], '/timesheets/bulk-return', 'operations.timesheets.bulkReturn');
    $legacyWriteRedirect(['POST'], '/timesheets/bulk-reject', 'operations.timesheets.bulkReject');

    // Legacy Rostering write URLs (308 redirects to operations).
    $legacyWriteRedirect(['POST'], '/rostering/time-off', 'operations.rostering.time_off.store');
    $legacyWriteRedirect(['DELETE'], '/rostering/time-off/{staffTimeOff}', 'operations.rostering.time_off.destroy')
        ->whereNumber('staffTimeOff');

    Route::get('/attendance', [AttendanceController::class, 'index'])
        ->middleware('permission:timesheets.viewAny|timesheets.viewAssigned|timesheets.create|shifts.viewAssigned|shifts.update|shifts.manageAny')
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
