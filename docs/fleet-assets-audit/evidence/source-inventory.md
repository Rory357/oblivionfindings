# Static route, action and surface inventory

MAIN ASTRA; Revision 10 + A1/A2; local HEAD 19354ecbc70046d12dfdf9c86f888e65fa1879d1. Source extraction only, not route execution or a passing test. Read middleware groups in the linked source; these declarations are not flattened effective permissions.

## routes/fleet-assets.php
- L34: Route::middleware(['auth'])->prefix('fleet-assets')->group(function () {
- L36: Route::middleware('permission:fleet.viewAny|assets.viewAny|assets.viewAssigned')->group(function () {
- L37: Route::redirect('/mobile/dashboard', '/fleet-assets')->name('fleet-assets.mobile.dashboard');
- L41: Route::middleware('permission:fleet.viewAny|assets.viewAny|assets.viewAssigned')->group(function () {
- L42: Route::get('/', DashboardController::class)->name('fleet-assets.dashboard');
- L43: Route::get('/map', LiveMapController::class)->name('fleet-assets.map');
- L44: Route::get('/compliance', [ComplianceController::class, 'index'])->name('fleet-assets.compliance.index');
- L45: Route::get('/daily-check', [DailyCheckController::class, 'index'])->name('fleet-assets.daily-check.index');
- L46: Route::post('/daily-check', [DailyCheckController::class, 'store'])->name('fleet-assets.daily-check.store');
- L50: Route::middleware('permission:fleet.viewAny')->group(function () {
- L51: Route::get('/vehicles', [VehicleController::class, 'index'])->name('fleet-assets.vehicles.index');
- L52: Route::get('/vehicles/{asset}', [VehicleController::class, 'show'])->whereNumber('asset')->name('fleet-assets.vehicles.show');
- L53: Route::get('/vehicles/{asset}/alerts-config', [VehicleController::class, 'alertsConfig'])->whereNumber('asset')->name('fleet-assets.vehicles.alerts-config');
- L54: Route::get('/trips', [VehicleController::class, 'trips'])->name('fleet-assets.trips.index');
- L55: Route::get('/trips/{trip}/playback', [FleetTripController::class, 'show'])->whereNumber('trip')->name('fleet-assets.trips.playback');
- L56: Route::get('/trips/{trip}/playback/data', [FleetTripController::class, 'playback'])->whereNumber('trip')->name('fleet-assets.trips.playback.data');
- L57: Route::get('/fuel', [VehicleController::class, 'fuel'])->name('fleet-assets.fuel.index');
- L61: Route::middleware('permission:fleet.manage')->group(function () {
- L62: Route::put('/vehicles/{asset}', [VehicleController::class, 'update'])->whereNumber('asset')->name('fleet-assets.vehicles.update');
- L63: Route::post('/vehicles/bulk-action', [VehicleController::class, 'bulkAction'])->name('fleet-assets.vehicles.bulk-action');
- L64: Route::post('/vehicles/{asset}/alerts-config', [VehicleController::class, 'saveAlertsConfig'])->whereNumber('asset')->name('fleet-assets.vehicles.alerts-config.save');
- L65: Route::post('/trips/{trip}/toggle-personal', [VehicleController::class, 'markPersonal'])->whereNumber('trip')->name('fleet-assets.trips.toggle-personal');
- L66: Route::post('/fuel', [VehicleController::class, 'storeFuel'])->name('fleet-assets.fuel.store');
- L70: Route::middleware('permission:assets.viewAny|assets.viewAssigned')->group(function () {
- L71: Route::get('/assets', [AssetController::class, 'index'])->name('fleet-assets.assets.index');
- L72: Route::get('/assets/{asset}', [AssetController::class, 'show'])->whereNumber('asset')->name('fleet-assets.assets.show');
- L74: Route::middleware('permission:assets.create')->group(function () {
- L75: Route::get('/assets/create', [AssetController::class, 'create'])->name('fleet-assets.assets.create');
- L76: Route::post('/assets', [AssetController::class, 'store'])->name('fleet-assets.assets.store');
- L78: Route::middleware('permission:assets.update')->group(function () {
- L79: Route::get('/assets/{asset}/edit', [AssetController::class, 'edit'])->whereNumber('asset')->name('fleet-assets.assets.edit');
- L80: Route::put('/assets/{asset}', [AssetController::class, 'update'])->whereNumber('asset')->name('fleet-assets.assets.update');
- L84: Route::middleware('permission:assets.viewAny|assets.alerts.view')->group(function () {
- L85: Route::get('/alerts', [AlertController::class, 'index'])->name('fleet-assets.alerts.index');
- L89: Route::middleware('permission:controlRoom.alerts.manage')->group(function () {
- L90: Route::post('/alerts/bulk-action', [AlertController::class, 'bulkAction'])->name('fleet-assets.alerts.bulk-action');
- L91: Route::post('/alerts/{alert}/acknowledge', [AlertController::class, 'acknowledge'])->whereNumber('alert')->name('fleet-assets.alerts.acknowledge');
- L92: Route::post('/alerts/{alert}/triage', [AlertController::class, 'triage'])->whereNumber('alert')->name('fleet-assets.alerts.triage');
- L93: Route::post('/alerts/{alert}/resolve', [AlertController::class, 'resolve'])->whereNumber('alert')->name('fleet-assets.alerts.resolve');
- L97: Route::middleware('permission:fleet.viewAny|assets.viewAny')->group(function () {
- L98: Route::get('/settings/notifications', fn () => Inertia::render('fleet-assets/settings/notifications'))->name('fleet-assets.settings.notifications');
- L102: Route::middleware('permission:fleet.viewAny|hr.driver.view')->group(function () {
- L103: Route::get('/drivers', [DriverController::class, 'index'])->name('fleet-assets.drivers.index');
- L104: Route::get('/drivers/{user}', [DriverController::class, 'show'])->whereNumber('user')->name('fleet-assets.drivers.show');
- L105: Route::get('/drivers/{user}/scorecard', [DriverController::class, 'scorecard'])->whereNumber('user')->name('fleet-assets.drivers.scorecard');
- L109: Route::middleware('permission:fleet.viewAny|assets.viewAny')->group(function () {
- L110: Route::get('/bookings', [VehicleBookingController::class, 'index'])->name('fleet-assets.bookings.index');
- L111: Route::get('/bookings/create', [VehicleBookingController::class, 'create'])->name('fleet-assets.bookings.create');
- L112: Route::post('/bookings', [VehicleBookingController::class, 'store'])->name('fleet-assets.bookings.store');
- L113: Route::get('/bookings/{booking}', [VehicleBookingController::class, 'show'])->whereNumber('booking')->name('fleet-assets.bookings.show');
- L117: Route::middleware('permission:fleet.manage')->group(function () {
- L118: Route::post('/bookings/{booking}/checkout', [VehicleBookingController::class, 'checkout'])->whereNumber('booking')->name('fleet-assets.bookings.checkout');
- L119: Route::post('/bookings/{booking}/return', [VehicleBookingController::class, 'returnVehicle'])->whereNumber('booking')->name('fleet-assets.bookings.return');
- L120: Route::post('/bookings/{booking}/cancel', [VehicleBookingController::class, 'cancel'])->whereNumber('booking')->name('fleet-assets.bookings.cancel');
- L124: Route::middleware('permission:fleet.bookings.approve|fleet.manage')->group(function () {
- L125: Route::post('/bookings/{booking}/approve', [VehicleBookingController::class, 'approve'])->whereNumber('booking')->name('fleet-assets.bookings.approve');
- L126: Route::post('/bookings/{booking}/reject', [VehicleBookingController::class, 'reject'])->whereNumber('booking')->name('fleet-assets.bookings.reject');
- L130: Route::middleware('permission:fleet.viewAny|assets.trackers.manage')->group(function () {
- L131: Route::get('/devices', [DeviceController::class, 'index'])->name('fleet-assets.devices.index');
- L132: Route::get('/devices/consent', [DeviceController::class, 'consentIndex'])->name('fleet-assets.devices.consent');
- L133: Route::get('/devices/{device}', [DeviceController::class, 'show'])->whereNumber('device')->name('fleet-assets.devices.show');
- L137: Route::middleware('permission:fleet.manage|assets.trackers.manage')->group(function () {
- L138: Route::get('/devices/options/search', [DeviceController::class, 'searchPairingOptions'])->name('fleet-assets.devices.options.search');
- L139: Route::post('/devices/pair', [DeviceController::class, 'pair'])->name('fleet-assets.devices.pair');
- L140: Route::post('/devices/{device}/unpair', [DeviceController::class, 'unpair'])->whereNumber('device')->name('fleet-assets.devices.unpair');
- L141: Route::post('/devices/{device}/consent/grant', [DeviceController::class, 'grantConsent'])->whereNumber('device')->name('fleet-assets.devices.consent.grant');
- L142: Route::post('/devices/{device}/consent/revoke', [DeviceController::class, 'revokeConsent'])->whereNumber('device')->name('fleet-assets.devices.consent.revoke');
- L146: Route::middleware('permission:fleet.viewAny|assets.geofences.manage')->group(function () {
- L147: Route::get('/geofences', [GeofenceController::class, 'index'])->name('fleet-assets.geofences.index');
- L151: Route::middleware('permission:assets.geofences.manage|fleet.manage')->group(function () {
- L152: Route::get('/geofences/create', [GeofenceController::class, 'create'])->name('fleet-assets.geofences.create');
- L153: Route::post('/geofences', [GeofenceController::class, 'store'])->name('fleet-assets.geofences.store');
- L154: Route::get('/geofences/{geofence}/edit', [GeofenceController::class, 'edit'])->whereNumber('geofence')->name('fleet-assets.geofences.edit');
- L155: Route::put('/geofences/{geofence}', [GeofenceController::class, 'update'])->whereNumber('geofence')->name('fleet-assets.geofences.update');
- L156: Route::post('/geofences/{geofence}/toggle', [GeofenceController::class, 'toggleActive'])->whereNumber('geofence')->name('fleet-assets.geofences.toggle');
- L157: Route::delete('/geofences/{geofence}', [GeofenceController::class, 'destroy'])->whereNumber('geofence')->name('fleet-assets.geofences.destroy');
- L161: Route::middleware('permission:fleet.viewAny|assets.viewAny')->group(function () {
- L162: Route::get('/maintenance/dashboard', MaintenanceDashboardController::class)->name('fleet-assets.maintenance.dashboard');
- L163: Route::get('/maintenance/work-orders', [WorkOrderController::class, 'index'])->name('fleet-assets.work-orders.index');
- L164: Route::get('/maintenance/work-orders/create', [WorkOrderController::class, 'create'])->name('fleet-assets.work-orders.create');
- L165: Route::get('/maintenance/work-orders/{workOrder}', [WorkOrderController::class, 'show'])->whereNumber('workOrder')->name('fleet-assets.work-orders.show');
- L167: Route::get('/maintenance/checklists', [ChecklistController::class, 'index'])->name('fleet-assets.checklists.index');
- L168: Route::get('/maintenance/checklists/run', [ChecklistController::class, 'runPage'])->name('fleet-assets.checklists.run-page');
- L170: Route::get('/maintenance/schedules', [ServiceScheduleController::class, 'index'])->name('fleet-assets.schedules.index');
- L173: Route::get('/inspections', [InspectionController::class, 'index'])->name('fleet-assets.inspections.index');
- L174: Route::get('/inspections/create', [InspectionController::class, 'create'])->name('fleet-assets.inspections.create');
- L175: Route::get('/inspections/{run}', [InspectionController::class, 'show'])->whereNumber('run')->name('fleet-assets.inspections.show');
- L179: Route::middleware('permission:fleet.maintenance.manage|fleet.manage')->group(function () {
- L180: Route::get('/maintenance/work-orders/options/search', [WorkOrderController::class, 'searchOptions'])->name('fleet-assets.work-orders.options.search');
- L181: Route::post('/maintenance/work-orders', [WorkOrderController::class, 'store'])->name('fleet-assets.work-orders.store');
- L182: Route::put('/maintenance/work-orders/{workOrder}', [WorkOrderController::class, 'update'])->whereNumber('workOrder')->name('fleet-assets.work-orders.update');
- L183: Route::post('/maintenance/work-orders/bulk-action', [WorkOrderController::class, 'bulkAction'])->name('fleet-assets.work-orders.bulk-action');
- L185: Route::post('/maintenance/checklists', [ChecklistController::class, 'store'])->name('fleet-assets.checklists.store');
- L186: Route::post('/maintenance/checklists/{template}/run', [ChecklistController::class, 'run'])->whereNumber('template')->name('fleet-assets.checklists.run');
- L188: Route::post('/maintenance/schedules', [ServiceScheduleController::class, 'store'])->name('fleet-assets.schedules.store');
- L189: Route::put('/maintenance/schedules/{schedule}', [ServiceScheduleController::class, 'update'])->whereNumber('schedule')->name('fleet-assets.schedules.update');
- L190: Route::post('/maintenance/schedules/{schedule}/mark-complete', [ServiceScheduleController::class, 'markComplete'])->whereNumber('schedule')->name('fleet-assets.schedules.mark-complete');
- L192: Route::post('/inspections', [InspectionController::class, 'store'])->name('fleet-assets.inspections.store');
- L196: Route::middleware('permission:fleet.viewAny|assets.viewAny')->group(function () {
- L197: Route::get('/keys', [KeyController::class, 'index'])->name('fleet-assets.keys.index');
- L201: Route::middleware('permission:fleet.manage')->group(function () {
- L202: Route::post('/keys/checkout', [KeyController::class, 'checkout'])->name('fleet-assets.keys.checkout');
- L203: Route::post('/keys/return', [KeyController::class, 'returnKey'])->name('fleet-assets.keys.return');
- L204: Route::post('/keys/transfer', [KeyController::class, 'transfer'])->name('fleet-assets.keys.transfer');
- L208: Route::middleware([
- L212: Route::get('/resident-tracking', [ResidentTrackingController::class, 'index'])->name('fleet-assets.resident-tracking.index');
- L213: Route::get('/resident-tracking/assign', [ResidentTrackingController::class, 'assignPage'])->name('fleet-assets.resident-tracking.assign');
- L214: Route::get('/resident-tracking/history/{client}', [ResidentTrackingController::class, 'history'])->whereNumber('client')->name('fleet-assets.resident-tracking.history');
- L215: Route::get('/resident-tracking/history/{client}/privacy-status', [ResidentTrackingController::class, 'privacyStatus'])->whereNumber('client')->name('fleet-assets.resident-tracking.privacy-status');
- L216: Route::post('/resident-tracking/history/{client}/export', [ResidentTrackingController::class, 'exportHistory'])->whereNumber('client')->middleware('permission:assets.telemetry.export')->name('fleet-assets.resident-tracking.export');
- L217: Route::post('/resident-tracking/{client}/locate-now', [ResidentTrackingController::class, 'locateNow'])->whereNumber('client')->name('fleet-assets.resident-tracking.locate-now');
- L221: Route::middleware('permission:fleet.manage')->group(function () {
- L222: Route::post('/resident-tracking/assign', [ResidentTrackingController::class, 'assign'])->name('fleet-assets.resident-tracking.assign.store');
- L223: Route::post('/resident-tracking/{device}/unassign', [ResidentTrackingController::class, 'unassign'])->whereNumber('device')->name('fleet-assets.resident-tracking.unassign');
- L224: Route::post('/resident-tracking/{client}/acknowledge-panic', [ResidentTrackingController::class, 'acknowledgePanic'])->whereNumber('client')->name('fleet-assets.resident-tracking.acknowledge-panic');
- L228: Route::middleware('permission:fleet.viewAny|assets.viewAny')->group(function () {
- L229: Route::get('/wandering-alerts', [WanderingAlertController::class, 'index'])->name('fleet-assets.wandering-alerts.index');
- L233: Route::middleware('permission:fleet.viewAny|assets.viewAny')->group(function () {
- L234: Route::get('/transports', [ResidentTransportController::class, 'index'])->name('fleet-assets.transports.index');
- L235: Route::get('/transports/medications', [ResidentTransportController::class, 'medicationIndex'])->name('fleet-assets.transports.medications');
- L236: Route::get('/transports/create', [ResidentTransportController::class, 'create'])->name('fleet-assets.transports.create');
- L237: Route::post('/transports', [ResidentTransportController::class, 'store'])->name('fleet-assets.transports.store');
- L238: Route::get('/transports/{transport}', [ResidentTransportController::class, 'show'])->whereNumber('transport')->name('fleet-assets.transports.show');
- L239: Route::post('/transports/{transport}/complete', [ResidentTransportController::class, 'complete'])->whereNumber('transport')->name('fleet-assets.transports.complete');
- L240: Route::get('/transports/{transport}/pre-check', [ResidentTransportController::class, 'preCheck'])->whereNumber('transport')->name('fleet-assets.transports.pre-check');
- L241: Route::post('/transports/{transport}/pre-check', [ResidentTransportController::class, 'savePreCheck'])->whereNumber('transport')->name('fleet-assets.transports.pre-check.store');
- L245: Route::middleware('permission:fleet.medication.manage')->group(function () {
- L246: Route::post('/transports/{transport}/pack-medication', [ResidentTransportController::class, 'packMedication'])->whereNumber('transport')->name('fleet-assets.transports.pack-medication');
- L247: Route::post('/medication-transit/{log}/correct-packing-attestation', [ResidentTransportController::class, 'correctPackingAttestation'])->whereNumber('log')->name('fleet-assets.medication-transit.correct-packing-attestation');
- L248: Route::post('/medication-transit/{log}/return', [ResidentTransportController::class, 'returnMedication'])->whereNumber('log')->name('fleet-assets.medication-transit.return');
- L250: Route::post('/medication-transit/{log}/administer', [ResidentTransportController::class, 'administerMedication'])
- L256: Route::middleware('permission:fleet.viewAny|assets.viewAny')->group(function () {
- L257: Route::get('/handovers', [HandoverController::class, 'index'])->name('fleet-assets.handovers.index');
- L258: Route::get('/handovers/create', [HandoverController::class, 'create'])->name('fleet-assets.handovers.create');
- L261: Route::get('/handovers/{handover}', [HandoverController::class, 'show'])
- L266: Route::middleware('permission:fleet.manage')->group(function () {
- L267: Route::post('/handovers', [HandoverController::class, 'store'])->name('fleet-assets.handovers.store');
- L270: Route::post('/handovers/{handover}/accept', [HandoverController::class, 'accept'])
- L273: Route::post('/handovers/{handover}/dispute', [HandoverController::class, 'dispute'])
- L278: Route::middleware('permission:fleet.viewAny|assets.viewAny')->group(function () {
- L279: Route::get('/incidents', [IncidentController::class, 'index'])->name('fleet-assets.incidents.index');
- L280: Route::get('/incidents/options/search', [IncidentController::class, 'searchOptions'])->name('fleet-assets.incidents.options.search');
- L281: Route::get('/incidents/create', [IncidentController::class, 'create'])->name('fleet-assets.incidents.create');
- L282: Route::post('/incidents', [IncidentController::class, 'store'])->name('fleet-assets.incidents.store');
- L283: Route::get('/incidents/{incident}', [IncidentController::class, 'show'])->whereNumber('incident')->name('fleet-assets.incidents.show');
- L284: Route::get('/incidents/{incident}/attachments/{attachment}/download', [IncidentController::class, 'downloadAttachment'])
- L289: Route::middleware('permission:fleet.incidents.manage|fleet.manage')->group(function () {
- L290: Route::put('/incidents/{incident}', [IncidentController::class, 'update'])->whereNumber('incident')->name('fleet-assets.incidents.update');
- L291: Route::post('/incidents/{incident}/status', [IncidentController::class, 'updateStatus'])->whereNumber('incident')->name('fleet-assets.incidents.status');
- L292: Route::post('/incidents/{incident}/followups', [IncidentController::class, 'addFollowup'])->whereNumber('incident')->name('fleet-assets.incidents.followups.add');
- L293: Route::post('/incidents/{incident}/followups/{followup}/complete', [IncidentController::class, 'completeFollowup'])
- L295: Route::post('/incidents/{incident}/attachments', [IncidentController::class, 'uploadAttachment'])->whereNumber('incident')->name('fleet-assets.incidents.attachments.store');
- L296: Route::delete('/incidents/{incident}/attachments/{attachment}', [IncidentController::class, 'destroyAttachment'])
- L298: Route::post('/incidents/{incident}/police-report', [IncidentController::class, 'logPoliceReport'])->whereNumber('incident')->name('fleet-assets.incidents.police-report');
- L299: Route::post('/incidents/{incident}/claim', [IncidentController::class, 'logClaim'])->whereNumber('incident')->name('fleet-assets.incidents.claim');
- L300: Route::post('/incidents/{incident}/off-road', [IncidentController::class, 'markOffRoad'])->whereNumber('incident')->name('fleet-assets.incidents.off-road');
- L301: Route::post('/incidents/{incident}/back-in-service', [IncidentController::class, 'backInService'])->whereNumber('incident')->name('fleet-assets.incidents.back-in-service');
- L305: Route::middleware('permission:fleet.viewAny|assets.viewAny')->group(function () {
- L306: Route::get('/outings', [OutingController::class, 'index'])->name('fleet-assets.outings.index');
- L307: Route::get('/outings/create', [OutingController::class, 'create'])->name('fleet-assets.outings.create');
- L308: Route::get('/outings/{outing}', [OutingController::class, 'show'])->whereNumber('outing')->name('fleet-assets.outings.show');
- L312: Route::middleware('permission:fleet.outings.manage|fleet.manage')->group(function () {
- L313: Route::post('/outings', [OutingController::class, 'store'])->name('fleet-assets.outings.store');
- L314: Route::post('/outings/{outing}/start', [OutingController::class, 'start'])->whereNumber('outing')->name('fleet-assets.outings.start');
- L315: Route::post('/outings/{outing}/complete', [OutingController::class, 'complete'])->whereNumber('outing')->name('fleet-assets.outings.complete');
- L316: Route::post('/outings/{outing}/cancel', [OutingController::class, 'cancel'])->whereNumber('outing')->name('fleet-assets.outings.cancel');
- L317: Route::post('/outings/{outing}/residents/{resident}/return', [OutingController::class, 'markResidentReturned'])->name('fleet-assets.outings.resident-return');
- L318: Route::post('/outings/{outing}/residents/return-all', [OutingController::class, 'returnAllResidents'])->whereNumber('outing')->name('fleet-assets.outings.return-all');
- L322: Route::middleware('permission:fleet.viewAny|assets.viewAny')->group(function () {
- L323: Route::get('/mileage/export', [MileageController::class, 'export'])->name('fleet-assets.mileage.export');
- L324: Route::get('/mileage', [MileageController::class, 'index'])->name('fleet-assets.mileage.index');
- L325: Route::get('/mileage/create', [MileageController::class, 'create'])->name('fleet-assets.mileage.create');
- L326: Route::post('/mileage', [MileageController::class, 'store'])->name('fleet-assets.mileage.store');
- L330: Route::middleware('permission:fleet.mileage.approve|fleet.manage')->group(function () {
- L331: Route::post('/mileage/{trip}/approve', [MileageController::class, 'approve'])->whereNumber('trip')->name('fleet-assets.mileage.approve');
- L332: Route::post('/mileage/{trip}/reject', [MileageController::class, 'reject'])->whereNumber('trip')->name('fleet-assets.mileage.reject');
- L333: Route::post('/mileage/{trip}/mark-paid', [MileageController::class, 'markPaid'])->whereNumber('trip')->name('fleet-assets.mileage.mark-paid');
- L337: Route::middleware('permission:fleet.viewAny|fleet.reports.view')->group(function () {
- L338: Route::get('/reports', [ReportController::class, 'index'])->name('fleet-assets.reports.index');
- L339: Route::get('/reports/export', [ReportController::class, 'export'])->name('fleet-assets.reports.export');
- L340: Route::get('/reports/by-house', [ReportController::class, 'byHouse'])->name('fleet-assets.reports.by-house');
- L341: Route::get('/reports/reimbursement', [ReportController::class, 'reimbursement'])->name('fleet-assets.reports.reimbursement');
- L342: Route::get('/reports/reimbursement/data', [ReportController::class, 'reimbursementData'])->name('fleet-assets.reports.reimbursement.data');
- L343: Route::get('/reports/cost-allocation', [CostAllocationController::class, 'index'])->name('fleet-assets.reports.cost-allocation');
- L344: Route::get('/reports/community-access', [CommunityAccessController::class, 'index'])->name('fleet-assets.reports.community-access');

## routes/fleet.php
- L22: Route::permanentRedirect('/fleet-management', '/fleet-assets')->name('fleet.index');
- L23: Route::permanentRedirect('/fleet/fuel', '/fleet-assets/fuel')->name('fleet.fuel.index');
- L24: Route::permanentRedirect('/fleet/reports', '/fleet-assets/reports')->name('fleet.reports.index');
- L25: Route::get('/fleet/vehicles/{asset}', fn (int $asset) => redirect("/fleet-assets/vehicles/{$asset}", 301))
- L29: Route::get('/fleet/trips/{trip}', fn (int $trip) => redirect("/fleet-assets/trips/{$trip}/playback", 301))
- L33: Route::get('/fleet/trips/{trip}/playback', fn (int $trip) => redirect("/fleet-assets/trips/{$trip}/playback/data", 301))
- L37: Route::middleware(['auth'])->group(function () {
- L39: Route::middleware('permission:fleet.viewAny')->group(function () {
- L40: Route::get('/fleet-management/maps-usage', FleetMapUsageDashboardController::class)
- L45: Route::middleware('permission:fleet.trips.manage')->group(function () {
- L46: Route::put('/fleet/trips/{trip}', [FleetTripController::class, 'update'])
- L49: Route::post('/fleet/trips/{trip}/close', [FleetTripController::class, 'close'])
- L52: Route::delete('/fleet/trips/{trip}', [FleetTripController::class, 'destroy'])

## routes/assets.php
- L27: Route::post('/telemetry/ingest/{vendor}', [AssetTelemetryIngestController::class, 'store'])
- L31: Route::middleware(['auth'])->group(function () {
- L33: Route::middleware('permission:sites.viewAny')->group(function () {
- L34: Route::get('/sites', [SiteController::class, 'index'])->name('sites.index');
- L35: Route::get('/sites/{site}', SiteProfileController::class)
- L40: Route::get('/sites/{site}/documents', [SiteDocumentController::class, 'index'])
- L43: Route::get('/sites/{site}/documents/{document}/download', [SiteDocumentController::class, 'download'])
- L48: Route::middleware('permission:sites.create')->group(function () {
- L49: Route::get('/sites/create', [SiteController::class, 'create'])->name('sites.create');
- L50: Route::post('/sites', [SiteController::class, 'store'])->name('sites.store');
- L53: Route::middleware('permission:sites.update')->group(function () {
- L54: Route::get('/sites/{site}/edit', [SiteController::class, 'edit'])
- L57: Route::put('/sites/{site}', [SiteController::class, 'update'])
- L62: Route::patch('/sites/{site}/contact-info', [SiteController::class, 'updateContactInfo'])
- L65: Route::patch('/sites/{site}/location', [SiteController::class, 'updateLocation'])
- L68: Route::patch('/sites/{site}/safety', [SiteController::class, 'updateSafety'])
- L73: Route::patch('/sites/{site}/active', [SiteController::class, 'toggleActive'])
- L78: Route::post('/sites/{site}/notes', [\App\Http\Controllers\Sites\SiteNoteController::class, 'store'])
- L81: Route::delete('/sites/{site}/notes/{note}', [\App\Http\Controllers\Sites\SiteNoteController::class, 'destroy'])
- L87: Route::get('/sites/geocode/search', [\App\Http\Controllers\Sites\SiteGeocodingController::class, 'search'])
- L91: Route::post('/sites/{site}/clients/link', [SiteClientController::class, 'link'])
- L94: Route::post('/sites/{site}/clients/{client}/unlink', [SiteClientController::class, 'unlink'])
- L100: Route::post('/sites/{site}/contacts', [SiteContactController::class, 'store'])
- L103: Route::put('/sites/{site}/contacts/{contact}', [SiteContactController::class, 'update'])
- L106: Route::delete('/sites/{site}/contacts/{contact}', [SiteContactController::class, 'destroy'])
- L111: Route::post('/sites/{site}/document-folders', [SiteDocumentController::class, 'storeFolder'])
- L114: Route::post('/sites/{site}/documents', [SiteDocumentController::class, 'store'])
- L117: Route::put('/sites/{site}/documents/{document}', [SiteDocumentController::class, 'update'])
- L120: Route::delete('/sites/{site}/documents/{document}', [SiteDocumentController::class, 'destroy'])
- L127: Route::middleware('permission:sites.archive')->group(function () {
- L128: Route::post('/sites/bulk/archive', [SiteController::class, 'bulkArchive'])
- L130: Route::patch('/sites/{site}/archive', [SiteController::class, 'archive'])
- L133: Route::patch('/sites/{site}/unarchive', [SiteController::class, 'unarchive'])
- L140: Route::permanentRedirect('/assets', '/fleet-assets/assets')->name('assets.index');
- L141: Route::permanentRedirect('/assets/alerts', '/fleet-assets/alerts')->name('assets.alerts.index');
- L142: Route::get('/assets/{asset}', fn (int $asset) => redirect("/fleet-assets/assets/{$asset}", 301))
- L146: Route::middleware('permission:assets.viewAny|assets.viewAssigned')->group(function () {
- L148: Route::get('/assets/qr/{token}', [AssetQrController::class, 'redirectByToken'])
- L152: Route::middleware(['throttle:qr-generation'])->group(function () {
- L153: Route::get('/assets/{asset}/qr.png', [AssetQrController::class, 'png'])
- L156: Route::get('/assets/{asset}/qr.svg', [AssetQrController::class, 'svg'])
- L159: Route::get('/assets/{asset}/qr.png/download', [AssetQrController::class, 'downloadPng'])
- L165: Route::get('/assets/{asset}/documents/{document}/download', [AssetDocumentController::class, 'download'])
- L171: Route::middleware('permission:assets.delete')->group(function () {
- L172: Route::delete('/assets/{asset}', [AssetController::class, 'destroy'])
- L178: Route::middleware('permission:assets.inspections.record')->group(function () {
- L179: Route::post('/assets/{asset}/inspections', [AssetInspectionController::class, 'store'])
- L185: Route::middleware('permission:assets.maintenance.record')->group(function () {
- L186: Route::post('/assets/{asset}/maintenance', [AssetMaintenanceController::class, 'store'])
- L192: Route::middleware('permission:assets.documents.manage')->group(function () {
- L193: Route::post('/assets/{asset}/documents', [AssetDocumentController::class, 'store'])
- L196: Route::delete('/assets/{asset}/documents/{document}', [AssetDocumentController::class, 'destroy'])
- L201: Route::middleware('permission:assets.scan.record')->group(function () {
- L202: Route::post('/assets/{asset}/scan-events', [AssetScanEventController::class, 'store'])
- L207: Route::middleware('permission:assets.ownership.manage')->group(function () {
- L208: Route::post('/assets/{asset}/ownerships', [AssetOwnershipController::class, 'store'])
- L213: Route::middleware('permission:assets.assignments.manage')->group(function () {
- L214: Route::post('/assets/{asset}/assignments', [AssetAssignmentController::class, 'store'])
- L217: Route::post('/assets/{asset}/assignments/{assignment}/release', [AssetAssignmentController::class, 'release'])
- L223: Route::middleware('permission:assets.geofences.manage')->group(function () {
- L224: Route::post('/assets/{asset}/geofences', [AssetGeofenceController::class, 'store'])
- L227: Route::delete('/assets/{asset}/geofences/{geofence}', [AssetGeofenceController::class, 'destroy'])
- L233: Route::middleware('permission:assets.telemetry.ingest')->group(function () {
- L235: Route::post('/telemetry/ingest/{vendor}/staff', [AssetTelemetryIngestController::class, 'store'])

## Fleet page and modal source inventory

### resources/js/pages/fleet-assets/alerts/index.tsx
- L171: <WizardShell
- L324: function handleSort(field: string) {
- L427: const handleBulkAction = useCallback(
- L439: router.post(
- L474: router.post(
- L494: router.post(
- L855: router.post(
- L869: router.post(

### resources/js/pages/fleet-assets/assets/components/asset-wizard-dialog.tsx
- L425: const handleClientChange = (value: string) => {
- L507: router.put(`/fleet-assets/assets/${asset.id}`, payload, options);
- L511: router.post(
- L597: <WizardShell

### resources/js/pages/fleet-assets/assets/index.tsx
- L182: const handleSearch = () => {
- L273: <TabsTrigger value="all" className="px-3 py-1 text-xs">
- L276: <TabsTrigger
- L282: <TabsTrigger
- L288: <TabsTrigger
- L294: <TabsTrigger

### resources/js/pages/fleet-assets/assets/show.tsx
- L116: <WizardShell
- L673: router.post(`/assets/${asset.id}/documents`, formData, {
- L953: <TabsTrigger value="overview">Overview</TabsTrigger>
- L954: <TabsTrigger value="lifecycle">Lifecycle</TabsTrigger>
- L955: <TabsTrigger value="documents">Documents</TabsTrigger>
- L956: <TabsTrigger value="maintenance">
- L959: <TabsTrigger value="inspections">
- L962: <TabsTrigger value="technology">
- L965: <TabsTrigger value="alerts">
- L968: <TabsTrigger value="assignments">

### resources/js/pages/fleet-assets/bookings/book-vehicle-wizard.tsx
- L465: <WizardShell

### resources/js/pages/fleet-assets/bookings/index.tsx

### resources/js/pages/fleet-assets/bookings/show.tsx
- L445: router.post(
- L661: router.post(`/fleet-assets/bookings/${b.id}/cancel`)
- L697: router.post(

### resources/js/pages/fleet-assets/compliance/index.tsx
- L147: const handleSearch = () => {

### resources/js/pages/fleet-assets/components/fleet-compact-hero.tsx

### resources/js/pages/fleet-assets/components/fleet-hero-kit.tsx

### resources/js/pages/fleet-assets/components/fleet-responsive-list.tsx

### resources/js/pages/fleet-assets/daily-check.tsx
- L83: const handleSubmit = (vehicleId: number, condition: 'good' | 'issue') => {
- L85: router.post(

### resources/js/pages/fleet-assets/dashboard.tsx
- L465: const handleScopeChange = (key: string) => {

### resources/js/pages/fleet-assets/devices/index.tsx
- L336: function handleSort(field: string) {
- L458: const handlePair = () => {
- L532: const handleGrant = (device: DeviceConsent) => {
- L534: router.post(
- L544: const handleRevoke = () => {
- L554: router.post(
- L1054: <WizardShell
- L1145: <WizardShell
- L1334: <WizardShell
- L1636: router.post(

### resources/js/pages/fleet-assets/drivers/index.tsx
- L138: function handleSort(field: string) {
- L177: const handleSearch = () => {

### resources/js/pages/fleet-assets/drivers/show.tsx
- L335: <TabsTrigger value="overview">Overview</TabsTrigger>
- L336: <TabsTrigger value="scorecard">Scorecard</TabsTrigger>

### resources/js/pages/fleet-assets/fuel/index.tsx
- L169: function handleSort(field: string) {
- L243: const handleSubmit = () => {
- L348: <WizardShell

### resources/js/pages/fleet-assets/geofences/create.tsx
- L188: const handleSiteQuickFill = useCallback(
- L257: router.put(
- L263: router.post('/fleet-assets/geofences', payload, options);
- L281: <WizardShell

### resources/js/pages/fleet-assets/geofences/index.tsx
- L207: const handleFilterChange = useCallback(
- L221: const handleToggle = useCallback((gf: Geofence) => {
- L222: router.post(
- L232: const handleDelete = useCallback((gf: Geofence) => {
- L233: router.delete(`/fleet-assets/geofences/${gf.id}`, {

### resources/js/pages/fleet-assets/handovers/index.tsx
- L346: <WizardShell

### resources/js/pages/fleet-assets/handovers/show.tsx
- L141: const handleAccept = () => {
- L142: router.post(`/fleet-assets/handovers/${h.id}/accept`);
- L145: const handleDispute = (e: React.FormEvent) => {

### resources/js/pages/fleet-assets/incidents/index.tsx
- L892: <TabStrip

### resources/js/pages/fleet-assets/inspections/create-wizard.tsx
- L247: <WizardShell

### resources/js/pages/fleet-assets/inspections/index.tsx

### resources/js/pages/fleet-assets/inspections/show.tsx

### resources/js/pages/fleet-assets/keys/index.tsx
- L153: const handleCheckout = () => {
- L154: router.post(
- L172: const handleReturn = () => {
- L173: router.post(
- L190: const handleTransfer = () => {
- L191: router.post(

### resources/js/pages/fleet-assets/maintenance/checklists/index.tsx
- L121: const handleCreateTemplate = () => {
- L214: <WizardShell

### resources/js/pages/fleet-assets/maintenance/checklists/run.tsx
- L56: const handleTemplateChange = (templateId: string) => {
- L61: const handleResponseChange = (
- L71: const handleSubmit = (e: React.FormEvent) => {

### resources/js/pages/fleet-assets/maintenance/components/hero-action-button.tsx

### resources/js/pages/fleet-assets/maintenance/dashboard.tsx
- L449: const handlePeriodChange = (val: string) => {

### resources/js/pages/fleet-assets/maintenance/schedules/index.tsx
- L232: function handleSort(field: string) {
- L244: function handleMarkComplete(scheduleId: number) {
- L246: router.post(
- L291: const handleCreate = () => {
- L349: <WizardShell

### resources/js/pages/fleet-assets/maintenance/work-orders/create-wizard.tsx
- L252: <WizardShell

### resources/js/pages/fleet-assets/maintenance/work-orders/index.tsx
- L183: function handleSort(field: string) {
- L247: const handleBulkAction = useCallback(
- L256: router.post(

### resources/js/pages/fleet-assets/maintenance/work-orders/show.tsx
- L99: const handleUpdate = (e: React.FormEvent) => {

### resources/js/pages/fleet-assets/map.tsx
- L234: const handleMarkerClick = useCallback((id: string | number) => {

### resources/js/pages/fleet-assets/mileage/index.tsx
- L210: const handleApprove = (tripId: number) => {
- L211: router.post(
- L218: const handleReject = (tripId: number) => {
- L219: router.post(
- L226: const handleMarkPaid = (tripId: number) => {
- L227: router.post(
- L836: <WizardShell

### resources/js/pages/fleet-assets/outings/create.tsx
- L377: const handleSubmit = useCallback(
- L390: <WizardShell

### resources/js/pages/fleet-assets/outings/index.tsx

### resources/js/pages/fleet-assets/outings/show.tsx
- L219: router.post(
- L283: router.post(
- L563: router.post(
- L774: router.post(
- L818: router.post(

### resources/js/pages/fleet-assets/reports/by-house.tsx
- L73: const handleHouseChange = (value: string) => {
- L84: const handleMonthChange = (value: string) => {

### resources/js/pages/fleet-assets/reports/community-access.tsx
- L79: const handlePeriodChange = (value: string) => {
- L87: const handleExport = () => {

### resources/js/pages/fleet-assets/reports/cost-allocation.tsx
- L227: const handlePeriodChange = (value: string) => {
- L235: const handleExport = (tab: string) => {
- L311: <TabsTrigger value="house">
- L314: <TabsTrigger value="resident">

### resources/js/pages/fleet-assets/reports/index.tsx
- L203: const handlePeriodChange = (newPeriod: string) => {

### resources/js/pages/fleet-assets/reports/reimbursement.tsx
- L43: const handleGenerate = useCallback(async () => {
- L87: const handleExportCSV = () => {

### resources/js/pages/fleet-assets/resident-tracking/history.tsx
- L343: const handleRangeClick = (value: string) => {
- L350: const handleEventTypeToggle = (type: string) => {
- L358: const handleSafetyOnly = () => {
- L363: const handleClearEventTypes = () => {
- L368: const handleTimelineEventTypeToggle = (type: string) => {
- L376: const handleTimelineSafetyOnly = (checked: boolean | 'indeterminate') => {
- L380: const handleTimelineClear = () => {
- L462: const handleMarkerClick = useCallback((id: string | number) => {

### resources/js/pages/fleet-assets/resident-tracking/index.tsx
- L294: const handleAcknowledge = (alertId: number) => {
- L295: router.post(
- L302: const handleResolve = (alertId: number) => {
- L303: router.post(
- L310: const handleStatusFilter = (value: string) => {
- L589: const handleAssign = () => {
- L601: const handleUnassign = (trackerId: number) => {
- L602: router.post(
- L616: <WizardShell
- L1100: const handleMarkerClick = useCallback((id: string | number) => {
- L1111: const handleLocateNow = useCallback((resident: Resident) => {
- L1113: router.post(resident.locate_now_url, {}, { preserveScroll: true });
- L1116: const handleAcknowledgePanic = useCallback((resident: Resident) => {
- L1118: router.post(
- L1125: const handleOpenProfile = useCallback((resident: Resident) => {

### resources/js/pages/fleet-assets/settings/notifications.tsx
- L188: const handleSave = () => {

### resources/js/pages/fleet-assets/transports/components/transport-medication-dialogs.tsx
- L407: <WizardShell
- L957: <WizardShell
- L1251: <WizardShell
- L1572: <WizardShell

### resources/js/pages/fleet-assets/transports/create.tsx
- L321: const handleClientChange = useCallback(
- L345: const handleShiftChange = useCallback(
- L391: const handleMedToggle = useCallback(
- L546: const handleSubmit = useCallback(
- L555: <WizardShell

### resources/js/pages/fleet-assets/transports/index.tsx
- L224: const handleSearch = () => {

### resources/js/pages/fleet-assets/transports/medications.tsx

### resources/js/pages/fleet-assets/transports/pre-check.tsx
- L139: const handleSubmit = () => {
- L141: router.post(

### resources/js/pages/fleet-assets/transports/show.tsx
- L323: const handleComplete = (e: React.FormEvent) => {

### resources/js/pages/fleet-assets/trips/index.tsx
- L200: function handleSort(field: string) {
- L251: const handleSearch = () => {
- L732: router.post(

### resources/js/pages/fleet-assets/trips/playback.tsx
- L100: const handleClose = () => {
- L102: router.post(
- L112: const handleDelete = () => {
- L113: router.delete(`/fleet/trips/${trip.id}`);
- L116: const handleAssignDriver = () => {
- L119: router.put(

### resources/js/pages/fleet-assets/vehicles/alerts-config.tsx
- L199: const handleSave = () => {
- L205: router.post(

### resources/js/pages/fleet-assets/vehicles/index.tsx
- L177: const handleBulkAction = useCallback(
- L187: router.post('/fleet-assets/vehicles/bulk-action', payload as any, {

### resources/js/pages/fleet-assets/vehicles/show.tsx
- L367: <TabsTrigger value="operations">
- L371: <TabsTrigger value="technology">
- L762: router.put(
- L896: router.put(
- L1056: router.put(
- L1515: router.put(
- L1572: router.put(
- L1629: router.put(
- L1700: router.put(

### resources/js/pages/fleet-assets/vehicles/vehicle-technology-projection.tsx

## Jobs and commands requiring lifecycle regression coverage
- app/Console/Commands/ExpireStaleConsentRequests.php
- app/Console/Commands/FleetGeocoderStatus.php
- app/Console/Commands/FleetReverseGeocodeBackfill.php
- app/Console/Commands/SendConsentRequestReminders.php
- app/Jobs/DetectFleetOfflineDevices.php
- app/Jobs/DispatchFleetMonitoringTicket.php
- app/Jobs/DispatchFleetSignalOutbox.php
- app/Jobs/FleetAutoAlertJob.php
- app/Jobs/PruneAssetTelemetry.php
- app/Jobs/PruneFleetTelemetry.php
- app/Jobs/PrunePersonalTrackingTelemetry.php
- app/Jobs/ReverseGeocodeFleetTelemetryEvent.php
- app/Jobs/ReverseGeocodeFleetTrip.php
- app/Jobs/SummarizeAssetTelemetry.php

## Supplementary control declarations

Mechanical anchors for buttons, menu actions, forms/fields, tabs and overlays in all non-test Fleet TSX sources, legacy map usage and shared Fleet/HR asset dialogs. Read adjacent lines for multi-line labels/handlers and permission conditions. Repeated controls and dynamically generated actions are interpreted with the page-family inventory; declaration is not runtime verification.

### resources/js/components/fleet/fleet-incident-dialog.tsx
- L365: <Button size="sm" onClick={() => setAction('status')}>
- L374: <Button
- L377: onClick={() => setAction('followup')}
- L382: <Button
- L385: onClick={() => setAction('police_report')}
- L392: <Button
- L395: onClick={() => setAction('claim')}
- L401: <Button
- L404: onClick={() => setAction('back_in_service')}
- L410: <Button
- L413: onClick={() => setAction('off_road')}
- L425: <WizardShell
- L1132: <Button
- L1135: onClick={() =>
- L1225: <Button
- L1228: onClick={() => completeFollowup(f.id)}
- L1409: <Button type="button" variant="outline" onClick={onCancel}>
- L1412: <Button type="submit" disabled={processing}>
- L1446: <form onSubmit={submit} className="flex flex-col gap-4">
- L1475: <Textarea
- L1515: <form onSubmit={submit} className="flex flex-col gap-4">
- L1522: <Textarea
- L1533: <select
- L1549: <Input
- L1591: <form onSubmit={submit} className="flex flex-col gap-4">
- L1602: <Input
- L1616: <Input
- L1627: <Input
- L1635: <Input
- L1687: <form onSubmit={submit} className="flex flex-col gap-4">
- L1695: <Input
- L1706: <Input
- L1714: <Input
- L1727: <Input
- L1743: <Input
- L1783: <form onSubmit={submit} className="flex flex-col gap-4">
- L1791: <Input
- L1800: <Input
- L1836: <form onSubmit={submit} className="flex flex-col gap-4">
- L1846: <Input

### resources/js/components/fleet/fleet-incident-report-dialog.tsx
- L763: <Button onClick={() => onOpenIncident(newId)}>
- L767: <Button variant="outline" onClick={reset}>
- L770: <Button variant="ghost" onClick={onClose}>
- L779: <WizardShell
- L796: <Button
- L798: onClick={() =>
- L806: <Button
- L807: onClick={() =>
- L817: <Button
- L818: onClick={submit}
- L839: <Input
- L855: <Input
- L902: <Input
- L911: <Input
- L921: <Textarea
- L950: <Textarea
- L997: <Input
- L1007: <Input
- L1020: <Input
- L1029: <Input
- L1049: <Input
- L1085: <Input
- L1096: <Input
- L1110: <Input
- L1124: <Input
- L1138: <Input
- L1152: <Input
- L1176: <Input
- L1196: <Input
- L1217: <Button
- L1221: onClick={() =>
- L1234: <Button
- L1239: onClick={() =>
- L1310: <Input
- L1343: <input
- L1388: <Input
- L1409: <input
- L1426: <Input
- L1440: <input
- L1454: <input
- L1480: <Input
- L1499: <Input
- L1529: <Input
- L1547: <Textarea
- L1567: <Textarea
- L1624: <Input
- L1637: <Input
- L1666: <Input
- L1682: <Input
- L1713: <Input
- L1722: <Input
- L1732: <Textarea
- L1782: <Input
- L1905: <Input
- L1916: <Input
- L1927: <Input

### resources/js/components/fleet/fleet-telematics-storyboard.tsx
- L116: onClick={onClose}
- L120: onClick={(e) => e.stopPropagation()}
- L139: <Button
- L142: onClick={onClose}
- L215: <Button
- L218: onClick={() =>
- L226: <Button
- L228: onClick={() =>
- L235: <Button size="sm" onClick={onClose}>

### resources/js/components/hr/asset-wizards.tsx
- L338: <WizardShell
- L366: <Button variant="outline" onClick={reset}>
- L370: <Button onClick={onClose}>Done</Button>
- L378: <Button variant="outline" onClick={wizard.back}>
- L385: <Button variant="ghost" onClick={onClose}>
- L389: <Button
- L390: onClick={submit}
- L400: <Button
- L401: onClick={wizard.next}
- L433: <Button
- L447: <Input
- L458: <Input
- L510: <Input
- L519: <Input
- L528: <Input
- L564: <Input
- L576: <Input
- L591: <Input
- L600: <Input
- L630: <Input
- L681: <Button
- L685: onClick={() =>
- L722: <Textarea
- L879: <Button
- L882: onClick={onUnlink}
- L891: <Input
- L907: <Button
- L911: onClick={() => onPick(m)}
- L1021: <WizardShell
- L1043: actions={<Button onClick={onClose}>Done</Button>}
- L1049: <Button variant="outline" onClick={wizard.back}>
- L1056: <Button variant="ghost" onClick={onClose}>
- L1060: <Button
- L1061: onClick={submit}
- L1067: <Button
- L1068: onClick={wizard.next}
- L1086: <Input
- L1098: <Button
- L1102: onClick={() =>
- L1147: <Input
- L1156: <Input
- L1178: <Textarea
- L1199: <input
- L1338: <WizardShell
- L1363: actions={<Button onClick={onClose}>Done</Button>}
- L1369: <Button variant="outline" onClick={wizard.back}>
- L1376: <Button variant="ghost" onClick={onClose}>
- L1380: <Button onClick={submit} disabled={form.processing}>
- L1384: <Button onClick={wizard.next}>Continue</Button>
- L1398: <Input
- L1418: <Textarea
- L1429: <input
- L1545: <WizardShell
- L1562: actions={<Button onClick={onClose}>Done</Button>}
- L1568: <Button variant="outline" onClick={wizard.back}>
- L1575: <Button variant="ghost" onClick={onClose}>
- L1579: <Button onClick={submit} disabled={form.processing}>
- L1583: <Button onClick={wizard.next}>Continue</Button>
- L1608: <Input
- L1617: <Input
- L1629: <Input
- L1638: <Input
- L1650: <Input
- L1661: <Textarea
- L1764: <WizardShell
- L1780: actions={<Button onClick={onClose}>Done</Button>}
- L1786: <Button variant="ghost" onClick={onClose}>
- L1789: <Button onClick={submit} disabled={form.processing}>
- L1814: <Input
- L1833: <Input
- L1899: <WizardShell
- L1916: actions={<Button onClick={onClose}>Done</Button>}
- L1922: <Button variant="outline" onClick={wizard.back}>
- L1929: <Button variant="ghost" onClick={onClose}>
- L1933: <Button
- L1935: onClick={submit}
- L1941: <Button onClick={wizard.next}>Continue</Button>
- L1969: <Input
- L1978: <Input
- L1995: <Textarea

### resources/js/pages/fleet-assets/alerts/index.tsx
- L171: <WizardShell
- L185: <Button type="button" variant="outline" onClick={close}>
- L191: <Button
- L194: onClick={() => setStepIndex(1)}
- L200: <Button
- L203: onClick={() => setStepIndex(0)}
- L207: <Button type="button" onClick={onSubmit}>
- L223: <Textarea
- L345: onClick={() => handleSort(field)}
- L673: <Select
- L690: <Select
- L718: <input
- L767: <input
- L852: <Button
- L854: onClick={() =>
- L866: <Button
- L868: onClick={() =>
- L880: <Button
- L883: onClick={() =>
- L1027: <Button
- L1030: onClick={() => setBulkAction('acknowledge')}
- L1037: <Button
- L1040: onClick={() => setBulkAction('triage')}
- L1046: <Button
- L1049: onClick={openBulkResolve}
- L1056: <Button
- L1059: onClick={() => setSelectedIds([])}
- L1070: <Button
- L1075: onClick={() => link.url && router.get(link.url)}
- L1086: onSubmit={submitResolve}

### resources/js/pages/fleet-assets/assets/components/asset-wizard-dialog.tsx
- L539: <Button onClick={onClose}>Done</Button>
- L542: <Button variant="outline" onClick={resetAll}>
- L546: <Button asChild>
- L554: <Button onClick={onClose}>Done</Button>
- L567: <Button variant="ghost" onClick={back} disabled={processing}>
- L571: <Button variant="outline" onClick={onClose} disabled={processing}>
- L575: <Button onClick={submit} disabled={processing}>
- L589: <Button onClick={next}>
- L597: <WizardShell
- L665: <Input
- L677: <Input
- L741: <Input
- L750: <Input
- L757: <Input
- L766: <Textarea
- L782: <Input
- L805: <Input
- L823: <Input
- L835: <Input
- L920: <Input
- L954: <Input
- L969: <Input
- L981: <Input
- L997: <Switch
- L1017: <Input
- L1033: <Switch
- L1053: <Input
- L1073: <Textarea

### resources/js/pages/fleet-assets/assets/index.tsx
- L273: <TabsTrigger value="all" className="px-3 py-1 text-xs">
- L276: <TabsTrigger
- L282: <TabsTrigger
- L288: <TabsTrigger
- L294: <TabsTrigger
- L307: <Input
- L317: <Select
- L337: <Select
- L440: <Button
- L445: onClick={() => link.url && router.get(link.url)}

### resources/js/pages/fleet-assets/assets/show.tsx
- L116: <WizardShell
- L130: <Button
- L133: onClick={close}
- L141: <Button
- L144: onClick={() => setStepIndex(1)}
- L150: <Button
- L153: onClick={() => setStepIndex(0)}
- L158: <Button
- L160: onClick={onSubmit}
- L179: <Input
- L196: <Input
- L213: <Select
- L870: <Button
- L874: onClick={() => setEditOpen(true)}
- L880: <Button
- L884: onClick={() => setActiveTab('assignments')}
- L953: <TabsTrigger value="overview">Overview</TabsTrigger>
- L954: <TabsTrigger value="lifecycle">Lifecycle</TabsTrigger>
- L955: <TabsTrigger value="documents">Documents</TabsTrigger>
- L956: <TabsTrigger value="maintenance">
- L959: <TabsTrigger value="inspections">
- L962: <TabsTrigger value="technology">
- L965: <TabsTrigger value="alerts">
- L968: <TabsTrigger value="assignments">
- L1271: <Button
- L1274: onClick={() => {
- L1307: <Button
- L1342: onSubmit={submitDocument}
- L1466: <Button variant="outline" size="sm" asChild>

### resources/js/pages/fleet-assets/bookings/book-vehicle-wizard.tsx
- L465: <WizardShell
- L499: <button
- L501: onClick={() => setStep((s) => s - 1)}
- L508: <button
- L511: onClick={() =>
- L542: <Select
- L630: <Select
- L692: <Input
- L708: <Input
- L855: <Input
- L868: <Input
- L880: <Input
- L895: <Select
- L920: <Select
- L946: <textarea

### resources/js/pages/fleet-assets/bookings/index.tsx
- L207: <Button
- L210: onClick={() =>
- L217: <Button
- L220: onClick={() =>
- L226: <Button
- L229: onClick={() =>
- L504: <button
- L506: onClick={() => setWizardOpen(true)}
- L525: <Button
- L529: onClick={() => switchView('list')}
- L535: <Button
- L539: onClick={() => switchView('calendar')}
- L547: <Select
- L669: <Button
- L676: onClick={() =>

### resources/js/pages/fleet-assets/bookings/show.tsx
- L442: <Button
- L444: onClick={() =>
- L454: <Button
- L457: onClick={() =>
- L492: <form
- L493: onSubmit={(e) => {
- L505: <Input
- L519: <Button
- L556: <form
- L557: onSubmit={(e) => {
- L569: <Input
- L585: <Input
- L603: <textarea
- L617: <Button
- L635: <Button
- L637: onClick={() => setShowCancelDialog(true)}
- L681: <textarea
- L690: onClick={() => setShowRejectDialog(false)}
- L696: onClick={() => {

### resources/js/pages/fleet-assets/compliance/index.tsx
- L280: <Input
- L290: <Select
- L361: onClick={() =>
- L515: <Button
- L519: onClick={(e) =>

### resources/js/pages/fleet-assets/components/fleet-compact-hero.tsx

### resources/js/pages/fleet-assets/components/fleet-hero-kit.tsx
- L416: <button type="button" onClick={props.onClick} className={className}>

### resources/js/pages/fleet-assets/components/fleet-responsive-list.tsx

### resources/js/pages/fleet-assets/daily-check.tsx
- L284: <Button
- L287: onClick={() =>
- L310: <Input
- L325: <Button
- L326: onClick={() =>
- L341: <Button
- L343: onClick={() =>
- L357: <Button
- L360: onClick={() =>

### resources/js/pages/fleet-assets/dashboard.tsx
- L935: <Button
- L944: onClick={() => setMapFilter(tab)}
- L1111: <Button
- L1261: <Button
- L1369: <Button

### resources/js/pages/fleet-assets/devices/index.tsx
- L357: onClick={() => handleSort(field)}
- L672: <Button
- L675: onClick={() => {
- L708: <Button
- L712: onClick={() => switchTab(t.key)}
- L759: onClick={() =>
- L780: onClick={(e) =>
- L841: <Button
- L848: onClick={() =>
- L870: <Input
- L1000: <Button
- L1003: onClick={() =>
- L1016: <Button
- L1019: onClick={() =>
- L1054: <WizardShell
- L1071: <Button
- L1074: onClick={closeRevokeDialog}
- L1081: <Button
- L1084: onClick={handleRevoke}
- L1115: <Textarea
- L1145: <WizardShell
- L1160: <Button
- L1163: onClick={closePairDialog}
- L1170: <Button
- L1173: onClick={() => setPairStepIndex(1)}
- L1179: <Button
- L1182: onClick={() => setPairStepIndex(0)}
- L1186: <Button
- L1188: onClick={handlePair}
- L1212: <Input
- L1222: <Select
- L1254: <Input
- L1264: <Select
- L1334: <WizardShell
- L1351: <Button
- L1354: onClick={closeDevice}
- L1361: <Button
- L1364: onClick={() => setShowUnpairDialog(true)}

### resources/js/pages/fleet-assets/drivers/index.tsx
- L159: onClick={() => handleSort(field)}
- L311: <Input
- L321: <Select
- L386: onClick={() =>
- L496: <Button
- L501: onClick={() => link.url && router.get(link.url)}

### resources/js/pages/fleet-assets/drivers/show.tsx
- L273: onClick={() => openTab('scorecard')}
- L335: <TabsTrigger value="overview">Overview</TabsTrigger>
- L336: <TabsTrigger value="scorecard">Scorecard</TabsTrigger>
- L810: <Select

### resources/js/pages/fleet-assets/fuel/index.tsx
- L190: onClick={() => handleSort(field)}
- L325: <Button
- L328: onClick={() => {
- L348: <WizardShell
- L363: <Button
- L366: onClick={closeFuelDialog}
- L373: <Button
- L376: onClick={() => setFuelStepIndex(1)}
- L382: <Button
- L385: onClick={() => setFuelStepIndex(0)}
- L389: <Button
- L391: onClick={handleSubmit}
- L409: <Select
- L440: <Input
- L461: <Input
- L481: <Input
- L509: <Input
- L534: <Select
- L566: <Input
- L581: <Input
- L594: <input
- L691: <Input
- L702: <Input
- L713: <Select
- L874: <Button
- L879: onClick={() => link.url && router.get(link.url)}

### resources/js/pages/fleet-assets/geofences/create.tsx
- L281: <WizardShell
- L304: <Button type="button" variant="ghost" onClick={onClose}>
- L311: <Button
- L314: onClick={() => setStepIndex(stepIndex - 1)}
- L320: <Button type="button" onClick={goNext}>
- L324: <Button
- L326: onClick={submit}
- L345: <Input
- L363: <Textarea
- L376: <Button
- L380: onClick={() => setScope(value)}
- L399: <Select
- L422: <Select
- L473: <Select
- L497: <Select
- L545: <input
- L565: <Input
- L578: <Input
- L589: <input

### resources/js/pages/fleet-assets/geofences/index.tsx
- L303: <button
- L305: onClick={() => setShowFilters(!showFilters)}
- L323: <Input
- L333: <Select
- L357: <Select
- L381: <Select
- L438: onClick={() =>
- L539: onClick={(e) =>
- L589: onClick={(e) => e.stopPropagation()}
- L591: <Button
- L604: <Button
- L608: onClick={() => handleToggle(gf)}
- L624: <Button
- L649: onClick={() =>
- L670: <Button asChild className="mt-4" size="sm">

### resources/js/pages/fleet-assets/handovers/index.tsx
- L346: <WizardShell
- L359: <Button
- L362: onClick={() => setStepIndex((i) => Math.max(0, i - 1))}
- L370: <Button
- L372: onClick={() =>
- L382: <Button
- L384: onClick={submit}
- L398: <Select
- L429: <Input
- L440: <Select
- L474: <Input
- L503: <Button
- L507: onClick={() =>
- L586: <Button
- L590: onClick={() =>
- L653: <Button
- L657: onClick={() =>
- L749: <Switch
- L768: <Button
- L772: onClick={addDamageNote}
- L792: <Select
- L822: <Input
- L834: <Button
- L838: onClick={() =>
- L865: <textarea
- L1118: <Select
- L1146: <Select
- L1175: <Input
- L1188: <Input
- L1297: <Button
- L1322: <Button
- L1329: onClick={() =>

### resources/js/pages/fleet-assets/handovers/show.tsx
- L463: <Button
- L464: onClick={handleAccept}
- L472: <form
- L473: onSubmit={handleDispute}
- L479: <textarea
- L503: <Button

### resources/js/pages/fleet-assets/incidents/index.tsx
- L395: <input
- L406: <input
- L651: <input
- L676: <button
- L678: onClick={clearFilters}
- L718: <Button
- L730: <Button
- L733: onClick={() =>
- L749: <Button
- L752: onClick={() => openReport('asset')}
- L766: <Button
- L769: onClick={() =>
- L789: <button
- L791: onClick={() => setTelematicsOpen(true)}
- L892: <TabStrip
- L1075: onClick={() => onOpen(i.id)}

### resources/js/pages/fleet-assets/inspections/create-wizard.tsx
- L247: <WizardShell
- L262: <Button
- L264: onClick={() => setStepIndex(stepIndex - 1)}
- L269: <Button variant="ghost" onClick={close}>
- L278: <Button
- L279: onClick={() => setStepIndex(stepIndex + 1)}
- L285: <Button
- L286: onClick={submit}
- L303: <Select
- L364: <button
- L367: onClick={() =>
- L404: <Input
- L423: <Select
- L491: <Button
- L495: onClick={() =>
- L513: <Button
- L517: onClick={() =>
- L535: <Button
- L539: onClick={() =>
- L558: <Input
- L635: <Select
- L680: <textarea
- L697: <textarea
- L717: <textarea

### resources/js/pages/fleet-assets/inspections/index.tsx
- L219: <HeroActionButton
- L220: onClick={() => setWizardOpen(true)}
- L240: <Input
- L257: <Select
- L285: <Select
- L311: <Input
- L323: <Input
- L329: <Button onClick={applyFilters} size="sm">
- L332: <Button
- L335: onClick={clearFilters}
- L424: <Button

### resources/js/pages/fleet-assets/inspections/show.tsx

### resources/js/pages/fleet-assets/keys/index.tsx
- L267: <Button
- L268: onClick={() => {
- L277: <Button
- L279: onClick={() => {
- L288: <Button
- L290: onClick={() => {
- L315: <Select
- L333: <Select
- L351: <Input
- L358: <Input
- L365: <Button
- L366: onClick={handleCheckout}
- L371: <Button
- L373: onClick={() => setShowCheckout(false)}
- L390: <Select
- L408: <Select
- L427: <Input
- L434: <Input
- L441: <Button
- L442: onClick={handleReturn}
- L447: <Button
- L449: onClick={() => setShowReturn(false)}
- L466: <Select
- L484: <Select
- L502: <Input
- L509: <Input
- L516: <Button
- L517: onClick={handleTransfer}
- L524: <Button
- L526: onClick={() => setShowTransfer(false)}
- L538: <Input

### resources/js/pages/fleet-assets/maintenance/checklists/index.tsx
- L200: <HeroActionButton
- L201: onClick={() => {
- L214: <WizardShell
- L234: <Button
- L237: onClick={closeTemplateDialog}
- L244: <Button
- L251: onClick={() =>
- L259: <Button
- L262: onClick={() => setTemplateStepIndex(1)}
- L266: <Button
- L268: onClick={handleCreateTemplate}
- L289: <Input
- L319: <Input
- L339: <Select
- L377: <Button
- L382: onClick={() =>

### resources/js/pages/fleet-assets/maintenance/checklists/run.tsx
- L140: <form onSubmit={handleSubmit} className="space-y-6">
- L150: <Select
- L173: <Select
- L219: <input
- L268: <Input
- L305: <Select
- L363: <Input
- L404: <Button type="submit" disabled={form.processing}>
- L408: <Button variant="outline" asChild>

### resources/js/pages/fleet-assets/maintenance/components/hero-action-button.tsx
- L21: <button
- L23: onClick={onClick}

### resources/js/pages/fleet-assets/maintenance/dashboard.tsx

### resources/js/pages/fleet-assets/maintenance/schedules/index.tsx
- L193: <HeroActionButton onClick={onCreate} icon={Plus} emphasis>
- L265: onClick={() => handleSort(field)}
- L349: <WizardShell
- L364: <Button
- L367: onClick={closeScheduleDialog}
- L374: <Button
- L377: onClick={() => setScheduleStepIndex(1)}
- L383: <Button
- L386: onClick={() => setScheduleStepIndex(0)}
- L390: <Button
- L392: onClick={handleCreate}
- L414: <Input
- L430: <Select
- L458: <Input
- L475: <Input
- L495: <Input
- L919: <Button
- L927: onClick={() =>

### resources/js/pages/fleet-assets/maintenance/work-orders/create-wizard.tsx
- L252: <WizardShell
- L272: <Button
- L274: onClick={() => setStepIndex(stepIndex - 1)}
- L279: <Button variant="ghost" onClick={close}>
- L286: <Button
- L287: onClick={() => setStepIndex(stepIndex + 1)}
- L293: <Button
- L294: onClick={submit}
- L312: <Input
- L327: <button
- L330: onClick={() =>
- L380: <Input
- L396: <textarea
- L419: <Button
- L423: onClick={() =>
- L447: <Input
- L464: <Input
- L472: <Select
- L515: <Select
- L568: <Input
- L591: <Input
- L614: <textarea

### resources/js/pages/fleet-assets/maintenance/work-orders/index.tsx
- L204: onClick={() => handleSort(field)}
- L332: <HeroActionButton
- L333: onClick={() => setWizardOpen(true)}
- L367: <Select
- L388: <Select
- L414: <input
- L445: onClick={() =>
- L453: onClick={(e) =>
- L457: <input
- L490: onClick={(e) =>
- L577: <Button
- L580: onClick={() => handleBulkAction('complete')}
- L584: <Button
- L587: onClick={() => handleBulkAction('in_progress')}
- L593: <Select
- L611: <Button
- L614: onClick={() =>
- L624: <Button
- L627: onClick={() => setSelectedIds([])}
- L638: <Button
- L643: onClick={() => link.url && router.get(link.url)}

### resources/js/pages/fleet-assets/maintenance/work-orders/show.tsx
- L369: <form
- L370: onSubmit={handleUpdate}
- L377: <Select
- L414: <Input
- L431: <textarea
- L444: <Button

### resources/js/pages/fleet-assets/map.tsx
- L306: <Input
- L319: <Button
- L323: onClick={() => setShowVehicles(!showVehicles)}
- L328: <Button
- L332: onClick={() => setShowHouses(!showHouses)}
- L337: <Button
- L343: onClick={() => setShowGeofences(!showGeofences)}

### resources/js/pages/fleet-assets/mileage/index.tsx
- L303: onClick={() => setWizardOpen(true)}
- L350: <Input
- L365: <Input
- L380: <Select
- L416: <Select
- L444: <Button size="sm" onClick={applyFilters}>
- L448: <Button
- L451: onClick={clearFilters}
- L591: <Button
- L594: onClick={() =>
- L604: <Button
- L607: onClick={() =>
- L621: <Button
- L624: onClick={() =>
- L659: <Button
- L668: onClick={() =>
- L836: <WizardShell
- L865: <Button
- L867: onClick={() => setStepIndex(stepIndex - 1)}
- L873: <Button
- L874: onClick={() => setStepIndex(stepIndex + 1)}
- L880: <Button onClick={submit} disabled={form.processing}>
- L896: <Button variant="outline" onClick={resetAll}>
- L899: <Button onClick={close}>Done</Button>
- L911: <Input
- L925: <Input
- L940: <Input
- L954: <Input
- L977: <Select
- L1004: <Select
- L1035: <textarea

### resources/js/pages/fleet-assets/outings/create.tsx
- L390: <WizardShell
- L405: <Button type="button" variant="ghost" onClick={onClose}>
- L418: <form onSubmit={handleSubmit} className="space-y-6">
- L432: <Button
- L436: onClick={() =>
- L470: <Input
- L490: <Input
- L510: <Input
- L530: <Input
- L550: <Button
- L552: onClick={() => setStep(2)}
- L593: <Button
- L597: onClick={() =>
- L744: <Button
- L747: onClick={() => setStep(1)}
- L751: <Button
- L753: onClick={() => setStep(3)}
- L806: <Input
- L830: <Input
- L882: <Button
- L889: onClick={() =>
- L916: <Button
- L924: onClick={() =>
- L933: <Button
- L943: onClick={() =>
- L952: <Button
- L957: onClick={() =>
- L983: <Button
- L986: onClick={addStop}
- L1044: <Button
- L1047: onClick={() => setStep(2)}
- L1051: <Button
- L1053: onClick={() => setStep(4)}
- L1073: <Select
- L1151: <Select
- L1192: <Button
- L1195: onClick={() => setStep(3)}
- L1199: <Button
- L1201: onClick={() => setStep(5)}
- L1223: <textarea
- L1240: <textarea
- L1257: <Button
- L1260: onClick={() => setStep(4)}
- L1264: <Button
- L1266: onClick={() => setStep(6)}
- L1343: <Button
- L1346: onClick={() => setStep(5)}
- L1351: <Button
- L1354: onClick={onClose}
- L1358: <Button

### resources/js/pages/fleet-assets/outings/index.tsx
- L323: <Input
- L336: <Select
- L366: <Input
- L376: <Input
- L516: <Button
- L546: <Button
- L556: onClick={() =>

### resources/js/pages/fleet-assets/outings/show.tsx
- L215: <Button
- L218: onClick={() =>
- L235: <Button
- L238: onClick={() =>
- L266: <Button
- L269: onClick={() =>
- L280: <Button
- L282: onClick={() =>
- L558: <Button
- L562: onClick={() =>
- L817: onClick={() => {

### resources/js/pages/fleet-assets/reports/by-house.tsx
- L120: <Select
- L138: <Select
- L378: onClick={() =>

### resources/js/pages/fleet-assets/reports/community-access.tsx
- L155: <Select
- L174: <Button variant="outline" size="sm" onClick={handleExport}>

### resources/js/pages/fleet-assets/reports/cost-allocation.tsx
- L289: <Select
- L311: <TabsTrigger value="house">
- L314: <TabsTrigger value="resident">
- L323: <Button
- L326: onClick={() => handleExport('house')}
- L450: <Button
- L453: onClick={() => handleExport('resident')}

### resources/js/pages/fleet-assets/reports/index.tsx
- L285: <Select

### resources/js/pages/fleet-assets/reports/reimbursement.tsx
- L180: <Select
- L206: <Input
- L218: <Input
- L234: <Input
- L252: <Button onClick={handleGenerate} disabled={loading}>
- L257: <Button
- L259: onClick={handleExportCSV}

### resources/js/pages/fleet-assets/resident-tracking/history.tsx
- L673: <Button
- L683: onClick={() => handleRangeClick(pill.value)}
- L693: <Input
- L703: <Input
- L710: <Button
- L713: onClick={() => applyRange('custom')}
- L722: <Button
- L737: <Button
- L740: onClick={handleClearEventTypes}
- L790: <Button
- L802: onClick={() => setMapPinMode(option.value)}
- L809: <Button
- L813: onClick={() => setExportOpen(true)}
- L838: <Button
- L842: onClick={() => handleRangeClick('7d')}
- L863: <Button
- L866: onClick={() => setMapExpanded((v) => !v)}
- L895: <Button
- L910: <Button
- L913: onClick={
- L987: <Button
- L1005: onClick={() =>

### resources/js/pages/fleet-assets/resident-tracking/index.tsx
- L364: <Select
- L489: <Button
- L492: onClick={() =>
- L504: <Button
- L507: onClick={() =>
- L539: <Button
- L544: onClick={() => link.url && router.visit(link.url)}
- L616: <WizardShell
- L636: <Button type="button" variant="outline" onClick={close}>
- L643: <Button
- L650: onClick={() => setStepIndex((step) => step + 1)}
- L656: <Button
- L659: onClick={() => setStepIndex(1)}
- L663: <Button
- L665: onClick={handleAssign}
- L697: <Select
- L728: <Select
- L881: <Button
- L884: onClick={() =>
- L920: <input
- L1271: <Button
- L1275: onClick={() => switchTab(t.key)}
- L1307: <Button
- L1310: onClick={() =>
- L1344: <Button
- L1348: onClick={() =>
- L1359: <Button
- L1363: onClick={() =>
- L1374: <Button
- L1378: onClick={() =>
- L1389: <Button
- L1393: onClick={() =>
- L1408: <Input
- L1599: <Button
- L1603: onClick={() =>
- L1679: <Button

### resources/js/pages/fleet-assets/settings/notifications.tsx
- L146: return <Switch checked={enabled} onCheckedChange={() => onClick()} />;
- L291: onClick={() =>
- L314: onClick={() =>
- L337: onClick={() =>
- L371: <Button onClick={handleSave}>Save Preferences</Button>

### resources/js/pages/fleet-assets/transports/components/transport-medication-dialogs.tsx
- L407: <WizardShell
- L422: <Button type="button" variant="ghost" onClick={close}>
- L428: <Button
- L430: onClick={() => setStepIndex(1)}
- L437: <Button
- L440: onClick={() => setStepIndex(0)}
- L445: <Button
- L447: onClick={submit}
- L480: <Select
- L575: <Select
- L630: <Select
- L690: <Input
- L729: <Textarea
- L777: <Textarea
- L957: <WizardShell
- L972: <Button type="button" variant="ghost" onClick={close}>
- L978: <Button
- L980: onClick={() => setStepIndex(1)}
- L987: <Button
- L990: onClick={() => setStepIndex(0)}
- L995: <Button
- L997: onClick={submit}
- L1030: <Select
- L1069: <Input
- L1092: <Textarea
- L1251: <WizardShell
- L1266: <Button type="button" variant="ghost" onClick={close}>
- L1272: <Button
- L1274: onClick={() => setStepIndex(1)}
- L1281: <Button
- L1284: onClick={() => setStepIndex(0)}
- L1289: <Button
- L1291: onClick={submit}
- L1313: <Input
- L1346: <Select
- L1387: <Input
- L1427: <Textarea
- L1572: <WizardShell
- L1587: <Button type="button" variant="ghost" onClick={close}>
- L1593: <Button
- L1595: onClick={() => setStepIndex(1)}
- L1602: <Button
- L1605: onClick={() => setStepIndex(0)}
- L1610: <Button
- L1612: onClick={submit}
- L1650: <Textarea

### resources/js/pages/fleet-assets/transports/create.tsx
- L555: <WizardShell
- L570: <Button type="button" variant="ghost" onClick={close}>
- L577: <Button
- L580: onClick={() => setStepIndex(stepIndex - 1)}
- L586: <Button
- L588: onClick={() => setStepIndex(stepIndex + 1)}
- L597: <form onSubmit={handleSubmit} className="space-y-6">
- L610: <Button
- L614: onClick={() =>
- L658: <Select
- L694: <Select
- L758: <Select
- L817: <Input
- L847: <button
- L888: <Input
- L909: <Input
- L937: <Input
- L963: <Input
- L989: <Input
- L1092: <input
- L1165: <Select
- L1231: <Input
- L1364: <textarea
- L1393: <Button
- L1409: <Button
- L1413: onClick={() => submitTransport('pack')}
- L1424: <Button
- L1427: onClick={close}

### resources/js/pages/fleet-assets/transports/index.tsx
- L348: <Input
- L357: <Button
- L360: onClick={handleSearch}
- L365: <Select
- L389: <Select
- L409: <Input
- L420: <Input
- L474: onClick={() =>
- L602: <Button
- L607: onClick={() => link.url && router.get(link.url)}

### resources/js/pages/fleet-assets/transports/medications.tsx
- L309: <Button asChild size="sm" variant="outline">
- L316: <Button asChild size="sm" variant="outline">
- L333: <Input
- L348: <Input
- L364: <Select
- L399: <Select
- L428: <Button size="sm" onClick={applyFilters}>
- L432: <Button
- L435: onClick={clearFilters}
- L440: <Button size="sm" variant="outline" asChild>
- L621: <Button
- L624: onClick={() =>
- L640: <Button
- L643: onClick={() =>
- L657: <Button
- L660: onClick={() =>
- L691: <Button
- L698: onClick={() =>

### resources/js/pages/fleet-assets/transports/pre-check.tsx
- L285: <Button
- L292: onClick={() =>
- L306: <Button
- L313: onClick={() =>
- L333: <Button
- L337: onClick={handleSubmit}

### resources/js/pages/fleet-assets/transports/show.tsx
- L643: <Button
- L659: <Button
- L664: onClick={openPackDialog}
- L872: <Button
- L875: onClick={() =>
- L890: <Button
- L893: onClick={() =>
- L906: <Button
- L909: onClick={() =>
- L979: <form
- L980: onSubmit={handleComplete}
- L987: <Input
- L1004: <Input
- L1016: <Button

### resources/js/pages/fleet-assets/trips/index.tsx
- L221: onClick={() => handleSort(field)}
- L481: <Input
- L491: <Input
- L502: <Input
- L513: <Select
- L533: <Select
- L619: <Button
- L624: onClick={() => link.url && router.get(link.url)}
- L659: onClick={onToggle}
- L706: <Button
- L712: onClick={(e) => e.stopPropagation()}
- L721: <Button
- L730: onClick={(e) => {

### resources/js/pages/fleet-assets/trips/playback.tsx
- L191: <Button
- L194: onClick={() => setConfirmClose(true)}
- L202: <Button
- L205: onClick={() => setConfirmDelete(true)}
- L357: <Select
- L379: <Button
- L380: onClick={handleAssignDriver}

### resources/js/pages/fleet-assets/vehicles/alerts-config.tsx
- L265: <input
- L297: <Input
- L319: <Select
- L354: <Select
- L407: <Input
- L429: <Input
- L455: <Select
- L489: <input
- L522: <Button onClick={handleSave} disabled={processing}>
- L533: <Button variant="outline" asChild>

### resources/js/pages/fleet-assets/vehicles/index.tsx
- L329: <Input
- L338: <Select
- L362: <input
- L390: <input
- L514: <Select
- L532: <Button
- L535: onClick={() =>
- L542: <Button
- L545: onClick={() =>
- L552: <Button
- L555: onClick={() => setSelectedIds([])}
- L567: <Button
- L576: onClick={() =>

### resources/js/pages/fleet-assets/vehicles/show.tsx
- L367: <TabsTrigger value="operations">
- L371: <TabsTrigger value="technology">
- L546: <Button
- L721: <Button
- L725: onClick={() =>
- L738: <form
- L739: onSubmit={(e) => {
- L779: <Select
- L847: <Button
- L888: <form
- L889: onSubmit={(e) => {
- L909: <Select
- L935: <Button
- L1047: <form
- L1048: onSubmit={(e) => {
- L1071: <Input
- L1081: <Button
- L1098: <Button
- L1111: <Button
- L1143: <Button
- L1166: <Button
- L1283: <Button
- L1395: <Button
- L1505: <Switch
- L1563: <form
- L1564: onSubmit={(e) => {
- L1588: <Input
- L1600: <Button
- L1621: <form
- L1622: onSubmit={(e) => {
- L1643: <textarea
- L1653: <Button

### resources/js/pages/fleet-assets/vehicles/vehicle-technology-projection.tsx
- L227: <Button asChild size="sm" variant="outline">
- L230: <Button asChild size="sm">
- L398: <Button asChild size="sm" variant="outline">

### resources/js/pages/fleet-management/maps-usage.tsx
