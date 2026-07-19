<?php

use App\Domain\SecurityDevices\Http\Controllers\AlertsEventsController;
use App\Domain\SecurityDevices\Http\Controllers\CategoryPageController;
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
use App\Domain\SecurityDevices\Http\Controllers\MonitoringOperationsController;
use App\Domain\SecurityDevices\Http\Controllers\ReportsController;
use App\Domain\SecurityDevices\Http\Controllers\SettingsAuditController;
use App\Domain\SecurityDevices\Http\Controllers\SiteTechnologyController;
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

    // ── UniFi provider configuration ─────────────────────────────
    // Was previously at /settings/integrations/unifi; kept behind the
    // module-scoped permission plus a fallback to the legacy tenant-secrets
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
    // Direct device-to-server TCP intake is the primary path; IMS cloud
    // credentials (kept for parity) live under /key on the same prefix.
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
            Route::post('/commands/{command}/retry', [QueclinkHubController::class, 'retryCommand'])
                ->name('security-devices.integrations.queclink.commands.retry');
            Route::post('/bulk', [QueclinkHubController::class, 'bulkAction'])
                ->name('security-devices.integrations.queclink.bulk');

            // Configuration presets — saved bundles applied with one click.
            Route::post('/devices/{queclinkDevice}/presets/{preset}/apply', [QueclinkHubController::class, 'applyPreset'])
                ->name('security-devices.integrations.queclink.presets.apply');
            Route::post('/presets', [QueclinkHubController::class, 'storePreset'])
                ->name('security-devices.integrations.queclink.presets.store');
            Route::delete('/presets/{preset}', [QueclinkHubController::class, 'destroyPreset'])
                ->name('security-devices.integrations.queclink.presets.destroy');

            // IMS cloud credential management (legacy scaffold endpoints).
            Route::post('/key', [QueclinkController::class, 'saveKey'])
                ->name('security-devices.integrations.queclink.key');
            Route::post('/test', [QueclinkController::class, 'testKey'])
                ->name('security-devices.integrations.queclink.test');
            Route::post('/rotate', [QueclinkController::class, 'rotateKey'])
                ->name('security-devices.integrations.queclink.rotate');
            Route::delete('/key', [QueclinkController::class, 'removeKey'])
                ->name('security-devices.integrations.queclink.remove');
        });

    // ── Milesight provider configuration (scaffold) ──────────────
    // LoRaWAN credentials and connection testing are live; gateway /
    // application mapping + LoRaWAN sensor import ship in PR D1.
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
            Route::delete('/key', [MilesightController::class, 'removeKey'])
                ->name('security-devices.integrations.milesight.remove');
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
