<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Sites\{
    SiteCalendarController,
    SiteHazardController,
    SiteChecklistController,
    SiteChecklistTemplateController,
    SiteVendorController,
    SiteCredentialController,
    SiteInspectionController,
    SiteOnboardingController,
    SiteReportingController,
    SiteRoomController,
    SiteResourceController,
    SiteZoneController
};

/*
|--------------------------------------------------------------------------
| Sites Module Routes
|--------------------------------------------------------------------------
|
| Extended routes for the Sites/Locations domain enhancement
|
*/

Route::middleware(['auth', 'verified'])->group(function () {

    // Global Sites Calendar
    Route::get('/sites/calendar', [SiteCalendarController::class, 'global'])
        ->name('sites.calendar.global')
        ->middleware('permission:calendar.view');

    // Site-scoped routes
    Route::prefix('sites/{site}')->middleware('permission:sites.viewAny')->group(function () {
        
        // Calendar
        Route::get('/calendar', [SiteCalendarController::class, 'index'])
            ->name('sites.calendar.index');
        Route::get('/calendar/events', [SiteCalendarController::class, 'events'])
            ->name('sites.calendar.events');
        Route::post('/calendar/events', [SiteCalendarController::class, 'store'])
            ->name('sites.calendar.store')
            ->middleware('permission:calendar.create');
        Route::put('/calendar/events/{event}', [SiteCalendarController::class, 'update'])
            ->name('sites.calendar.update')
            ->middleware('permission:calendar.create');
        Route::delete('/calendar/events/{event}', [SiteCalendarController::class, 'destroy'])
            ->name('sites.calendar.destroy')
            ->middleware('permission:calendar.create');
        
        // Hazards
        Route::get('/hazards', [SiteHazardController::class, 'index'])
            ->name('sites.hazards.index');
        Route::get('/hazards/create', [SiteHazardController::class, 'create'])
            ->name('sites.hazards.create')
            ->middleware('permission:hazards.create');
        Route::post('/hazards', [SiteHazardController::class, 'store'])
            ->name('sites.hazards.store')
            ->middleware('permission:hazards.create');
        
        // Checklists
        Route::get('/checklists', [SiteChecklistController::class, 'index'])
            ->name('sites.checklists.index');
        Route::get('/checklists/runs', [SiteChecklistController::class, 'runs'])
            ->name('sites.checklists.runs');
        Route::post('/checklists/assign', [SiteChecklistController::class, 'assignChecklist'])
            ->name('sites.checklists.assign')
            ->middleware('permission:checklists.schedule');
        Route::delete('/checklists/assignments/{assignment}', [SiteChecklistController::class, 'removeAssignment'])
            ->name('sites.checklists.removeAssignment')
            ->middleware('permission:checklists.schedule');
        Route::post('/checklists/assignments/{assignment}/run', [SiteChecklistController::class, 'createRun'])
            ->name('sites.checklists.createRun')
            ->middleware('permission:checklists.run');

        // Vendors
        Route::get('/vendors', [SiteVendorController::class, 'index'])
            ->name('sites.vendors.index')
            ->middleware('permission:vendors.view');
        Route::post('/vendors', [SiteVendorController::class, 'store'])
            ->name('sites.vendors.store')
            ->middleware('permission:vendors.manage');
        Route::put('/vendors/{vendor}', [SiteVendorController::class, 'update'])
            ->name('sites.vendors.update')
            ->middleware('permission:vendors.manage');
        Route::delete('/vendors/{vendor}', [SiteVendorController::class, 'destroy'])
            ->name('sites.vendors.destroy')
            ->middleware('permission:vendors.manage');

        // Credentials
        Route::get('/credentials', [SiteCredentialController::class, 'index'])
            ->name('sites.credentials.index')
            ->middleware('permission:credentials.view');
        Route::post('/credentials', [SiteCredentialController::class, 'store'])
            ->name('sites.credentials.store')
            ->middleware('permission:credentials.manage');
        Route::post('/credentials/{credential}/reveal', [SiteCredentialController::class, 'reveal'])
            ->name('sites.credentials.reveal')
            ->middleware('permission:credentials.reveal');
        Route::post('/credentials/{credential}/copy', [SiteCredentialController::class, 'copy'])
            ->name('sites.credentials.copy')
            ->middleware('permission:credentials.reveal');
        Route::put('/credentials/{credential}', [SiteCredentialController::class, 'update'])
            ->name('sites.credentials.update')
            ->middleware('permission:credentials.manage');
        Route::delete('/credentials/{credential}', [SiteCredentialController::class, 'destroy'])
            ->name('sites.credentials.destroy')
            ->middleware('permission:credentials.manage');
        Route::get('/credentials/{credential}/audit', [SiteCredentialController::class, 'auditLog'])
            ->name('sites.credentials.audit');

        // Inspections
        Route::get('/inspections', [SiteInspectionController::class, 'index'])
            ->name('sites.inspections.index')
            ->middleware('permission:checklists.view');
        Route::post('/inspections', [SiteInspectionController::class, 'store'])
            ->name('sites.inspections.store')
            ->middleware('permission:checklists.schedule');
        Route::post('/inspections/{schedule}/complete', [SiteInspectionController::class, 'complete'])
            ->name('sites.inspections.complete')
            ->middleware('permission:checklists.run');
        Route::delete('/inspections/{schedule}', [SiteInspectionController::class, 'destroy'])
            ->name('sites.inspections.destroy')
            ->middleware('permission:checklists.schedule');

        // Onboarding
        Route::get('/onboarding', [SiteOnboardingController::class, 'wizard'])
            ->name('sites.onboarding.wizard')
            ->middleware('permission:sites.update');
        Route::post('/onboarding/step', [SiteOnboardingController::class, 'saveStep'])
            ->name('sites.onboarding.saveStep')
            ->middleware('permission:sites.update');
        Route::post('/onboarding/complete', [SiteOnboardingController::class, 'complete'])
            ->name('sites.onboarding.complete')
            ->middleware('permission:sites.update');
    });

    // Hazard routes (not site-scoped)
    Route::get('/hazards/{hazard}', [SiteHazardController::class, 'show'])
        ->name('sites.hazards.show');
    Route::put('/hazards/{hazard}', [SiteHazardController::class, 'update'])
        ->name('sites.hazards.update')
        ->middleware('permission:hazards.create');
    Route::post('/hazards/{hazard}/assign', [SiteHazardController::class, 'assign'])
        ->name('sites.hazards.assign')
        ->middleware('permission:hazards.assign');
    Route::post('/hazards/{hazard}/close', [SiteHazardController::class, 'close'])
        ->name('sites.hazards.close')
        ->middleware('permission:hazards.close');

    // Checklist run routes
    Route::get('/checklists/runs/{run}', [SiteChecklistController::class, 'showRun'])
        ->name('sites.checklists.showRun');
    Route::post('/checklists/runs/{run}/start', [SiteChecklistController::class, 'startRun'])
        ->name('sites.checklists.startRun')
        ->middleware('permission:checklists.run');
    Route::post('/checklists/runs/{run}/complete', [SiteChecklistController::class, 'completeRun'])
        ->name('sites.checklists.completeRun')
        ->middleware('permission:checklists.run');
    Route::post('/checklists/runs/{run}/responses', [SiteChecklistController::class, 'saveResponse'])
        ->name('sites.checklists.response')
        ->middleware('permission:checklists.run');

    // Calendar exceptions
    Route::post('/calendar/events/{event}/exception', [SiteCalendarController::class, 'createException'])
        ->name('sites.calendar.exception')
        ->middleware('permission:calendar.manage_recurring');

    // Global Hazards page
    Route::get('/compliance/hazards', [SiteHazardController::class, 'globalIndex'])
        ->name('compliance.hazards')
        ->middleware('permission:hazards.view');

    // Site Reports
    Route::get('/sites/reports', [SiteReportingController::class, 'index'])
        ->name('sites.reports.index')
        ->middleware('permission:reports.sites.view');
    Route::get('/sites/reports/houses', [SiteReportingController::class, 'houses'])
        ->name('sites.reports.houses')
        ->middleware('permission:reports.sites.view');
    Route::get('/sites/reports/facilities', [SiteReportingController::class, 'facilities'])
        ->name('sites.reports.facilities')
        ->middleware('permission:reports.sites.view');
    Route::get('/sites/reports/head-office', [SiteReportingController::class, 'headOffice'])
        ->name('sites.reports.head-office')
        ->middleware('permission:reports.sites.view');
    Route::get('/sites/reports/export', [SiteReportingController::class, 'export'])
        ->name('sites.reports.export')
        ->middleware('permission:reports.sites.export');
    Route::get('/sites/reports/site/{site}', [SiteReportingController::class, 'perSiteDetail'])
        ->name('sites.reports.site-detail')
        ->middleware('permission:reports.sites.view');
    Route::get('/sites/reports/overdue-actions', [SiteReportingController::class, 'overdueCorrectiveActions'])
        ->name('sites.reports.overdue-actions')
        ->middleware('permission:reports.sites.view');
    Route::get('/sites/reports/checklist-trends', [SiteReportingController::class, 'checklistTrends'])
        ->name('sites.reports.checklist-trends')
        ->middleware('permission:reports.sites.view');
    Route::get('/sites/reports/asset-condition', [SiteReportingController::class, 'assetConditionReport'])
        ->name('sites.reports.asset-condition')
        ->middleware('permission:reports.sites.view');
    Route::get('/sites/reports/vendor-export', [SiteReportingController::class, 'vendorContactsExport'])
        ->name('sites.reports.vendor-export')
        ->middleware('permission:reports.sites.export');

    // Type-specific management
    Route::get('/sites/{site}/rooms', [SiteRoomController::class, 'index'])
        ->name('sites.rooms.index');
    Route::post('/sites/{site}/rooms', [SiteRoomController::class, 'store'])
        ->name('sites.rooms.store')
        ->middleware('permission:sites.update');
    Route::put('/sites/{site}/rooms/{room}', [SiteRoomController::class, 'update'])
        ->name('sites.rooms.update')
        ->middleware('permission:sites.update');
    Route::delete('/sites/{site}/rooms/{room}', [SiteRoomController::class, 'destroy'])
        ->name('sites.rooms.destroy')
        ->middleware('permission:sites.update');

    Route::get('/sites/{site}/resources', [SiteResourceController::class, 'index'])
        ->name('sites.resources.index');
    Route::post('/sites/{site}/resources', [SiteResourceController::class, 'store'])
        ->name('sites.resources.store')
        ->middleware('permission:sites.update');
    Route::put('/sites/{site}/resources/{resource}', [SiteResourceController::class, 'update'])
        ->name('sites.resources.update')
        ->middleware('permission:sites.update');
    Route::delete('/sites/{site}/resources/{resource}', [SiteResourceController::class, 'destroy'])
        ->name('sites.resources.destroy')
        ->middleware('permission:sites.update');

    Route::get('/sites/{site}/zones', [SiteZoneController::class, 'index'])
        ->name('sites.zones.index');
    Route::post('/sites/{site}/zones', [SiteZoneController::class, 'store'])
        ->name('sites.zones.store')
        ->middleware('permission:sites.update');
    Route::put('/sites/{site}/zones/{zone}', [SiteZoneController::class, 'update'])
        ->name('sites.zones.update')
        ->middleware('permission:sites.update');
    Route::delete('/sites/{site}/zones/{zone}', [SiteZoneController::class, 'destroy'])
        ->name('sites.zones.destroy')
        ->middleware('permission:sites.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    // Checklist Templates (global management)
    Route::get('/sites/checklists/templates', [SiteChecklistTemplateController::class, 'index'])
        ->name('sites.checklists.templates.index')
        ->middleware('permission:checklists.view');
    Route::get('/sites/checklists/templates/create', [SiteChecklistTemplateController::class, 'create'])
        ->name('sites.checklists.templates.create')
        ->middleware('permission:checklists.manage_templates');
    Route::post('/sites/checklists/templates', [SiteChecklistTemplateController::class, 'store'])
        ->name('sites.checklists.templates.store')
        ->middleware('permission:checklists.manage_templates');
    Route::get('/sites/checklists/templates/{template}/edit', [SiteChecklistTemplateController::class, 'edit'])
        ->name('sites.checklists.templates.edit')
        ->middleware('permission:checklists.manage_templates');
    Route::put('/sites/checklists/templates/{template}', [SiteChecklistTemplateController::class, 'update'])
        ->name('sites.checklists.templates.update')
        ->middleware('permission:checklists.manage_templates');
    Route::delete('/sites/checklists/templates/{template}', [SiteChecklistTemplateController::class, 'destroy'])
        ->name('sites.checklists.templates.destroy')
        ->middleware('permission:checklists.manage_templates');

    // Template Items
    Route::post('/sites/checklists/templates/{template}/items', [SiteChecklistTemplateController::class, 'storeItem'])
        ->name('sites.checklists.templates.items.store')
        ->middleware('permission:checklists.manage_templates');
    Route::put('/sites/checklists/templates/items/{item}', [SiteChecklistTemplateController::class, 'updateItem'])
        ->name('sites.checklists.templates.items.update')
        ->middleware('permission:checklists.manage_templates');
    Route::delete('/sites/checklists/templates/items/{item}', [SiteChecklistTemplateController::class, 'destroyItem'])
        ->name('sites.checklists.templates.items.destroy')
        ->middleware('permission:checklists.manage_templates');
});
