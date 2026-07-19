<?php

namespace App\Domain\SecurityDevices\Presenters;

use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Integration\IntegrationSiteSecret;
use App\Models\Integration\IntegrationSyncLog;
use App\Models\Integration\IntegrationTenantSecret;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class IntegrationsWorkspacePresenter
{
    private const ROTATION_CADENCE_DAYS = 90;

    private const STALE_SYNC_HOURS = 24;

    private const SITE_MAPPING_DISPLAY_LIMIT = 50;

    private const PROVIDERS = [
        ['slug' => 'unifi', 'name' => 'UniFi', 'vendor' => 'Ubiquiti', 'implementation_status' => 'live', 'summary' => 'Network, CCTV, and access infrastructure.', 'capabilities' => ['network', 'cctv', 'access_control', 'device_health', 'event_stream'], 'device_scope' => ['cameras', 'doors', 'access points', 'switches', 'gateways']],
        ['slug' => 'queclink', 'name' => 'Queclink', 'vendor' => 'Queclink Wireless', 'implementation_status' => 'scaffold', 'summary' => 'Cellular GPS trackers for vehicles, assets, and personal safety.', 'capabilities' => ['tracking', 'telemetry', 'device_health', 'event_stream'], 'device_scope' => ['vehicle trackers', 'personal trackers', 'asset trackers']],
        ['slug' => 'milesight', 'name' => 'Milesight', 'vendor' => 'Milesight IoT', 'implementation_status' => 'scaffold', 'summary' => 'LoRaWAN gateways and environmental or support sensors.', 'capabilities' => ['iot', 'environmental', 'healthcare_sensors', 'gateway_management', 'event_stream'], 'device_scope' => ['bed sensors', 'fall sensors', 'door contacts', 'environment sensors', 'gateways']],
    ];

    public function __construct(private readonly SecurityDevicesAccessService $access) {}

    /** @return array<string, mixed> */
    public function present(User $viewer): array
    {
        $tenantId = $this->access->tenantId($viewer);
        $canManage = $viewer->canDo('securityDevices.integrations.manage');
        $canViewDevices = $viewer->canDo('securityDevices.devices.view');
        $siteIds = $this->access->accessibleSiteIds($viewer);
        $providerSlugs = collect(self::PROVIDERS)->pluck('slug');
        $visibleDeviceQuery = $this->access->visibleDevices($viewer)
            ->whereIn('provider', $providerSlugs);
        $deviceCounts = (clone $visibleDeviceQuery)
            ->select('provider')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('provider')
            ->pluck('aggregate', 'provider');
        $unassignedCounts = (clone $visibleDeviceQuery)
            ->whereDoesntHave('assignments', fn ($query) => $query->active())
            ->select('provider')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('provider')
            ->pluck('aggregate', 'provider');
        $duplicateCounts = $this->duplicateCandidateCounts(clone $visibleDeviceQuery);
        $eventsByProvider = DeviceEvent::query()
            ->whereIn('device_id', (clone $visibleDeviceQuery)->select('devices.id'))
            ->where('occurred_at', '>=', now()->subDay())
            ->whereNotNull('source')
            ->selectRaw('source, count(*) as aggregate')
            ->groupBy('source')
            ->pluck('aggregate', 'source');
        $secrets = IntegrationTenantSecret::query()->forTenant($tenantId)->get()->keyBy('provider');
        $configQuery = IntegrationSiteConfig::query()
            ->forTenant($tenantId)
            ->when($siteIds === [], fn ($query) => $query->whereRaw('1 = 0'))
            ->when($siteIds !== [], fn ($query) => $query->whereIn('site_id', $siteIds))
            ->whereHas('site', fn ($site) => $site->where('tenant_id', $tenantId));
        $configStats = (clone $configQuery)
            ->select('provider')
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw("SUM(CASE WHEN is_active = 0 OR status = ? OR mapped_external_site_id IS NULL OR mapped_external_site_id = '' THEN 1 ELSE 0 END) as unmapped_count", [IntegrationSiteConfig::STATUS_DISCONNECTED])
            ->groupBy('provider')
            ->get()
            ->keyBy('provider');
        $configs = $providerSlugs->flatMap(fn (string $provider) => (clone $configQuery)
            ->where('provider', $provider)
            ->with('site:id,name,tenant_id')
            ->orderBy('site_id')
            ->limit(self::SITE_MAPPING_DISPLAY_LIMIT)
            ->get())
            ->groupBy('provider');
        $siteSecretQuery = IntegrationSiteSecret::query()
            ->forTenant($tenantId)
            ->when($siteIds === [], fn ($query) => $query->whereRaw('1 = 0'))
            ->when($siteIds !== [], fn ($query) => $query->whereIn('site_id', $siteIds))
            ->whereHas('site', fn ($site) => $site->where('tenant_id', $tenantId));
        $siteSecretStats = (clone $siteSecretQuery)
            ->select('provider')
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw('SUM(CASE WHEN is_enabled = 1 THEN 1 ELSE 0 END) as enabled_count')
            ->selectRaw("SUM(CASE WHEN last_error IS NOT NULL AND last_error <> '' THEN 1 ELSE 0 END) as error_count")
            ->selectRaw("SUM(CASE WHEN (last_error IS NULL OR last_error = '') AND is_enabled = 1 AND last_tested_at IS NULL THEN 1 ELSE 0 END) as untested_count")
            ->selectRaw("SUM(CASE WHEN (last_error IS NULL OR last_error = '') AND is_enabled = 1 AND last_tested_at IS NOT NULL THEN 1 ELSE 0 END) as connected_count")
            ->selectRaw("SUM(CASE WHEN (last_error IS NULL OR last_error = '') AND is_enabled = 0 THEN 1 ELSE 0 END) as disabled_count")
            ->selectRaw('MAX(last_tested_at) as latest_tested_at')
            ->groupBy('provider')
            ->get()
            ->keyBy('provider');
        $siteSecretCapabilities = (clone $siteSecretQuery)
            ->where('is_enabled', true)
            ->whereNotNull('capability')
            ->select(['provider', 'capability'])
            ->distinct()
            ->orderBy('capability')
            ->get()
            ->groupBy('provider')
            ->map(fn ($rows) => $rows->pluck('capability')->values()->all());
        $syncSummaries = $this->syncSummaries($tenantId, $siteIds, $this->access->canViewAllTenantSites($viewer));

        $providers = collect(self::PROVIDERS)->map(function (array $catalog) use ($canManage, $canViewDevices, $configStats, $configs, $deviceCounts, $duplicateCounts, $eventsByProvider, $secrets, $siteSecretCapabilities, $siteSecretStats, $syncSummaries, $unassignedCounts): array {
            $slug = $catalog['slug'];
            $secret = $secrets->get($slug);
            $siteSecretStat = $siteSecretStats->get($slug);
            $siteSecretTotal = (int) ($siteSecretStat?->total_count ?? 0);
            $enabledSiteSecretCount = (int) ($siteSecretStat?->enabled_count ?? 0);
            $erroredSiteSecretCount = (int) ($siteSecretStat?->error_count ?? 0);
            $connectedSiteSecretCount = (int) ($siteSecretStat?->connected_count ?? 0);
            $untestedSiteSecretCount = (int) ($siteSecretStat?->untested_count ?? 0);
            $disabledSiteSecretCount = (int) ($siteSecretStat?->disabled_count ?? 0);
            $siteSecretExceptionCount = $erroredSiteSecretCount + $untestedSiteSecretCount;
            $credentialConfigured = $secret !== null || $siteSecretTotal > 0;
            $connectionStatus = match (true) {
                $erroredSiteSecretCount > 0 => IntegrationTenantSecret::STATUS_ERROR,
                $secret !== null => $secret->status,
                $connectedSiteSecretCount > 0 => IntegrationTenantSecret::STATUS_CONNECTED,
                $untestedSiteSecretCount > 0 => IntegrationSiteCredentialsPresenter::STATE_UNTESTED,
                $disabledSiteSecretCount > 0 => IntegrationSiteCredentialsPresenter::STATE_DISABLED,
                default => 'not_configured',
            };
            $providerConfigs = $configs->get($slug, collect())->values();
            $providerConfigStat = $configStats->get($slug);
            $configTotal = (int) ($providerConfigStat?->total_count ?? 0);
            $unmappedCount = (int) ($providerConfigStat?->unmapped_count ?? 0);
            $sync = $syncSummaries->get($slug, $this->emptySyncSummary());
            $deviceCount = (int) ($deviceCounts[$slug] ?? 0);
            $unassigned = (int) ($unassignedCounts[$slug] ?? 0);
            $duplicateCandidates = (int) ($duplicateCounts[$slug] ?? 0);
            $unsupportedChecks = 0;
            $syncAt = $sync['at'] ?? $secret?->last_synced_at;
            $syncFreshness = $syncAt === null
                ? 'never'
                : (($sync['stale_scope_count'] ?? 0) > 0 || $syncAt->lt(now()->subHours(self::STALE_SYNC_HOURS)) ? 'stale' : 'current');

            $exceptions = collect();
            if (! $credentialConfigured) {
                $exceptions->push($this->exception('missing_credentials', 'Credentials are not configured.', 'Add credentials', $canManage ? "/security-devices/integrations/{$slug}" : null));
            }
            if ($erroredSiteSecretCount > 0) {
                $exceptions->push($this->exception('site_credential_error', "{$erroredSiteSecretCount} site credential has failure evidence.", 'Review site credentials', $canManage ? "/security-devices/integrations/{$slug}" : null, $erroredSiteSecretCount));
            }
            if ($untestedSiteSecretCount > 0) {
                $exceptions->push($this->exception('site_credential_untested', "{$untestedSiteSecretCount} enabled site credential has not been tested.", 'Test site credentials', $canManage ? "/security-devices/integrations/{$slug}" : null, $untestedSiteSecretCount));
            }
            if ($unmappedCount > 0) {
                $exceptions->push($this->exception('unmapped_site', "{$unmappedCount} site mapping requires attention.", 'Review site mappings', $canManage ? "/security-devices/integrations/{$slug}" : null, $unmappedCount));
            }
            if (in_array($sync['status'], [IntegrationSyncLog::STATUS_FAILED, IntegrationSyncLog::STATUS_PARTIAL], true)) {
                $exceptions->push($this->exception('integration_error', 'The latest provider sync did not complete successfully.', 'Review sync diagnostics', $canManage ? "/security-devices/integrations/{$slug}" : null, max(1, $sync['items_errored'])));
            } elseif ($secret?->status === IntegrationTenantSecret::STATUS_ERROR) {
                $exceptions->push($this->exception('integration_error', 'The provider connection needs attention.', 'Test the connection', $canManage ? "/security-devices/integrations/{$slug}" : null));
            }
            if ($syncFreshness === 'stale') {
                $exceptions->push($this->exception('stale_sync', 'One or more latest sync scopes are more than 24 hours old.', 'Review sync schedule', $canManage ? "/security-devices/integrations/{$slug}" : null, max(1, (int) ($sync['stale_site_count'] ?? 0))));
            }
            if ($unassigned > 0) {
                $exceptions->push($this->exception('unassigned_import', "{$unassigned} imported device has no current assignment.", 'Review imported devices', $canViewDevices ? '/security-devices/devices?view=unassigned' : null, $unassigned));
            }
            if ($duplicateCandidates > 0) {
                $exceptions->push($this->exception('duplicate_candidate', "{$duplicateCandidates} duplicate external identity requires review.", 'Reconcile duplicate candidates', $canViewDevices ? '/security-devices/devices' : null, $duplicateCandidates));
            }

            $provider = array_merge($catalog, [
                'docs_href' => $canManage ? "/security-devices/integrations/{$slug}" : null,
                'connection_status' => $connectionStatus,
                'connected' => $connectionStatus === IntegrationTenantSecret::STATUS_CONNECTED,
                'last_tested_at' => $secret?->last_tested_at?->toISOString()
                    ?? (filled($siteSecretStat?->latest_tested_at) ? Carbon::parse($siteSecretStat->latest_tested_at)->toISOString() : null),
                'last_synced_at' => $syncAt?->toISOString(),
                'device_count' => $deviceCount,
                'events_24h' => (int) ($eventsByProvider[$slug] ?? 0),
                'site_mapping' => [
                    'total' => $configTotal,
                    'mapped' => $configTotal - $unmappedCount,
                    'unmapped' => $unmappedCount,
                    'sites' => $providerConfigs->map(fn (IntegrationSiteConfig $config): array => [
                        'id' => $config->site_id,
                        'name' => $config->site?->name,
                        'state' => ! $config->is_active
                            || $config->status === IntegrationSiteConfig::STATUS_DISCONNECTED
                            || blank($config->mapped_external_site_id)
                                ? 'needs_attention'
                                : 'mapped',
                    ])->values()->all(),
                ],
                'sync' => [
                    'status' => $sync['status'] ?? ($syncAt ? 'success' : 'not_run'),
                    'freshness' => $syncFreshness,
                    'last_synced_at' => $syncAt?->toISOString(),
                    'items_processed' => $sync['items_processed'],
                    'items_errored' => $sync['items_errored'],
                    'stale_site_count' => $sync['stale_site_count'],
                    'affected_site_count' => $sync['affected_site_count'],
                    'summary' => $sync['status'] && $sync['status'] !== IntegrationSyncLog::STATUS_SUCCESS
                        ? 'The latest sync needs review in the provider workspace.'
                        : null,
                ],
                'reconciliation' => [
                    'imported_devices' => $deviceCount,
                    'unassigned_devices' => $unassigned,
                    'duplicate_candidates' => $duplicateCandidates,
                    'unsupported_checks' => $unsupportedChecks,
                ],
                'monitoring_support' => [
                    'state' => 'not_assessed',
                    'scope' => 'provider',
                    'note' => 'Monitoring support is assessed from canonical provider capability evidence, not device metadata.',
                ],
                'exceptions' => $exceptions->values()->all(),
                'exception_count' => $exceptions->sum('count'),
            ]);

            if ($canManage) {
                $provider['credential'] = [
                    'configured' => $credentialConfigured,
                    'reference' => $secret?->secret_last4,
                    'reference_label' => $secret?->secret_last4 ? 'Credential ending '.$secret->secret_last4 : null,
                    'display_state' => $secret !== null
                        ? 'tenant_credential_configured'
                        : ($siteSecretTotal > 0 ? 'site_credentials_configured' : 'not_configured'),
                    'rotation_state' => $this->rotationState($secret, $siteSecretTotal),
                    'rotation_cadence_days' => self::ROTATION_CADENCE_DAYS,
                    'rotated_at' => $secret?->rotated_at?->toISOString(),
                    'created_at' => $secret?->created_at?->toISOString(),
                    'last_tested_at' => $secret?->last_tested_at?->toISOString(),
                    'site_credentials' => [
                        'total' => $siteSecretTotal,
                        'enabled' => $enabledSiteSecretCount,
                        'needs_attention' => $siteSecretExceptionCount,
                        'capabilities' => $siteSecretCapabilities->get($slug, []),
                    ],
                ];
            }

            return $provider;
        })->all();

        return [
            'providers' => $providers,
            'stats' => [
                'providers_total' => count(self::PROVIDERS),
                'providers_live' => collect(self::PROVIDERS)->where('implementation_status', 'live')->count(),
                'providers_connected' => collect($providers)->where('connected', true)->count(),
                'providers_errored' => collect($providers)->where('connection_status', IntegrationTenantSecret::STATUS_ERROR)->count(),
                'imported_devices' => collect($providers)->sum('device_count'),
                'events_24h' => collect($providers)->sum('events_24h'),
                'exceptions' => collect($providers)->sum('exception_count'),
            ],
            'can' => ['manage' => $canManage],
            'boundaries' => [
                'sync_stale_after_hours' => self::STALE_SYNC_HOURS,
                'credential_rotation_cadence_days' => self::ROTATION_CADENCE_DAYS,
                'alert_owner' => 'Control Room',
            ],
        ];
    }

    /** @return Collection<string, int> */
    private function duplicateCandidateCounts(Builder $devices): Collection
    {
        $identity = DB::connection()->getDriverName() === 'sqlite'
            ? "json_extract(external_ref, '$.provider_entity_id')"
            : "JSON_UNQUOTE(JSON_EXTRACT(external_ref, '$.provider_entity_id'))";
        $groups = $devices
            ->whereNotNull('external_ref')
            ->whereRaw("{$identity} IS NOT NULL")
            ->whereRaw("TRIM({$identity}) <> ''")
            ->select('provider')
            ->selectRaw("{$identity} as external_identity")
            ->groupBy('provider')
            ->groupByRaw($identity)
            ->havingRaw('COUNT(*) > 1');

        return DB::query()
            ->fromSub($groups, 'duplicate_groups')
            ->select('provider')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('provider')
            ->pluck('aggregate', 'provider')
            ->map(fn ($count): int => (int) $count);
    }

    /** @return array<string, mixed> */
    private function exception(string $type, string $summary, string $action, ?string $href, int $count = 1): array
    {
        return compact('type', 'summary', 'action', 'href', 'count');
    }

    /** @return Collection<string, array{status: ?string, at: ?Carbon, items_processed: int, items_errored: int, stale_site_count: int, affected_site_count: int, stale_scope_count: int}> */
    private function syncSummaries(int $tenantId, array $siteIds, bool $canViewAllTenantSites): Collection
    {
        $scoped = IntegrationSyncLog::query()
            ->forTenant($tenantId)
            ->when(
                $canViewAllTenantSites,
                fn ($query) => $query,
                fn ($query) => $siteIds === [] ? $query->whereRaw('1 = 0') : $query->whereIn('site_id', $siteIds),
            )
            ->select([
                'id', 'provider', 'site_id', 'status', 'items_processed', 'items_errored',
                'started_at', 'completed_at',
            ])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY provider, COALESCE(site_id, 0) ORDER BY started_at DESC, id DESC) as scope_rank');
        $cutoff = now()->subHours(self::STALE_SYNC_HOURS)->toDateTimeString();
        $rows = DB::query()
            ->fromSub($scoped, 'latest_syncs')
            ->where('scope_rank', 1)
            ->select('provider')
            ->selectRaw("MAX(CASE status WHEN 'failed' THEN 4 WHEN 'partial' THEN 3 WHEN 'started' THEN 2 WHEN 'success' THEN 1 ELSE 0 END) as worst_rank")
            ->selectRaw('MAX(COALESCE(completed_at, started_at)) as latest_at')
            ->selectRaw('COALESCE(SUM(items_processed), 0) as items_processed')
            ->selectRaw('COALESCE(SUM(items_errored), 0) as items_errored')
            ->selectRaw('COUNT(DISTINCT site_id) as affected_site_count')
            ->selectRaw('SUM(CASE WHEN site_id IS NOT NULL AND COALESCE(completed_at, started_at) < ? THEN 1 ELSE 0 END) as stale_site_count', [$cutoff])
            ->selectRaw('SUM(CASE WHEN COALESCE(completed_at, started_at) < ? THEN 1 ELSE 0 END) as stale_scope_count', [$cutoff])
            ->groupBy('provider')
            ->get();
        $statuses = [
            4 => IntegrationSyncLog::STATUS_FAILED,
            3 => IntegrationSyncLog::STATUS_PARTIAL,
            2 => IntegrationSyncLog::STATUS_STARTED,
            1 => IntegrationSyncLog::STATUS_SUCCESS,
        ];

        return $rows->mapWithKeys(fn ($row): array => [
            $row->provider => [
                'status' => $statuses[(int) $row->worst_rank] ?? null,
                'at' => filled($row->latest_at) ? Carbon::parse($row->latest_at) : null,
                'items_processed' => (int) $row->items_processed,
                'items_errored' => (int) $row->items_errored,
                'stale_site_count' => (int) $row->stale_site_count,
                'affected_site_count' => (int) $row->affected_site_count,
                'stale_scope_count' => (int) $row->stale_scope_count,
            ],
        ]);
    }

    /** @return array{status: null, at: null, items_processed: int, items_errored: int, stale_site_count: int, affected_site_count: int, stale_scope_count: int} */
    private function emptySyncSummary(): array
    {
        return [
            'status' => null,
            'at' => null,
            'items_processed' => 0,
            'items_errored' => 0,
            'stale_site_count' => 0,
            'affected_site_count' => 0,
            'stale_scope_count' => 0,
        ];
    }

    private function rotationState(?IntegrationTenantSecret $secret, int $siteCredentials = 0): string
    {
        if (! $secret) {
            return $siteCredentials > 0 ? 'unknown' : 'not_configured';
        }
        $reference = $secret->rotated_at ?? $secret->created_at;
        if (! $reference) {
            return 'unknown';
        }

        return $reference->lte(now()->subDays(self::ROTATION_CADENCE_DAYS)) ? 'rotation_due' : 'current';
    }
}
