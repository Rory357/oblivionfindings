<?php

use App\Http\Controllers\Api\HrApiController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('hr')->name('api.hr.')->group(function () {
    Route::get('/employees', [HrApiController::class, 'employees'])->name('employees');
    Route::get('/employees/{id}', [HrApiController::class, 'employee'])->name('employee');
    Route::get('/leave/requests', [HrApiController::class, 'leaveRequests'])->name('leave.requests');
    Route::get('/leave/balances/{userId}', [HrApiController::class, 'leaveBalances'])->name('leave.balances');
    Route::get('/positions', [HrApiController::class, 'positions'])->name('positions');
    Route::get('/compliance/status', [HrApiController::class, 'complianceStatus'])->name('compliance.status');
    Route::get('/time/entries', [HrApiController::class, 'timeEntries'])->name('time.entries');
    Route::get('/payroll/runs', [HrApiController::class, 'payrollRuns'])->name('payroll.runs');
});
