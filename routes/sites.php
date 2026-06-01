<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\Sites\{
    ChecklistsDashboardController,
    HouseLedgerController,
    HouseChecklistController,
    SiteCalendarController,
    SiteComplianceController,
    SiteHazardController,
    SiteChecklistController,
    SiteChecklistTemplateController,
    SiteVendorController,
    SiteCredentialController,
    SiteDamageController,
    SiteHardwareController,
    SiteIntegrationController,
    SiteInspectionController,
    SiteGeofenceController,
    SiteReportingController,
    SiteRoomController,
    SiteResourceController,
    SiteZoneController,
    SiteTypePlanController,
    SiteTypePlanPinController,
    SiteEmergencyPlanController,
    SiteMealPlanController,
    SiteMealInventoryController,
    SiteMealShoppingListController
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
    Route::get('/calendar', [SiteCalendarController::class, 'global'])
        ->name('sites.calendar.global')
        ->middleware('permission:calendar.view');

    // Site-scoped routes
    Route::prefix('sites/{site}')->middleware('permission:sites.viewAny')->group(function () {
        
        // House ledger
        Route::get('/ledger', [HouseLedgerController::class, 'index'])
            ->name('sites.ledger.index');
        Route::post('/ledger/entries', [HouseLedgerController::class, 'store'])
            ->name('sites.ledger.entries.store')
            ->middleware('permission:sites.ledger.create');
        Route::post('/ledger', [HouseLedgerController::class, 'store'])
            ->name('sites.ledger.store')
            ->middleware('permission:sites.ledger.create');
        Route::get('/ledger/entries/{entry}/download', [HouseLedgerController::class, 'downloadAttachment'])
            ->name('sites.ledger.entries.download');
        Route::get('/ledger/entries/{entry}/attachment', [HouseLedgerController::class, 'downloadAttachment'])
            ->name('sites.ledger.entries.attachment');
        Route::post('/ledger/reconcile', [HouseLedgerController::class, 'reconcile'])
            ->name('sites.ledger.reconcile')
            ->middleware('permission:sites.ledger.manage');
        
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

        Route::middleware('permission:assets.geofences.manage')->group(function () {
            Route::post('/geofence', [SiteGeofenceController::class, 'store'])
                ->name('sites.geofence.store');
            Route::put('/geofence/{geofence}', [SiteGeofenceController::class, 'update'])
                ->whereNumber('geofence')
                ->name('sites.geofence.update');
            Route::delete('/geofence/{geofence}', [SiteGeofenceController::class, 'destroy'])
                ->whereNumber('geofence')
                ->name('sites.geofence.destroy');
        });
        
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

        Route::get('/house-checklists', [HouseChecklistController::class, 'index'])
            ->name('sites.house-checklists.index');
        Route::post('/house-checklists/templates', [HouseChecklistController::class, 'storeTemplate'])
            ->name('sites.house-checklists.templates.store')
            ->middleware('permission:checklists.manage_templates');
        Route::post('/house-checklists/{template}/start', [HouseChecklistController::class, 'startRun'])
            ->name('sites.house-checklists.start')
            ->middleware('permission:checklists.run');
        Route::post('/house-checklists/runs/{run}/complete', [HouseChecklistController::class, 'completeRun'])
            ->name('sites.house-checklists.runs.complete')
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
        Route::patch('/vendors/{vendor}/flags', [SiteVendorController::class, 'toggleVendorFlags'])
            ->name('sites.vendors.flags')
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
        Route::post('/credentials/{credential}/rotate', [SiteCredentialController::class, 'rotate'])
            ->name('sites.credentials.rotate')
            ->middleware('permission:credentials.manage');
        Route::patch('/credentials/{credential}/reauth', [SiteCredentialController::class, 'toggleReauth'])
            ->name('sites.credentials.reauth')
            ->middleware('permission:credentials.manage');
        Route::get('/credentials/{credential}/audit', [SiteCredentialController::class, 'auditLog'])
            ->name('sites.credentials.audit')
            ->middleware('permission:credentials.view');

        // TOTP / Authenticator endpoints.
        // Oblivion *is* the authenticator app: the operator pastes an
        // existing Base32 secret via the credential dialog (handled by
        // store/update); these endpoints expose the live code and the
        // removal action. No server-side secret generation.
        Route::post('/credentials/{credential}/totp/code', [SiteCredentialController::class, 'totpCode'])
            ->name('sites.credentials.totp.code')
            ->middleware('permission:credentials.reveal');
        Route::delete('/credentials/{credential}/totp', [SiteCredentialController::class, 'removeTotp'])
            ->name('sites.credentials.totp.remove')
            ->middleware('permission:credentials.manage');

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

        // Hardware
        Route::get('/hardware', [SiteHardwareController::class, 'index'])
            ->name('sites.hardware.index')
            ->middleware('permission:siteHardware.view');
        // Remaining room-management routes:
        // - assignRoom: stable URL retained, but writes canonical device_assignments
        //   and only mirrors LocationHardware as compatibility metadata
        // - manageRooms: room management remains in the Sites module
        Route::post('/hardware/{hardware}/assign-room', [SiteHardwareController::class, 'assignRoom'])
            ->name('sites.hardware.assignRoom')
            ->middleware('permission:siteHardware.manage');
        Route::post('/hardware/{device}/pin', [SiteHardwareController::class, 'pinDevice'])
            ->whereNumber('device')
            ->name('sites.hardware.pin')
            ->middleware('permission:siteHardware.manage');
        Route::delete('/hardware/{device}/pin', [SiteHardwareController::class, 'unpinDevice'])
            ->whereNumber('device')
            ->name('sites.hardware.unpin')
            ->middleware('permission:siteHardware.manage');
        Route::post('/hardware/rooms', [SiteHardwareController::class, 'manageRooms'])
            ->name('sites.hardware.manageRooms')
            ->middleware('permission:siteHardware.manage');

        // Site type plan and emergency plan
        Route::get('/plan', [SiteTypePlanController::class, 'show'])
            ->name('sites.plan.show');
        Route::post('/plan/draft', [SiteTypePlanController::class, 'storeDraft'])
            ->name('sites.plan.draft.store')
            ->middleware('permission:sites.update');
        Route::put('/plan/draft', [SiteTypePlanController::class, 'updateDraft'])
            ->name('sites.plan.draft.update')
            ->middleware('permission:sites.update');
        Route::post('/plan/publish', [SiteTypePlanController::class, 'publish'])
            ->name('sites.plan.publish')
            ->middleware('permission:sites.update');
        Route::post('/plan/duplicate-to-draft', [SiteTypePlanController::class, 'duplicate'])
            ->name('sites.plan.duplicate')
            ->middleware('permission:sites.update');
        Route::delete('/plan/draft', [SiteTypePlanController::class, 'discardDraft'])
            ->name('sites.plan.draft.destroy')
            ->middleware('permission:sites.update');
        Route::post('/plan/pins', [SiteTypePlanPinController::class, 'storeBatch'])
            ->name('sites.plan.pins.store')
            ->middleware('permission:sites.update');
        Route::put('/plan/pins/{pin}', [SiteTypePlanPinController::class, 'update'])
            ->whereNumber('pin')
            ->name('sites.plan.pins.update')
            ->middleware('permission:sites.update');
        Route::delete('/plan/pins/{pin}', [SiteTypePlanPinController::class, 'destroy'])
            ->whereNumber('pin')
            ->name('sites.plan.pins.destroy')
            ->middleware('permission:sites.update');
        Route::get('/emergency-plan', [SiteEmergencyPlanController::class, 'show'])
            ->name('sites.emergency-plan.show');
        Route::put('/emergency-plan', [SiteEmergencyPlanController::class, 'update'])
            ->name('sites.emergency-plan.update')
            ->middleware('permission:sites.update');
        Route::get('/emergency-plan.pdf', [SiteEmergencyPlanController::class, 'download'])
            ->name('sites.emergency-plan.download');

        // Meal Planner
        Route::middleware('permission:sites.meals.view')->group(function () {
            Route::get('/meal-planner/bootstrap', [SiteMealPlanController::class, 'bootstrap'])
                ->name('sites.meals.bootstrap');
            Route::post('/meal-planner/check-conflicts', [SiteMealPlanController::class, 'checkConflicts'])
                ->name('sites.meals.checkConflicts');
            Route::get('/meal-planner/takeaway-vendors', [SiteMealPlanController::class, 'takeawayVendors'])
                ->name('sites.meals.takeawayVendors');
            Route::get('/meal-plan', [SiteMealPlanController::class, 'index'])
                ->name('sites.meals.plan.index');
            Route::get('/meal-plan/week-summary', [SiteMealPlanController::class, 'weekSummary'])
                ->name('sites.meals.plan.weekSummary');
            Route::post('/meal-plan', [SiteMealPlanController::class, 'store'])
                ->name('sites.meals.plan.store')
                ->middleware('permission:sites.meals.plan');
            Route::put('/meal-plan/{entry}', [SiteMealPlanController::class, 'update'])
                ->whereNumber('entry')
                ->name('sites.meals.plan.update')
                ->middleware('permission:sites.meals.plan');
            Route::delete('/meal-plan/{entry}', [SiteMealPlanController::class, 'destroy'])
                ->whereNumber('entry')
                ->name('sites.meals.plan.destroy')
                ->middleware('permission:sites.meals.plan');
            Route::post('/meal-plan/{entry}/serve', [SiteMealPlanController::class, 'markServed'])
                ->whereNumber('entry')
                ->name('sites.meals.plan.serve')
                ->middleware('permission:sites.meals.plan');

            Route::get('/meal-inventory', [SiteMealInventoryController::class, 'index'])
                ->name('sites.meals.inventory.index');
            Route::post('/meal-inventory/items', [SiteMealInventoryController::class, 'storeItem'])
                ->name('sites.meals.inventory.items.store')
                ->middleware('permission:sites.meals.inventory.adjust');
            Route::put('/meal-inventory/items/{item}', [SiteMealInventoryController::class, 'updateItem'])
                ->whereNumber('item')
                ->name('sites.meals.inventory.items.update')
                ->middleware('permission:sites.meals.inventory.adjust');
            Route::delete('/meal-inventory/items/{item}', [SiteMealInventoryController::class, 'destroyItem'])
                ->whereNumber('item')
                ->name('sites.meals.inventory.items.destroy')
                ->middleware('permission:sites.meals.inventory.adjust');
            Route::post('/meal-inventory/adjust', [SiteMealInventoryController::class, 'adjust'])
                ->name('sites.meals.inventory.adjust')
                ->middleware('permission:sites.meals.inventory.adjust');
            Route::post('/meal-inventory/stocktake', [SiteMealInventoryController::class, 'stocktake'])
                ->name('sites.meals.inventory.stocktake')
                ->middleware('permission:sites.meals.inventory.adjust');
            Route::get('/meal-inventory/movements', [SiteMealInventoryController::class, 'movements'])
                ->name('sites.meals.inventory.movements');

            Route::get('/meal-shopping-lists', [SiteMealShoppingListController::class, 'index'])
                ->name('sites.meals.shopping.index');
            Route::post('/meal-shopping-lists/generate', [SiteMealShoppingListController::class, 'generate'])
                ->name('sites.meals.shopping.generate')
                ->middleware('permission:sites.meals.shopping.manage');
            Route::put('/meal-shopping-lists/{list}', [SiteMealShoppingListController::class, 'update'])
                ->whereNumber('list')
                ->name('sites.meals.shopping.update')
                ->middleware('permission:sites.meals.shopping.manage');
            Route::post('/meal-shopping-lists/{list}/items', [SiteMealShoppingListController::class, 'addItem'])
                ->whereNumber('list')
                ->name('sites.meals.shopping.addItem')
                ->middleware('permission:sites.meals.shopping.manage');
            Route::delete('/meal-shopping-lists/{list}/items/{item}', [SiteMealShoppingListController::class, 'removeItem'])
                ->whereNumber('list')
                ->whereNumber('item')
                ->name('sites.meals.shopping.removeItem')
                ->middleware('permission:sites.meals.shopping.manage');
            Route::post('/meal-shopping-lists/{list}/receive', [SiteMealShoppingListController::class, 'markReceived'])
                ->whereNumber('list')
                ->name('sites.meals.shopping.receive')
                ->middleware('permission:sites.meals.shopping.manage');
            Route::delete('/meal-shopping-lists/{list}', [SiteMealShoppingListController::class, 'destroy'])
                ->whereNumber('list')
                ->name('sites.meals.shopping.destroy')
                ->middleware('permission:sites.meals.shopping.manage');
        });

        // Site Integrations
        Route::get('/integrations', [SiteIntegrationController::class, 'index'])
            ->name('sites.integrations.index')
            ->middleware('permission:siteHardware.view');
        Route::post('/integrations/{provider}', [SiteIntegrationController::class, 'configure'])
            ->name('sites.integrations.configure')
            ->middleware('permission:integrations.manage_site_secrets');
        Route::post('/integrations/{provider}/test', [SiteIntegrationController::class, 'testConnection'])
            ->name('sites.integrations.test')
            ->middleware('permission:integrations.manage_site_secrets');
        Route::post('/integrations/{provider}/sync-sites', [SiteIntegrationController::class, 'syncSites'])
            ->name('sites.integrations.syncSites')
            ->middleware('permission:integrations.manage_site_secrets');
        Route::post('/integrations/{provider}/sync-devices', [SiteIntegrationController::class, 'syncDevices'])
            ->name('sites.integrations.syncDevices')
            ->middleware('permission:siteHardware.manage');
        Route::post('/integrations/{provider}/pull-events', [SiteIntegrationController::class, 'pullEvents'])
            ->name('sites.integrations.pullEvents')
            ->middleware('permission:integrations.manage_site_secrets');
        Route::put('/integrations/{provider}/secrets/{capability}', [SiteIntegrationController::class, 'updateSecret'])
            ->name('sites.integrations.updateSecret')
            ->middleware('permission:integrations.manage_site_secrets');
        Route::put('/integrations/{provider}/overrides', [SiteIntegrationController::class, 'updateOverrides'])
            ->name('sites.integrations.updateOverrides')
            ->middleware('permission:integrations.manage_site_secrets');

        Route::post('/onboarding/step', [SiteController::class, 'storeOnboardingStep'])
            ->name('sites.onboarding.step')
            ->middleware('permission:sites.update');

        // Damage reporting
        Route::get('/damages', [SiteDamageController::class, 'index'])
            ->name('sites.damages.index');
        Route::post('/damages', [SiteDamageController::class, 'store'])
            ->name('sites.damages.store');
        Route::put('/damages/{damage}', [SiteDamageController::class, 'update'])
            ->name('sites.damages.update');
        Route::delete('/damages/{damage}', [SiteDamageController::class, 'destroy'])
            ->name('sites.damages.destroy');

    });

    // Hazard routes (not site-scoped)
    Route::get('/hazards/{hazard}', [SiteHazardController::class, 'show'])
        ->name('sites.hazards.show')
        ->middleware('permission:hazards.view');
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
        ->name('sites.checklists.showRun')
        ->middleware('permission:checklists.view');
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

    // Global Inspections & Maintenance — cross-site dashboard
    Route::get('/sites/inspections', [SiteInspectionController::class, 'globalIndex'])
        ->name('sites.inspections.global')
        ->middleware('permission:checklists.view');

    // Global Vendors & Credentials — cross-site dashboard.
    // Pipe-OR matches the controller's view check: either permission grants access.
    Route::get('/vendors', [SiteVendorController::class, 'globalIndex'])
        ->name('sites.vendors.global')
        ->middleware('permission:vendors.view|credentials.view');

    // Cross-site reveal & audit feed (JSON) for the Vendors & Credentials page.
    // Credential-scoped, so it requires credentials.view specifically.
    Route::get('/vendors/audit', [SiteVendorController::class, 'globalAudit'])
        ->name('sites.vendors.audit')
        ->middleware('permission:credentials.view');

    // Legacy URL — /sites/vendors-credentials is the previous canonical location.
    // Permanent redirect preserves bookmarks and historical references.
    Route::redirect('/sites/vendors-credentials', '/vendors', 301)
        ->name('sites.vendors-credentials.legacy');

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
        ->name('sites.rooms.index')
        ->middleware('permission:sites.viewAny');
    Route::post('/sites/{site}/rooms', [SiteRoomController::class, 'store'])
        ->name('sites.rooms.store')
        ->middleware('permission:sites.update');
    Route::post('/sites/{site}/rooms/seed-defaults', [SiteRoomController::class, 'seedDefaults'])
        ->name('sites.rooms.seed-defaults')
        ->middleware('permission:sites.update');
    Route::put('/sites/{site}/rooms/{room}', [SiteRoomController::class, 'update'])
        ->name('sites.rooms.update')
        ->middleware('permission:sites.update');
    Route::delete('/sites/{site}/rooms/{room}', [SiteRoomController::class, 'destroy'])
        ->name('sites.rooms.destroy')
        ->middleware('permission:sites.update');
    Route::post('/sites/{site}/rooms/{room}/assign', [SiteRoomController::class, 'assign'])
        ->name('sites.rooms.assign')
        ->middleware('permission:sites.update');
    Route::post('/sites/{site}/rooms/{room}/assets', [SiteRoomController::class, 'attachAsset'])
        ->name('sites.rooms.assets.attach')
        ->middleware('permission:sites.update');
    Route::delete('/sites/{site}/rooms/{room}/assets/{asset}', [SiteRoomController::class, 'detachAsset'])
        ->name('sites.rooms.assets.detach')
        ->middleware('permission:sites.update');
    Route::patch('/sites/{site}/rooms/order', [SiteRoomController::class, 'reorder'])
        ->name('sites.rooms.reorder')
        ->middleware('permission:sites.update');
    Route::post('/sites/{site}/rooms/{room}/restore', [SiteRoomController::class, 'restore'])
        ->name('sites.rooms.restore')
        ->middleware('permission:sites.update');
    Route::get('/sites/{site}/rooms/{room}/door-card', [SiteRoomController::class, 'doorCard'])
        ->name('sites.rooms.door-card')
        ->middleware('permission:sites.viewAny');

    Route::get('/sites/{site}/resources', [SiteResourceController::class, 'index'])
        ->name('sites.resources.index')
        ->middleware('permission:sites.viewAny');
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
        ->name('sites.zones.index')
        ->middleware('permission:sites.viewAny');
    Route::post('/sites/{site}/zones', [SiteZoneController::class, 'store'])
        ->name('sites.zones.store')
        ->middleware('permission:sites.update');
    Route::put('/sites/{site}/zones/{zone}', [SiteZoneController::class, 'update'])
        ->name('sites.zones.update')
        ->middleware('permission:sites.update');
    Route::delete('/sites/{site}/zones/{zone}', [SiteZoneController::class, 'destroy'])
        ->name('sites.zones.destroy')
        ->middleware('permission:sites.update');

    // Staff Requirements
    Route::post('/sites/{site}/staff-requirements', [SiteComplianceController::class, 'storeStaffRequirement'])
        ->name('sites.staff_requirements.store')
        ->middleware('permission:sites.update');
    Route::put('/sites/{site}/staff-requirements/{requirement}', [SiteComplianceController::class, 'updateStaffRequirement'])
        ->name('sites.staff_requirements.update')
        ->middleware('permission:sites.update');
    Route::delete('/sites/{site}/staff-requirements/{requirement}', [SiteComplianceController::class, 'destroyStaffRequirement'])
        ->name('sites.staff_requirements.destroy')
        ->middleware('permission:sites.update');

    Route::post('/sites/{site}/coverage-requirements', [SiteComplianceController::class, 'storeCoverageRequirement'])
        ->name('sites.coverage_requirements.store')
        ->middleware('permission:sites.update');
    Route::put('/sites/{site}/coverage-requirements/{requirement}', [SiteComplianceController::class, 'updateCoverageRequirement'])
        ->name('sites.coverage_requirements.update')
        ->middleware('permission:sites.update');
    Route::delete('/sites/{site}/coverage-requirements/{requirement}', [SiteComplianceController::class, 'destroyCoverageRequirement'])
        ->name('sites.coverage_requirements.destroy')
        ->middleware('permission:sites.update');

    // Feedback
    Route::get('/sites/{site}/feedback', [SiteComplianceController::class, 'feedback'])
        ->name('sites.feedback')
        ->middleware('permission:sites.viewAny');
    Route::post('/sites/{site}/feedback', [SiteComplianceController::class, 'storeFeedback'])
        ->name('sites.feedback.store')
        ->middleware('permission:sites.update');
    Route::post('/sites/{site}/feedback/{feedback}/respond', [SiteComplianceController::class, 'respondFeedback'])
        ->name('sites.feedback.respond')
        ->middleware('permission:sites.update');
    Route::put('/sites/{site}/feedback/{feedback}/status', [SiteComplianceController::class, 'updateFeedbackStatus'])
        ->name('sites.feedback.update_status')
        ->middleware('permission:sites.update');

    // Compliance
    Route::get('/sites/{site}/compliance', [SiteComplianceController::class, 'dashboard'])
        ->name('sites.compliance.dashboard')
        ->middleware('permission:sites.viewAny');
    Route::post('/sites/{site}/certifications', [SiteComplianceController::class, 'storeCertification'])
        ->name('sites.certifications.store')
        ->middleware('permission:sites.update');
    Route::put('/sites/{site}/certifications/{certification}', [SiteComplianceController::class, 'updateCertification'])
        ->name('sites.certifications.update')
        ->middleware('permission:sites.update');
    Route::delete('/sites/{site}/certifications/{certification}', [SiteComplianceController::class, 'destroyCertification'])
        ->name('sites.certifications.destroy')
        ->middleware('permission:sites.update');
    Route::post('/sites/{site}/compliance-checks', [SiteComplianceController::class, 'storeCheck'])
        ->name('sites.compliance_checks.store')
        ->middleware('permission:sites.update');
    Route::patch('/sites/{site}/compliance-checks/{check}/complete', [SiteComplianceController::class, 'completeCheck'])
        ->name('sites.compliance_checks.complete')
        ->middleware('permission:sites.update');
    Route::put('/sites/{site}/compliance-checks/{check}', [SiteComplianceController::class, 'updateCheck'])
        ->name('sites.compliance_checks.update')
        ->middleware('permission:sites.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    // Global Checklists dashboard (cross-site overview)
    Route::get('/checklists', [ChecklistsDashboardController::class, 'index'])
        ->name('checklists.index')
        ->middleware('permission:checklists.view');

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
