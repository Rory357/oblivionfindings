<?php

namespace App\Domain\SecurityDevices\Http\Controllers\Integrations;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Presenters\IntegrationSiteCredentialsPresenter;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use App\Models\Integration\Integration;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Integration\IntegrationSyncLog;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Services\Integration\IntegrationAdapterRegistry;
use App\Services\Integration\UnifiOperationalBridgeService;
use App\Support\LegacyStorageContext;
use App\Support\SafeOperationalData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;

class UnifiController extends Controller
{
    private const PROVIDER = 'unifi';

    private const PERMISSION_MANAGE = 'securityDevices.integrations.manage';

    public function __construct(
        private readonly IntegrationSiteCredentialsPresenter $siteCredentials,
        private readonly SecurityDevicesAccessService $access,
    ) {}

    /**
     * Show the UniFi integration settings page.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($this->userCanManage($user), 403);

        $siteIds = $this->access->accessibleSiteIds($user);

        // Provider connection (never expose encrypted values or raw config)
        $providerConnection = IntegrationProviderConnection::query()
            ->forProvider(self::PROVIDER)
            ->first();

        $connectionConfig = is_array($providerConnection?->config) ? $providerConnection->config : [];
        $discoveredSites = collect($connectionConfig['discovered_sites'] ?? [])
            ->map(fn (array $site) => [
                'mapping_token' => $this->mappingToken((string) ($site['external_id'] ?? '')),
                'name' => $site['name'] ?? 'Unknown',
                'device_count' => is_numeric(data_get($site, 'meta.device_count')) ? (int) data_get($site, 'meta.device_count') : null,
            ])
            ->filter(fn (array $site) => $site['mapping_token'] !== '')
            ->values()
            ->all();

        // Site configs with related site metadata
        $siteConfigs = IntegrationSiteConfig::query()
            ->forProvider(self::PROVIDER)
            ->whereIn('site_id', $siteIds)
            ->whereHas('site')
            ->with('site:id,name,type,tenant_id')
            ->orderBy('site_id')
            ->get()
            ->map(fn (IntegrationSiteConfig $config) => [
                'id' => $config->id,
                'site_id' => $config->site_id,
                'site_name' => $config->site?->name ?? 'Unknown site',
                'site_type' => $config->site?->type,
                'status' => $config->status,
                'mapped_external_site_name' => $config->mapped_external_site_name,
                'is_active' => (bool) $config->is_active,
            ])
            ->values()
            ->all();

        // All approved application Sites available for mapping
        $sites = Site::query()
            ->whereIn('id', $siteIds)
            ->orderBy('name')
            ->get(['id', 'name', 'type']);

        // Rooms used for room assignment in settings
        $rooms = SiteRoom::query()
            ->whereIn('site_id', $siteIds)
            ->orderBy('site_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'site_id', 'name']);

        // Synced UniFi devices — reads from canonical Security & Devices registry.
        // Resolves site/room context via device_assignments.
        $syncedDevices = $this->access->visibleDevices($user)
            ->byProvider(self::PROVIDER)
            ->with(['assignments' => fn ($q) => $q->active()])
            ->orderBy('name')
            ->get()
            ->map(function (Device $device) use ($sites, $rooms) {
                // Resolve site/room from active assignment.
                $assignment = $device->assignments->first(fn ($a) => $a->released_at === null);
                $siteId = null;
                $roomId = null;

                if ($assignment) {
                    if ($assignment->assignable_type === 'site') {
                        $siteId = $assignment->assignable_id;
                    } elseif ($assignment->assignable_type === 'room') {
                        $roomId = $assignment->assignable_id;
                        // Resolve site from room.
                        $room = $rooms->firstWhere('id', $roomId);
                        $siteId = $room?->site_id;
                    }
                }

                $site = $siteId ? $sites->firstWhere('id', $siteId) : null;
                $room = $roomId ? $rooms->firstWhere('id', $roomId) : null;

                return [
                    'id' => $device->id,
                    'site_id' => $siteId,
                    'site_name' => $site?->name ?? 'Unassigned',
                    'site_type' => $site?->type,
                    'room_id' => $roomId,
                    'room_name' => $room?->name,
                    'name' => $device->name,
                    'domain' => $device->domain,
                    'category' => $device->category,
                    'subcategory' => $device->subcategory,
                    'status' => $device->status?->value,
                    'health_status' => $device->health_status?->value,
                    'model' => $device->model,
                    'manufacturer' => $device->manufacturer,
                    'firmware_version' => $device->firmware_version,
                    'last_seen_at' => $device->last_seen_at?->toISOString(),
                    'detail_url' => "/security-devices/devices/{$device->id}",
                ];
            })
            ->sortBy('site_name')
            ->values()
            ->all();

        // Recent sync logs
        $syncLogs = IntegrationSyncLog::query()
            ->forProvider(self::PROVIDER)
            ->where(function ($query) use ($siteIds): void {
                $query->whereNull('site_id')->orWhereIn('site_id', $siteIds);
            })
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
                'failure_category' => $log->status === IntegrationSyncLog::STATUS_FAILED ? 'provider_failure' : null,
                'started_at' => $log->started_at?->toDateTimeString(),
                'completed_at' => $log->completed_at?->toDateTimeString(),
            ])
            ->values()
            ->all();

        return Inertia::render('security-devices/integrations/unifi', [
            'providerConnection' => $providerConnection ? [
                'status' => $providerConnection->status,
                'secret_last4' => $providerConnection->secret_last4,
                'last_tested_at' => $providerConnection->last_tested_at?->toDateTimeString(),
                'last_synced_at' => $providerConnection->last_synced_at?->toDateTimeString(),
                'sites_synced_at' => $connectionConfig['sites_synced_at'] ?? null,
                'defaults' => collect($connectionConfig)->only([
                    'refresh_interval_minutes', 'alert_motion_events', 'alert_device_offline',
                    'quiet_hours_start', 'quiet_hours_end',
                ])->all(),
            ] : null,
            'discoveredSites' => $discoveredSites,
            'siteConfigs' => $siteConfigs,
            'sites' => $sites,
            'rooms' => $rooms,
            'syncedDevices' => $syncedDevices,
            'syncLogs' => $syncLogs,
            'siteCredentials' => $this->siteCredentials->present($user, self::PROVIDER),
            'can' => [
                'manage' => $this->userCanManage($user),
            ],
        ]);
    }

    private function userCanManage($user): bool
    {
        return $user && $user->canDo(self::PERMISSION_MANAGE);
    }

    /** Save or update the application provider connection. */
    public function saveKey(Request $request)
    {
        $user = $request->user();
        abort_unless($this->userCanManage($user), 403);

        $request->validate([
            'api_key' => ['required', 'string'],
        ]);

        IntegrationProviderConnection::updateOrCreate(
            [
                'provider' => self::PROVIDER,
            ],
            [
                'tenant_id' => LegacyStorageContext::id(),
                'secret_encrypted' => Crypt::encryptString($request->string('api_key')->toString()),
                'secret_last4' => substr($request->string('api_key')->toString(), -4),
                'status' => IntegrationProviderConnection::STATUS_DISCONNECTED,
                'last_error' => null,
                'created_by' => $user->id,
            ],
        );

        Integration::updateOrCreate(
            [
                'provider' => self::PROVIDER,
            ],
            [
                'tenant_id' => LegacyStorageContext::id(),
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
        abort_unless($this->userCanManage($user), 403);

        $connection = IntegrationProviderConnection::query()
            ->forProvider(self::PROVIDER)
            ->firstOrFail();

        if (! $registry->has(self::PROVIDER)) {
            return redirect()->back()->with('error', 'UniFi adapter is not registered.');
        }

        $adapter = $registry->resolve(self::PROVIDER);
        $isConnected = $adapter->testConnection($connection);

        if ($isConnected) {
            $connection->update([
                'status' => IntegrationProviderConnection::STATUS_CONNECTED,
                'last_tested_at' => now(),
                'last_error' => null,
            ]);

            Integration::updateOrCreate(
                [
                    'provider' => self::PROVIDER,
                ],
                [
                    'tenant_id' => LegacyStorageContext::id(),
                    'display_name' => 'UniFi',
                    'status' => Integration::STATUS_ACTIVE,
                    'last_tested_at' => now(),
                    'last_error' => null,
                ],
            );

            return redirect()->back()->with('success', 'UniFi connection test succeeded.');
        }

        $connection->update([
            'status' => IntegrationProviderConnection::STATUS_ERROR,
            'last_tested_at' => now(),
            'last_error' => 'UniFi API rejected the key or was unreachable.',
        ]);

        Integration::updateOrCreate(
            [
                'provider' => self::PROVIDER,
            ],
            [
                'tenant_id' => LegacyStorageContext::id(),
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
        abort_unless($this->userCanManage($user), 403);

        $request->validate([
            'api_key' => ['required', 'string'],
        ]);

        $connection = IntegrationProviderConnection::query()
            ->forProvider(self::PROVIDER)
            ->firstOrFail();

        $connection->update([
            'secret_encrypted' => Crypt::encryptString($request->string('api_key')->toString()),
            'secret_last4' => substr($request->string('api_key')->toString(), -4),
            'rotated_at' => now(),
            'status' => IntegrationProviderConnection::STATUS_DISCONNECTED,
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
        abort_unless($this->userCanManage($user), 403);

        $connection = IntegrationProviderConnection::query()
            ->forProvider(self::PROVIDER)
            ->firstOrFail();

        if (! $registry->has(self::PROVIDER)) {
            return redirect()->back()->with('error', 'UniFi adapter is not registered.');
        }

        $syncLog = IntegrationSyncLog::create([
            'tenant_id' => LegacyStorageContext::id(),
            'provider' => self::PROVIDER,
            'action' => 'discover_sites',
            'status' => IntegrationSyncLog::STATUS_STARTED,
            'started_at' => now(),
        ]);

        try {
            $adapter = $registry->resolve(self::PROVIDER);
            $sites = $adapter->discoverSites($connection);
            $hosts = [];
            if (method_exists($adapter, 'discoverHosts')) {
                try {
                    $hosts = $adapter->discoverHosts($connection);
                } catch (\Throwable) {
                    $syncLog->markCompleted(IntegrationSyncLog::STATUS_FAILED, SafeOperationalData::failureSummary());

                    return redirect()->back()->with('error', 'Failed to sync UniFi hosts. Existing discovery state was preserved; review the bounded diagnostic state and retry.');
                }
            }

            $config = $this->mergeSecretConfig(
                $connection->config,
                [
                    'discovered_sites' => array_values($sites),
                    'discovered_host_count' => count($hosts),
                    'sites_synced_at' => now()->toISOString(),
                ]
            );

            $connection->update([
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
            $syncLog->markCompleted(IntegrationSyncLog::STATUS_FAILED, SafeOperationalData::failureSummary());

            $connection->update([
                'status' => IntegrationProviderConnection::STATUS_ERROR,
                'last_error' => SafeOperationalData::failureSummary(),
            ]);

            return redirect()->back()->with('error', 'Failed to sync UniFi sites. Review the bounded diagnostic state and retry.');
        }
    }

    /**
     * Map a UniFi site to a platform location (site/head office/facility/house).
     */
    public function mapSite(Request $request)
    {
        $user = $request->user();
        abort_unless($this->userCanManage($user), 403);

        $request->validate([
            'site_id' => ['required', 'integer'],
            'mapping_token' => ['required', 'string', 'size:64'],
        ]);

        $siteId = (int) $request->input('site_id');
        $this->access->assertCanViewSite($user, $siteId);
        $site = Site::query()->find($siteId);
        abort_unless($site, 404);

        $connection = IntegrationProviderConnection::query()
            ->forProvider(self::PROVIDER)
            ->firstOrFail();
        $discoveredSite = collect(data_get($connection->config, 'discovered_sites', []))
            ->first(function ($candidate) use ($request): bool {
                $externalId = is_array($candidate) ? (string) ($candidate['external_id'] ?? '') : '';

                return $externalId !== '' && hash_equals(
                    $this->mappingToken($externalId),
                    (string) $request->input('mapping_token'),
                );
            });
        abort_unless(is_array($discoveredSite), 422, 'The selected provider location is no longer available. Sync locations and try again.');

        IntegrationSiteConfig::updateOrCreate(
            [
                'site_id' => $site->id,
                'provider' => self::PROVIDER,
            ],
            [
                'tenant_id' => LegacyStorageContext::id(),
                'mapped_external_site_id' => (string) $discoveredSite['external_id'],
                'mapped_external_site_name' => (string) ($discoveredSite['name'] ?? 'Provider location'),
                'status' => IntegrationSiteConfig::STATUS_HYBRID,
                'is_active' => true,
            ],
        );

        return redirect()->back()->with('success', 'Site mapping saved.');
    }

    private function mappingToken(string $externalId): string
    {
        if ($externalId === '') {
            return '';
        }

        return hash_hmac('sha256', self::PROVIDER.'|'.$externalId, (string) config('app.key'));
    }

    /**
     * Remove a UniFi site mapping.
     */
    public function removeSiteMapping(Request $request, IntegrationSiteConfig $siteConfig)
    {
        $user = $request->user();
        abort_unless($this->userCanManage($user), 403);
        abort_unless($siteConfig->provider === self::PROVIDER, 404);
        $this->access->assertCanViewSite($user, (int) $siteConfig->site_id);

        $siteConfig->delete();

        return redirect()->back()->with('success', 'Site mapping removed.');
    }

    /**
     * Sync devices for a mapped site config.
     */
    public function syncDevices(Request $request, IntegrationAdapterRegistry $registry)
    {
        $user = $request->user();
        abort_unless($this->userCanManage($user), 403);

        $request->validate([
            'site_config_id' => ['required', 'integer'],
        ]);

        $siteIds = $this->access->accessibleSiteIds($user);

        $siteConfig = IntegrationSiteConfig::query()
            ->forProvider(self::PROVIDER)
            ->active()
            ->whereIn('site_id', $siteIds)
            ->whereHas('site')
            ->find((int) $request->input('site_config_id'));
        abort_unless($siteConfig, 404);

        if (empty($siteConfig->mapped_external_site_id)) {
            return redirect()->back()->with('error', 'Map a UniFi site before syncing devices.');
        }

        $connection = IntegrationProviderConnection::query()
            ->forProvider(self::PROVIDER)
            ->connected()
            ->first();

        if (! $connection) {
            return redirect()->back()->with('error', 'Test and connect your UniFi API key before syncing devices.');
        }

        if (! $registry->has(self::PROVIDER)) {
            return redirect()->back()->with('error', 'UniFi adapter is not registered.');
        }

        $syncLog = IntegrationSyncLog::create([
            'tenant_id' => LegacyStorageContext::id(),
            'provider' => self::PROVIDER,
            'site_id' => $siteConfig->site_id,
            'action' => 'sync_devices',
            'status' => IntegrationSyncLog::STATUS_STARTED,
            'started_at' => now(),
        ]);

        try {
            $adapter = $registry->resolve(self::PROVIDER);
            $result = $adapter->syncDevices($siteConfig, $connection);

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
                $syncLog->markCompleted(IntegrationSyncLog::STATUS_FAILED, SafeOperationalData::failureSummary());
            }

            $connection->update([
                'last_synced_at' => now(),
                'last_error' => $result->isSuccess() ? null : SafeOperationalData::failureSummary(),
            ]);

            if (! $result->isSuccess() && ! $result->isPartial()) {
                return redirect()->back()->with('error', 'UniFi device sync failed. Review the bounded diagnostic state and retry.');
            }

            return redirect()->back()->with(
                'success',
                "Device sync complete. Processed {$result->processed}, created {$result->created}, updated {$result->updated}, errored {$result->errored}."
            );
        } catch (\Throwable $e) {
            $syncLog->markCompleted(IntegrationSyncLog::STATUS_FAILED, SafeOperationalData::failureSummary());

            return redirect()->back()->with('error', 'Failed to sync UniFi devices. Review the bounded diagnostic state and retry.');
        }
    }

    /**
     * Assign or clear room for a synced UniFi device.
     *
     * The stable /hardware/{id}/room URL is retained for UI compatibility,
     * but the route parameter now represents the canonical devices.id value.
     */
    public function assignHardwareRoom(
        Request $request,
        int $hardware,
        UnifiOperationalBridgeService $runtime,
    ) {
        $user = $request->user();
        abort_unless($this->userCanManage($user), 403);
        $device = $this->access->visibleDevices($user)
            ->byProvider(self::PROVIDER)
            ->find($hardware);
        abort_unless($device, 404);

        $validated = $request->validate([
            'room_id' => ['nullable', 'integer'],
        ]);

        $roomId = $validated['room_id'] ?? null;

        if ($roomId !== null) {
            $currentSiteId = $runtime->resolveSiteId($device);
            abort_unless($currentSiteId !== null, 404);
            $this->access->assertCanViewSite($user, $currentSiteId);

            $room = SiteRoom::query()
                ->where('site_id', $currentSiteId)
                ->whereHas('site')
                ->find($roomId);
            abort_unless($room, 404);
        }

        $runtime->syncRoomAssignment($device, $room ?? null, $user?->id, null);

        return redirect()->back()->with('success', 'Device room assignment updated.');
    }

    /**
     * Update default refresh/alert configuration.
     */
    public function updateDefaults(Request $request)
    {
        $user = $request->user();
        abort_unless($this->userCanManage($user), 403);

        $request->validate([
            'config' => ['nullable', 'array'],
            'config.refresh_interval_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'config.alert_motion_events' => ['nullable', 'boolean'],
            'config.alert_device_offline' => ['nullable', 'boolean'],
            'config.quiet_hours_start' => ['nullable', 'date_format:H:i'],
            'config.quiet_hours_end' => ['nullable', 'date_format:H:i'],
        ]);

        $connection = IntegrationProviderConnection::query()
            ->forProvider(self::PROVIDER)
            ->firstOrFail();

        $connection->update([
            'config' => $this->mergeSecretConfig(
                $connection->config,
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
            'discovered_host_count' => is_numeric($existing['discovered_host_count'] ?? null)
                ? max(0, (int) $existing['discovered_host_count'])
                : count(is_array($existing['discovered_hosts'] ?? null) ? $existing['discovered_hosts'] : []),
            'sites_synced_at' => $existing['sites_synced_at'] ?? null,
        ];

        $merged = array_merge($preserved, $existing, $newConfig);
        unset($merged['discovered_hosts']);

        return $merged;
    }
}
