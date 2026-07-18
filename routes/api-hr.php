<?php

use App\Http\Controllers\Api\HrApiController;
use App\Http\Controllers\Api\ItApiWorkItemController;
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

Route::prefix('v1/it')->name('api.v1.it.')->middleware(['it.service', 'it.api.request'])->group(function () {
    Route::post('/work-items', [ItApiWorkItemController::class, 'store'])
        ->middleware('it.ability:work:create')->name('work-items.store');
    Route::get('/work-items/{workItem}', [ItApiWorkItemController::class, 'show'])
        ->middleware('it.ability:work:read')->name('work-items.show');
    Route::post('/work-items/{workItem}/comments', [ItApiWorkItemController::class, 'comment'])
        ->middleware('it.ability:work:comment')->name('work-items.comments.store');
    Route::post('/work-items/{workItem}/transitions', [ItApiWorkItemController::class, 'transition'])
        ->middleware('it.ability:work:transition')->name('work-items.transitions.store');
});
