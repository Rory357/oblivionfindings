<?php

namespace App\Domain\SecurityDevices\Screens\Setup;

use App\Domain\SecurityDevices\Endpoints\Services\IntuneBulkImportService;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\AppSetting;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Integration\IntegrationSyncLog;
use App\Models\Site;
use App\Models\User;
use App\Services\Integration\IntegrationSecretManager;
use Illuminate\Support\Collection;

/**
 * Pop-up 15, Connect a service (S&D rebuild, Phase E): what the wizard needs to know about each
 * service the viewer may connect, read from the same records each service's settings page reads.
 *
 * Shown only where a backend exists: UniFi and Microsoft Intune (sign in, tested on the spot, then
 * match and bring in), Queclink trackers (they report to Oblivion; no sign-in and no test), and
 * Milesight gateways and MQTT sensors (pop-up 98 makes their login on the Site's hub). Inner Range,
 * Gallagher, Ajax and other SIA panels have no service-level save path yet, so they aren't offered.
 *
 * A saved key, secret or its last characters never leave the server: only whether one is saved.
 * Provider site ids stay behind the same signed tokens the UniFi settings page uses.
 */
final class ConnectServicePresenter
{
    public const string UNIFI = 'unifi';

    public const string INTUNE = 'intune';

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly IntegrationSecretManager $secrets,
        private readonly IntuneBulkImportService $intune,
    ) {}

    /** The permission each service's own settings page needs today. */
    public static function canConnect(User $viewer): bool
    {
        return $viewer->canDo('securityDevices.integrations.manage');
    }

    /** @return array<string, mixed> */
    public function present(User $viewer): array
    {
        $sites = $this->access->accessibleSites($viewer)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('archived')->orWhere('archived', false))
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get(['id', 'name']);
        // Pop-up 98 makes a gateway's login on the Site's hub: securityDevices.savedLogins.manage.
        $gateways = $viewer->canDo('securityDevices.savedLogins.manage');

        return [
            'services' => array_values(array_filter([
                self::UNIFI,
                $gateways ? 'milesight' : null,
                'queclink',
                self::INTUNE,
                $gateways ? 'mqtt' : null,
            ])),
            'sites' => $sites->map(fn (Site $site): array => ['id' => (int) $site->id, 'name' => (string) $site->name])->all(),
            'can' => [
                'gateway' => $gateways,
                // IntuneController::importDevices: bringing computers in also adds and places devices.
                'import_computers' => self::canConnect($viewer)
                    && $viewer->canDo('securityDevices.devices.create')
                    && $viewer->canDo('securityDevices.devices.assign'),
            ],
            'unifi' => $this->unifi($sites),
            'intune' => $this->intune($viewer),
            'queclink' => $this->queclink(),
        ];
    }

    /**
     * @param  Collection<int, Site>  $sites  the viewer's Sites
     * @return array<string, mixed>
     */
    private function unifi(Collection $sites): array
    {
        $connection = IntegrationProviderConnection::query()->forProvider(self::UNIFI)->first();
        $config = is_array($connection?->config) ? $connection->config : [];
        $names = $sites->mapWithKeys(fn (Site $site): array => [(int) $site->id => (string) $site->name]);
        $matches = IntegrationSiteConfig::query()
            ->forProvider(self::UNIFI)
            ->get(['id', 'site_id', 'mapped_external_site_id', 'is_active'])
            ->filter(fn (IntegrationSiteConfig $match): bool => is_scalar($match->mapped_external_site_id) && (string) $match->mapped_external_site_id !== '')
            ->keyBy(fn (IntegrationSiteConfig $match): string => (string) $match->mapped_external_site_id);
        $syncs = $names->isEmpty() ? collect() : IntegrationSyncLog::query()
            ->forProvider(self::UNIFI)
            ->where('action', 'sync_devices')
            ->whereIn('site_id', $names->keys()->all())
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'site_id', 'status', 'items_created', 'items_updated', 'items_errored', 'completed_at'])
            ->unique('site_id')
            ->keyBy('site_id');

        $remote = collect($config['discovered_sites'] ?? [])
            ->filter(fn (mixed $site): bool => is_array($site) && is_scalar($site['external_id'] ?? null) && (string) $site['external_id'] !== '')
            ->map(function (array $site) use ($matches, $names, $syncs): array {
                $match = $matches->get((string) $site['external_id']);
                $siteId = $match !== null ? (int) $match->site_id : null;
                $mine = $siteId !== null && $names->has($siteId);
                $sync = $mine ? $syncs->get($siteId) : null;

                return [
                    'token' => self::unifiToken((string) $site['external_id']),
                    'name' => mb_substr(trim((string) ($site['name'] ?? '')) ?: 'UniFi site', 0, 120),
                    'devices' => is_numeric(data_get($site, 'meta.device_count')) ? (int) data_get($site, 'meta.device_count') : null,
                    'match' => $match === null ? null : [
                        // Matched to a Site the viewer can't see: say so, never which one.
                        'elsewhere' => ! $mine,
                        'site_id' => $mine ? $siteId : null,
                        'site_name' => $mine ? $names->get($siteId) : null,
                        'config_id' => $mine ? (int) $match->id : null,
                        'last_sync' => $sync === null ? null : [
                            'status' => (string) $sync->status,
                            'created' => (int) ($sync->items_created ?? 0),
                            'updated' => (int) ($sync->items_updated ?? 0),
                            'errored' => (int) ($sync->items_errored ?? 0),
                            'id' => (int) $sync->id,
                        ],
                    ],
                ];
            })
            ->values()
            ->all();

        return [
            'has_key' => $connection !== null && $this->secrets->applicationConfigured($connection, IntegrationSecretManager::PURPOSE_PRIMARY),
            'status' => $connection?->status ?? 'not_configured',
            'tested_at' => $connection?->last_tested_at?->toIso8601String(),
            'consoles' => is_numeric($config['discovered_host_count'] ?? null) ? max(0, (int) $config['discovered_host_count']) : null,
            'sites_found_at' => is_string($config['sites_synced_at'] ?? null) ? $config['sites_synced_at'] : null,
            'remote_sites' => $remote,
            // A Site takes one UniFi site (the match is per Site); these are already taken.
            'taken_site_ids' => $matches->pluck('site_id')->map(fn (mixed $id): int => (int) $id)->filter(fn (int $id): bool => $names->has($id))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function intune(User $viewer): array
    {
        $connection = IntegrationProviderConnection::query()->forProvider(self::INTUNE)->first();
        $config = is_array($connection?->config) ? $connection->config : [];
        $uuid = fn (mixed $value): bool => is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;

        return [
            'has_key' => $connection !== null && $this->secrets->applicationConfigured($connection, IntegrationSecretManager::PURPOSE_PRIMARY),
            'status' => $connection?->status ?? 'not_configured',
            'tested_at' => $connection?->last_tested_at?->toIso8601String(),
            // Whether the directory and app ids are saved; the ids themselves aren't sent.
            'ids_saved' => $uuid($config['directory_id'] ?? null) && $uuid($config['client_id'] ?? null),
            'found_at' => is_string($config['discovered_at'] ?? null) ? $config['discovered_at'] : null,
            'computers' => collect($this->intune->presentDiscovered($connection, $viewer))
                ->take(200)
                ->map(fn (array $computer): array => [
                    'id' => (string) $computer['managed_device_id'],
                    'name' => (string) $computer['device_name'],
                    'sub' => implode(' · ', array_values(array_filter([
                        trim(((string) ($computer['manufacturer'] ?? '')).' '.((string) ($computer['model'] ?? ''))),
                        (string) ($computer['operating_system'] ?? ''),
                    ], fn (string $part): bool => $part !== ''))),
                    'linked' => ($computer['link_state'] ?? 'unlinked') !== 'unlinked',
                    'site' => $computer['linked_device']['site_name'] ?? null,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Queclink trackers report to Oblivion's listener. The public address stays on the server (the
     * Queclink page applies it through the secure device-management process); only whether it's set.
     *
     * @return array{port: int, address_set: bool}
     */
    private function queclink(): array
    {
        $port = AppSetting::query()->where('key', 'queclink.listener.port')->value('value') ?? config('services.queclink.port', 8090);
        $address = AppSetting::query()->where('key', 'queclink.public_hostname')->value('value') ?? config('services.queclink.public_hostname') ?? '';

        return ['port' => (int) $port, 'address_set' => trim((string) $address) !== ''];
    }

    /** The UniFi settings page's mapping token (UnifiController::mappingToken), so map-site accepts it. */
    public static function unifiToken(string $externalId): string
    {
        return hash_hmac('sha256', self::UNIFI.'|'.$externalId, (string) config('app.key'));
    }
}
