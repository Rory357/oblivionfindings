<?php

namespace App\Domain\SecurityDevices\Http\Controllers\Integrations;

use App\Domain\SecurityDevices\Presenters\IntegrationSiteCredentialsPresenter;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use App\Models\Integration\Integration;
use App\Models\Integration\IntegrationEvent;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Integration\IntegrationSyncLog;
use App\Models\Site;
use App\Services\Integration\Adapters\MilesightAdapter;
use App\Services\Integration\Contracts\ConnectionHealthCapability;
use App\Services\Integration\Contracts\DeviceSyncCapability;
use App\Services\Integration\Contracts\InventoryDiscoveryCapability;
use App\Services\Integration\IntegrationAdapterRegistry;
use App\Services\Integration\IntegrationSecretManager;
use App\Support\SafeOperationalData;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Milesight Development Platform provider configuration.
 */
class MilesightController extends Controller
{
    private const PROVIDER = MilesightAdapter::PROVIDER_SLUG;

    public function __construct(
        private readonly IntegrationSiteCredentialsPresenter $siteCredentials,
        private readonly SecurityDevicesAccessService $access,
        private readonly IntegrationSecretManager $secrets,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($this->userCanManage($user), 403);

        $providerConnection = IntegrationProviderConnection::query()
            ->forProvider(self::PROVIDER)
            ->first();

        $config = is_array($providerConnection?->config) ? $providerConnection->config : [];
        $latestWebhookEventAt = IntegrationEvent::query()
            ->where('provider', self::PROVIDER)
            ->latest('received_at')
            ->first(['received_at'])
            ?->received_at;
        $siteIds = $this->access->accessibleSiteIds($user);
        $discoveredApplications = collect($config['discovered_applications'] ?? [])
            ->filter(fn (mixed $application): bool => is_array($application))
            ->map(fn (array $application): array => [
                'mapping_token' => $this->mappingToken((string) ($application['external_id'] ?? '')),
                'name' => (string) ($application['name'] ?? 'Unknown application'),
                'device_count' => is_numeric(data_get($application, 'meta.device_count'))
                    ? (int) data_get($application, 'meta.device_count')
                    : null,
            ])
            ->filter(fn (array $application): bool => $application['mapping_token'] !== '')
            ->values()
            ->all();
        $siteConfigs = IntegrationSiteConfig::query()
            ->forProvider(self::PROVIDER)
            ->whereIn('site_id', $siteIds)
            ->whereHas('site')
            ->with('site:id,name,type')
            ->orderBy('site_id')
            ->get()
            ->map(fn (IntegrationSiteConfig $siteConfig): array => [
                'id' => $siteConfig->id,
                'site_id' => $siteConfig->site_id,
                'site_name' => $siteConfig->site?->name ?? 'Unknown Site',
                'site_type' => $siteConfig->site?->type,
                'mapped_external_site_name' => $siteConfig->mapped_external_site_name,
                'is_active' => (bool) $siteConfig->is_active,
            ])
            ->values()
            ->all();
        $sites = Site::query()
            ->whereIn('id', $siteIds)
            ->orderBy('name')
            ->get(['id', 'name', 'type']);

        $syncLogs = IntegrationSyncLog::query()
            ->forProvider(self::PROVIDER)
            ->where(function ($query) use ($siteIds): void {
                $query->whereNull('site_id')->orWhereIn('site_id', $siteIds);
            })
            ->orderByDesc('created_at')
            ->limit(10)
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

        return Inertia::render('security-devices/integrations/milesight', [
            'providerConnection' => $providerConnection ? [
                'status' => $providerConnection->status,
                'secret_last4' => $providerConnection->secret_last4,
                'last_tested_at' => $providerConnection->last_tested_at?->toDateTimeString(),
                'last_synced_at' => $providerConnection->last_synced_at?->toDateTimeString(),
                'endpoint_configured' => filled($config['base_url'] ?? null),
                'client_id_configured' => filled($config['client_id'] ?? null),
                'applications_synced_at' => $config['applications_synced_at'] ?? null,
                'webhook_configured' => $this->secrets->applicationConfigured(
                    $providerConnection,
                    IntegrationSecretManager::PURPOSE_WEBHOOK,
                ) || filled($config['webhook_secret_encrypted'] ?? null),
                'webhook_secret_last4' => filled($config['webhook_secret_last4'] ?? null)
                    ? (string) $config['webhook_secret_last4']
                    : null,
                'webhook_url' => route('webhooks.receive', ['provider' => self::PROVIDER]),
                'last_webhook_received_at' => $latestWebhookEventAt?->toDateTimeString(),
            ] : null,
            'discoveredApplications' => $discoveredApplications,
            'siteConfigs' => $siteConfigs,
            'sites' => $sites,
            'syncLogs' => $syncLogs,
            'siteCredentials' => $this->siteCredentials->present($user, self::PROVIDER),
            'can' => [
                'manage' => $this->userCanManage($user),
            ],
        ]);
    }

    public function saveKey(Request $request)
    {
        $user = $request->user();
        abort_unless($this->userCanManage($user), 403);

        $request->validate([
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['required', 'string', 'max:4096'],
            'base_url' => ['nullable', 'string', 'max:255', 'url', 'starts_with:https://'],
        ]);

        $baseUrl = trim((string) $request->input('base_url', '')) ?: null;
        $clientSecret = $request->string('client_secret')->toString();
        $existingConnection = IntegrationProviderConnection::query()
            ->forProvider(self::PROVIDER)
            ->first();
        $existingConfig = is_array($existingConnection?->config) ? $existingConnection->config : [];
        $config = collect($existingConfig)
            ->only([
                'discovered_applications',
                'applications_synced_at',
                'webhook_secret_encrypted',
                'webhook_secret_last4',
                'webhook_configured_at',
            ])
            ->all();
        $config['client_id'] = $request->string('client_id')->trim()->toString();
        if ($baseUrl !== null) {
            $config['base_url'] = $baseUrl;
        }

        $connection = $existingConnection
            ?? IntegrationProviderConnection::create([
                'provider' => self::PROVIDER,
                'status' => IntegrationProviderConnection::STATUS_DISCONNECTED,
                'created_by' => $user->id,
            ]);
        $connectionWasCreated = $existingConnection === null;

        try {
            $this->secrets->storeApplication(
                $connection,
                IntegrationSecretManager::PURPOSE_PRIMARY,
                ['client_secret' => $clientSecret],
            );
        } catch (\Throwable) {
            if ($connectionWasCreated && $connection->secretReferences()->doesntExist()) {
                $connection->delete();
            }

            return redirect()->back()->with('error', 'The governed secret manager is unavailable. No Milesight client secret was stored.');
        }
        $connection->update([
            'secret_last4' => substr($clientSecret, -4),
            'status' => IntegrationProviderConnection::STATUS_DISCONNECTED,
            'last_error' => null,
            'config' => $config,
            'created_by' => $user->id,
        ]);

        Integration::updateOrCreate(
            [
                'provider' => self::PROVIDER,
            ],
            [
                'display_name' => 'Milesight',
                'status' => Integration::STATUS_INACTIVE,
                'last_error' => null,
            ],
        );

        return redirect()->back()->with('success', 'Milesight OAuth credentials saved. Run Test Connection to verify.');
    }

    public function testKey(Request $request, IntegrationAdapterRegistry $registry)
    {
        $user = $request->user();
        abort_unless($this->userCanManage($user), 403);

        $connection = IntegrationProviderConnection::query()
            ->forProvider(self::PROVIDER)
            ->firstOrFail();

        if (! $registry->hasCapability(self::PROVIDER, ConnectionHealthCapability::class)) {
            return redirect()->back()->with('error', 'Milesight connection testing is not available.');
        }

        $adapter = $registry->capability(self::PROVIDER, ConnectionHealthCapability::class);
        assert($adapter instanceof ConnectionHealthCapability);
        $ok = $adapter->testConnection($connection);

        if ($ok) {
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
                    'display_name' => 'Milesight',
                    'status' => Integration::STATUS_ACTIVE,
                    'last_tested_at' => now(),
                    'last_error' => null,
                ],
            );

            return redirect()->back()->with('success', 'Milesight connection test succeeded.');
        }

        $connection->update([
            'status' => IntegrationProviderConnection::STATUS_ERROR,
            'last_tested_at' => now(),
            'last_error' => 'Milesight rejected the OAuth credentials or the server was unreachable.',
        ]);

        Integration::updateOrCreate(
            [
                'provider' => self::PROVIDER,
            ],
            [
                'display_name' => 'Milesight',
                'status' => Integration::STATUS_ERROR,
                'last_tested_at' => now(),
                'last_error' => 'Milesight rejected the OAuth credentials or the server was unreachable.',
            ],
        );

        return redirect()->back()->with('error', 'Milesight connection test failed. Check the client ID, client secret, and server URL.');
    }

    public function rotateKey(Request $request)
    {
        $user = $request->user();
        abort_unless($this->userCanManage($user), 403);

        $request->validate([
            'client_secret' => ['required', 'string', 'max:4096'],
        ]);

        $connection = IntegrationProviderConnection::query()
            ->forProvider(self::PROVIDER)
            ->firstOrFail();

        $clientSecret = $request->string('client_secret')->toString();
        try {
            $this->secrets->storeApplication(
                $connection,
                IntegrationSecretManager::PURPOSE_PRIMARY,
                ['client_secret' => $clientSecret],
            );
        } catch (\Throwable) {
            return redirect()->back()->with('error', 'The governed secret manager is unavailable. The existing Milesight credential remains unchanged.');
        }
        $connection->update([
            'secret_last4' => substr($clientSecret, -4),
            'rotated_at' => now(),
            'status' => IntegrationProviderConnection::STATUS_DISCONNECTED,
            'last_error' => null,
        ]);

        return redirect()->back()->with('success', 'Client secret rotated. Run Test Connection.');
    }

    public function saveWebhook(Request $request)
    {
        $user = $request->user();
        abort_unless($this->userCanManage($user), 403);

        $validated = $request->validate([
            'webhook_secret' => ['required', 'string', 'min:16', 'max:4096'],
        ]);
        $connection = IntegrationProviderConnection::query()
            ->forProvider(self::PROVIDER)
            ->firstOrFail();
        $config = is_array($connection->config) ? $connection->config : [];
        $secret = (string) $validated['webhook_secret'];
        try {
            $this->secrets->storeApplication(
                $connection,
                IntegrationSecretManager::PURPOSE_WEBHOOK,
                ['webhook_secret' => $secret],
            );
        } catch (\Throwable) {
            return redirect()->back()->with('error', 'The governed secret manager is unavailable. No Milesight webhook secret was stored.');
        }
        $config['webhook_secret_last4'] = substr($secret, -4);
        $config['webhook_configured_at'] = now()->toIso8601String();
        $connection->update(['config' => $config]);

        return redirect()->back()->with('success', 'Milesight webhook verification enabled. Add the callback URL to the same Milesight application.');
    }

    public function removeWebhook(Request $request)
    {
        $user = $request->user();
        abort_unless($this->userCanManage($user), 403);

        $connection = IntegrationProviderConnection::query()
            ->forProvider(self::PROVIDER)
            ->firstOrFail();
        $this->secrets->revokeApplication($connection, IntegrationSecretManager::PURPOSE_WEBHOOK);
        $config = is_array($connection->config) ? $connection->config : [];
        unset(
            $config['webhook_secret_encrypted'],
            $config['webhook_secret_last4'],
            $config['webhook_configured_at'],
        );
        $connection->update(['config' => $config]);

        return redirect()->back()->with('success', 'Milesight webhook verification disabled. Remove the callback from Milesight too.');
    }

    public function removeKey(Request $request)
    {
        $user = $request->user();
        abort_unless($this->userCanManage($user), 403);

        $connection = IntegrationProviderConnection::query()
            ->forProvider(self::PROVIDER)
            ->first();
        if ($connection !== null) {
            $this->secrets->deleteApplicationConnection($connection, [
                IntegrationSecretManager::PURPOSE_PRIMARY,
                IntegrationSecretManager::PURPOSE_WEBHOOK,
            ]);
        }

        Integration::query()
            ->where('provider', self::PROVIDER)
            ->update([
                'status' => Integration::STATUS_INACTIVE,
            ]);

        return redirect()->back()->with('success', 'Milesight credentials removed.');
    }

    public function syncApplications(Request $request, IntegrationAdapterRegistry $registry)
    {
        $user = $request->user();
        abort_unless($this->userCanManage($user), 403);

        $connection = IntegrationProviderConnection::query()
            ->forProvider(self::PROVIDER)
            ->connected()
            ->firstOrFail();
        abort_unless($registry->hasCapability(self::PROVIDER, InventoryDiscoveryCapability::class), 409);

        $syncLog = IntegrationSyncLog::query()->create([
            'provider' => self::PROVIDER,
            'action' => 'discover_applications',
            'status' => IntegrationSyncLog::STATUS_STARTED,
            'started_at' => now(),
        ]);

        try {
            $adapter = $registry->capability(self::PROVIDER, InventoryDiscoveryCapability::class);
            assert($adapter instanceof InventoryDiscoveryCapability);
            $applications = $adapter->discoverSites($connection);
            $config = is_array($connection->config) ? $connection->config : [];
            $connection->update([
                'config' => array_merge($config, [
                    'discovered_applications' => array_values($applications),
                    'applications_synced_at' => now()->toISOString(),
                ]),
                'last_synced_at' => now(),
                'last_error' => null,
            ]);
            $syncLog->update([
                'items_processed' => count($applications),
                'items_created' => count($applications),
            ]);
            $syncLog->markCompleted(
                $applications === [] ? IntegrationSyncLog::STATUS_PARTIAL : IntegrationSyncLog::STATUS_SUCCESS,
                $applications === [] ? 'No applications were represented in the Milesight inventory.' : null,
            );

            return redirect()->back()->with(
                $applications === [] ? 'warning' : 'success',
                $applications === []
                    ? 'No Milesight applications were represented in the device inventory.'
                    : 'Milesight applications synced successfully.',
            );
        } catch (\Throwable $e) {
            $syncLog->markCompleted(IntegrationSyncLog::STATUS_FAILED, SafeOperationalData::failureSummary());
            $connection->update([
                'status' => IntegrationProviderConnection::STATUS_ERROR,
                'last_error' => SafeOperationalData::failureSummary(),
            ]);

            return redirect()->back()->with('error', 'Milesight application discovery failed. Review the bounded diagnostic state and retry.');
        }
    }

    public function mapApplication(Request $request)
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

        $connection = IntegrationProviderConnection::query()->forProvider(self::PROVIDER)->firstOrFail();
        $application = collect(data_get($connection->config, 'discovered_applications', []))
            ->first(function (mixed $candidate) use ($request): bool {
                $externalId = is_array($candidate) ? (string) ($candidate['external_id'] ?? '') : '';

                return $externalId !== '' && hash_equals(
                    $this->mappingToken($externalId),
                    (string) $request->input('mapping_token'),
                );
            });
        abort_unless(is_array($application), 422, 'The selected Milesight application is no longer available. Sync applications and try again.');
        abort_if(
            IntegrationSiteConfig::query()
                ->forProvider(self::PROVIDER)
                ->where('mapped_external_site_id', (string) $application['external_id'])
                ->where('site_id', '!=', $site->id)
                ->exists(),
            422,
            'This Milesight application is already mapped to another Site.',
        );

        try {
            IntegrationSiteConfig::query()->updateOrCreate(
                ['site_id' => $site->id, 'provider' => self::PROVIDER],
                [
                    'mapped_external_site_id' => (string) $application['external_id'],
                    'mapped_external_site_name' => (string) ($application['name'] ?? 'Milesight application'),
                    'status' => IntegrationSiteConfig::STATUS_HYBRID,
                    'is_active' => true,
                ],
            );
        } catch (QueryException $exception) {
            if (! $this->isExternalSiteIdentityConflict($exception)) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'mapping_token' => 'This Milesight application was mapped to another Site. Refresh the mappings and try again.',
            ]);
        }

        return redirect()->back()->with('success', 'Milesight application mapping saved.');
    }

    private function isExternalSiteIdentityConflict(QueryException $exception): bool
    {
        return (int) ($exception->errorInfo[1] ?? 0) === 1062
            && str_contains($exception->getMessage(), 'integration_provider_external_site_unique');
    }

    public function removeApplicationMapping(Request $request, IntegrationSiteConfig $siteConfig)
    {
        $user = $request->user();
        abort_unless($this->userCanManage($user), 403);
        abort_unless($siteConfig->provider === self::PROVIDER, 404);
        $this->access->assertCanViewSite($user, (int) $siteConfig->site_id);
        $siteConfig->delete();

        return redirect()->back()->with('success', 'Milesight application mapping removed.');
    }

    public function syncDevices(Request $request, IntegrationAdapterRegistry $registry)
    {
        $user = $request->user();
        abort_unless($this->userCanManage($user), 403);
        $request->validate(['site_config_id' => ['required', 'integer']]);

        $siteConfig = IntegrationSiteConfig::query()
            ->forProvider(self::PROVIDER)
            ->active()
            ->whereIn('site_id', $this->access->accessibleSiteIds($user))
            ->whereHas('site')
            ->find((int) $request->input('site_config_id'));
        abort_unless($siteConfig, 404);
        abort_if(blank($siteConfig->mapped_external_site_id), 422, 'Map a Milesight application before syncing devices.');

        $connection = IntegrationProviderConnection::query()
            ->forProvider(self::PROVIDER)
            ->connected()
            ->first();
        if (! $connection) {
            return redirect()->back()->with('error', 'Test and connect the Milesight OAuth credentials before syncing devices.');
        }
        abort_unless($registry->hasCapability(self::PROVIDER, DeviceSyncCapability::class), 409);

        $syncLog = IntegrationSyncLog::query()->create([
            'provider' => self::PROVIDER,
            'site_id' => $siteConfig->site_id,
            'action' => 'sync_devices',
            'status' => IntegrationSyncLog::STATUS_STARTED,
            'started_at' => now(),
        ]);

        try {
            $adapter = $registry->capability(self::PROVIDER, DeviceSyncCapability::class);
            assert($adapter instanceof DeviceSyncCapability);
            $result = $adapter->syncDevices($siteConfig, $connection);
            $syncLog->update([
                'items_processed' => $result->processed,
                'items_created' => $result->created,
                'items_updated' => $result->updated,
                'items_errored' => $result->errored,
            ]);
            $status = $result->isSuccess()
                ? IntegrationSyncLog::STATUS_SUCCESS
                : ($result->isPartial() ? IntegrationSyncLog::STATUS_PARTIAL : IntegrationSyncLog::STATUS_FAILED);
            $syncLog->markCompleted(
                $status,
                $status === IntegrationSyncLog::STATUS_FAILED ? SafeOperationalData::failureSummary() : null,
            );
            $connection->update([
                'last_synced_at' => now(),
                'last_error' => $status === IntegrationSyncLog::STATUS_FAILED ? SafeOperationalData::failureSummary() : null,
            ]);

            if ($status === IntegrationSyncLog::STATUS_FAILED) {
                return redirect()->back()->with('error', 'Milesight device sync failed. Review the bounded diagnostic state and retry.');
            }

            return redirect()->back()->with(
                $result->isPartial() ? 'warning' : 'success',
                "Milesight device sync complete. Processed {$result->processed}, created {$result->created}, updated {$result->updated}, errored {$result->errored}.",
            );
        } catch (\Throwable $e) {
            $syncLog->markCompleted(IntegrationSyncLog::STATUS_FAILED, SafeOperationalData::failureSummary());

            return redirect()->back()->with('error', 'Milesight device sync failed. Review the bounded diagnostic state and retry.');
        }
    }

    private function mappingToken(string $externalId): string
    {
        return $externalId === ''
            ? ''
            : hash_hmac('sha256', self::PROVIDER.'|'.$externalId, (string) config('app.key'));
    }

    private function userCanManage($user): bool
    {
        return $user && $user->canDo('securityDevices.integrations.manage');
    }
}
