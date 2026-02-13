<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Integration\Integration;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Integration\IntegrationSyncLog;
use App\Models\Integration\IntegrationTenantSecret;
use App\Models\LocationHardware;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Services\Integration\IntegrationAdapterRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;

class UnifiSettingsController extends Controller
{
    private const PROVIDER = 'unifi';

    /**
     * Show the UniFi integration settings page.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('integrations.manage_tenant_secrets'), 403);

        $tenantId = $this->resolveTenantId($user);

        // Tenant secret (never expose encrypted values)
        $tenantSecret = IntegrationTenantSecret::query()
            ->forTenant($tenantId)
            ->where('provider', self::PROVIDER)
            ->first();

        $secretConfig = is_array($tenantSecret?->config) ? $tenantSecret->config : [];
        $discoveredSites = collect($secretConfig['discovered_sites'] ?? [])
            ->map(fn (array $site) => [
                'external_id' => (string) ($site['external_id'] ?? ''),
                'name' => $site['name'] ?? 'Unknown',
                'meta' => $site['meta'] ?? [],
            ])
            ->filter(fn (array $site) => $site['external_id'] !== '')
            ->values()
            ->all();

        // Site configs with related site metadata
        $siteConfigs = IntegrationSiteConfig::query()
            ->forTenant($tenantId)
            ->forProvider(self::PROVIDER)
            ->with('site:id,name,type')
            ->orderBy('site_id')
            ->get()
            ->map(fn (IntegrationSiteConfig $config) => [
                'id' => $config->id,
                'site_id' => $config->site_id,
                'site_name' => $config->site?->name ?? 'Unknown site',
                'site_type' => $config->site?->type,
                'status' => $config->status,
                'mapped_external_site_id' => $config->mapped_external_site_id,
                'mapped_external_site_name' => $config->mapped_external_site_name,
                'is_active' => (bool) $config->is_active,
            ])
            ->values()
            ->all();

        // All tenant locations for mapping
        $sites = Site::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'name', 'type']);

        // Rooms used for room assignment in settings
        $rooms = SiteRoom::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('site_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'site_id', 'name']);

        // Synced UniFi devices across tenant locations
        $syncedDevices = LocationHardware::query()
            ->where('tenant_id', $tenantId)
            ->where('provider', self::PROVIDER)
            ->with(['site:id,name,type', 'room:id,name'])
            ->orderBy('site_id')
            ->orderBy('name')
            ->get()
            ->map(fn (LocationHardware $device) => [
                'id' => $device->id,
                'site_id' => $device->site_id,
                'site_name' => $device->site?->name ?? 'Unknown site',
                'site_type' => $device->site?->type,
                'room_id' => $device->room_id,
                'room_name' => $device->room?->name,
                'name' => $device->name,
                'category' => $device->category,
                'status' => $device->status,
                'provider_entity_id' => data_get($device->external_ref, 'provider_entity_id'),
                'provider_type' => data_get($device->meta, 'provider_type') ?: data_get($device->external_ref, 'provider_type'),
                'model' => data_get($device->external_ref, 'model') ?: data_get($device->meta, 'model_long'),
                'last_seen_at' => $device->last_seen_at?->toISOString(),
            ])
            ->values()
            ->all();

        // Recent sync logs
        $syncLogs = IntegrationSyncLog::query()
            ->forTenant($tenantId)
            ->forProvider(self::PROVIDER)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (IntegrationSyncLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'status' => $log->status,
                'items_processed' => $log->items_processed,
                'items_created' => $log->items_created,
                'items_updated' => $log->items_updated,
                'items_errored' => $log->items_errored,
                'error_message' => $log->error_message,
                'started_at' => $log->started_at?->toDateTimeString(),
                'completed_at' => $log->completed_at?->toDateTimeString(),
            ])
            ->values()
            ->all();

        return Inertia::render('settings/integrations/unifi', [
            'tenantSecret' => $tenantSecret ? [
                'status' => $tenantSecret->status,
                'secret_last4' => $tenantSecret->secret_last4,
                'last_tested_at' => $tenantSecret->last_tested_at?->toDateTimeString(),
                'last_synced_at' => $tenantSecret->last_synced_at?->toDateTimeString(),
                'sites_synced_at' => $secretConfig['sites_synced_at'] ?? null,
                'config' => collect($secretConfig)->except(['discovered_sites', 'sites_synced_at'])->all(),
            ] : null,
            'discoveredSites' => $discoveredSites,
            'siteConfigs' => $siteConfigs,
            'sites' => $sites,
            'rooms' => $rooms,
            'syncedDevices' => $syncedDevices,
            'syncLogs' => $syncLogs,
            'can' => [
                'manage' => $user->canDo('integrations.manage_tenant_secrets'),
            ],
        ]);
    }

    /**
     * Save or update the tenant API key.
     */
    public function saveKey(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('integrations.manage_tenant_secrets'), 403);

        $request->validate([
            'api_key' => ['required', 'string'],
        ]);

        $tenantId = $this->resolveTenantId($user);

        IntegrationTenantSecret::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'provider' => self::PROVIDER,
            ],
            [
                'secret_encrypted' => Crypt::encryptString($request->string('api_key')->toString()),
                'secret_last4' => substr($request->string('api_key')->toString(), -4),
                'status' => IntegrationTenantSecret::STATUS_DISCONNECTED,
                'last_error' => null,
                'created_by' => $user->id,
            ],
        );

        Integration::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'provider' => self::PROVIDER,
            ],
            [
                'display_name' => 'UniFi',
                'status' => Integration::STATUS_INACTIVE,
                'last_error' => null,
            ],
        );

        return redirect()->back()->with('success', 'UniFi API key saved.');
    }

    /**
     * Test the API key against UniFi API.
     */
    public function testKey(Request $request, IntegrationAdapterRegistry $registry)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('integrations.manage_tenant_secrets'), 403);

        $tenantId = $this->resolveTenantId($user);

        $secret = IntegrationTenantSecret::query()
            ->forTenant($tenantId)
            ->where('provider', self::PROVIDER)
            ->firstOrFail();

        if (!$registry->has(self::PROVIDER)) {
            return redirect()->back()->with('error', 'UniFi adapter is not registered.');
        }

        $adapter = $registry->resolve(self::PROVIDER);
        $isConnected = $adapter->testConnection($secret);

        if ($isConnected) {
            $secret->update([
                'status' => IntegrationTenantSecret::STATUS_CONNECTED,
                'last_tested_at' => now(),
                'last_error' => null,
            ]);

            Integration::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'provider' => self::PROVIDER,
                ],
                [
                    'display_name' => 'UniFi',
                    'status' => Integration::STATUS_ACTIVE,
                    'last_tested_at' => now(),
                    'last_error' => null,
                ],
            );

            return redirect()->back()->with('success', 'UniFi connection test succeeded.');
        }

        $secret->update([
            'status' => IntegrationTenantSecret::STATUS_ERROR,
            'last_tested_at' => now(),
            'last_error' => 'UniFi API rejected the key or was unreachable.',
        ]);

        Integration::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'provider' => self::PROVIDER,
            ],
            [
                'display_name' => 'UniFi',
                'status' => Integration::STATUS_ERROR,
                'last_tested_at' => now(),
                'last_error' => 'UniFi API rejected the key or was unreachable.',
            ],
        );

        return redirect()->back()->with('error', 'UniFi connection test failed.');
    }

    /**
     * Rotate the API key.
     */
    public function rotateKey(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('integrations.manage_tenant_secrets'), 403);

        $request->validate([
            'api_key' => ['required', 'string'],
        ]);

        $tenantId = $this->resolveTenantId($user);

        $secret = IntegrationTenantSecret::query()
            ->forTenant($tenantId)
            ->where('provider', self::PROVIDER)
            ->firstOrFail();

        $secret->update([
            'secret_encrypted' => Crypt::encryptString($request->string('api_key')->toString()),
            'secret_last4' => substr($request->string('api_key')->toString(), -4),
            'rotated_at' => now(),
            'status' => IntegrationTenantSecret::STATUS_DISCONNECTED,
            'last_error' => null,
        ]);

        return redirect()->back()->with('success', 'API key rotated. Run Test Connection.');
    }

    /**
     * Discover UniFi sites and cache them in tenant config.
     */
    public function syncSites(Request $request, IntegrationAdapterRegistry $registry)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('integrations.manage_tenant_secrets'), 403);

        $tenantId = $this->resolveTenantId($user);

        $secret = IntegrationTenantSecret::query()
            ->forTenant($tenantId)
            ->where('provider', self::PROVIDER)
            ->firstOrFail();

        if (!$registry->has(self::PROVIDER)) {
            return redirect()->back()->with('error', 'UniFi adapter is not registered.');
        }

        $syncLog = IntegrationSyncLog::create([
            'tenant_id' => $tenantId,
            'provider' => self::PROVIDER,
            'action' => 'discover_sites',
            'status' => IntegrationSyncLog::STATUS_STARTED,
            'started_at' => now(),
        ]);

        try {
            $adapter = $registry->resolve(self::PROVIDER);
            $sites = $adapter->discoverSites($secret);

            $config = $this->mergeSecretConfig(
                $secret->config,
                [
                    'discovered_sites' => array_values($sites),
                    'sites_synced_at' => now()->toISOString(),
                ]
            );

            $secret->update([
                'config' => $config,
                'last_synced_at' => now(),
                'last_error' => null,
            ]);

            $syncLog->update([
                'items_processed' => count($sites),
                'items_created' => count($sites),
            ]);

            if (count($sites) > 0) {
                $syncLog->markCompleted(IntegrationSyncLog::STATUS_SUCCESS);
                return redirect()->back()->with('success', 'UniFi sites synced successfully.');
            }

            $syncLog->markCompleted(IntegrationSyncLog::STATUS_PARTIAL, 'No sites returned by UniFi API.');
            return redirect()->back()->with('warning', 'No UniFi sites returned by API.');
        } catch (\Throwable $e) {
            $syncLog->markCompleted(IntegrationSyncLog::STATUS_FAILED, $e->getMessage());

            $secret->update([
                'status' => IntegrationTenantSecret::STATUS_ERROR,
                'last_error' => $e->getMessage(),
            ]);

            return redirect()->back()->with('error', 'Failed to sync UniFi sites: ' . $e->getMessage());
        }
    }

    /**
     * Map a UniFi site to a platform location (site/head office/facility/house).
     */
    public function mapSite(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('integrations.manage_tenant_secrets'), 403);

        $request->validate([
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'external_site_id' => ['required', 'string', 'max:255'],
            'external_site_name' => ['nullable', 'string', 'max:255'],
        ]);

        $tenantId = $this->resolveTenantId($user);

        $site = Site::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail((int) $request->input('site_id'));

        IntegrationSiteConfig::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'site_id' => $site->id,
                'provider' => self::PROVIDER,
            ],
            [
                'mapped_external_site_id' => $request->input('external_site_id'),
                'mapped_external_site_name' => $request->input('external_site_name'),
                'status' => IntegrationSiteConfig::STATUS_HYBRID,
                'is_active' => true,
            ],
        );

        return redirect()->back()->with('success', 'Site mapping saved.');
    }

    /**
     * Remove a UniFi site mapping.
     */
    public function removeSiteMapping(Request $request, IntegrationSiteConfig $siteConfig)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('integrations.manage_tenant_secrets'), 403);
        $tenantId = $this->resolveTenantId($user);

        abort_unless(
            $siteConfig->tenant_id === $tenantId && $siteConfig->provider === self::PROVIDER,
            404
        );

        $siteConfig->delete();

        return redirect()->back()->with('success', 'Site mapping removed.');
    }

    /**
     * Sync devices for a mapped site config.
     */
    public function syncDevices(Request $request, IntegrationAdapterRegistry $registry)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('integrations.manage_tenant_secrets'), 403);

        $request->validate([
            'site_config_id' => ['required', 'integer', 'exists:integration_site_configs,id'],
        ]);

        $tenantId = $this->resolveTenantId($user);

        $siteConfig = IntegrationSiteConfig::query()
            ->forTenant($tenantId)
            ->forProvider(self::PROVIDER)
            ->findOrFail((int) $request->input('site_config_id'));

        if (empty($siteConfig->mapped_external_site_id)) {
            return redirect()->back()->with('error', 'Map a UniFi site before syncing devices.');
        }

        $secret = IntegrationTenantSecret::query()
            ->forTenant($tenantId)
            ->where('provider', self::PROVIDER)
            ->connected()
            ->first();

        if (!$secret) {
            return redirect()->back()->with('error', 'Test and connect your UniFi API key before syncing devices.');
        }

        if (!$registry->has(self::PROVIDER)) {
            return redirect()->back()->with('error', 'UniFi adapter is not registered.');
        }

        $syncLog = IntegrationSyncLog::create([
            'tenant_id' => $tenantId,
            'provider' => self::PROVIDER,
            'site_id' => $siteConfig->site_id,
            'action' => 'sync_devices',
            'status' => IntegrationSyncLog::STATUS_STARTED,
            'started_at' => now(),
        ]);

        try {
            $adapter = $registry->resolve(self::PROVIDER);
            $result = $adapter->syncDevices($siteConfig, $secret);

            $syncLog->update([
                'items_processed' => $result->processed,
                'items_created' => $result->created,
                'items_updated' => $result->updated,
                'items_errored' => $result->errored,
            ]);

            if ($result->isSuccess()) {
                $syncLog->markCompleted(IntegrationSyncLog::STATUS_SUCCESS);
            } elseif ($result->isPartial()) {
                $syncLog->markCompleted(IntegrationSyncLog::STATUS_PARTIAL);
            } else {
                $syncLog->markCompleted(IntegrationSyncLog::STATUS_FAILED, $result->error);
            }

            $secret->update([
                'last_synced_at' => now(),
                'last_error' => $result->error,
            ]);

            if (!$result->isSuccess() && !$result->isPartial()) {
                return redirect()->back()->with('error', $result->error ?? 'UniFi device sync failed.');
            }

            return redirect()->back()->with(
                'success',
                "Device sync complete. Processed {$result->processed}, created {$result->created}, updated {$result->updated}, errored {$result->errored}."
            );
        } catch (\Throwable $e) {
            $syncLog->markCompleted(IntegrationSyncLog::STATUS_FAILED, $e->getMessage());

            return redirect()->back()->with('error', 'Failed to sync UniFi devices: ' . $e->getMessage());
        }
    }

    /**
     * Assign or clear room for a synced UniFi hardware item.
     */
    public function assignHardwareRoom(Request $request, LocationHardware $hardware)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('integrations.manage_tenant_secrets'), 403);
        $tenantId = $this->resolveTenantId($user);

        abort_unless($hardware->tenant_id === $tenantId && $hardware->provider === self::PROVIDER, 404);

        $validated = $request->validate([
            'room_id' => ['nullable', 'integer', 'exists:site_rooms,id'],
        ]);

        $roomId = $validated['room_id'] ?? null;

        if ($roomId !== null) {
            $room = SiteRoom::query()
                ->where('tenant_id', $tenantId)
                ->where('site_id', $hardware->site_id)
                ->find($roomId);

            if (!$room) {
                return redirect()->back()->with('error', 'Selected room does not belong to the device location.');
            }
        }

        $hardware->update(['room_id' => $roomId]);

        return redirect()->back()->with('success', 'Device room assignment updated.');
    }

    /**
     * Update default refresh/alert configuration.
     */
    public function updateDefaults(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('integrations.manage_tenant_secrets'), 403);

        $request->validate([
            'config' => ['nullable', 'array'],
            'config.refresh_interval_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'config.alert_motion_events' => ['nullable', 'boolean'],
            'config.alert_device_offline' => ['nullable', 'boolean'],
            'config.quiet_hours_start' => ['nullable', 'date_format:H:i'],
            'config.quiet_hours_end' => ['nullable', 'date_format:H:i'],
        ]);

        $tenantId = $this->resolveTenantId($user);

        $secret = IntegrationTenantSecret::query()
            ->forTenant($tenantId)
            ->where('provider', self::PROVIDER)
            ->firstOrFail();

        $secret->update([
            'config' => $this->mergeSecretConfig(
                $secret->config,
                $request->input('config', [])
            ),
        ]);

        return redirect()->back()->with('success', 'Default settings updated.');
    }

    /**
     * Merge settings while preserving discovered-site cache.
     */
    private function mergeSecretConfig(?array $existingConfig, array $newConfig): array
    {
        $existing = is_array($existingConfig) ? $existingConfig : [];

        $preserved = [
            'discovered_sites' => $existing['discovered_sites'] ?? [],
            'sites_synced_at' => $existing['sites_synced_at'] ?? null,
        ];

        return array_merge($preserved, $existing, $newConfig);
    }

    private function resolveTenantId($user): int
    {
        $tenantId = $user->tenant_id ?? $user->organization_id ?? 1;

        return (int) $tenantId;
    }
}
