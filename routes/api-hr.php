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

// §P-S4 email-to-ticket webhook — unauthenticated, shared-secret verified in the
// controller (inert until IT_INBOUND_MAIL_SECRET is set). Stateless: no session,
// no CSRF. A provider maps its payload to {from, subject, text, message_id}.
Route::post('/it/email/inbound', \App\Http\Controllers\It\ItInboundEmailController::class)
    ->name('api.it.email.inbound');
