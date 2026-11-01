<?php

use App\Domain\SecurityDevices\AccessControl\Http\Controllers\AccessControlController;
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
use App\Domain\SecurityDevices\Http\Controllers\AlertsEventsController;
use App\Domain\SecurityDevices\Http\Controllers\CategoryPageController;
use App\Domain\SecurityDevices\Http\Controllers\CollectorInstallationController;
use App\Domain\SecurityDevices\Http\Controllers\ConfigurationSnapshotComparisonController;
use App\Domain\SecurityDevices\Http\Controllers\ConfigurationSnapshotController;
use App\Domain\SecurityDevices\Http\Controllers\DashboardController;
use App\Domain\SecurityDevices\Http\Controllers\DeviceAssignmentController;
use App\Domain\SecurityDevices\Http\Controllers\DeviceController;
use App\Domain\SecurityDevices\Http\Controllers\DeviceDocumentController;
use App\Domain\SecurityDevices\Http\Controllers\DeviceGroupController;
use App\Domain\SecurityDevices\Http\Controllers\DeviceOfflineAlertController;
use App\Domain\SecurityDevices\Http\Controllers\SshHostKeyTrustController;
use App\Domain\SecurityDevices\Http\Controllers\DevicePingController;
use App\Domain\SecurityDevices\Http\Controllers\DiscoveryCandidateReviewController;
use App\Domain\SecurityDevices\Http\Controllers\DiscoveryCollectorController;
use App\Domain\SecurityDevices\Http\Controllers\DiscoveryScopeLifecycleController;
use App\Domain\SecurityDevices\Http\Controllers\Integrations\IntuneController;
use App\Domain\SecurityDevices\Http\Controllers\Integrations\MilesightController;
use App\Domain\SecurityDevices\Http\Controllers\Integrations\QueclinkController;
use App\Domain\SecurityDevices\Http\Controllers\Integrations\QueclinkHubController;
use App\Domain\SecurityDevices\Http\Controllers\Integrations\UnifiController;
use App\Domain\SecurityDevices\Http\Controllers\IntegrationsHubController;
use App\Domain\SecurityDevices\Http\Controllers\MaintenanceHealthController;
use App\Domain\SecurityDevices\Http\Controllers\MaintenanceOperationsController;
use App\Domain\SecurityDevices\Http\Controllers\MonitoringAlertRouteSettingsController;
use App\Domain\SecurityDevices\Http\Controllers\MonitoringCollectorLifecycleController;
use App\Domain\SecurityDevices\Http\Controllers\MonitoringCoverageSettingsController;
use App\Domain\SecurityDevices\Http\Controllers\MonitoringDeadLetterController;
use App\Domain\SecurityDevices\Http\Controllers\MonitoringDependencySettingsController;
use App\Domain\SecurityDevices\Http\Controllers\MonitoringEffectivePolicyPreviewController;
use App\Domain\SecurityDevices\Http\Controllers\MonitoringHistoryController;
use App\Domain\SecurityDevices\Http\Controllers\MonitoringMaintenanceSettingsController;
use App\Domain\SecurityDevices\Http\Controllers\MonitoringOperationsController;
use App\Domain\SecurityDevices\Http\Controllers\MonitoringProfileSettingsController;
use App\Domain\SecurityDevices\Http\Controllers\MonitoringRetentionSettingsController;
use App\Domain\SecurityDevices\Http\Controllers\MonitoringRuntimeHealthController;
use App\Domain\SecurityDevices\Http\Controllers\MonitoringThresholdSettingsController;
use App\Domain\SecurityDevices\Http\Controllers\NativeMonitorLifecycleController;
use App\Domain\SecurityDevices\Http\Controllers\QueclinkUnitConsoleController;
use App\Domain\SecurityDevices\Http\Controllers\ReportsController;
use App\Domain\SecurityDevices\Http\Controllers\SettingsAuditController;
use App\Domain\SecurityDevices\Http\Controllers\SiteHubWizardController;
use App\Domain\SecurityDevices\Http\Controllers\SiteTechnologyController;
use App\Domain\SecurityDevices\Http\Controllers\SupportedDevicesController;
use App\Domain\SecurityDevices\Management\Http\Controllers\DeviceCommandApprovalController;
use App\Domain\SecurityDevices\Management\Http\Controllers\DeviceCommandBatchController;
use App\Domain\SecurityDevices\Management\Http\Controllers\DeviceCommandBatchDecisionController;
use App\Domain\SecurityDevices\Management\Http\Controllers\DeviceCommandBatchExecutionController;
use App\Domain\SecurityDevices\Management\Http\Controllers\DeviceCommandBreakGlassReviewController;
use App\Domain\SecurityDevices\Management\Http\Controllers\DeviceCommandController;
use App\Domain\SecurityDevices\Management\Http\Controllers\DeviceCommandEvidenceController;
use App\Domain\SecurityDevices\Management\Http\Controllers\DeviceCommandExecutionController;
use App\Domain\SecurityDevices\Management\Http\Middleware\AuditDeniedDeviceCommandRequest;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware([
    'auth',
    'permission:securityDevices.viewAny',
])->prefix('security-devices')->group(function () {
    Route::get('/', DashboardController::class)
        ->name('security-devices.index');

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

    Route::get('/devices', [DeviceController::class, 'index'])
        ->middleware('permission:securityDevices.devices.view')
        ->name('security-devices.devices.index');

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

    Route::get('/command-batches/{batch}', [DeviceCommandBatchController::class, 'show'])
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

    Route::get('/sites', [SiteTechnologyController::class, 'index'])
        ->middleware('permission:securityDevices.devices.view')
        ->name('security-devices.sites.index');

    Route::get('/sites/{site}', [SiteTechnologyController::class, 'show'])
        ->middleware('permission:securityDevices.devices.view')
        ->name('security-devices.sites.show');

    Route::get('/network-it', [CategoryPageController::class, 'networkIt'])
        ->middleware('permission:securityDevices.devices.view')
        ->name('security-devices.network-it');

    Route::get('/security', [CategoryPageController::class, 'security'])
        ->middleware('permission:securityDevices.devices.view')
        ->name('security-devices.security');

    Route::get('/healthcare', [CategoryPageController::class, 'healthcare'])
        ->middleware('permission:securityDevices.devices.view')
        ->name('security-devices.healthcare');

    Route::get('/tracking', [CategoryPageController::class, 'tracking'])
        ->middleware('permission:securityDevices.devices.view')
        ->name('security-devices.tracking');

    Route::get('/facilities-iot', [CategoryPageController::class, 'facilitiesIot'])
        ->middleware('permission:securityDevices.devices.view')
        ->name('security-devices.facilities-iot');

    // ── Legacy category compatibility redirects ──────────────────
    // Route names remain stable, while the controller preserves filters and
    // device context when moving users into the matching canonical local tab.

    Route::get('/alarms', [CategoryPageController::class, 'alarms'])
        ->name('security-devices.alarms');

    Route::get('/cctv', [CategoryPageController::class, 'cctv'])
        ->name('security-devices.cctv');

    Route::get('/access-control', [CategoryPageController::class, 'accessControl'])
        ->name('security-devices.access-control');

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

    Route::get('/tracking-devices', [CategoryPageController::class, 'trackingDevices'])
        ->name('security-devices.tracking-devices');

    Route::get('/smart-iot-healthcare', [CategoryPageController::class, 'smartIotHealthcare'])
        ->name('security-devices.smart-iot-healthcare');

    Route::get('/it-infrastructure', [CategoryPageController::class, 'itInfrastructure'])
        ->name('security-devices.it-infrastructure');

    Route::get('/facilities', [CategoryPageController::class, 'facilities'])
        ->name('security-devices.facilities');

    // ── Operations pages (specific permission gates) ──────────────

    // ── Device Groups ───────────────────────────────────────────

    Route::get('/device-groups', [DeviceGroupController::class, 'index'])
        ->middleware('permission:securityDevices.groups.manage')
        ->name('security-devices.device-groups');

    Route::get('/device-groups/create', [DeviceGroupController::class, 'create'])
        ->middleware('permission:securityDevices.groups.manage')
        ->name('security-devices.device-groups.create');

    Route::post('/device-groups', [DeviceGroupController::class, 'store'])
        ->middleware('permission:securityDevices.groups.manage')
        ->name('security-devices.device-groups.store');

    Route::post('/device-groups/auto-rules/preview', [DeviceGroupController::class, 'previewDraftAutoRules'])
        ->middleware('permission:securityDevices.groups.manage')
        ->name('security-devices.device-groups.auto-rules.preview-draft');

    Route::get('/device-groups/{group}', [DeviceGroupController::class, 'show'])
        ->middleware('permission:securityDevices.groups.manage')
        ->name('security-devices.device-groups.show');

    // Own throttle bucket (prefix) so this read never spends another route's limit.
    Route::get('/device-groups/{group}/maintenance-policy-preview', [DeviceGroupController::class, 'previewMaintenancePolicy'])
        ->middleware(['permission:securityDevices.groups.manage', 'permission:securityDevices.monitoring.manage', 'throttle:30,1,device-group-maintenance-preview'])
        ->missing(static fn () => DeviceGroupController::previewUnavailable(404))
        ->name('security-devices.device-groups.maintenance-policy-preview');

    Route::get('/device-groups/{group}/edit', [DeviceGroupController::class, 'edit'])
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

    Route::get('/alerts-events', [AlertsEventsController::class, 'index'])
        ->middleware('permission:securityDevices.events.view')
        ->name('security-devices.alerts-events');

    Route::get('/monitoring', MonitoringOperationsController::class)
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

    Route::get('/maintenance-health', [MaintenanceHealthController::class, 'index'])
        ->middleware('permission:securityDevices.maintenance.view')
        ->name('security-devices.maintenance-health');

    Route::get('/maintenance', MaintenanceOperationsController::class)
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

    Route::get('/integrations', IntegrationsHubController::class)
        ->middleware('permission:securityDevices.integrations.view')
        ->name('security-devices.integrations');

    Route::get('/discovery', DiscoveryCollectorController::class)
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

    Route::get('/settings', SettingsAuditController::class)
        ->name('security-devices.settings');

    Route::get('/supported-devices', SupportedDevicesController::class)
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
