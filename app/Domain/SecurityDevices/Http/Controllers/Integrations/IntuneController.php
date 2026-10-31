<?php

namespace App\Domain\SecurityDevices\Http\Controllers\Integrations;

use App\Domain\SecurityDevices\Endpoints\Models\IntuneDeviceLink;
use App\Domain\SecurityDevices\Endpoints\Services\IntuneBulkImportService;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use App\Models\Integration\Integration;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSyncLog;
use App\Models\Site;
use App\Services\Integration\Adapters\IntuneAdapter;
use App\Services\Integration\Contracts\ConnectionHealthCapability;
use App\Services\Integration\IntegrationAdapterRegistry;
use App\Services\Integration\IntegrationSecretManager;
use App\Support\SafeOperationalData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Microsoft Intune read-only provider setup, Windows computer discovery, and bulk import.
 */
final class IntuneController extends Controller
{
    private const PROVIDER = IntuneAdapter::PROVIDER_SLUG;

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly IntegrationSecretManager $secrets,
        private readonly IntuneBulkImportService $bulkImport,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $asOf = now()->toImmutable();
        abort_unless($this->userCanManage($user), 403);

        $providerConnection = IntegrationProviderConnection::query()
            ->forProvider(self::PROVIDER)
            ->first();

        $config = is_array($providerConnection?->config) ? $providerConnection->config : [];
        $directoryId = $this->validUuidOrNull($config['directory_id'] ?? null);
        $clientId = $this->validUuidOrNull($config['client_id'] ?? null);

        $siteIds = $this->access->accessibleSiteIds($user);
        $sites = $siteIds === []
            ? []
            : Site::query()
                ->whereIn('id', $siteIds)
                ->where('is_active', true)
                ->where(static fn ($query) => $query->whereNull('archived')->orWhere('archived', false))
                ->whereNull('archived_at')
                ->orderBy('name')
                ->get(['id', 'name', 'type'])
                ->map(static fn (Site $site): array => [
                    'id' => (int) $site->id,
                    'name' => (string) $site->name,
                    'type' => $site->type,
                ])
                ->values()
                ->all();

        $linkedDevicesCount = Schema::hasTable('endpoint_intune_links')
            ? IntuneDeviceLink::query()
                ->whereIn('device_id', $this->access->visibleDevices($user)->select('devices.id'))
                ->count()
            : 0;

        $syncLogs = IntegrationSyncLog::query()
            ->forProvider(self::PROVIDER)
            ->where(static function ($query) use ($siteIds): void {
                if ($siteIds === []) {
                    $query->whereNull('site_id');

                    return;
                }

                $query->whereNull('site_id')->orWhereIn('site_id', $siteIds);
            })
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(static fn (IntegrationSyncLog $log): array => [
                'id' => (int) $log->id,
                'action' => (string) $log->action,
                'status' => (string) $log->status,
                'items_processed' => (int) ($log->items_processed ?? 0),
                'items_created' => (int) ($log->items_created ?? 0),
                'items_updated' => (int) ($log->items_updated ?? 0),
                'items_errored' => (int) ($log->items_errored ?? 0),
                'failure_category' => $log->status === IntegrationSyncLog::STATUS_FAILED ? 'provider_failure' : null,
                'started_at' => $log->started_at?->toISOString(),
                'completed_at' => $log->completed_at?->toISOString(),
            ])
            ->values()
            ->all();

        return Inertia::render('security-devices/integrations/intune', [
            'actor_id' => (int) $user->id,
            'as_of' => $asOf->toISOString(),
            'providerConnection' => $providerConnection ? [
                'status' => $providerConnection->status,
                'secret_last4' => $providerConnection->secret_last4,
                'last_tested_at' => $providerConnection->last_tested_at?->toISOString(),
                'last_synced_at' => $providerConnection->last_synced_at?->toISOString(),
                'directory_id' => $directoryId,
                'client_id' => $clientId,
                'directory_id_configured' => $directoryId !== null,
                'client_id_configured' => $clientId !== null,
                'endpoint_configured' => $directoryId !== null && $clientId !== null,
                'discovered_at' => is_string($config['discovered_at'] ?? null) ? $config['discovered_at'] : null,
            ] : null,
            'discoveredDevices' => $this->bulkImport->presentDiscovered($providerConnection, $user),
            'linkedDevicesCount' => $linkedDevicesCount,
            'sites' => $sites,
            'syncLogs' => $syncLogs,
            'can' => [
                'manage' => $this->userCanManage($user),
                'import_devices' => $this->userCanImport($user),
            ],
        ]);
    }

    public function saveKey(Request $request)
    {
        $user = $request->user();
        abort_unless($this->userCanManage($user), 403);

        $validated = $request->validate([
            'directory_id' => ['required', 'uuid'],
            'client_id' => ['required', 'uuid'],
            'client_secret' => ['required', 'string', 'max:4096'],
        ]);

        $directoryId = strtolower(trim((string) $validated['directory_id']));
        $clientId = strtolower(trim((string) $validated['client_id']));
        $clientSecret = (string) $validated['client_secret'];

        $existingConnection = IntegrationProviderConnection::query()
            ->forProvider(self::PROVIDER)
            ->first();
        $existingConfig = is_array($existingConnection?->config) ? $existingConnection->config : [];
        $config = collect($existingConfig)
            ->only(['discovered_devices', 'discovered_at'])
            ->all();
        $config['directory_id'] = $directoryId;
        $config['client_id'] = $clientId;

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

            return redirect()->back()->with('error', 'Secure credential storage is currently unavailable. No Microsoft Intune secret was saved.');
        }

        $connection->update([
            'secret_last4' => substr($clientSecret, -4),
            'status' => IntegrationProviderConnection::STATUS_DISCONNECTED,
            'last_error' => null,
            'config' => $config,
            'created_by' => $user->id,
        ]);

        Integration::updateOrCreate(
            ['provider' => self::PROVIDER],
            [
                'display_name' => 'Microsoft Intune',
                'status' => Integration::STATUS_INACTIVE,
                'last_error' => null,
            ],
        );

        return redirect()->back()->with('success', 'Microsoft Intune credentials saved. Run Test connection to verify access.');
    }

    public function testKey(Request $request, IntegrationAdapterRegistry $registry)
    {
        $user = $request->user();
        abort_unless($this->userCanManage($user), 403);

        $connection = IntegrationProviderConnection::query()
            ->forProvider(self::PROVIDER)
            ->firstOrFail();

        if (! $registry->hasCapability(self::PROVIDER, ConnectionHealthCapability::class)) {
            return redirect()->back()->with('error', 'Microsoft Intune connection testing is not available.');
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
                ['provider' => self::PROVIDER],
                [
                    'display_name' => 'Microsoft Intune',
                    'status' => Integration::STATUS_ACTIVE,
                    'last_tested_at' => now(),
                    'last_error' => null,
                ],
            );

            return redirect()->back()->with('success', 'Microsoft Intune connection test succeeded.');
        }

        $connection->update([
            'status' => IntegrationProviderConnection::STATUS_ERROR,
            'last_tested_at' => now(),
            'last_error' => 'Microsoft Entra or Microsoft Graph rejected the read-only credentials.',
        ]);

        Integration::updateOrCreate(
            ['provider' => self::PROVIDER],
            [
                'display_name' => 'Microsoft Intune',
                'status' => Integration::STATUS_ERROR,
                'last_tested_at' => now(),
                'last_error' => 'Microsoft Entra or Microsoft Graph rejected the read-only credentials.',
            ],
        );

        return redirect()->back()->with(
            'error',
            'Microsoft Intune connection test failed. Check the Microsoft directory ID, application (client) ID, client secret, and DeviceManagementManagedDevices.Read.All admin consent.',
        );
    }

    public function rotateKey(Request $request)
    {
        $user = $request->user();
        abort_unless($this->userCanManage($user), 403);

        $validated = $request->validate([
            'client_secret' => ['required', 'string', 'max:4096'],
        ]);

        $connection = IntegrationProviderConnection::query()
            ->forProvider(self::PROVIDER)
            ->firstOrFail();

        $clientSecret = (string) $validated['client_secret'];
        try {
            $this->secrets->storeApplication(
                $connection,
                IntegrationSecretManager::PURPOSE_PRIMARY,
                ['client_secret' => $clientSecret],
            );
        } catch (\Throwable) {
            return redirect()->back()->with('error', 'Secure credential storage is currently unavailable. Existing credentials remain unchanged.');
        }

        $connection->update([
            'secret_last4' => substr($clientSecret, -4),
            'rotated_at' => now(),
            'status' => IntegrationProviderConnection::STATUS_DISCONNECTED,
            'last_error' => null,
        ]);

        return redirect()->back()->with('success', 'Client secret rotated. Run Test connection to confirm access.');
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
            ]);
        }

        Integration::query()
            ->where('provider', self::PROVIDER)
            ->update([
                'status' => Integration::STATUS_INACTIVE,
            ]);

        return redirect()->back()->with('success', 'Microsoft Intune credentials removed.');
    }

    public function discover(Request $request)
    {
        $user = $request->user();
        abort_unless($this->userCanManage($user), 403);

        $connection = IntegrationProviderConnection::query()
            ->forProvider(self::PROVIDER)
            ->connected()
            ->first();
        if ($connection === null) {
            return redirect()->back()->with('error', 'Test and connect Microsoft Intune credentials before discovering Windows computers.');
        }

        $syncLog = IntegrationSyncLog::query()->create([
            'provider' => self::PROVIDER,
            'action' => 'discover_devices',
            'status' => IntegrationSyncLog::STATUS_STARTED,
            'started_at' => now(),
        ]);

        try {
            $discovered = $this->bulkImport->discover($connection, $user);
            $syncLog->update([
                'items_processed' => count($discovered),
                'items_created' => count($discovered),
            ]);
            $syncLog->markCompleted(IntegrationSyncLog::STATUS_SUCCESS);

            $count = count($discovered);

            return redirect()->back()->with(
                'success',
                $count === 0
                    ? 'Microsoft Intune scan finished. No Windows computers were returned.'
                    : "Found {$count} Windows ".($count === 1 ? 'computer' : 'computers').' in Microsoft Intune.',
            );
        } catch (\Throwable) {
            $syncLog->markCompleted(IntegrationSyncLog::STATUS_FAILED, SafeOperationalData::failureSummary());
            $connection->update([
                'status' => IntegrationProviderConnection::STATUS_ERROR,
                'last_error' => SafeOperationalData::failureSummary(),
            ]);

            return redirect()->back()->with('error', 'Microsoft Intune discovery could not complete. Check the connection and try again.');
        }
    }

    public function importDevices(Request $request)
    {
        $user = $request->user();
        abort_unless($this->userCanImport($user), 403);

        $validated = $request->validate([
            'site_id' => ['required', 'integer'],
            'managed_device_ids' => ['required', 'array', 'min:1', 'max:200'],
            'managed_device_ids.*' => ['required', 'uuid'],
        ]);

        $siteId = (int) $validated['site_id'];
        $this->access->assertCanViewSite($user, $siteId);
        $site = Site::query()
            ->whereKey($siteId)
            ->where('is_active', true)
            ->where(static fn ($query) => $query->whereNull('archived')->orWhere('archived', false))
            ->whereNull('archived_at')
            ->first();
        if ($site === null) {
            $this->rejectRequest($request, 'site_id', 'Choose an active Site before importing computers.');
        }

        $connection = IntegrationProviderConnection::query()
            ->forProvider(self::PROVIDER)
            ->connected()
            ->first();
        if ($connection === null) {
            return redirect()->back()->with('error', 'Test and connect Microsoft Intune credentials before importing computers.');
        }

        try {
            $summary = $this->bulkImport->import(
                $connection,
                $user,
                $siteId,
                array_values($validated['managed_device_ids']),
            );
        } catch (\Throwable) {
            return redirect()->back()->with('error', 'Microsoft Intune import could not complete. Check the connection and try again.');
        }

        if ($summary['errored'] > 0 && ($summary['created'] + $summary['updated']) === 0) {
            return redirect()->back()->with('error', 'None of the selected Microsoft Intune computers could be imported.');
        }

        $level = $summary['errored'] > 0 ? 'warning' : 'success';

        return redirect()->back()->with(
            $level,
            "Microsoft Intune import complete for {$site->name}: {$summary['created']} added, {$summary['updated']} refreshed, {$summary['errored']} skipped.",
        );
    }

    public function syncLinked(Request $request)
    {
        $user = $request->user();
        abort_unless($this->userCanManage($user), 403);

        $connection = IntegrationProviderConnection::query()
            ->forProvider(self::PROVIDER)
            ->connected()
            ->first();
        if ($connection === null) {
            return redirect()->back()->with('error', 'Test and connect Microsoft Intune credentials before refreshing linked computers.');
        }

        try {
            $summary = $this->bulkImport->syncLinked($connection);

            return redirect()->back()->with(
                'success',
                "Microsoft Intune posture refreshed: {$summary['updated']} updated, {$summary['not_found']} not found in Intune.",
            );
        } catch (\Throwable) {
            return redirect()->back()->with('error', 'Microsoft Intune refresh could not complete. Check the connection and try again.');
        }
    }

    private function rejectRequest(Request $request, string $field, string $message): never
    {
        if ($request->header('X-Inertia')) {
            throw ValidationException::withMessages([$field => $message]);
        }

        abort(422, $message);
    }

    private function validUuidOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = strtolower(trim($value));

        return preg_match(self::UUID, $trimmed) === 1 ? $trimmed : null;
    }

    private function userCanManage($user): bool
    {
        return (bool) ($user && $user->canDo('securityDevices.integrations.manage'));
    }

    private function userCanImport($user): bool
    {
        return (bool) (
            $user
            && $user->canDo('securityDevices.integrations.manage')
            && $user->canDo('securityDevices.devices.create')
            && $user->canDo('securityDevices.devices.assign')
        );
    }
}
