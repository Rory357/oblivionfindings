<?php

namespace App\Http\Controllers\Sites;

use App\Domain\SecurityDevices\Presenters\IntegrationSiteCredentialsPresenter;
use App\Http\Controllers\Controller;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Integration\IntegrationSiteSecret;
use App\Models\Integration\IntegrationSyncLog;
use App\Models\Integration\IntegrationTenantSecret;
use App\Models\Site;
use App\Models\TimelineEvent;
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
        $tenantId = $this->resolveTenantId($request->user(), $site);

        $configs = IntegrationSiteConfig::query()
            ->forTenant($tenantId)
            ->where('site_id', $site->id)
            ->get()
            ->map(fn (IntegrationSiteConfig $config): array => [
                'id' => $config->id,
                'site_id' => (int) $config->site_id,
                'provider' => $config->provider,
                'status' => in_array($config->status, [
                    IntegrationSiteConfig::STATUS_TENANT_ONLY,
                    IntegrationSiteConfig::STATUS_HYBRID,
                    IntegrationSiteConfig::STATUS_DISCONNECTED,
                ], true) ? $config->status : 'unknown',
                'enabled' => (bool) $config->is_active,
                'mapping_state' => filled($config->mapped_external_site_id) ? 'mapped' : 'not_mapped',
                'overrides_configured' => is_array($config->overrides) && $config->overrides !== [],
            ])
            ->values();

        $siteSecrets = IntegrationSiteSecret::query()
            ->forTenant($tenantId)
            ->where('site_id', $site->id)
            ->with('site:id,name,tenant_id')
            ->get()
            ->map(fn (IntegrationSiteSecret $secret): array => $siteCredentialsPresenter->project($secret))
            ->values();

        $tenantSecrets = IntegrationTenantSecret::where('tenant_id', $tenantId)
            ->get()
            ->map(function (IntegrationTenantSecret $secret): array {
                $config = is_array($secret->config) ? $secret->config : [];

                return [
                    'id' => $secret->id,
                    'provider' => $secret->provider,
                    'configured' => true,
                    'tested' => $secret->last_tested_at !== null,
                    'status' => in_array($secret->status, [
                        IntegrationTenantSecret::STATUS_CONNECTED,
                        IntegrationTenantSecret::STATUS_DISCONNECTED,
                        IntegrationTenantSecret::STATUS_ERROR,
                    ], true) ? $secret->status : 'unknown',
                    'failure_category' => filled($secret->last_error) ? 'provider_failure' : null,
                    'last_tested_at' => $secret->last_tested_at?->toISOString(),
                    'last_synced_at' => $secret->last_synced_at?->toISOString(),
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
            'tenantSecrets' => $tenantSecrets,
        ]);
    }

    public function configure(Request $request, Site $site, string $provider)
    {
        $this->authorize('update', $site);
        $tenantId = $this->resolveTenantId($request->user(), $site);

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
        $status = $mappedId ? IntegrationSiteConfig::STATUS_HYBRID : IntegrationSiteConfig::STATUS_TENANT_ONLY;

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
                'tenant_id' => $tenantId,
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
        $tenantId = $this->resolveTenantId($request->user(), $site);

        $tenantSecret = IntegrationTenantSecret::where('tenant_id', $tenantId)
            ->where('provider', $provider)
            ->first();

        if (! $tenantSecret) {
            return redirect()->back()->with('error', 'No tenant credentials found for this integration.');
        }

        if (! $registry->has($provider)) {
            return redirect()->back()->with('error', 'No adapter registered for this integration provider.');
        }

        $syncLog = IntegrationSyncLog::create([
            'tenant_id' => $tenantId,
            'provider' => $provider,
            'site_id' => $site->id,
            'action' => 'discover_sites',
            'status' => IntegrationSyncLog::STATUS_STARTED,
            'started_at' => now(),
        ]);

        try {
            $sites = $registry->resolve($provider)->discoverSites($tenantSecret);
            $hosts = [];
            $adapter = $registry->resolve($provider);
            if (method_exists($adapter, 'discoverHosts')) {
                try {
                    $hosts = $adapter->discoverHosts($tenantSecret);
                } catch (\Throwable) {
                    $syncLog->markCompleted(IntegrationSyncLog::STATUS_FAILED, SafeOperationalData::failureSummary());

                    return redirect()->back()->with('error', 'Failed to sync integration hosts. Existing discovery state was preserved; review the bounded diagnostic state and retry.');
                }
            }

            $config = $this->mergeSecretConfig(
                $tenantSecret->config,
                [
                    'discovered_sites' => array_values($sites),
                    'discovered_host_count' => count($hosts),
                    'sites_synced_at' => now()->toISOString(),
                ]
            );

            $tenantSecret->update([
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
            $failure = $this->providerFailure($tenantId, $site->id, $provider, $e);
            $syncLog->markCompleted(IntegrationSyncLog::STATUS_FAILED, $failure);

            $tenantSecret->update([
                'status' => IntegrationTenantSecret::STATUS_ERROR,
                'last_error' => $failure,
            ]);

            return redirect()->back()->with('error', 'Failed to sync integration sites. Review the bounded diagnostic state and retry.');
        }
    }

    public function testConnection(Request $request, Site $site, string $provider, IntegrationAdapterRegistry $registry)
    {
        $this->authorize('view', $site);
        $tenantId = $this->resolveTenantId($request->user(), $site);

        $tenantSecret = IntegrationTenantSecret::where('tenant_id', $tenantId)
            ->where('provider', $provider)
            ->first();

        if (! $tenantSecret) {
            return redirect()->back()->with('error', 'No tenant credentials found for this integration.');
        }

        if (! $registry->has($provider)) {
            return redirect()->back()->with('error', 'No adapter registered for this integration provider.');
        }

        $isConnected = $registry->resolve($provider)->testConnection($tenantSecret);

        $tenantSecret->update([
            'status' => $isConnected
                ? IntegrationTenantSecret::STATUS_CONNECTED
                : IntegrationTenantSecret::STATUS_ERROR,
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

        if (! $registry->has($provider)) {
            return redirect()->back()->with('error', 'No adapter registered for this integration provider.');
        }

        $tenantId = $this->resolveTenantId($request->user(), $site);

        $siteConfig = IntegrationSiteConfig::query()
            ->forTenant($tenantId)
            ->forProvider($provider)
            ->where('site_id', $site->id)
            ->active()
            ->first();

        if (! $siteConfig || empty($siteConfig->mapped_external_site_id)) {
            return redirect()->back()->with('error', 'Integration mapping is missing for this location.');
        }

        $tenantSecret = IntegrationTenantSecret::query()
            ->forTenant($tenantId)
            ->where('provider', $provider)
            ->connected()
            ->first();

        if (! $tenantSecret) {
            return redirect()->back()->with('error', 'Tenant credentials are not connected for this integration.');
        }

        $syncLog = IntegrationSyncLog::create([
            'tenant_id' => $tenantId,
            'provider' => $provider,
            'site_id' => $site->id,
            'action' => 'sync_devices',
            'status' => IntegrationSyncLog::STATUS_STARTED,
            'started_at' => now(),
        ]);

        try {
            $result = $registry->resolve($provider)->syncDevices($siteConfig, $tenantSecret);

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
                    $this->providerFailure($tenantId, $site->id, $provider),
                );
            }

            $tenantSecret->update([
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
            $failure = $this->providerFailure($tenantId, $site->id, $provider, $e);
            $syncLog->markCompleted(IntegrationSyncLog::STATUS_FAILED, $failure);
            $tenantSecret->update([
                'status' => IntegrationTenantSecret::STATUS_ERROR,
                'last_error' => $failure,
            ]);

            return redirect()->back()->with('error', 'Device sync failed. Review the bounded diagnostic state and retry.');
        }
    }

    public function pullEvents(Request $request, Site $site, string $provider, IntegrationAdapterRegistry $registry)
    {
        $this->authorize('update', $site);
        $tenantId = $this->resolveTenantId($request->user(), $site);

        if (! $registry->has($provider)) {
            return redirect()->back()->with('error', 'No adapter registered for this integration provider.');
        }

        $siteConfig = IntegrationSiteConfig::firstOrCreate(
            [
                'site_id' => $site->id,
                'provider' => $provider,
            ],
            [
                'tenant_id' => $tenantId,
                'status' => IntegrationSiteConfig::STATUS_TENANT_ONLY,
                'is_active' => true,
            ]
        );

        $tenantSecret = IntegrationTenantSecret::query()
            ->forTenant($tenantId)
            ->where('provider', $provider)
            ->connected()
            ->first();

        if (! $tenantSecret) {
            return redirect()->back()->with('error', 'Tenant credentials are not connected for this integration.');
        }

        $accessSecret = IntegrationSiteSecret::query()
            ->where('tenant_id', $tenantId)
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
            $events = $registry->resolve($provider)->pullEvents($siteConfig, $tenantSecret, $since);
            $created = 0;
            $updated = 0;

            foreach ($events as $event) {
                $sourceId = $event['source_event_id'] ?? null;
                if (! $sourceId) {
                    continue;
                }

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
                        'subject' => $event['summary'] ?? 'UniFi Access event',
                        'body' => $event['summary'] ?? null,
                        'meta' => [
                            'event_type' => $event['event_type'] ?? null,
                            'door' => $event['door_name'] ?? null,
                            'user' => $event['user_name'] ?? null,
                            'direction' => $event['direction'] ?? null,
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
            $failure = $this->providerFailure($tenantId, $site->id, $provider, $e);
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
        $tenantId = $this->resolveTenantId($request->user(), $site);

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
                'tenant_id' => $tenantId,
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

    private function resolveTenantId($user, ?Site $site = null): int
    {
        $tenantId = $user->tenant_id ?? $user->organization_id ?? $site?->tenant_id ?? 1;

        return (int) $tenantId;
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

    private function providerFailure(int $tenantId, int $siteId, string $provider, ?\Throwable $exception = null): string
    {
        Log::warning('Site integration provider operation failed', SafeOperationalData::logContext([
            'tenant_id' => $tenantId,
            'site_id' => $siteId,
            'provider' => $provider,
            'failure_category' => SafeOperationalData::failureCategory($exception),
        ]));

        return SafeOperationalData::failureSummary();
    }
}
