<?php

use App\Domain\SecurityDevices\AccessControl\Http\Controllers\AccessControlController;
use App\Domain\SecurityDevices\Cctv\Http\Controllers\CameraPrivacyHoursController;
use App\Domain\SecurityDevices\Cctv\Http\Controllers\CctvLiveController;
use App\Domain\SecurityDevices\Cctv\Http\Controllers\CctvRecordingReferenceController;
use App\Domain\SecurityDevices\Cctv\Http\Controllers\CctvSourceController;
use App\Domain\SecurityDevices\Cctv\Http\Controllers\DeviceCameraOnvifController;
use App\Domain\SecurityDevices\Cctv\Http\Controllers\HikvisionDiscoveryController;
use App\Domain\SecurityDevices\Cctv\Http\Controllers\HikvisionSourceController;
use App\Domain\SecurityDevices\Credentials\Http\Controllers\CredentialReferenceController;
use App\Domain\SecurityDevices\Endpoints\Http\Controllers\EndpointDownloadsController;
use App\Domain\SecurityDevices\Endpoints\Http\Controllers\EndpointIntuneLinkController;
use App\Domain\SecurityDevices\Endpoints\Http\Controllers\EndpointLifecycleController;
use App\Domain\SecurityDevices\Endpoints\Http\Controllers\EndpointRestartCommandController;
use App\Domain\SecurityDevices\Endpoints\Http\Controllers\EndpointRestartPolicyController;
use App\Domain\SecurityDevices\Endpoints\Http\Controllers\EndpointScriptCatalogueController;
use App\Domain\SecurityDevices\Endpoints\Http\Controllers\EndpointScriptCommandController;
use App\Domain\SecurityDevices\Endpoints\Http\Controllers\EndpointScriptPolicyController;
use App\Domain\SecurityDevices\Endpoints\Http\Controllers\EndpointSoftwareCatalogueController;
use App\Domain\SecurityDevices\Endpoints\Http\Controllers\EndpointSoftwarePolicyController;
use App\Domain\SecurityDevices\Endpoints\Http\Middleware\EndpointTransport;
use App\Domain\SecurityDevices\Http\Controllers\CollectorInstallationController;
use App\Domain\SecurityDevices\Http\Controllers\ConfigurationSnapshotComparisonController;
use App\Domain\SecurityDevices\Http\Controllers\ConfigurationSnapshotController;
use App\Domain\SecurityDevices\Http\Controllers\DeviceAssignmentController;
use App\Domain\SecurityDevices\Http\Controllers\DeviceController;
use App\Domain\SecurityDevices\Http\Controllers\DeviceDocumentController;
use App\Domain\SecurityDevices\Http\Controllers\DeviceGroupController;
use App\Domain\SecurityDevices\Http\Controllers\DeviceOfflineAlertController;
use App\Domain\SecurityDevices\Http\Controllers\SshHostKeyTrustController;
use App\Domain\SecurityDevices\Http\Controllers\DevicePingController;
use App\Domain\SecurityDevices\Http\Controllers\DiscoveryCandidateReviewController;
use App\Domain\SecurityDevices\Http\Controllers\DiscoveryScopeLifecycleController;
use App\Domain\SecurityDevices\Http\Controllers\Integrations\IntuneController;
use App\Domain\SecurityDevices\Http\Controllers\Integrations\MilesightController;
use App\Domain\SecurityDevices\Http\Controllers\Integrations\QueclinkController;
use App\Domain\SecurityDevices\Http\Controllers\Integrations\QueclinkHubController;
use App\Domain\SecurityDevices\Http\Controllers\Integrations\UnifiController;
use App\Domain\SecurityDevices\Http\Controllers\MaintenanceHealthController;
use App\Domain\SecurityDevices\Http\Controllers\MonitoringAlertRouteSettingsController;
use App\Domain\SecurityDevices\Http\Controllers\MonitoringCollectorLifecycleController;
use App\Domain\SecurityDevices\Http\Controllers\MonitoringCoverageSettingsController;
use App\Domain\SecurityDevices\Http\Controllers\MonitoringDeadLetterController;
use App\Domain\SecurityDevices\Http\Controllers\MonitoringDependencySettingsController;
use App\Domain\SecurityDevices\Http\Controllers\MonitoringEffectivePolicyPreviewController;
use App\Domain\SecurityDevices\Http\Controllers\MonitoringHistoryController;
use App\Domain\SecurityDevices\Http\Controllers\MonitoringMaintenanceSettingsController;
use App\Domain\SecurityDevices\Http\Controllers\MonitoringProfileSettingsController;
use App\Domain\SecurityDevices\Http\Controllers\MonitoringRetentionSettingsController;
use App\Domain\SecurityDevices\Http\Controllers\MonitoringRuntimeHealthController;
use App\Domain\SecurityDevices\Http\Controllers\MonitoringThresholdSettingsController;
use App\Domain\SecurityDevices\Http\Controllers\NativeMonitorLifecycleController;
use App\Domain\SecurityDevices\Http\Controllers\QueclinkUnitConsoleController;
use App\Domain\SecurityDevices\Http\Controllers\ReportsController;
use App\Domain\SecurityDevices\Http\Controllers\SiteHubWizardController;
use App\Domain\SecurityDevices\Hubs\Http\Controllers\HubAdminController;
use App\Domain\SecurityDevices\Hubs\Http\Controllers\HubLinuxSetupController;
use App\Domain\SecurityDevices\Management\Http\Controllers\DeviceCommandApprovalController;
use App\Domain\SecurityDevices\Management\Http\Controllers\DeviceCommandBatchController;
use App\Domain\SecurityDevices\Management\Http\Controllers\DeviceCommandBatchDecisionController;
use App\Domain\SecurityDevices\Management\Http\Controllers\DeviceCommandBatchExecutionController;
use App\Domain\SecurityDevices\Management\Http\Controllers\DeviceCommandBreakGlassReviewController;
use App\Domain\SecurityDevices\Management\Http\Controllers\DeviceCommandController;
use App\Domain\SecurityDevices\Management\Http\Controllers\DeviceCommandEvidenceController;
use App\Domain\SecurityDevices\Management\Http\Controllers\DeviceCommandExecutionController;
use App\Domain\SecurityDevices\Management\Http\Middleware\AuditDeniedDeviceCommandRequest;
use App\Domain\SecurityDevices\Screens\Http\AlertsController;
use App\Domain\SecurityDevices\Screens\Http\CareLinkController;
use App\Domain\SecurityDevices\Screens\Http\BulkActionsController;
use App\Domain\SecurityDevices\Screens\Http\DevicesController;
use App\Domain\SecurityDevices\Screens\Http\FoundDevicesController;
use App\Domain\SecurityDevices\Screens\Http\HubPanelController;
use App\Domain\SecurityDevices\Screens\Http\ListExportsController;
use App\Domain\SecurityDevices\Screens\Http\MonthlyReportController;
use App\Domain\SecurityDevices\Screens\Http\OverviewController;
use App\Domain\SecurityDevices\Screens\Http\RetiredPagesController;
use App\Domain\SecurityDevices\Screens\Http\SafetyKitCommandController;
use App\Domain\SecurityDevices\Screens\Http\SetupController;
use App\Domain\SecurityDevices\Screens\Http\SitesController;
use App\Domain\SecurityDevices\Screens\Http\TrackerCommandController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Setup links (S&D Phase B, pop-up 12). No sign-in exists yet: the 8-character code is the key
// (one computer, 24 hours), they're throttled, and a new hub still waits for approval.
//   /h/<CODE>        the Windows installer, named for its Site (to send to whoever installs it)
//   /h/<CODE>/linux  curl -fsSL https://<server>/h/<CODE>/linux | sudo bash (Raspberry Pi or NUC)
Route::middleware('throttle:20,1')->group(function () {
    Route::get('/h/{code}', [HubLinuxSetupController::class, 'link'])
        ->where('code', '[0-9A-Za-z-]{8,9}')
        ->name('security-devices.hubs.setup-link');
    Route::get('/h/{code}/linux', [HubLinuxSetupController::class, 'script'])
        ->where('code', '[0-9A-Za-z-]{8,9}')
        ->name('security-devices.hubs.linux-script');
});

// S&D Phase G (pop-up 61): a device's web page, served to a sandboxed frame. The frame can't carry
// Oblivion's sign-in, so the unguessable link is the key (only its hash is stored); the person who
// opened it must still hold webPage.open, and every answer is sandboxed into an origin of its own.
Route::match(['GET', 'HEAD', 'POST'], '/security-devices/web/{token}/{path?}', [\App\Domain\SecurityDevices\WebPages\Http\WebPageController::class, 'serve'])
    ->where('token', '[A-Za-z0-9_-]{43}')
    ->where('path', '.*')
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->middleware('throttle:600,1')
    ->name('security-devices.web.serve');

Route::middleware([
    'auth',
    'permission:securityDevices.viewAny',
])->prefix('security-devices')->group(function () {
    // S&D rebuild C4: the Security & devices home.
    Route::get('/', OverviewController::class)
        ->name('security-devices.index');

    // S&D rebuild C4b: Overview › Reports (pg-sdr-18b) as PDF or CSV, its camera viewing log, the monthly email,
    // and "Export" on the Devices and Alerts lists (today's three Reports CSVs, same key and scope).
    Route::middleware('permission:securityDevices.reports.view')->group(function (): void {
        Route::get('/monthly-report.pdf', [MonthlyReportController::class, 'pdf'])->middleware('throttle:10,1')->name('security-devices.monthly-report.pdf');
        Route::get('/monthly-report.csv', [MonthlyReportController::class, 'csv'])->middleware('throttle:20,1')->name('security-devices.monthly-report.csv');
        Route::get('/monthly-report/camera-views.csv', [MonthlyReportController::class, 'cameraViews'])->middleware('throttle:20,1')->name('security-devices.monthly-report.camera-views');
        Route::patch('/monthly-report/email', [MonthlyReportController::class, 'email'])->middleware('throttle:20,1')->name('security-devices.monthly-report.email');
        Route::get('/exports/devices.csv', [ListExportsController::class, 'devices'])->middleware('throttle:20,1')->name('security-devices.exports.devices');
        Route::get('/exports/events.csv', [ListExportsController::class, 'events'])->middleware('throttle:20,1')->name('security-devices.exports.events');
        Route::get('/exports/upkeep.csv', [ListExportsController::class, 'upkeep'])->middleware('throttle:20,1')->name('security-devices.exports.upkeep');
    });

    Route::middleware([EndpointTransport::class, 'permission:securityDevices.commands.admin', 'throttle:10,1'])->group(function (): void {
        Route::post('/devices/{device}/endpoint/enrolments', [EndpointLifecycleController::class, 'issue'])->name('security-devices.devices.endpoint.enrolments');
        Route::get('/devices/{device}/endpoint/bootstrap-options', [EndpointLifecycleController::class, 'options'])->name('security-devices.devices.endpoint.bootstrap-options');
        Route::post('/devices/{device}/endpoint/bootstrap', [EndpointLifecycleController::class, 'bootstrap'])->name('security-devices.devices.endpoint.bootstrap');
        Route::post('/devices/{device}/endpoint/revoke', [EndpointLifecycleController::class, 'revoke'])->name('security-devices.devices.endpoint.revoke');
    });

    $scripts = EndpointScriptCatalogueController::class;
    Route::get('/scripts/confirm-identity', [$scripts, 'confirmIdentity'])->name('security-devices.scripts.confirm-identity');
    Route::get('/scripts', [$scripts, 'index'])->name('security-devices.scripts.index');
    Route::post('/scripts', [$scripts, 'upload'])->middleware('throttle:10,1')->name('security-devices.scripts.upload');
    Route::get('/scripts/uploads/{request_key}', [$scripts, 'recovery'])->whereUuid('request_key')->name('security-devices.scripts.recovery');
    Route::get('/scripts/{script}', [$scripts, 'show'])->whereUuid('script')->name('security-devices.scripts.show');
    Route::get('/scripts/{script}/material', [$scripts, 'material'])->whereUuid('script')->name('security-devices.scripts.material');
    Route::get('/scripts/{script}/requests/{request_key}', [$scripts, 'actionRecovery'])->whereUuid('script')->whereUuid('request_key')->name('security-devices.scripts.action-recovery');
    foreach (['submit', 'review', 'withdraw'] as $action) {
        Route::post('/scripts/{script}/'.$action, [$scripts, $action])->whereUuid('script')->middleware('throttle:10,1')->name('security-devices.scripts.'.$action);
    }

    Route::middleware(EndpointTransport::class)->group(function (): void {
        $policy = EndpointScriptPolicyController::class;
        Route::get('/devices/{device}/endpoint/script-policy', [$policy, 'show'])->name('security-devices.devices.endpoint.script-policy');
        Route::put('/devices/{device}/endpoint/script-policy', [$policy, 'update'])->middleware('throttle:10,1')->name('security-devices.devices.endpoint.script-policy.update');
        Route::get('/devices/{device}/endpoint/script-policy/requests/{request_key}', [$policy, 'recovery'])->whereUuid('request_key')->name('security-devices.devices.endpoint.script-policy.recovery');
        Route::get('/devices/{device}/endpoint/script-policy/confirm-identity', [$policy, 'confirmIdentity'])->name('security-devices.devices.endpoint.script-policy.confirm-identity');
    });

    $software = EndpointSoftwareCatalogueController::class;
    Route::get('/software/confirm-identity', [$software, 'confirmIdentity'])->name('security-devices.software.confirm-identity');
    Route::get('/software', [$software, 'index'])->name('security-devices.software.index');
    Route::post('/software', [$software, 'upload'])->middleware('throttle:10,1')->name('security-devices.software.upload');
    Route::get('/software/uploads/{request_key}', [$software, 'recovery'])->whereUuid('request_key')->name('security-devices.software.recovery');
    Route::get('/software/{package}', [$software, 'show'])->whereUuid('package')->name('security-devices.software.show');
    foreach (['submit', 'review', 'withdraw'] as $action) {
        Route::post('/software/{package}/'.$action, [$software, $action])->whereUuid('package')->name('security-devices.software.'.$action);
    }
    $softwarePolicy = EndpointSoftwarePolicyController::class;
    Route::get('/devices/{device}/endpoint/software-policy', [$softwarePolicy, 'show'])->middleware(EndpointTransport::class)->name('security-devices.devices.endpoint.software-policy');
    Route::put('/devices/{device}/endpoint/software-policy', [$softwarePolicy, 'update'])->middleware(EndpointTransport::class)->name('security-devices.devices.endpoint.software-policy.update');
    // S&D rebuild C6: kept for now (verdict "Drop page"). Its new homes aren't reachable yet: pop-up 23
    // (Install Oblivion on a computer) isn't placed on any page and the Setup › Hubs list (15p) isn't
    // built, so this is still the only place to get the Obli installer for a staff computer.
    Route::get('/agent-downloads', [EndpointDownloadsController::class, 'index'])
        ->middleware('permission:securityDevices.commands.admin|securityDevices.integrations.manage')
        ->name('security-devices.agent-downloads.index');
    Route::get('/agent-downloads/{artifact}/{digest}', [EndpointDownloadsController::class, 'download'])
        ->where('artifact', '[a-z0-9][a-z0-9-]{1,63}')->where('digest', '[a-f0-9]{64}')
        ->middleware(['permission:securityDevices.commands.admin|securityDevices.integrations.manage', 'throttle:10,1'])
        ->name('security-devices.agent-downloads.download');

    $hikvisionDiscovery = HikvisionDiscoveryController::class;
    Route::post('/devices/{device}/hikvision-discoveries', [$hikvisionDiscovery, 'store'])->whereNumber('device')->middleware('throttle:10,1')->name('security-devices.cctv.hikvision.discovery.store');
    Route::get('/cctv/hikvision-discoveries/requests/{key}', [$hikvisionDiscovery, 'recover'])->whereUuid('key')->name('security-devices.cctv.hikvision.discovery.recover');
    Route::get('/cctv/hikvision-discoveries/{uuid}', [$hikvisionDiscovery, 'show'])->whereUuid('uuid')->name('security-devices.cctv.hikvision.discovery.show');
    $hikvisionSource = HikvisionSourceController::class;
    Route::get('/devices/{device}/live/hikvision/setup', [$hikvisionSource, 'setup'])->whereNumber('device')->name('security-devices.cctv.hikvision.setup');
    Route::post('/devices/{device}/live/hikvision/source/enable', [$hikvisionSource, 'enable'])->whereNumber('device')->middleware('throttle:12,1')->name('security-devices.cctv.hikvision.source.enable');
    Route::post('/devices/{device}/live/hikvision/source/disable', [$hikvisionSource, 'disable'])->whereNumber('device')->middleware('throttle:12,1')->name('security-devices.cctv.hikvision.source.disable');
    Route::get('/devices/{device}/live/hikvision/source', [$hikvisionSource, 'show'])->whereNumber('device')->name('security-devices.cctv.hikvision.source.show');
    Route::post('/devices/{device}/live/hikvision/source', [$hikvisionSource, 'store'])->whereNumber('device')->middleware('throttle:12,1')->name('security-devices.cctv.hikvision.source.store');
    Route::get('/devices/{device}/live/hikvision/source/discoveries/{uuid}', [$hikvisionSource, 'review'])->whereNumber('device')->whereUuid('uuid')->name('security-devices.cctv.hikvision.source.review');
    Route::get('/devices/{device}/live/hikvision/source/requests/{key}', [$hikvisionSource, 'recover'])->whereNumber('device')->whereUuid('key')->name('security-devices.cctv.hikvision.source.recover');
    $recordingReferences = CctvRecordingReferenceController::class;
    Route::get('/devices/{device}/recording-references', [$recordingReferences, 'index'])->whereNumber('device')->name('security-devices.cctv.references.index');
    Route::post('/devices/{device}/recording-references', [$recordingReferences, 'store'])->whereNumber('device')->middleware('throttle:12,1')->name('security-devices.cctv.references.store');
    Route::get('/cctv/recording-references/requests/{key}', [$recordingReferences, 'recover'])->whereUuid('key')->name('security-devices.cctv.references.recover');
    Route::get('/cctv/recording-references/{uuid}', [$recordingReferences, 'show'])->whereUuid('uuid')->name('security-devices.cctv.references.show');
    $live = CctvLiveController::class;
    $source = CctvSourceController::class;
    Route::get('/devices/{device}/live', [$live, 'show'])->whereNumber('device')->name('security-devices.cctv.live');
    Route::post('/devices/{device}/live-sessions', [$live, 'start'])->whereNumber('device')->middleware('throttle:12,1')->name('security-devices.cctv.start');
    Route::get('/cctv/live-sessions/{uuid}', [$live, 'status'])->whereUuid('uuid')->name('security-devices.cctv.status');
    foreach (['offer', 'renew', 'stop'] as $action) {
        Route::post('/cctv/live-sessions/{uuid}/'.$action, [$live, $action])->whereUuid('uuid')->middleware('throttle:60,1')->name('security-devices.cctv.'.$action);
    }
    Route::get('/devices/{device}/live/source', [$source, 'show'])->whereNumber('device')->name('security-devices.cctv.source');
    Route::post('/devices/{device}/live/source', [$source, 'save'])->whereNumber('device')->middleware('throttle:10,1')->name('security-devices.cctv.source.save');
    Route::post('/devices/{device}/live/source/enable-stream', [$source, 'enable'])->whereNumber('device')->middleware('throttle:10,1')->name('security-devices.cctv.source.enable');
    Route::post('/devices/{device}/live/source/disable', [$source, 'disable'])->whereNumber('device')->middleware('throttle:10,1')->name('security-devices.cctv.source.disable');
    // ── Device CRUD ───────────────────────────────────────────────

    // S&D rebuild C1: the Devices menu, Found devices and the scan pop-ups.
    Route::get('/devices', [DevicesController::class, 'index'])
        ->middleware('permission:securityDevices.devices.view')
        ->name('security-devices.devices.index');

    Route::post('/devices/add-one', [DevicesController::class, 'store'])
        ->middleware(['permission:securityDevices.devices.create', 'throttle:20,1'])
        ->name('security-devices.devices.add-one');

    Route::post('/devices/move', [DevicesController::class, 'move'])
        ->middleware(['permission:securityDevices.devices.assign', 'throttle:20,1'])
        ->name('security-devices.devices.move');

    // S&D rebuild C6: the bulk bar's governed actions for the ticked devices. Running one posts to
    // /command-batches below (same keys), so every batch check stays in one place.
    Route::get('/devices/bulk-actions', [BulkActionsController::class, 'options'])
        ->middleware([
            'permission:securityDevices.devices.view',
            'permission:securityDevices.commands.operate|securityDevices.commands.manage|securityDevices.commands.control|securityDevices.commands.admin',
            'throttle:30,1',
        ])
        ->name('security-devices.devices.bulk-actions');

    Route::get('/scans/{run}', [FoundDevicesController::class, 'show'])
        ->whereNumber('run')
        ->middleware('permission:securityDevices.devices.view')
        ->name('security-devices.scans.show');

    Route::get('/scans/{run}/progress', [FoundDevicesController::class, 'progress'])
        ->whereNumber('run')
        ->middleware(['permission:securityDevices.devices.view', 'throttle:120,1'])
        ->name('security-devices.scans.progress');

    Route::post('/scans', [FoundDevicesController::class, 'start'])
        ->middleware(['permission:securityDevices.integrations.manage', 'throttle:10,1'])
        ->name('security-devices.scans.start');

    Route::post('/scans/{run}/stop', [FoundDevicesController::class, 'stop'])
        ->whereNumber('run')
        ->middleware(['permission:securityDevices.integrations.manage', 'throttle:20,1'])
        ->name('security-devices.scans.stop');

    Route::post('/networks', [FoundDevicesController::class, 'addNetwork'])
        ->middleware(['permission:securityDevices.integrations.manage', 'throttle:20,1'])
        ->name('security-devices.networks.store');

    Route::get('/networks/range-check', [FoundDevicesController::class, 'rangeCheck'])
        ->middleware(['permission:securityDevices.integrations.manage', 'throttle:60,1'])
        ->name('security-devices.networks.range-check');

    Route::get('/devices/create', [DeviceController::class, 'create'])
        ->middleware('permission:securityDevices.devices.create')
        ->name('security-devices.devices.create');

    Route::post('/devices', [DeviceController::class, 'store'])
        ->middleware('permission:securityDevices.devices.create')
        ->name('security-devices.devices.store');

    Route::get('/devices/{device}', [DeviceController::class, 'show'])
        ->middleware('permission:securityDevices.devices.view')
        ->name('security-devices.devices.show');

    Route::get('/devices/{device}/edit', [DeviceController::class, 'edit'])
        ->middleware('permission:securityDevices.devices.update')
        ->name('security-devices.devices.edit');

    Route::put('/devices/{device}', [DeviceController::class, 'update'])
        ->middleware('permission:securityDevices.devices.update')
        ->name('security-devices.devices.store.update');

    Route::delete('/devices/{device}', [DeviceController::class, 'destroy'])
        ->middleware('permission:securityDevices.devices.delete')
        ->name('security-devices.devices.destroy');

    // Narrow in-place patch for Overview-tab inline edits (notes, asset_tag,
    // location_description). Full-record updates still go through PUT above.
    Route::patch('/devices/{device}/fields', [DeviceController::class, 'patchFields'])
        ->middleware('permission:securityDevices.devices.update')
        ->name('security-devices.devices.patch-fields');

    Route::post('/devices/{device}/ping', DevicePingController::class)
        ->whereNumber('device')
        ->middleware([
            'permission:securityDevices.devices.view',
            'throttle:20,1',
        ])
        ->name('security-devices.devices.ping');

    Route::post('/devices/{device}/offline-alert', DeviceOfflineAlertController::class)
        ->whereNumber('device')
        ->middleware([
            'permission:securityDevices.monitoring.manage',
            'throttle:20,1',
        ])
        ->name('security-devices.devices.offline-alert');

    // Phase A, item A6: trust (or, as an IT admin, replace) the SSH key a device offered.
    Route::post('/devices/{device}/ssh-host-key/trust', SshHostKeyTrustController::class)
        ->whereNumber('device')
        ->middleware([
            'permission:securityDevices.commands.manage',
            'throttle:20,1',
        ])
        ->name('security-devices.devices.ssh-host-key.trust');

    Route::post('/devices/{device}/camera/snapshot', [DeviceCameraOnvifController::class, 'snapshot'])
        ->whereNumber('device')
        ->middleware([
            'permission:securityDevices.devices.view',
            'permission:securityDevices.cctv.media.view',
            'throttle:20,1',
        ])
        ->name('security-devices.devices.camera.snapshot');

    // S&D Phase B: the picture a Site hub took, fetched once by the person who asked for it.
    Route::get('/devices/{device}/camera/snapshot/{command}', [DeviceCameraOnvifController::class, 'snapshotStatus'])
        ->whereNumber('device')
        ->whereNumber('command')
        ->middleware([
            'permission:securityDevices.devices.view',
            'permission:securityDevices.cctv.media.view',
            'throttle:90,1',
        ])
        ->name('security-devices.devices.camera.snapshot.status');

    // S&D rebuild Phase E: a camera's privacy hours (pop-up 64). Audited; no picture is served inside them.
    Route::put('/devices/{device}/camera/privacy-hours', [CameraPrivacyHoursController::class, 'update'])
        ->whereNumber('device')
        ->middleware(['permission:securityDevices.devices.update', 'throttle:20,1'])
        ->name('security-devices.devices.camera.privacy-hours');

    // Phase A, item A5: trust (or, as an IT admin, replace) the certificate a camera or Protect console offered.
    Route::post('/devices/{device}/camera/certificate/trust', [DeviceCameraOnvifController::class, 'trustCertificate'])
        ->whereNumber('device')
        ->middleware([
            'permission:securityDevices.devices.view',
            'permission:securityDevices.commands.manage',
            'throttle:20,1',
        ])
        ->name('security-devices.devices.camera.certificate.trust');

    Route::get('/devices/{device}/camera/streams', [DeviceCameraOnvifController::class, 'streams'])
        ->whereNumber('device')
        ->middleware([
            'permission:securityDevices.devices.view',
            'permission:securityDevices.cctv.media.view',
            'throttle:30,1',
        ])
        ->name('security-devices.devices.camera.streams');

    Route::post('/devices/{device}/camera/ptz', [DeviceCameraOnvifController::class, 'ptz'])
        ->whereNumber('device')
        ->middleware([
            'permission:securityDevices.devices.view',
            'permission:securityDevices.cctv.media.view',
            'permission:securityDevices.commands.operate|securityDevices.commands.manage|securityDevices.commands.control|securityDevices.commands.admin',
            'throttle:30,1',
        ])
        ->name('security-devices.devices.camera.ptz');

    Route::get('/devices/{device}/queclink-console', [QueclinkUnitConsoleController::class, 'show'])
        ->whereNumber('device')
        ->middleware([
            'permission:securityDevices.devices.view',
            'permission:securityDevices.integrations.view|securityDevices.integrations.manage',
            'throttle:30,1',
        ])
        ->name('security-devices.devices.queclink-console.show');

    Route::post('/devices/{device}/queclink-console/commands', [QueclinkUnitConsoleController::class, 'sendCommand'])
        ->whereNumber('device')
        ->middleware([
            'permission:securityDevices.devices.view',
            'permission:securityDevices.integrations.view|securityDevices.integrations.manage',
            'throttle:15,1',
        ])
        ->name('security-devices.devices.queclink-console.commands');

    Route::get('/devices/{device}/commands/confirm-identity', [DeviceCommandController::class, 'confirmIdentity'])
        ->middleware('permission:securityDevices.commands.operate|securityDevices.commands.manage|securityDevices.commands.control|securityDevices.commands.admin')
        ->name('security-devices.devices.commands.confirm-identity');

    Route::post('/devices/{device}/commands', [DeviceCommandController::class, 'store'])
        ->middleware([
            AuditDeniedDeviceCommandRequest::class,
            'permission:securityDevices.commands.operate|securityDevices.commands.manage|securityDevices.commands.control|securityDevices.commands.admin',
        ])
        ->name('security-devices.devices.commands.store');

    // S&D rebuild Phase E: arm, disarm and unlock from the alarm and door pages. Today's governed
    // commands, plus the rebuild keys; the controller checks both. A person only, never an automation (D12).
    Route::prefix('/devices/{device}/safety')->whereNumber('device')->group(function (): void {
        Route::get('/confirm-identity', [SafetyKitCommandController::class, 'confirmIdentity'])->name('security-devices.devices.safety.confirm-identity');
        Route::middleware([AuditDeniedDeviceCommandRequest::class, 'throttle:20,1'])->group(function (): void {
            Route::post('/arm', [SafetyKitCommandController::class, 'arm'])->name('security-devices.devices.safety.arm');
            Route::post('/disarm', [SafetyKitCommandController::class, 'disarm'])->name('security-devices.devices.safety.disarm');
            Route::post('/unlock', [SafetyKitCommandController::class, 'unlock'])->name('security-devices.devices.safety.unlock');
            // S&D Phase H (pop-up 70): keep a UniFi Access door unlocked for a while, or lock it again early.
            Route::post('/keep-unlocked', [SafetyKitCommandController::class, 'keepUnlocked'])->name('security-devices.devices.safety.keep-unlocked');
            Route::post('/keep-unlocked/end', [SafetyKitCommandController::class, 'keepUnlockedEnd'])->name('security-devices.devices.safety.keep-unlocked-end');
            // S&D Phase H (pop-up 86): lock down, evacuate or end, on a Site's UniFi Access console.
            Route::post('/site/{mode}', [SafetyKitCommandController::class, 'siteEmergency'])->whereIn('mode', ['lockdown', 'evacuate', 'end'])->name('security-devices.devices.safety.site');
            Route::post('/commands/{command}/run', [SafetyKitCommandController::class, 'run'])->whereNumber('command')->name('security-devices.devices.safety.run');
        });
    });

    // S&D rebuild Phase E: send a command to a care tracker (pop-up 19). Today's console command path,
    // plus securityDevices.trackers.command; the controller checks both.
    Route::post('/devices/{device}/tracker/commands', [TrackerCommandController::class, 'store'])
        ->whereNumber('device')
        ->middleware(['permission:securityDevices.devices.view', 'permission:securityDevices.trackers.command', 'throttle:15,1'])
        ->name('security-devices.devices.tracker.commands');

    $restartPolicy = EndpointRestartPolicyController::class;
    Route::get('/devices/{device}/endpoint/restart-policy', [$restartPolicy, 'show'])->name('security-devices.devices.endpoint.restart-policy.show');
    Route::put('/devices/{device}/endpoint/restart-policy', [$restartPolicy, 'update'])->name('security-devices.devices.endpoint.restart-policy.update');
    Route::middleware([
        'permission:securityDevices.integrations.manage|securityDevices.commands.admin',
        'permission:securityDevices.devices.update',
        'throttle:20,1',
    ])->group(function (): void {
        Route::post('/devices/{device}/endpoint/intune-link', [EndpointIntuneLinkController::class, 'store'])
            ->whereNumber('device')
            ->name('security-devices.devices.endpoint.intune-link.store');
        Route::delete('/devices/{device}/endpoint/intune-link', [EndpointIntuneLinkController::class, 'destroy'])
            ->whereNumber('device')
            ->name('security-devices.devices.endpoint.intune-link.destroy');
    });
    Route::post('/devices/{device}/commands/{command}/cancel', [EndpointRestartCommandController::class, 'cancel'])->name('security-devices.devices.commands.cancel');
    Route::get('/devices/{device}/commands/{command}/script-output/{attempt}', [EndpointScriptCommandController::class, 'outputReceipt'])
        ->whereUuid('attempt')->name('security-devices.devices.commands.script-output');
    Route::get('/devices/{device}/commands/{command}/script-output/{attempt}/content', [EndpointScriptCommandController::class, 'outputContent'])
        ->whereUuid('attempt')->middleware('throttle:30,1')->name('security-devices.devices.commands.script-output.content');
    Route::post('/devices/{device}/commands/{command}/script-cancellation', [EndpointScriptCommandController::class, 'cancel'])
        ->middleware('throttle:10,1')->name('security-devices.devices.commands.script-cancellation');
    Route::get('/devices/{device}/commands/{command}/script-cancellation/requests/{request_key}', [EndpointScriptCommandController::class, 'cancellation'])
        ->whereUuid('request_key')->name('security-devices.devices.commands.script-cancellation.recovery');

    Route::post('/commands/{command}/decision', [DeviceCommandApprovalController::class, 'store'])
        ->middleware('permission:securityDevices.commands.approve')
        ->name('security-devices.commands.decision');

    Route::post('/commands/{command}/dispatch', [DeviceCommandExecutionController::class, 'store'])
        ->middleware('permission:securityDevices.commands.operate|securityDevices.commands.manage|securityDevices.commands.control|securityDevices.commands.admin')
        ->name('security-devices.commands.dispatch');

    Route::post('/commands/{command}/break-glass-review', DeviceCommandBreakGlassReviewController::class)
        ->middleware('permission:securityDevices.commands.admin')
        ->name('security-devices.commands.break-glass-review');

    Route::get('/devices/{device}/commands/{command}/evidence', DeviceCommandEvidenceController::class)
        ->middleware('permission:securityDevices.commands.observe|securityDevices.commands.operate|securityDevices.commands.manage|securityDevices.commands.control|securityDevices.commands.admin')
        ->name('security-devices.devices.commands.evidence');

    Route::get('/command-batches/confirm-identity', [DeviceCommandBatchController::class, 'confirmIdentity'])
        ->middleware('permission:securityDevices.commands.operate|securityDevices.commands.manage|securityDevices.commands.control|securityDevices.commands.admin')
        ->name('security-devices.command-batches.confirm-identity');

    Route::post('/command-batches', [DeviceCommandBatchController::class, 'store'])
        ->middleware([
            AuditDeniedDeviceCommandRequest::class,
            'permission:securityDevices.commands.operate|securityDevices.commands.manage|securityDevices.commands.control|securityDevices.commands.admin',
        ])
        ->name('security-devices.command-batches.store');

    // S&D rebuild C6 (Drop): the batch page goes; results are in each device's logbook. Starting,
    // approving, sending and exporting a batch stay on their routes (no new screen does them yet).
    Route::get('/command-batches/{batch}', [RetiredPagesController::class, 'commandBatch'])
        ->middleware('permission:securityDevices.commands.observe|securityDevices.commands.operate|securityDevices.commands.manage|securityDevices.commands.control|securityDevices.commands.admin')
        ->name('security-devices.command-batches.show');

    Route::get('/command-batches/{batch}/export', [DeviceCommandBatchController::class, 'export'])
        ->middleware('permission:securityDevices.commands.observe|securityDevices.commands.operate|securityDevices.commands.manage|securityDevices.commands.control|securityDevices.commands.admin')
        ->name('security-devices.command-batches.export');

    Route::post('/command-batches/{batch}/decision', [DeviceCommandBatchDecisionController::class, 'store'])
        ->middleware('permission:securityDevices.commands.approve')
        ->name('security-devices.command-batches.decision');

    Route::post('/command-batches/{batch}/dispatch', [DeviceCommandBatchExecutionController::class, 'store'])
        ->middleware('permission:securityDevices.commands.operate|securityDevices.commands.manage|securityDevices.commands.control|securityDevices.commands.admin')
        ->name('security-devices.command-batches.dispatch');

    // ── Device Assignments ────────────────────────────────────────

    Route::post('/devices/{device}/assign', [DeviceAssignmentController::class, 'assign'])
        ->middleware('permission:securityDevices.devices.assign')
        ->name('security-devices.devices.assign');

    Route::post('/devices/{device}/release', [DeviceAssignmentController::class, 'release'])
        ->middleware('permission:securityDevices.devices.assign')
        ->name('security-devices.devices.release');

    // ── Device <-> Asset links ───────────────────────────────────
    // Polymorphic: a device can be a primary asset, installed inside an
    // asset (e.g., tracker in a vehicle), or an accessory.
    Route::post('/devices/{device}/asset-links', [DeviceController::class, 'linkAsset'])
        ->middleware('permission:securityDevices.devices.update')
        ->name('security-devices.devices.asset-links.store');

    Route::delete('/devices/{device}/asset-links/{link}', [DeviceController::class, 'unlinkAsset'])
        ->middleware('permission:securityDevices.devices.update')
        ->name('security-devices.devices.asset-links.destroy');

    // ── Device topology relationships ────────────────────────────
    // `direction=downstream` makes this device the parent; `upstream` makes
    // it the child. Preserves the physical / logical wiring model.
    Route::post('/devices/{device}/relationships', [DeviceController::class, 'linkRelated'])
        ->middleware('permission:securityDevices.devices.update')
        ->name('security-devices.devices.relationships.store');

    Route::delete('/devices/{device}/relationships/{relationship}', [DeviceController::class, 'unlinkRelated'])
        ->middleware('permission:securityDevices.devices.update')
        ->name('security-devices.devices.relationships.destroy');

    // ── Device documents (uploads) ───────────────────────────────
    Route::post('/devices/{device}/documents', [DeviceDocumentController::class, 'store'])
        ->middleware('permission:securityDevices.devices.update')
        ->name('security-devices.devices.documents.store');

    Route::get('/devices/{device}/documents/{document}', [DeviceDocumentController::class, 'download'])
        ->middleware('permission:securityDevices.devices.view')
        ->name('security-devices.devices.documents.download');

    Route::delete('/devices/{device}/documents/{document}', [DeviceDocumentController::class, 'destroy'])
        ->middleware('permission:securityDevices.devices.update')
        ->name('security-devices.devices.documents.destroy');

    Route::get(
        '/devices/{device}/configuration-snapshots/{snapshot}',
        ConfigurationSnapshotController::class,
    )
        ->middleware('permission:securityDevices.devices.view')
        ->name('security-devices.devices.configuration-snapshots.download');

    Route::get(
        '/devices/{device}/configuration-snapshots/{snapshot}/compare/{baseline?}',
        ConfigurationSnapshotComparisonController::class,
    )
        ->whereNumber(['device', 'snapshot'])
        ->middleware(['permission:securityDevices.devices.view', 'throttle:30,1'])
        ->name('security-devices.devices.configuration-snapshots.compare');

    // ── Approved grouped-navigation destinations ─────────────────
    // These canonical routes reuse today's production-backed controllers.
    // Later workspace tasks expand their local tabs without changing URLs.

    // S&D rebuild C3: Sites and the Site page.
    Route::get('/sites', [SitesController::class, 'index'])
        ->middleware('permission:securityDevices.devices.view')
        ->name('security-devices.sites.index');

    Route::get('/sites/{site}', [SitesController::class, 'show'])
        ->whereNumber('site')
        ->middleware('permission:securityDevices.devices.view')
        ->name('security-devices.sites.show');
    // S&D 12i: export a day or date range of the Site's logbook as PDF or CSV, with a required audited reason.
    Route::post('/sites/{site}/logbook/export', [SitesController::class, 'exportLogbook'])
        ->whereNumber('site')
        ->middleware(['permission:securityDevices.devices.view', 'throttle:20,1'])
        ->name('security-devices.sites.logbook.export');
    // S&D 12j: place unplaced Site devices (e.g. radio sensors) onto the Site map by hand.
    Route::post('/sites/{site}/network/place', [SitesController::class, 'placeOnMap'])
        ->whereNumber('site')
        ->middleware(['permission:securityDevices.devices.update', 'throttle:30,1'])
        ->name('security-devices.sites.network.place');
    // S&D Phase F (pg-sdr-12c, pop-ups 42 and 43): a Site's house modes. Safe actions only; another Site's mode is 404.
    Route::post('/sites/{site}/modes', [\App\Domain\SecurityDevices\Screens\Http\SiteModesController::class, 'store'])
        ->whereNumber('site')
        ->middleware(['permission:securityDevices.automations.manage', 'throttle:20,1'])
        ->name('security-devices.sites.modes.store');
    Route::put('/sites/{site}/modes/{mode}', [\App\Domain\SecurityDevices\Screens\Http\SiteModesController::class, 'update'])
        ->whereNumber(['site', 'mode'])
        ->middleware(['permission:securityDevices.automations.manage', 'throttle:20,1'])
        ->name('security-devices.sites.modes.update');
    Route::post('/sites/{site}/modes/{mode}/switch', [\App\Domain\SecurityDevices\Screens\Http\SiteModesController::class, 'switch'])
        ->whereNumber(['site', 'mode'])
        ->middleware(['permission:securityDevices.modes.switch', 'throttle:20,1'])
        ->name('security-devices.sites.modes.switch');

    // S&D rebuild C5: Setup. The controller checks who may open it and each section.
    Route::get('/setup', [SetupController::class, 'show'])
        ->name('security-devices.setup');
    Route::get('/setup/history/export', [SetupController::class, 'exportHistory'])
        ->middleware('throttle:10,1')
        ->name('security-devices.setup.history.export');
    Route::patch('/setup/settings', [SetupController::class, 'settings'])
        ->middleware('throttle:20,1')
        ->name('security-devices.setup.settings');
    Route::post('/setup/networks/{scope}/scan', [SetupController::class, 'scan'])
        ->whereNumber('scope')
        ->middleware('throttle:20,1')
        ->name('security-devices.setup.networks.scan');
    Route::post('/setup/broker/gateways', [SetupController::class, 'storeGateway'])
        ->middleware('throttle:10,1')
        ->name('security-devices.setup.broker.gateways.store');
    Route::get('/setup/broker/gateways/{login}', [SetupController::class, 'showGateway'])
        ->whereNumber('login')
        ->middleware('throttle:60,1')
        ->name('security-devices.setup.broker.gateways.show');
    Route::delete('/setup/broker/gateways/{login}', [SetupController::class, 'revokeGateway'])
        ->whereNumber('login')
        ->middleware('throttle:20,1')
        ->name('security-devices.setup.broker.gateways.revoke');
    // S&D Phase E (E5): the sensors certificate a gateway loads to check its hub (TLS, port 8883).
    Route::get('/setup/broker/sensors-certificate', [SetupController::class, 'sensorsCertificate'])
        ->middleware('throttle:30,1')
        ->name('security-devices.setup.broker.sensors-certificate');
    // S&D Phase G (pg-sdr-15k): Setup › Computers & Obli › Remote support, the "Allow remote support" switch.
    Route::put('/setup/computers/remote-support', [\App\Domain\SecurityDevices\Screens\Http\SetupRemoteSupportController::class, 'update'])
        ->middleware('throttle:10,1')
        ->name('security-devices.setup.computers.remote-support');
    // S&D Phase H (pg-sdr-15m, pop-up 91): Setup › Doors & access. The controller and AccessSetup check who may do what.
    Route::prefix('/setup/access')->middleware('throttle:30,1')->group(function (): void {
        $setup = \App\Domain\SecurityDevices\Access\Http\AccessSetupController::class;
        Route::post('/consoles', [$setup, 'store'])->name('security-devices.setup.access.consoles.store');
        Route::get('/consoles/{console}', [$setup, 'show'])->whereNumber('console')->withoutMiddleware('throttle:30,1')->middleware('throttle:120,1')->name('security-devices.setup.access.consoles.show');
        Route::put('/consoles/{console}/token', [$setup, 'token'])->whereNumber('console')->middleware('throttle:10,1')->name('security-devices.setup.access.consoles.token');
        Route::post('/consoles/{console}/trust', [$setup, 'trust'])->whereNumber('console')->name('security-devices.setup.access.consoles.trust');
        Route::post('/consoles/{console}/doors-confirmed', [$setup, 'confirmDoors'])->whereNumber('console')->name('security-devices.setup.access.consoles.doors');
    });
    // S&D Phase E (E8): pop-up 15, Connect a service. Each step runs the service's own settings action.
    Route::get('/setup/services/connect', [\App\Domain\SecurityDevices\Screens\Http\ConnectServiceController::class, 'show'])
        ->middleware(['permission:securityDevices.integrations.manage', 'throttle:60,1'])
        ->name('security-devices.setup.services.connect');
    Route::post('/setup/services/connect/{service}/{step}', [\App\Domain\SecurityDevices\Screens\Http\ConnectServiceController::class, 'run'])
        ->where('service', 'unifi|intune')
        ->where('step', 'key|test|sites|match|bring-in|find')
        ->middleware(['permission:securityDevices.integrations.manage', 'throttle:30,1'])
        ->name('security-devices.setup.services.connect.run');
    Route::get('/setup/{section}', [SetupController::class, 'show'])
        ->where('section', 'hubs|services|alerts|access|computers|history')
        ->name('security-devices.setup.section');

    // S&D rebuild Phase D: Alerts, and what people do on one. The controller checks permissions.
    Route::get('/alerts', [AlertsController::class, 'index'])
        ->name('security-devices.alerts.index');
    // S&D round 3 (pg-sdr-13d): handover notes between the people on IT on call.
    Route::get('/alerts/on-call/handovers', [AlertsController::class, 'handovers'])
        ->name('security-devices.alerts.on-call.handovers');
    Route::post('/alerts/on-call/handover', [AlertsController::class, 'handover'])
        ->middleware('throttle:10,1')
        ->name('security-devices.alerts.on-call.handover');
    Route::prefix('/alerts/{alert}')->whereNumber('alert')->middleware('throttle:30,1')->group(function (): void {
        Route::post('/acknowledge', [AlertsController::class, 'acknowledge'])->name('security-devices.alerts.acknowledge');
        Route::post('/assign', [AlertsController::class, 'assign'])->name('security-devices.alerts.assign');
        Route::get('/assignees', [AlertsController::class, 'assignees'])->name('security-devices.alerts.assignees');
        Route::post('/snooze', [AlertsController::class, 'snooze'])->name('security-devices.alerts.snooze');
        Route::post('/incident', [AlertsController::class, 'reportIncident'])->name('security-devices.alerts.incident');
        Route::post('/done', [AlertsController::class, 'done'])->name('security-devices.alerts.done');
        // S&D Phase F: raise the H&S hazard from a scald-risk alert (the person is its reporter).
        Route::post('/hazard', [AlertsController::class, 'raiseHazard'])->name('security-devices.alerts.hazard');
    });

    // S&D Phase F: house automations (pg-sdr-13b, pop-ups 40 and 41). The controller checks automations.manage.
    Route::post('/automations', [\App\Domain\SecurityDevices\Screens\Http\AutomationsController::class, 'store'])
        ->middleware('throttle:20,1')->name('security-devices.automations.store');
    Route::prefix('/automations/{automation}')->whereNumber('automation')->middleware('throttle:20,1')->group(function (): void {
        Route::put('/', [\App\Domain\SecurityDevices\Screens\Http\AutomationsController::class, 'update'])->name('security-devices.automations.update');
        Route::post('/off', [\App\Domain\SecurityDevices\Screens\Http\AutomationsController::class, 'turnOff'])->name('security-devices.automations.off');
        Route::post('/on', [\App\Domain\SecurityDevices\Screens\Http\AutomationsController::class, 'turnOn'])->name('security-devices.automations.on');
        Route::delete('/', [\App\Domain\SecurityDevices\Screens\Http\AutomationsController::class, 'destroy'])->name('security-devices.automations.destroy');
        Route::post('/test', [\App\Domain\SecurityDevices\Screens\Http\AutomationsController::class, 'test'])->middleware('throttle:6,1')->name('security-devices.automations.test');
    });

    // S&D Phase G (pop-up 61): open a device's web page through the hub (webPage.open).
    Route::post('/devices/{device}/web', [\App\Domain\SecurityDevices\WebPages\Http\WebPageController::class, 'open'])
        ->whereNumber('device')
        ->middleware(['permission:securityDevices.webPage.open', 'throttle:20,1'])
        ->name('security-devices.devices.web.open');

    // S&D Phase H (pop-up 87): the fire roll call from the Access page (the page itself is at the end
    // of this file). The controller checks accessControl.view and the Site.
    Route::get('/access/sites/{site}/roll-call', [\App\Domain\SecurityDevices\Screens\Http\AccessPageController::class, 'rollCall'])
        ->whereNumber('site')
        ->middleware('throttle:30,1')
        ->name('security-devices.access.roll-call');
    Route::get('/access/jobs/{uuid}/person', [\App\Domain\SecurityDevices\Screens\Http\AccessPageController::class, 'addedPerson'])
        ->whereUuid('uuid')
        ->middleware('throttle:60,1')
        ->name('security-devices.access.jobs.person');

    // S&D Phase H (pop-ups 81–84, 90): people, cards and visitor passes in UniFi Access, through the Site's hub.
    Route::prefix('/access')->middleware('throttle:30,1')->group(function (): void {
        $people = \App\Domain\SecurityDevices\Access\Http\AccessPeopleController::class;
        Route::post('/consoles/{console}/people', [$people, 'store'])->whereNumber('console')->name('security-devices.access.people.store');
        Route::post('/consoles/{console}/visitors', [$people, 'storeVisitor'])->whereNumber('console')->name('security-devices.access.visitors.store');
        Route::delete('/visitors/{visitor}', [$people, 'destroyVisitor'])->whereNumber('visitor')->name('security-devices.access.visitors.destroy');
        Route::post('/people/{person}/status', [$people, 'status'])->whereNumber('person')->name('security-devices.access.people.status');
        Route::put('/people/{person}/doors', [$people, 'doors'])->whereNumber('person')->name('security-devices.access.people.doors');
        Route::post('/people/{person}/cards', [$people, 'enrol'])->whereNumber('person')->name('security-devices.access.people.cards.enrol');
        Route::put('/people/{person}/match', [$people, 'match'])->whereNumber('person')->name('security-devices.access.people.match');
        Route::delete('/cards/{card}', [$people, 'removeCard'])->whereNumber('card')->name('security-devices.access.cards.destroy');
        Route::get('/jobs/{uuid}', [$people, 'job'])->whereUuid('uuid')->withoutMiddleware('throttle:30,1')->middleware('throttle:120,1')->name('security-devices.access.jobs.show');
    });

    // S&D Phase G (D5): a device's Terminal tab, through the hub, for IT admins (terminal.use).
    Route::post('/devices/{device}/terminal', [\App\Domain\SecurityDevices\Terminal\Http\TerminalController::class, 'open'])
        ->whereNumber('device')
        ->middleware(['permission:securityDevices.terminal.use', 'throttle:10,1'])
        ->name('security-devices.devices.terminal.open');
    Route::prefix('/terminal/{session}')->middleware('permission:securityDevices.terminal.use')->group(function (): void {
        Route::post('/input', [\App\Domain\SecurityDevices\Terminal\Http\TerminalController::class, 'input'])->middleware('throttle:240,1')->name('security-devices.terminal.input');
        Route::get('/output', [\App\Domain\SecurityDevices\Terminal\Http\TerminalController::class, 'output'])->middleware('throttle:600,1')->name('security-devices.terminal.output');
        Route::post('/close', [\App\Domain\SecurityDevices\Terminal\Http\TerminalController::class, 'close'])->middleware('throttle:30,1')->name('security-devices.terminal.close');
    });

    // S&D Phase G (pop-up 24): a settings backup beside the one before it, and both as one file. The presenter checks Site and snapshot access.
    Route::get('/devices/{device}/settings-backups/{snapshot}/changes', [\App\Domain\SecurityDevices\Screens\Http\SettingsBackupsController::class, 'changes'])
        ->whereNumber(['device', 'snapshot'])
        ->middleware(['permission:securityDevices.devices.view', 'throttle:30,1'])
        ->name('security-devices.devices.settings-backups.changes');
    Route::get('/devices/{device}/settings-backups/{snapshot}/download', [\App\Domain\SecurityDevices\Screens\Http\SettingsBackupsController::class, 'download'])
        ->whereNumber(['device', 'snapshot'])
        ->middleware(['permission:securityDevices.devices.view', 'throttle:30,1'])
        ->name('security-devices.devices.settings-backups.download');

    // S&D Phase G (pop-up 63): replace the saved login the hub uses for a device (savedLogins.manage; the manager also checks commands.admin).
    Route::put('/devices/{device}/saved-login', [\App\Domain\SecurityDevices\Screens\Http\SavedLoginController::class, 'update'])
        ->whereNumber('device')
        ->middleware(['permission:securityDevices.savedLogins.manage', 'throttle:10,1'])
        ->name('security-devices.devices.saved-login.update');

    // S&D Phase F: link a sensor to a person's care (pop-up 65, consent checked) and the scald watch.
    Route::prefix('/devices/{device}')->whereNumber('device')->middleware('throttle:30,1')->group(function (): void {
        Route::get('/care-link/options', [CareLinkController::class, 'options'])->name('security-devices.devices.care-link.options');
        Route::post('/care-link', [CareLinkController::class, 'store'])->name('security-devices.devices.care-link.store');
        Route::delete('/care-link', [CareLinkController::class, 'destroy'])->name('security-devices.devices.care-link.destroy');
        Route::put('/scald-watch', [CareLinkController::class, 'scaldWatch'])->name('security-devices.devices.scald-watch');
    });

    // ── S&D rebuild C6: the old pages send people to the six-entry menu ──
    // Each keeps its name and permission; RetiredPagesController only redirects the page view.
    // The five workspaces (Drop) → the matching Devices tab; the global map → each Site's Network map.
    Route::middleware('permission:securityDevices.devices.view')->group(function (): void {
        Route::get('/network-it', [RetiredPagesController::class, 'networkIt'])->name('security-devices.network-it');
        Route::get('/security', [RetiredPagesController::class, 'security'])->name('security-devices.security');
        // Stephan, 1 Nov: Health kit becomes a Devices › Sensors filter; trackers are Fleet's, read-only here.
        Route::get('/healthcare', [RetiredPagesController::class, 'healthcare'])->name('security-devices.healthcare');
        Route::get('/tracking', [RetiredPagesController::class, 'tracking'])->name('security-devices.tracking');
        Route::get('/facilities-iot', [RetiredPagesController::class, 'facilitiesIot'])->name('security-devices.facilities-iot');

        // The 7 old category links (Keep redirects), now straight to the matching Devices tab.
        Route::get('/alarms', [RetiredPagesController::class, 'alarms'])->name('security-devices.alarms');
        Route::get('/cctv', [RetiredPagesController::class, 'cctv'])->name('security-devices.cctv');
        Route::get('/access-control', [RetiredPagesController::class, 'accessControl'])->name('security-devices.access-control');
        Route::get('/tracking-devices', [RetiredPagesController::class, 'trackingDevices'])->name('security-devices.tracking-devices');
        Route::get('/smart-iot-healthcare', [RetiredPagesController::class, 'smartIotHealthcare'])->name('security-devices.smart-iot-healthcare');
        Route::get('/it-infrastructure', [RetiredPagesController::class, 'itInfrastructure'])->name('security-devices.it-infrastructure');
        Route::get('/facilities', [RetiredPagesController::class, 'facilities'])->name('security-devices.facilities');
    });

    // Today's local door schedules and cards (not sent to UniFi). Kept: the Access page has no
    // screen for these local records yet (the plan's data move matches them to UniFi first).

    Route::post('/access-control/schedules', [AccessControlController::class, 'storeSchedule'])
        ->middleware('permission:securityDevices.accessControl.manage')
        ->name('security-devices.access-control.schedules.store');

    Route::put('/access-control/schedules/{accessSchedule}', [AccessControlController::class, 'updateSchedule'])
        ->middleware('permission:securityDevices.accessControl.manage')
        ->name('security-devices.access-control.schedules.update');

    Route::post('/access-control/schedules/{accessSchedule}/deactivate', [AccessControlController::class, 'deactivateSchedule'])
        ->middleware('permission:securityDevices.accessControl.manage')
        ->name('security-devices.access-control.schedules.deactivate');

    Route::post('/access-control/credentials', [AccessControlController::class, 'storeCredential'])
        ->middleware('permission:securityDevices.accessControl.manage')
        ->name('security-devices.access-control.credentials.store');

    Route::post('/access-control/credentials/{accessCredential}/revoke', [AccessControlController::class, 'revoke'])
        ->middleware('permission:securityDevices.accessControl.manage')
        ->name('security-devices.access-control.credentials.revoke');

    // ── Operations pages (specific permission gates) ──────────────

    // ── Device Groups ───────────────────────────────────────────
    // S&D rebuild C6 (D8, Fold in): the group pages go; each group is a filter on Devices
    // (Devices › "Any group"), rooms are on the Site page. The tables stay, read-only for one
    // release: no screen writes to them any more. The write routes below are kept, unused, until
    // Stephan says yes to dropping the tables (decision for him, not removed here).

    Route::get('/device-groups', [RetiredPagesController::class, 'deviceGroups'])
        ->middleware('permission:securityDevices.groups.manage')
        ->name('security-devices.device-groups');

    Route::get('/device-groups/create', [RetiredPagesController::class, 'deviceGroups'])
        ->middleware('permission:securityDevices.groups.manage')
        ->name('security-devices.device-groups.create');

    Route::post('/device-groups', [DeviceGroupController::class, 'store'])
        ->middleware('permission:securityDevices.groups.manage')
        ->name('security-devices.device-groups.store');

    Route::post('/device-groups/auto-rules/preview', [DeviceGroupController::class, 'previewDraftAutoRules'])
        ->middleware('permission:securityDevices.groups.manage')
        ->name('security-devices.device-groups.auto-rules.preview-draft');

    Route::get('/device-groups/{group}', [RetiredPagesController::class, 'deviceGroups'])
        ->middleware('permission:securityDevices.groups.manage')
        ->name('security-devices.device-groups.show');

    // Own throttle bucket (prefix) so this read never spends another route's limit.
    Route::get('/device-groups/{group}/maintenance-policy-preview', [DeviceGroupController::class, 'previewMaintenancePolicy'])
        ->middleware(['permission:securityDevices.groups.manage', 'permission:securityDevices.monitoring.manage', 'throttle:30,1,device-group-maintenance-preview'])
        ->missing(static fn () => DeviceGroupController::previewUnavailable(404))
        ->name('security-devices.device-groups.maintenance-policy-preview');

    Route::get('/device-groups/{group}/edit', [RetiredPagesController::class, 'deviceGroups'])
        ->middleware('permission:securityDevices.groups.manage')
        ->name('security-devices.device-groups.edit');

    Route::put('/device-groups/{group}', [DeviceGroupController::class, 'update'])
        ->middleware('permission:securityDevices.groups.manage')
        ->name('security-devices.device-groups.update');

    Route::delete('/device-groups/{group}', [DeviceGroupController::class, 'destroy'])
        ->middleware('permission:securityDevices.groups.manage')
        ->name('security-devices.device-groups.destroy');

    Route::post('/device-groups/{group}/members', [DeviceGroupController::class, 'addMember'])
        ->middleware('permission:securityDevices.groups.manage')
        ->name('security-devices.device-groups.add-member');

    Route::delete('/device-groups/{group}/members/{device}', [DeviceGroupController::class, 'removeMember'])
        ->middleware('permission:securityDevices.groups.manage')
        ->name('security-devices.device-groups.remove-member');

    // ── Auto-rule membership ─────────────────────────────────────
    // Preview returns JSON so the UI can show a confirm dialog;
    // sync is a POST redirect-back so it plays nicely with Inertia.
    Route::get('/device-groups/{group}/auto-rules/preview', [DeviceGroupController::class, 'previewAutoRules'])
        ->middleware('permission:securityDevices.groups.manage')
        ->name('security-devices.device-groups.auto-rules.preview');

    Route::post('/device-groups/{group}/auto-rules/sync', [DeviceGroupController::class, 'syncAutoRules'])
        ->middleware('permission:securityDevices.groups.manage')
        ->name('security-devices.device-groups.auto-rules.sync');

    // S&D rebuild C6: /alerts-events and /monitoring are rebuilt as Alerts. Their JSON reads
    // (monitor history, runtime health) and write endpoints (native monitors, dead letters) stay.
    Route::get('/alerts-events', [RetiredPagesController::class, 'alerts'])
        ->middleware('permission:securityDevices.events.view')
        ->name('security-devices.alerts-events');

    Route::get('/monitoring', [RetiredPagesController::class, 'alerts'])
        ->middleware('permission:securityDevices.events.view')
        ->name('security-devices.monitoring');

    Route::get('/monitoring/monitors/{monitor}/history', MonitoringHistoryController::class)
        ->whereNumber('monitor')
        ->middleware([
            'permission:securityDevices.events.view|securityDevices.devices.view',
            'throttle:60,1',
        ])
        ->name('security-devices.monitoring.history');

    Route::prefix('/monitoring/native-monitors')
        ->middleware([
            'permission:securityDevices.monitoring.manage',
            'throttle:30,1',
        ])
        ->group(function () {
            Route::get('/requests', [NativeMonitorLifecycleController::class, 'requests'])->name('security-devices.monitoring.native-monitors.requests');
            Route::post('/requests/{requestUuid}/recover', [NativeMonitorLifecycleController::class, 'recover'])
                ->whereUuid('requestUuid')
                ->name('security-devices.monitoring.native-monitors.recover');
            Route::post('/', [NativeMonitorLifecycleController::class, 'store'])
                ->name('security-devices.monitoring.native-monitors.store');
            Route::patch('/{monitor}', [NativeMonitorLifecycleController::class, 'update'])
                ->whereNumber('monitor')
                ->name('security-devices.monitoring.native-monitors.update');
            Route::post('/{monitor}/deactivate', [NativeMonitorLifecycleController::class, 'deactivate'])
                ->whereNumber('monitor')
                ->name('security-devices.monitoring.native-monitors.deactivate');
        });

    Route::get('/runtime-health', MonitoringRuntimeHealthController::class)
        ->middleware('permission:securityDevices.events.view')
        ->name('security-devices.runtime-health');

    Route::prefix('/monitoring/dead-letters')
        ->middleware('permission:securityDevices.integrations.manage')
        ->group(function () {
            Route::get('/requests', [MonitoringDeadLetterController::class, 'requests'])->name('security-devices.monitoring.dead-letters.requests');
            Route::post('/requests/{requestUuid}/recover', [MonitoringDeadLetterController::class, 'recover'])->whereUuid('requestUuid')->name('security-devices.monitoring.dead-letters.recover');
            Route::post('/{deadLetter}/replay', [MonitoringDeadLetterController::class, 'replay'])
                ->name('security-devices.monitoring.dead-letters.replay');
            Route::post('/{deadLetter}/discard', [MonitoringDeadLetterController::class, 'discard'])
                ->name('security-devices.monitoring.dead-letters.discard');
        });

    // S&D rebuild C6 (Merge): both maintenance pages → Devices › Upkeep. Recording maintenance
    // (below) stays: the old device profile (?section=maintenance) still uses it.
    Route::get('/maintenance-health', [RetiredPagesController::class, 'maintenance'])
        ->middleware('permission:securityDevices.maintenance.view')
        ->name('security-devices.maintenance-health');

    Route::get('/maintenance', [RetiredPagesController::class, 'maintenance'])
        ->middleware('permission:securityDevices.maintenance.view')
        ->name('security-devices.maintenance');

    Route::post('/devices/{device}/maintenance', [MaintenanceHealthController::class, 'store'])
        ->middleware('permission:securityDevices.maintenance.manage')
        ->name('security-devices.maintenance.store');

    Route::put('/maintenance/{record}', [MaintenanceHealthController::class, 'update'])
        ->middleware('permission:securityDevices.maintenance.manage')
        ->name('security-devices.maintenance.update');

    Route::post('/maintenance/{record}/complete', [MaintenanceHealthController::class, 'complete'])
        ->middleware('permission:securityDevices.maintenance.manage')
        ->name('security-devices.maintenance.complete');

    // S&D rebuild C6: the integrations hub → Setup › Connected services. Each service's own page
    // (UniFi, Queclink, Milesight, Intune, below) stays and opens from its Connected services card:
    // the service panels that replace them aren't built yet.
    Route::get('/integrations', [RetiredPagesController::class, 'integrations'])
        ->middleware('permission:securityDevices.integrations.view')
        ->name('security-devices.integrations');

    // S&D rebuild C6: Discovery & collectors → Setup › Hubs & networks. Its scope, candidate and
    // collector endpoints stay (Found devices and the hub pop-ups use them).
    Route::get('/discovery', [RetiredPagesController::class, 'hubsAndNetworks'])
        ->middleware('permission:securityDevices.integrations.view')
        ->name('security-devices.discovery');

    Route::prefix('/discovery/scopes')
        ->middleware([
            'permission:securityDevices.integrations.manage',
            'throttle:20,1',
        ])
        ->group(function () {
            Route::post('/', [DiscoveryScopeLifecycleController::class, 'store'])
                ->name('security-devices.discovery.scopes.store');
            Route::patch('/{scope}', [DiscoveryScopeLifecycleController::class, 'update'])
                ->whereNumber('scope')
                ->name('security-devices.discovery.scopes.update');
            Route::post('/{scope}/deactivate', [DiscoveryScopeLifecycleController::class, 'deactivate'])
                ->whereNumber('scope')
                ->name('security-devices.discovery.scopes.deactivate');
            Route::post('/{scope}/apply', [DiscoveryScopeLifecycleController::class, 'apply'])
                ->whereNumber('scope')
                ->name('security-devices.discovery.scopes.apply');
        });

    Route::prefix('/discovery/candidates')
        ->middleware([
            'permission:securityDevices.integrations.manage',
            'throttle:30,1',
        ])
        ->group(function () {
            Route::post('/adopt-all', [DiscoveryCandidateReviewController::class, 'adoptAll'])
                ->middleware('permission:securityDevices.devices.create')
                ->name('security-devices.discovery.candidates.adopt-all');
            Route::post('/{candidate}/adopt', [DiscoveryCandidateReviewController::class, 'adopt'])
                ->whereNumber('candidate')
                ->middleware('permission:securityDevices.devices.create')
                ->name('security-devices.discovery.candidates.adopt');
            Route::post('/{candidate}/same-as', [DiscoveryCandidateReviewController::class, 'sameAs'])
                ->whereNumber('candidate')
                ->middleware('permission:securityDevices.devices.update')
                ->name('security-devices.discovery.candidates.same-as');
            Route::post('/{candidate}/ignore', [DiscoveryCandidateReviewController::class, 'ignore'])
                ->whereNumber('candidate')
                ->name('security-devices.discovery.candidates.ignore');
        });

    Route::prefix('/discovery/collectors')
        ->middleware([
            'permission:securityDevices.integrations.manage',
            'throttle:6,1,security-device-collectors:',
        ])
        ->group(function () {
            Route::get('/installation-options', [CollectorInstallationController::class, 'options'])->name('security-devices.collectors.installation.options');
            Route::post('/installation-profile', [CollectorInstallationController::class, 'issue'])->name('security-devices.collectors.installation.issue');
            Route::post('/enrolments', [MonitoringCollectorLifecycleController::class, 'issue'])
                ->name('security-devices.collectors.enrolments.issue');
            Route::post('/{collector}/re-enrolment', [MonitoringCollectorLifecycleController::class, 'reEnroll'])
                ->whereNumber('collector')
                ->name('security-devices.collectors.enrolments.reissue');
            Route::post('/{collector}/revoke', [MonitoringCollectorLifecycleController::class, 'revoke'])
                ->whereNumber('collector')
                ->name('security-devices.collectors.revoke');
        });

    Route::prefix('/discovery/collectors')
        ->middleware('permission:securityDevices.integrations.manage')
        ->group(function () {
            Route::get('/hub-wizard/options', [SiteHubWizardController::class, 'options'])
                ->middleware('throttle:30,1,security-device-hub-wizard-options:')
                ->name('security-devices.collectors.hub-wizard.options');
            Route::post('/hub-wizard/issue', [SiteHubWizardController::class, 'issue'])
                ->middleware('throttle:10,1,security-device-hub-wizard-issue:')
                ->name('security-devices.collectors.hub-wizard.issue');
            Route::get('/enrolments/{enrollment}/status', [SiteHubWizardController::class, 'status'])
                ->whereNumber('enrollment')
                ->middleware('throttle:60,1,security-device-hub-wizard-status:')
                ->name('security-devices.collectors.enrolments.status');
            Route::patch('/{collector}', [MonitoringCollectorLifecycleController::class, 'update'])
                ->whereNumber('collector')
                ->middleware('throttle:20,1,security-device-collectors-update:')
                ->name('security-devices.collectors.update');
        });

    // ── Hubs (S&D Phase B): setup codes in the file name, approval, updates ──
    // Each action checks its own permission in HubAdminController (integrations.manage to
    // add and edit; hubs.approve to approve, replace, remove, hold updates and "Check now").
    Route::prefix('/hubs')->middleware('throttle:30,1,security-device-hubs:')->group(function () {
        Route::post('/setup-codes', [HubAdminController::class, 'issue'])->name('security-devices.hubs.setup-codes.issue');
        Route::post('/computer-setup-codes', [HubAdminController::class, 'issueComputer'])->name('security-devices.computers.setup-codes.issue');
        Route::get('/setup-codes/{setupCode}', [HubAdminController::class, 'status'])->whereNumber('setupCode')
            ->withoutMiddleware('throttle:30,1,security-device-hubs:')->middleware('throttle:90,1,security-device-hubs-status:')
            ->name('security-devices.hubs.setup-codes.status');
        Route::get('/setup-codes/{setupCode}/download', [HubAdminController::class, 'download'])->whereNumber('setupCode')
            ->name('security-devices.hubs.setup-codes.download');
        Route::delete('/setup-codes/{setupCode}', [HubAdminController::class, 'cancel'])->whereNumber('setupCode')
            ->name('security-devices.hubs.setup-codes.cancel');
        Route::put('/updates/hold', [HubAdminController::class, 'hold'])->name('security-devices.hubs.updates.hold');
        Route::post('/{collector}/approve', [HubAdminController::class, 'approve'])->whereNumber('collector')
            ->name('security-devices.hubs.approve');
        Route::post('/{collector}/remove', [HubAdminController::class, 'remove'])->whereNumber('collector')
            ->name('security-devices.hubs.remove');
        Route::post('/{collector}/replace', [HubAdminController::class, 'replace'])->whereNumber('collector')
            ->name('security-devices.hubs.replace');
        Route::post('/{collector}/check-update', [HubAdminController::class, 'checkUpdate'])->whereNumber('collector')
            ->name('security-devices.hubs.check-update');
        Route::patch('/{collector}', [HubAdminController::class, 'update'])->whereNumber('collector')
            ->name('security-devices.hubs.update');
        // Pop-up 13, the hub panel: read-only, scoped to the hub's own Site (HubPanelPresenter).
        Route::get('/{collector}/panel', HubPanelController::class)->whereNumber('collector')
            ->withoutMiddleware('throttle:30,1,security-device-hubs:')->middleware('throttle:90,1,security-device-hubs-panel:')
            ->name('security-devices.hubs.panel');
    });

    // S&D rebuild C6: Settings & audit → Setup (Alert rules, Checks, Saved logins, Change history).
    // Same people as before; Setup checks its own sections. The monitoring-policy and
    // credential-reference endpoints below stay (no screen of the new Setup replaces them yet).
    Route::get('/settings', [RetiredPagesController::class, 'settings'])
        ->middleware('permission:securityDevices.groups.manage|securityDevices.reports.view|securityDevices.commands.admin|securityDevices.monitoring.manage')
        ->name('security-devices.settings');

    // Rebuilt as Setup › Services & logins › Supported devices (C5).
    Route::get('/supported-devices', fn () => redirect('/security-devices/setup/services?tab=supported'))
        ->name('security-devices.supported-devices');

    Route::get('/settings/monitoring/effective-preview/{monitor}', [MonitoringEffectivePolicyPreviewController::class, 'show'])
        ->whereNumber('monitor')
        ->middleware([
            'permission:securityDevices.monitoring.manage|securityDevices.events.view',
            'throttle:30,1',
        ])
        ->name('security-devices.settings.monitoring.effective-preview');

    Route::prefix('/settings/monitoring')
        ->middleware([
            'permission:securityDevices.monitoring.manage',
            'throttle:30,1',
        ])
        ->group(function () {
            Route::post('/profiles', [MonitoringProfileSettingsController::class, 'store'])
                ->name('security-devices.settings.monitoring.profiles.store');
            Route::patch('/profiles/{profile}', [MonitoringProfileSettingsController::class, 'update'])
                ->whereNumber('profile')
                ->name('security-devices.settings.monitoring.profiles.update');
            Route::post('/profiles/{profile}/deactivate', [MonitoringProfileSettingsController::class, 'deactivate'])
                ->whereNumber('profile')
                ->name('security-devices.settings.monitoring.profiles.deactivate');

            Route::post('/thresholds', [MonitoringThresholdSettingsController::class, 'store'])
                ->name('security-devices.settings.monitoring.thresholds.store');
            Route::patch('/thresholds/{thresholdRule}', [MonitoringThresholdSettingsController::class, 'update'])
                ->whereNumber('thresholdRule')
                ->name('security-devices.settings.monitoring.thresholds.update');
            Route::post('/thresholds/{thresholdRule}/deactivate', [MonitoringThresholdSettingsController::class, 'deactivate'])
                ->whereNumber('thresholdRule')
                ->name('security-devices.settings.monitoring.thresholds.deactivate');
            Route::post('/thresholds/{thresholdRule}/reactivate', [MonitoringThresholdSettingsController::class, 'reactivate'])
                ->whereNumber('thresholdRule')
                ->name('security-devices.settings.monitoring.thresholds.reactivate');

            Route::post('/alert-routes', [MonitoringAlertRouteSettingsController::class, 'store'])
                ->name('security-devices.settings.monitoring.alert-routes.store');
            Route::patch('/alert-routes/{alertRoute}', [MonitoringAlertRouteSettingsController::class, 'update'])
                ->whereNumber('alertRoute')
                ->name('security-devices.settings.monitoring.alert-routes.update');
            Route::post('/alert-routes/{alertRoute}/deactivate', [MonitoringAlertRouteSettingsController::class, 'deactivate'])
                ->whereNumber('alertRoute')
                ->name('security-devices.settings.monitoring.alert-routes.deactivate');
            Route::post('/alert-routes/{alertRoute}/reactivate', [MonitoringAlertRouteSettingsController::class, 'reactivate'])
                ->whereNumber('alertRoute')
                ->name('security-devices.settings.monitoring.alert-routes.reactivate');

            Route::post('/coverage', [MonitoringCoverageSettingsController::class, 'store'])
                ->name('security-devices.settings.monitoring.coverage.store');
            Route::patch('/coverage/{expectation}', [MonitoringCoverageSettingsController::class, 'update'])
                ->whereNumber('expectation')
                ->name('security-devices.settings.monitoring.coverage.update');
            Route::post('/coverage/{expectation}/deactivate', [MonitoringCoverageSettingsController::class, 'deactivate'])
                ->whereNumber('expectation')
                ->name('security-devices.settings.monitoring.coverage.deactivate');
            Route::post('/coverage/{expectation}/reactivate', [MonitoringCoverageSettingsController::class, 'reactivate'])
                ->whereNumber('expectation')
                ->name('security-devices.settings.monitoring.coverage.reactivate');

            Route::post('/dependencies', [MonitoringDependencySettingsController::class, 'store'])
                ->name('security-devices.settings.monitoring.dependencies.store');
            Route::patch('/dependencies/{dependency}', [MonitoringDependencySettingsController::class, 'update'])
                ->whereNumber('dependency')
                ->name('security-devices.settings.monitoring.dependencies.update');
            Route::post('/dependencies/{dependency}/deactivate', [MonitoringDependencySettingsController::class, 'deactivate'])
                ->whereNumber('dependency')
                ->name('security-devices.settings.monitoring.dependencies.deactivate');

            Route::get('/maintenance/requests/{requestUuid}', [MonitoringMaintenanceSettingsController::class, 'recover'])
                ->whereUuid('requestUuid')
                ->name('security-devices.settings.monitoring.maintenance.recover');
            Route::post('/maintenance/preview', [MonitoringMaintenanceSettingsController::class, 'preview'])
                ->name('security-devices.settings.monitoring.maintenance.preview');
            Route::post('/maintenance', [MonitoringMaintenanceSettingsController::class, 'store'])
                ->name('security-devices.settings.monitoring.maintenance.store');
            Route::patch('/maintenance/{window}', [MonitoringMaintenanceSettingsController::class, 'update'])
                ->whereNumber('window')
                ->name('security-devices.settings.monitoring.maintenance.update');
            Route::post('/maintenance/{window}/cancel', [MonitoringMaintenanceSettingsController::class, 'cancel'])
                ->whereNumber('window')
                ->name('security-devices.settings.monitoring.maintenance.cancel');

            Route::post('/retention/preview', [MonitoringRetentionSettingsController::class, 'preview'])
                ->name('security-devices.settings.monitoring.retention.preview');
            Route::post('/retention', [MonitoringRetentionSettingsController::class, 'store'])
                ->name('security-devices.settings.monitoring.retention.store');
            Route::patch('/retention/{policy}', [MonitoringRetentionSettingsController::class, 'update'])
                ->whereNumber('policy')
                ->name('security-devices.settings.monitoring.retention.update');
            Route::post('/retention/{policy}/deactivate', [MonitoringRetentionSettingsController::class, 'deactivate'])
                ->whereNumber('policy')
                ->name('security-devices.settings.monitoring.retention.deactivate');
            Route::post('/retention/{policy}/reactivate', [MonitoringRetentionSettingsController::class, 'reactivate'])
                ->whereNumber('policy')
                ->name('security-devices.settings.monitoring.retention.reactivate');
        });

    Route::prefix('/settings/credential-references')
        ->middleware('permission:securityDevices.commands.admin')
        ->group(function () {
            Route::post('/', [CredentialReferenceController::class, 'store'])
                ->name('security-devices.credential-references.store');
            Route::post('/{credentialReference}/test', [CredentialReferenceController::class, 'test'])
                ->name('security-devices.credential-references.test');
            Route::post('/{credentialReference}/rotate', [CredentialReferenceController::class, 'rotate'])
                ->name('security-devices.credential-references.rotate');
            Route::post('/{credentialReference}/revoke', [CredentialReferenceController::class, 'revoke'])
                ->name('security-devices.credential-references.revoke');
        });

    // ── UniFi provider configuration ─────────────────────────────
    // Was previously at /settings/integrations/unifi; kept behind the
    // module-scoped permission plus a fallback to the legacy provider-secrets
    // permission inside the controller to preserve existing admin access.
    Route::prefix('/integrations/unifi')
        ->middleware('permission:securityDevices.integrations.manage')
        ->group(function () {
            Route::get('/', [UnifiController::class, 'index'])
                ->name('security-devices.integrations.unifi');
            Route::post('/key', [UnifiController::class, 'saveKey'])
                ->name('security-devices.integrations.unifi.key');
            Route::post('/test', [UnifiController::class, 'testKey'])
                ->name('security-devices.integrations.unifi.test');
            Route::post('/rotate', [UnifiController::class, 'rotateKey'])
                ->name('security-devices.integrations.unifi.rotate');
            Route::post('/disable', [UnifiController::class, 'disable'])
                ->name('security-devices.integrations.unifi.disable');
            Route::post('/sync-sites', [UnifiController::class, 'syncSites'])
                ->name('security-devices.integrations.unifi.sync-sites');
            Route::post('/map-site', [UnifiController::class, 'mapSite'])
                ->name('security-devices.integrations.unifi.map-site');
            Route::delete('/map-site/{siteConfig}', [UnifiController::class, 'removeSiteMapping'])
                ->name('security-devices.integrations.unifi.remove-mapping');
            Route::post('/sync-devices', [UnifiController::class, 'syncDevices'])
                ->name('security-devices.integrations.unifi.sync-devices');
            Route::put('/hardware/{hardware}/room', [UnifiController::class, 'assignHardwareRoom'])
                ->name('security-devices.integrations.unifi.assign-room');
            Route::put('/defaults', [UnifiController::class, 'updateDefaults'])
                ->name('security-devices.integrations.unifi.defaults');
        });

    // ── Queclink integration hub ─────────────────────────────────
    // Direct device-to-server TCP intake is the primary path. No Queclink
    // cloud API capability is exposed until a verified public contract exists.
    Route::prefix('/integrations/queclink')
        ->middleware('permission:securityDevices.integrations.manage')
        ->group(function () {
            // Hub overview + listener config (replaces former scaffold page).
            Route::get('/', [QueclinkHubController::class, 'index'])
                ->name('security-devices.integrations.queclink');
            Route::post('/settings', [QueclinkHubController::class, 'saveSettings'])
                ->name('security-devices.integrations.queclink.settings');
            Route::get('/provisioning', [QueclinkHubController::class, 'provisioningString'])
                ->name('security-devices.integrations.queclink.provisioning');

            // Pairing flows — pending tray → vehicle / staff / client.
            Route::post('/devices/{queclinkDevice}/claim', [QueclinkHubController::class, 'claimDevice'])
                ->name('security-devices.integrations.queclink.claim');
            Route::post('/devices/{queclinkDevice}/reject', [QueclinkHubController::class, 'rejectDevice'])
                ->name('security-devices.integrations.queclink.reject');
            Route::post('/devices/{queclinkDevice}/restore', [QueclinkHubController::class, 'restoreDevice'])
                ->name('security-devices.integrations.queclink.restore');
            Route::post('/devices/{queclinkDevice}/release', [QueclinkHubController::class, 'releaseDevice'])
                ->name('security-devices.integrations.queclink.release');

            // Debug console — live frames + AT command REPL.
            Route::get('/frames', [QueclinkHubController::class, 'frames'])
                ->name('security-devices.integrations.queclink.frames');
            Route::get('/stream', [QueclinkHubController::class, 'stream'])
                ->name('security-devices.integrations.queclink.stream');
            Route::post('/devices/{queclinkDevice}/command', [QueclinkHubController::class, 'sendCommand'])
                ->name('security-devices.integrations.queclink.command');
            Route::post('/devices/{queclinkDevice}/configuration/read', [QueclinkHubController::class, 'readConfiguration'])
                ->name('security-devices.integrations.queclink.configuration.read');
            Route::post('/devices/{queclinkDevice}/configuration/{section}/read', [QueclinkHubController::class, 'readConfigurationSection'])
                ->name('security-devices.integrations.queclink.configuration.section.read');
            Route::post('/devices/{queclinkDevice}/configuration/server', [QueclinkHubController::class, 'updateServerConfiguration'])
                ->name('security-devices.integrations.queclink.configuration.server');
            Route::post('/devices/{queclinkDevice}/configuration/global', [QueclinkHubController::class, 'updateGlobalConfiguration'])
                ->name('security-devices.integrations.queclink.configuration.global');
            Route::post('/devices/{queclinkDevice}/configuration/{section}', [QueclinkHubController::class, 'updateSectionConfiguration'])
                ->whereIn('section', ['identity', 'tracking', 'alarms', 'power', 'connectivity', 'bluetooth', 'firmware'])
                ->name('security-devices.integrations.queclink.configuration.section');
            Route::post('/devices/{queclinkDevice}/configuration/resident-safety-profile', [QueclinkHubController::class, 'applyResidentSafetyProfile'])
                ->name('security-devices.integrations.queclink.configuration.resident-safety-profile');
            Route::post('/commands/{command}/cancel', [QueclinkHubController::class, 'cancelCommand'])
                ->name('security-devices.integrations.queclink.commands.cancel');
            Route::post('/bulk', [QueclinkHubController::class, 'bulkAction'])
                ->name('security-devices.integrations.queclink.bulk');

            // Configuration presets — saved bundles applied with one click.
            Route::post('/devices/{queclinkDevice}/presets/{preset}/apply', [QueclinkHubController::class, 'applyPreset'])
                ->name('security-devices.integrations.queclink.presets.apply');
            Route::post('/presets', [QueclinkHubController::class, 'storePreset'])
                ->name('security-devices.integrations.queclink.presets.store');
            Route::delete('/presets/{preset}', [QueclinkHubController::class, 'destroyPreset'])
                ->name('security-devices.integrations.queclink.presets.destroy');

            // Cleanup-only route for credentials saved by the retired cloud scaffold.
            Route::delete('/key', [QueclinkController::class, 'removeKey'])
                ->name('security-devices.integrations.queclink.remove');
        });

    // ── Milesight Development Platform ───────────────────────────
    Route::prefix('/integrations/milesight')
        ->middleware('permission:securityDevices.integrations.manage')
        ->group(function () {
            Route::get('/', [MilesightController::class, 'index'])
                ->name('security-devices.integrations.milesight');
            Route::post('/key', [MilesightController::class, 'saveKey'])
                ->name('security-devices.integrations.milesight.key');
            Route::post('/test', [MilesightController::class, 'testKey'])
                ->name('security-devices.integrations.milesight.test');
            Route::post('/rotate', [MilesightController::class, 'rotateKey'])
                ->name('security-devices.integrations.milesight.rotate');
            Route::post('/webhook', [MilesightController::class, 'saveWebhook'])
                ->name('security-devices.integrations.milesight.webhook.save');
            Route::delete('/webhook', [MilesightController::class, 'removeWebhook'])
                ->name('security-devices.integrations.milesight.webhook.remove');
            Route::delete('/key', [MilesightController::class, 'removeKey'])
                ->name('security-devices.integrations.milesight.remove');
            Route::post('/applications/sync', [MilesightController::class, 'syncApplications'])
                ->name('security-devices.integrations.milesight.applications.sync');
            Route::post('/applications/map', [MilesightController::class, 'mapApplication'])
                ->name('security-devices.integrations.milesight.applications.map');
            Route::delete('/applications/{siteConfig}', [MilesightController::class, 'removeApplicationMapping'])
                ->name('security-devices.integrations.milesight.applications.remove');
            Route::post('/devices/sync', [MilesightController::class, 'syncDevices'])
                ->name('security-devices.integrations.milesight.devices.sync');
        });

    // ── Microsoft Intune (read-only Windows computer discovery & import) ──
    Route::prefix('/integrations/intune')
        ->middleware('permission:securityDevices.integrations.manage')
        ->group(function () {
            Route::get('/', [IntuneController::class, 'index'])
                ->name('security-devices.integrations.intune');
            Route::post('/key', [IntuneController::class, 'saveKey'])
                ->name('security-devices.integrations.intune.key');
            Route::post('/test', [IntuneController::class, 'testKey'])
                ->name('security-devices.integrations.intune.test');
            Route::post('/rotate', [IntuneController::class, 'rotateKey'])
                ->name('security-devices.integrations.intune.rotate');
            Route::delete('/key', [IntuneController::class, 'removeKey'])
                ->name('security-devices.integrations.intune.remove');
            Route::post('/discover', [IntuneController::class, 'discover'])
                ->name('security-devices.integrations.intune.discover');
            Route::post('/import', [IntuneController::class, 'importDevices'])
                ->name('security-devices.integrations.intune.import');
            Route::post('/sync', [IntuneController::class, 'syncLinked'])
                ->name('security-devices.integrations.intune.sync');
        });

    // ── Reports ──────────────────────────────────────────────────
    Route::prefix('/reports')
        ->middleware('permission:securityDevices.reports.view')
        ->group(function () {
            Route::get('/', [ReportsController::class, 'index'])
                ->name('security-devices.reports');
            Route::get('/devices.csv', [ReportsController::class, 'exportDevices'])
                ->name('security-devices.reports.devices');
            Route::get('/events.csv', [ReportsController::class, 'exportEvents'])
                ->name('security-devices.reports.events');
            Route::get('/maintenance.csv', [ReportsController::class, 'exportMaintenance'])
                ->name('security-devices.reports.maintenance');
        });
});

// S&D Phase H (D15): clock-ins suggested from door badges. Outside the S&D group on purpose: the
// person answering is any staff member (it's their own row), and the managers who sort out gaps
// hold attendance.doorClockIns.review (from timesheets.approve), not always Security & devices keys.
// S&D Phase H (pg-sdr-19 to 19f): the Access page. Outside the group too: managers who only sort out
// clock-ins from doors (D15) open it on the Clock-ins tab alone. The controller and presenter check
// door access (securityDevices.viewAny + accessControl.view) for everything else, Site by Site.
Route::get('/security-devices/access', [\App\Domain\SecurityDevices\Screens\Http\AccessPageController::class, 'index'])
    ->middleware('auth')
    ->name('security-devices.access.index');

Route::middleware(['auth', 'throttle:30,1'])->group(function (): void {
    $doorClockins = \App\Domain\SecurityDevices\DoorClockins\Http\DoorClockinsController::class;
    // Pop-up 88 and the My Day band (19g): the person's own choice.
    Route::post('/door-clockins/{clockin}/answer', [$doorClockins, 'answer'])->whereNumber('clockin')->name('door-clockins.answer');
    // Access › Clock-ins (19e) and pop-up 89. The controller checks the review key and the Site.
    Route::get('/security-devices/access/clockins/{clockin}/panel', [$doorClockins, 'panel'])->whereNumber('clockin')->withoutMiddleware('throttle:30,1')->middleware('throttle:120,1')->name('security-devices.access.clockins.panel');
    Route::post('/security-devices/access/clockins/{clockin}/review', [$doorClockins, 'review'])->whereNumber('clockin')->name('security-devices.access.clockins.review');
    // Setup › Doors & access › Clock-ins from doors (15n) and pop-up 92: door access and timesheet authority both.
    Route::put('/security-devices/setup/clockins/doors', [$doorClockins, 'saveDoors'])->middleware('throttle:10,1')->name('security-devices.setup.clockins.doors');
    Route::post('/security-devices/setup/clockins/off', [$doorClockins, 'turnOff'])->middleware('throttle:10,1')->name('security-devices.setup.clockins.off');
    Route::patch('/security-devices/setup/clockins/settings', [$doorClockins, 'settings'])->name('security-devices.setup.clockins.settings');
});

// S&D Phase I: Obli fixes things, IT's side (9d, 15i, 15j, pop-ups 93-96). The services check the
// Obli permission (fixes.manage or pictures.view) and the computer's Site on every call.
Route::middleware(['auth', 'permission:securityDevices.viewAny', 'throttle:60,1'])->prefix('security-devices')->group(function (): void {
    $obli = \App\Domain\Obli\Fixes\Http\ObliFixWebController::class;
    Route::get('/obli/sessions/{uuid}', [$obli, 'session'])->whereUuid('uuid')->name('security-devices.obli.sessions.show');
    Route::post('/obli/sessions/{uuid}/pictures', [$obli, 'openPictures'])->whereUuid('uuid')->middleware('throttle:20,1')->name('security-devices.obli.sessions.pictures');
    Route::get('/obli/pictures/{picture}', [$obli, 'picture'])->whereNumber('picture')->withoutMiddleware('throttle:60,1')->middleware('throttle:240,1')->name('security-devices.obli.pictures.show');
    Route::get('/obli/requests/{id}', [$obli, 'request'])->whereNumber('id')->name('security-devices.obli.requests.show');
    Route::post('/obli/requests/{id}/decide', [$obli, 'decide'])->whereNumber('id')->middleware('throttle:20,1')->name('security-devices.obli.requests.decide');
    Route::post('/setup/obli/fixes/on', [$obli, 'turnOn'])->middleware('throttle:10,1')->name('security-devices.setup.obli.fixes.on');
    Route::post('/setup/obli/fixes/off', [$obli, 'turnOff'])->middleware('throttle:10,1')->name('security-devices.setup.obli.fixes.off');
    Route::put('/setup/obli/fixes-switch', [$obli, 'fixesSwitch'])->middleware('throttle:10,1')->name('security-devices.setup.obli.fixes-switch');
    Route::put('/setup/obli/helper', [$obli, 'helperSwitch'])->middleware('throttle:10,1')->name('security-devices.setup.obli.helper');
});

// S&D Phase I (pop-up 97): "Obli checks <Site>'s network", read-only checks through the Site's hub.
// IT only (devices.view + integrations.manage, as "Scan now"); the service checks the Site every call.
// Each route counts on its own (a named prefix), so the 2-second poll never uses up asking or raising an IT job.
Route::middleware(['auth', 'permission:securityDevices.viewAny', 'permission:securityDevices.integrations.manage'])->prefix('security-devices')->group(function (): void {
    $network = \App\Domain\SecurityDevices\NetworkChecks\Http\NetworkCheckController::class;
    Route::get('/sites/{site}/network-checks', [$network, 'panel'])->whereNumber('site')->middleware('throttle:30,1,netcheck-panel')->name('security-devices.network-checks.panel');
    Route::post('/sites/{site}/network-checks', [$network, 'ask'])->whereNumber('site')->middleware('throttle:6,1,netcheck-ask')->name('security-devices.network-checks.ask');
    Route::get('/network-checks/{uuid}', [$network, 'show'])->whereUuid('uuid')->middleware('throttle:120,1,netcheck-show')->name('security-devices.network-checks.show');
    Route::post('/network-checks/{uuid}/ticket', [$network, 'ticket'])->whereUuid('uuid')->middleware('throttle:10,1,netcheck-ticket')->name('security-devices.network-checks.ticket');
});
