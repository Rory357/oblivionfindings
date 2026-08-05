<?php

namespace App\Http\Controllers\Sites;

use App\Domain\SecurityDevices\Presenters\IntegrationSiteCredentialsPresenter;
use App\Http\Controllers\Controller;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Integration\IntegrationSiteSecret;
use App\Models\Integration\IntegrationSyncLog;
use App\Models\Site;
use App\Models\TimelineEvent;
use App\Services\Integration\Contracts\ConnectionHealthCapability;
use App\Services\Integration\Contracts\DeviceSyncCapability;
use App\Services\Integration\Contracts\EventCollectionCapability;
use App\Services\Integration\Contracts\InventoryDiscoveryCapability;
use App\Services\Integration\IntegrationAdapterRegistry;
use App\Support\SafeOperationalData;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class SiteIntegrationController extends Controller
{
    public function index(Request $request, Site $site, IntegrationSiteCredentialsPresenter $siteCredentialsPresenter)
    {
        $this->authorize('view', $site);

        $configs = IntegrationSiteConfig::query()
            ->where('site_id', $site->id)
            ->get()
            ->map(fn (IntegrationSiteConfig $config): array => [
                'id' => $config->id,
                'site_id' => (int) $config->site_id,
                'provider' => $config->provider,
                'status' => in_array($config->status, [
                    IntegrationSiteConfig::STATUS_LOCAL_ONLY,
                    IntegrationSiteConfig::STATUS_HYBRID,
                    IntegrationSiteConfig::STATUS_DISCONNECTED,
                ], true) ? $config->status : 'unknown',
                'enabled' => (bool) $config->is_active,
                'mapping_state' => filled($config->mapped_external_site_id) ? 'mapped' : 'not_mapped',
                'overrides_configured' => is_array($config->overrides) && $config->overrides !== [],
            ])
            ->values();

        $siteSecrets = IntegrationSiteSecret::query()
            ->where('site_id', $site->id)
            ->with('site:id,name')
            ->get()
            ->map(fn (IntegrationSiteSecret $secret): array => $siteCredentialsPresenter->project($secret))
            ->values();

        $providerConnections = IntegrationProviderConnection::query()
            ->get()
            ->map(function (IntegrationProviderConnection $connection): array {
                $config = is_array($connection->config) ? $connection->config : [];

                return [
                    'id' => $connection->id,
                    'provider' => $connection->provider,
                    'configured' => true,
                    'tested' => $connection->last_tested_at !== null,
                    'status' => in_array($connection->status, [
                        IntegrationProviderConnection::STATUS_CONNECTED,
                        IntegrationProviderConnection::STATUS_DISCONNECTED,
                        IntegrationProviderConnection::STATUS_DISABLED,
                        IntegrationProviderConnection::STATUS_ERROR,
                    ], true) ? $connection->status : 'unknown',
                    'failure_category' => filled($connection->last_error) ? 'provider_failure' : null,
                    'last_tested_at' => $connection->last_tested_at?->toISOString(),
                    'last_synced_at' => $connection->last_synced_at?->toISOString(),
                    'discovery' => [
                        'site_count' => count(is_array($config['discovered_sites'] ?? null) ? $config['discovered_sites'] : []),
                        'host_count' => is_numeric($config['discovered_host_count'] ?? null)
                            ? max(0, (int) $config['discovered_host_count'])
                            : count(is_array($config['discovered_hosts'] ?? null) ? $config['discovered_hosts'] : []),
                    ],
                ];
            })
            ->values();

        return response()->json([
            'configs' => $configs,
            'siteSecrets' => $siteSecrets,
            'providerConnections' => $providerConnections,
        ]);
    }

    public function configure(Request $request, Site $site, string $provider)
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'mapped_external_site_id' => 'nullable|string|max:255',
            'mapped_external_site_name' => 'nullable|string|max:255',
            'protect_host_id' => 'nullable|string|max:255',
            'protect_host_name' => 'nullable|string|max:255',
            'access_host_id' => 'nullable|string|max:255',
            'access_host_name' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        $mappedId = $validated['mapped_external_site_id'] ?? null;
        $isActive = $validated['is_active'] ?? ! empty($mappedId);
        $status = $mappedId ? IntegrationSiteConfig::STATUS_HYBRID : IntegrationSiteConfig::STATUS_LOCAL_ONLY;

        $existingConfig = IntegrationSiteConfig::where('site_id', $site->id)
            ->where('provider', $provider)
            ->first();

        $existingOverrides = is_array($existingConfig?->overrides) ? $existingConfig->overrides : [];
        $overrideUpdates = [];
        if ($request->has('protect_host_id') || $request->has('protect_host_name')) {
            $overrideUpdates['protect_host_id'] = $validated['protect_host_id'] ?? null;
            $overrideUpdates['protect_host_name'] = $validated['protect_host_name'] ?? null;
        }
        if ($request->has('access_host_id') || $request->has('access_host_name')) {
            $overrideUpdates['access_host_id'] = $validated['access_host_id'] ?? null;
            $overrideUpdates['access_host_name'] = $validated['access_host_name'] ?? null;
        }
        $overrides = array_merge($existingOverrides, $overrideUpdates);

        IntegrationSiteConfig::updateOrCreate(
            [
                'site_id' => $site->id,
                'provider' => $provider,
            ],
            [
                'mapped_external_site_id' => $mappedId,
                'mapped_external_site_name' => $validated['mapped_external_site_name'] ?? null,
                'status' => $status,
                'is_active' => $isActive,
                'overrides' => $overrides,
            ]
        );

        return redirect()->back()->with('success', 'Integration configured successfully.');
    }

    public function syncSites(Request $request, Site $site, string $provider, IntegrationAdapterRegistry $registry)
    {
        $this->authorize('update', $site);

        $providerConnection = IntegrationProviderConnection::query()
            ->forProvider($provider)
            ->connected()
            ->first();

        if (! $providerConnection) {
            return redirect()->back()->with('error', 'The provider connection must be enabled and successfully tested in Security & Devices before collecting inventory.');
        }

        if (! $registry->hasCapability($provider, InventoryDiscoveryCapability::class)) {
            return redirect()->back()->with('error', 'Inventory discovery is not available for this provider.');
        }

        $syncLog = IntegrationSyncLog::create([
            'provider' => $provider,
            'site_id' => $site->id,
            'action' => 'discover_sites',
            'status' => IntegrationSyncLog::STATUS_STARTED,
            'started_at' => now(),
        ]);

        try {
            $adapter = $registry->capability($provider, InventoryDiscoveryCapability::class);
            assert($adapter instanceof InventoryDiscoveryCapability);
            $sites = $adapter->discoverSites($providerConnection);
            $hosts = [];
            if (method_exists($adapter, 'discoverHosts')) {
                try {
                    $hosts = $adapter->discoverHosts($providerConnection);
                } catch (\Throwable) {
                    $syncLog->markCompleted(IntegrationSyncLog::STATUS_FAILED, SafeOperationalData::failureSummary());

                    return redirect()->back()->with('error', 'Failed to sync integration hosts. Existing discovery state was preserved; review the bounded diagnostic state and retry.');
                }
            }

            $config = $this->mergeSecretConfig(
                $providerConnection->config,
                [
                    'discovered_sites' => array_values($sites),
                    'discovered_host_count' => count($hosts),
                    'sites_synced_at' => now()->toISOString(),
                ]
            );

            $providerConnection->update([
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

                return redirect()->back()->with('success', 'Integration sites synced successfully.');
            }

            $syncLog->markCompleted(IntegrationSyncLog::STATUS_PARTIAL, 'No sites returned by provider API.');

            return redirect()->back()->with('warning', 'No sites returned by provider API.');
        } catch (\Throwable $e) {
            $failure = $this->providerFailure($site->id, $provider, $e);
            $syncLog->markCompleted(IntegrationSyncLog::STATUS_FAILED, $failure);

            $providerConnection->update([
                'status' => IntegrationProviderConnection::STATUS_ERROR,
                'last_error' => $failure,
            ]);

            return redirect()->back()->with('error', 'Failed to sync integration sites. Review the bounded diagnostic state and retry.');
        }
    }

    public function testConnection(Request $request, Site $site, string $provider, IntegrationAdapterRegistry $registry)
    {
        $this->authorize('view', $site);

        $providerConnection = IntegrationProviderConnection::query()
            ->forProvider($provider)
            ->first();

        if (! $providerConnection) {
            return redirect()->back()->with('error', 'No provider connection is configured for this integration.');
        }

        if ($providerConnection->status === IntegrationProviderConnection::STATUS_DISABLED
            || $providerConnection->requires_credential_replacement) {
            return redirect()->back()->with('error', 'This provider connection is disabled centrally. Replace its credential in Security & Devices before testing it.');
        }

        if (! $registry->hasCapability($provider, ConnectionHealthCapability::class)) {
            return redirect()->back()->with('error', 'Connection testing is not available for this provider.');
        }

        $adapter = $registry->capability($provider, ConnectionHealthCapability::class);
        assert($adapter instanceof ConnectionHealthCapability);
        $isConnected = $adapter->testConnection($providerConnection);

        $providerConnection->update([
            'status' => $isConnected
                ? IntegrationProviderConnection::STATUS_CONNECTED
                : IntegrationProviderConnection::STATUS_ERROR,
            'last_tested_at' => now(),
            'last_error' => $isConnected ? null : 'Connection test failed.',
        ]);

        if ($isConnected) {
            return redirect()->back()->with('success', 'Connection test passed.');
        }

        return redirect()->back()->with('error', 'Connection test failed.');
    }

    public function syncDevices(Request $request, Site $site, string $provider, IntegrationAdapterRegistry $registry)
    {
        $this->authorize('update', $site);

        if (! $registry->hasCapability($provider, DeviceSyncCapability::class)) {
            return redirect()->back()->with('error', 'Device sync is not available for this provider.');
        }

        $siteConfig = IntegrationSiteConfig::query()
            ->forProvider($provider)
            ->where('site_id', $site->id)
            ->active()
            ->first();

        if (! $siteConfig || empty($siteConfig->mapped_external_site_id)) {
            return redirect()->back()->with('error', 'Integration mapping is missing for this location.');
        }

        $providerConnection = IntegrationProviderConnection::query()
            ->forProvider($provider)
            ->connected()
            ->first();

        if (! $providerConnection) {
            return redirect()->back()->with('error', 'The provider connection is not connected for this integration.');
        }

        $syncLog = IntegrationSyncLog::create([
            'provider' => $provider,
            'site_id' => $site->id,
            'action' => 'sync_devices',
            'status' => IntegrationSyncLog::STATUS_STARTED,
            'started_at' => now(),
        ]);

        try {
            $adapter = $registry->capability($provider, DeviceSyncCapability::class);
            assert($adapter instanceof DeviceSyncCapability);
            $result = $adapter->syncDevices($siteConfig, $providerConnection);

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
                $syncLog->markCompleted(
                    IntegrationSyncLog::STATUS_FAILED,
                    $this->providerFailure($site->id, $provider),
                );
            }

            $providerConnection->update([
                'last_synced_at' => now(),
                'last_error' => $result->isSuccess() || $result->isPartial()
                    ? null
                    : SafeOperationalData::failureSummary(),
            ]);

            if (! $result->isSuccess() && ! $result->isPartial()) {
                return redirect()->back()->with('error', 'Device sync failed. Review the bounded diagnostic state and retry.');
            }

            return redirect()->back()->with(
                'success',
                "Device sync complete. Processed {$result->processed}, created {$result->created}, updated {$result->updated}, errored {$result->errored}."
            );
        } catch (\Throwable $e) {
            $failure = $this->providerFailure($site->id, $provider, $e);
            $syncLog->markCompleted(IntegrationSyncLog::STATUS_FAILED, $failure);
            $providerConnection->update([
                'status' => IntegrationProviderConnection::STATUS_ERROR,
                'last_error' => $failure,
            ]);

            return redirect()->back()->with('error', 'Device sync failed. Review the bounded diagnostic state and retry.');
        }
    }

    public function pullEvents(Request $request, Site $site, string $provider, IntegrationAdapterRegistry $registry)
    {
        $this->authorize('update', $site);

        abort_unless($registry->hasCapability($provider, EventCollectionCapability::class), 404);

        $siteConfig = IntegrationSiteConfig::firstOrCreate(
            [
                'site_id' => $site->id,
                'provider' => $provider,
            ],
            [
                'status' => IntegrationSiteConfig::STATUS_LOCAL_ONLY,
                'is_active' => true,
            ]
        );

        $providerConnection = IntegrationProviderConnection::query()
            ->forProvider($provider)
            ->connected()
            ->first();

        if (! $providerConnection) {
            return redirect()->back()->with('error', 'The provider connection is not connected for this integration.');
        }

        $accessSecret = IntegrationSiteSecret::query()
            ->where('site_id', $site->id)
            ->where('provider', $provider)
            ->where('capability', 'access_api')
            ->first();

        if (! $accessSecret || ! $accessSecret->is_enabled || empty($accessSecret->base_url)) {
            return redirect()->back()->with('error', 'Access API credentials are missing for this location.');
        }

        $since = null;
        if ($request->filled('since')) {
            try {
                $since = Carbon::parse($request->input('since'));
            } catch (\Throwable) {
                $since = null;
            }
        } elseif ($accessSecret->last_tested_at) {
            $since = $accessSecret->last_tested_at->copy()->subMinutes(5);
        } else {
            $since = now()->subDays(2);
        }

        try {
            $adapter = $registry->capability($provider, EventCollectionCapability::class);
            assert($adapter instanceof EventCollectionCapability);
            $events = $adapter->collectEvents(
                $siteConfig,
                $providerConnection,
                $since?->toISOString(),
                $registry->manifest($provider)->pageLimit,
            )->items;
            $created = 0;
            $updated = 0;

            foreach ($events as $event) {
                $sourceId = $event['source_event_id'] ?? null;
                if (! $sourceId) {
                    continue;
                }
                $normalized = is_array($event['normalized_payload'] ?? null)
                    ? $event['normalized_payload']
                    : [];
                $summary = is_string($normalized['summary'] ?? null)
                    ? $normalized['summary']
                    : 'UniFi Access event';

                $model = TimelineEvent::updateOrCreate(
                    [
                        'source_type' => 'unifi_access',
                        'source_id' => $sourceId,
                    ],
                    [
                        'occurred_at' => $event['occurred_at'] ?? now(),
                        'type' => 'unifi_access',
                        'actor_user_id' => $request->user()?->id,
                        'site_id' => $site->id,
                        'subject' => $summary,
                        'body' => $summary,
                        'meta' => [
                            'event_type' => $event['event_type'] ?? null,
                            'door' => $normalized['door_name'] ?? null,
                            'user' => $normalized['actor_name'] ?? null,
                            'direction' => $normalized['direction'] ?? null,
                            'provider' => $provider,
                        ],
                        'visibility' => 'internal',
                        'created_by' => $request->user()?->id,
                    ]
                );

                if ($model->wasRecentlyCreated) {
                    $created++;
                } else {
                    $updated++;
                }
            }

            $accessSecret->update([
                'last_tested_at' => now(),
                'last_error' => null,
            ]);

            return redirect()->back()->with('success', "Access events synced. Added {$created}, updated {$updated}.");
        } catch (\Throwable $e) {
            $failure = $this->providerFailure($site->id, $provider, $e);
            $accessSecret->update([
                'last_tested_at' => now(),
                'last_error' => $failure,
            ]);

            return redirect()->back()->with('error', 'Access event sync failed. Review the bounded diagnostic state and retry.');
        }
    }

    public function updateSecret(Request $request, Site $site, string $provider, string $capability)
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'base_url' => 'nullable|string|max:500',
            'secret' => 'required|string',
            'is_enabled' => 'boolean',
        ]);

        IntegrationSiteSecret::updateOrCreate(
            [
                'site_id' => $site->id,
                'provider' => $provider,
                'capability' => $capability,
            ],
            [
                'base_url' => $validated['base_url'] ?? null,
                'secret_encrypted' => Crypt::encryptString($validated['secret']),
                'is_enabled' => $validated['is_enabled'] ?? true,
            ]
        );

        return redirect()->back()->with('success', 'Site credential saved successfully.');
    }

    public function updateOverrides(Request $request, Site $site, string $provider)
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'overrides' => 'nullable|array',
        ]);

        $config = IntegrationSiteConfig::where('site_id', $site->id)
            ->where('provider', $provider)
            ->firstOrFail();

        $config->update([
            'overrides' => $validated['overrides'] ?? null,
        ]);

        return redirect()->back()->with('success', 'Integration overrides updated successfully.');
    }

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

    private function providerFailure(int $siteId, string $provider, ?\Throwable $exception = null): string
    {
        Log::warning('Site integration provider operation failed', SafeOperationalData::logContext([
            'site_id' => $siteId,
            'provider' => $provider,
            'failure_category' => SafeOperationalData::failureCategory($exception),
        ]));

        return SafeOperationalData::failureSummary();
    }
}
