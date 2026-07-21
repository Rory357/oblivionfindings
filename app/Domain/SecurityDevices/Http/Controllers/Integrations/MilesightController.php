<?php

namespace App\Domain\SecurityDevices\Http\Controllers\Integrations;

use App\Domain\SecurityDevices\Presenters\IntegrationSiteCredentialsPresenter;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use App\Models\Integration\Integration;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSyncLog;
use App\Services\Integration\Adapters\MilesightAdapter;
use App\Services\Integration\IntegrationAdapterRegistry;
use App\Support\LegacyStorageContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;

/**
 * Milesight provider configuration (scaffold stage).
 *
 * Credential management and connection testing are fully functional.
 * LoRaWAN application / gateway mapping, device sync, and payload decoding
 * are deferred to PR D1.
 */
class MilesightController extends Controller
{
    private const PROVIDER = MilesightAdapter::PROVIDER_SLUG;

    public function __construct(
        private readonly IntegrationSiteCredentialsPresenter $siteCredentials,
        private readonly SecurityDevicesAccessService $access,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($this->userCanManage($user), 403);

        $providerConnection = IntegrationProviderConnection::query()
            ->forProvider(self::PROVIDER)
            ->first();

        $config = is_array($providerConnection?->config) ? $providerConnection->config : [];
        $siteIds = $this->access->accessibleSiteIds($user);

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
            ] : null,
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
            'api_key' => ['required', 'string'],
            'base_url' => ['nullable', 'string', 'max:255', 'url'],
        ]);

        $baseUrl = trim((string) $request->input('base_url', '')) ?: null;

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
                'config' => $baseUrl ? ['base_url' => $baseUrl] : [],
                'created_by' => $user->id,
            ],
        );

        Integration::updateOrCreate(
            [
                'provider' => self::PROVIDER,
            ],
            [
                'tenant_id' => LegacyStorageContext::id(),
                'display_name' => 'Milesight',
                'status' => Integration::STATUS_INACTIVE,
                'last_error' => null,
            ],
        );

        return redirect()->back()->with('success', 'Milesight API key saved. Run Test Connection to verify.');
    }

    public function testKey(Request $request, IntegrationAdapterRegistry $registry)
    {
        $user = $request->user();
        abort_unless($this->userCanManage($user), 403);

        $connection = IntegrationProviderConnection::query()
            ->forProvider(self::PROVIDER)
            ->firstOrFail();

        if (! $registry->has(self::PROVIDER)) {
            return redirect()->back()->with('error', 'Milesight adapter is not registered.');
        }

        $adapter = $registry->resolve(self::PROVIDER);
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
                    'tenant_id' => LegacyStorageContext::id(),
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
            'last_error' => 'Milesight rejected the key or the server was unreachable.',
        ]);

        Integration::updateOrCreate(
            [
                'provider' => self::PROVIDER,
            ],
            [
                'tenant_id' => LegacyStorageContext::id(),
                'display_name' => 'Milesight',
                'status' => Integration::STATUS_ERROR,
                'last_tested_at' => now(),
                'last_error' => 'Milesight rejected the key or the server was unreachable.',
            ],
        );

        return redirect()->back()->with('error', 'Milesight connection test failed. Check the API key and server URL.');
    }

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

    public function removeKey(Request $request)
    {
        $user = $request->user();
        abort_unless($this->userCanManage($user), 403);

        IntegrationProviderConnection::query()
            ->forProvider(self::PROVIDER)
            ->delete();

        Integration::query()
            ->where('provider', self::PROVIDER)
            ->update([
                'status' => Integration::STATUS_INACTIVE,
            ]);

        return redirect()->back()->with('success', 'Milesight credentials removed.');
    }

    private function userCanManage($user): bool
    {
        return $user && $user->canDo('securityDevices.integrations.manage');
    }
}
