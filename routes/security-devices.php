<?php

use App\Domain\SecurityDevices\AccessControl\Http\Controllers\AccessControlController;
use App\Domain\SecurityDevices\Credentials\Http\Controllers\CredentialReferenceController;
use App\Domain\SecurityDevices\Http\Controllers\AlertsEventsController;
use App\Domain\SecurityDevices\Http\Controllers\CategoryPageController;
use App\Domain\SecurityDevices\Http\Controllers\ConfigurationSnapshotController;
use App\Domain\SecurityDevices\Http\Controllers\DashboardController;
use App\Domain\SecurityDevices\Http\Controllers\DeviceAssignmentController;
use App\Domain\SecurityDevices\Http\Controllers\DeviceController;
use App\Domain\SecurityDevices\Http\Controllers\DeviceDocumentController;
use App\Domain\SecurityDevices\Http\Controllers\DeviceGroupController;
use App\Domain\SecurityDevices\Http\Controllers\DiscoveryCollectorController;
use App\Domain\SecurityDevices\Http\Controllers\Integrations\MilesightController;
use App\Domain\SecurityDevices\Http\Controllers\Integrations\QueclinkController;
use App\Domain\SecurityDevices\Http\Controllers\Integrations\QueclinkHubController;
use App\Domain\SecurityDevices\Http\Controllers\Integrations\UnifiController;
use App\Domain\SecurityDevices\Http\Controllers\IntegrationsHubController;
use App\Domain\SecurityDevices\Http\Controllers\MaintenanceHealthController;
use App\Domain\SecurityDevices\Http\Controllers\MaintenanceOperationsController;
use App\Domain\SecurityDevices\Http\Controllers\MonitoringDeadLetterController;
use App\Domain\SecurityDevices\Http\Controllers\MonitoringOperationsController;
use App\Domain\SecurityDevices\Http\Controllers\MonitoringRuntimeHealthController;
use App\Domain\SecurityDevices\Http\Controllers\ReportsController;
use App\Domain\SecurityDevices\Http\Controllers\SettingsAuditController;
use App\Domain\SecurityDevices\Http\Controllers\SiteTechnologyController;
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

    Route::get('/devices/{device}/commands/confirm-identity', [DeviceCommandController::class, 'confirmIdentity'])
        ->middleware('permission:securityDevices.commands.operate|securityDevices.commands.manage|securityDevices.commands.control|securityDevices.commands.admin')
        ->name('security-devices.devices.commands.confirm-identity');

    Route::post('/devices/{device}/commands', [DeviceCommandController::class, 'store'])
        ->middleware([
            AuditDeniedDeviceCommandRequest::class,
            'permission:securityDevices.commands.operate|securityDevices.commands.manage|securityDevices.commands.control|securityDevices.commands.admin',
        ])
        ->name('security-devices.devices.commands.store');

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

    Route::get('/devices/{device}/assignments', [DeviceAssignmentController::class, 'history'])
        ->middleware('permission:securityDevices.devices.view')
        ->name('security-devices.devices.assignments');

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

    Route::get('/runtime-health', MonitoringRuntimeHealthController::class)
        ->middleware('permission:securityDevices.events.view')
        ->name('security-devices.runtime-health');

    Route::prefix('/monitoring/dead-letters')
        ->middleware('permission:securityDevices.integrations.manage')
        ->group(function () {
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

    Route::get('/settings', SettingsAuditController::class)
        ->name('security-devices.settings');

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
