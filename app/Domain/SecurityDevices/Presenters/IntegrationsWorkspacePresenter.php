<?php

namespace App\Domain\SecurityDevices\Presenters;

use App\Domain\Monitoring\Models\ProviderCapabilityCursor;
use App\Domain\Monitoring\Models\ProviderCapabilityException;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Integration\IntegrationSiteSecret;
use App\Models\Integration\IntegrationSyncLog;
use App\Models\User;
use App\Services\Integration\Contracts\DeviceSyncCapability;
use App\Services\Integration\Contracts\EventCollectionCapability;
use App\Services\Integration\Contracts\InventoryDiscoveryCapability;
use App\Services\Integration\Contracts\ObservationCollectionCapability;
use App\Services\Integration\Contracts\SnapshotCollectionCapability;
use App\Services\Integration\Contracts\TopologyCollectionCapability;
use App\Services\Integration\Contracts\WebhookVerificationCapability;
use App\Services\Integration\Data\IntegrationCapabilityManifest;
use App\Services\Integration\IntegrationAdapterRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IntegrationsWorkspacePresenter
{
    private const ROTATION_CADENCE_DAYS = 90;

    private const STALE_SYNC_HOURS = 24;

    private const HEALTH_COLLECTION_GRACE_MINUTES = 5;

    private const SITE_MAPPING_DISPLAY_LIMIT = 50;

    private const PROVIDERS = [
        ['slug' => 'unifi', 'name' => 'UniFi', 'vendor' => 'Ubiquiti', 'summary' => 'Network, CCTV, and access infrastructure.', 'device_scope' => ['cameras', 'doors', 'access points', 'switches', 'gateways']],
        ['slug' => 'queclink', 'name' => 'Queclink', 'vendor' => 'Queclink Wireless', 'summary' => 'Cellular GPS trackers for vehicles, assets, and personal safety.', 'device_scope' => ['vehicle trackers', 'personal trackers', 'asset trackers']],
        ['slug' => 'milesight', 'name' => 'Milesight', 'vendor' => 'Milesight IoT', 'summary' => 'OAuth inventory import for LoRaWAN gateways and environmental or support sensors.', 'device_scope' => ['bed sensors', 'fall sensors', 'door contacts', 'environment sensors', 'gateways']],
    ];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly IntegrationAdapterRegistry $adapters,
    ) {}

    /** @return array<string, mixed> */
    public function present(User $viewer): array
    {
        $viewer->loadMissing(['roles.permissions', 'permissionOverrides']);

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
        $connections = IntegrationProviderConnection::query()->get()->keyBy('provider');
        $configQuery = IntegrationSiteConfig::query()
            ->when($siteIds === [], fn ($query) => $query->whereRaw('1 = 0'))
            ->when($siteIds !== [], fn ($query) => $query->whereIn('site_id', $siteIds))
            ->whereHas('site', fn ($site) => $site
                ->where('is_active', true)
                ->where('archived', false)
                ->whereNull('archived_at'));
        $configStats = (clone $configQuery)
            ->select('provider')
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw("SUM(CASE WHEN is_active = 0 OR status = ? OR mapped_external_site_id IS NULL OR mapped_external_site_id = '' THEN 1 ELSE 0 END) as unmapped_count", [IntegrationSiteConfig::STATUS_DISCONNECTED])
            ->groupBy('provider')
            ->get()
            ->keyBy('provider');
        $healthScopeSiteIds = (clone $configQuery)
            ->active()
            ->whereNotNull('mapped_external_site_id')
            ->where('mapped_external_site_id', '<>', '')
            ->get(['provider', 'site_id'])
            ->groupBy('provider')
            ->map(fn (Collection $rows): Collection => $rows
                ->pluck('site_id')
                ->map(fn (mixed $siteId): int => (int) $siteId)
                ->unique()
                ->values());
        $configs = collect();
        if ($configStats->sum('total_count') > 0) {
            $rankedConfigs = (clone $configQuery)
                ->select('integration_site_configs.*')
                ->selectRaw('ROW_NUMBER() OVER (PARTITION BY provider ORDER BY site_id) AS provider_row');
            $configs = IntegrationSiteConfig::query()
                ->fromSub($rankedConfigs, 'integration_site_configs')
                ->join('sites', 'sites.id', '=', 'integration_site_configs.site_id')
                ->select(['integration_site_configs.*', 'sites.name as site_name'])
                ->where('provider_row', '<=', self::SITE_MAPPING_DISPLAY_LIMIT)
                ->orderBy('provider')
                ->orderBy('site_id')
                ->get()
                ->groupBy('provider');
        }
        $siteSecretQuery = IntegrationSiteSecret::query()
            ->when($siteIds === [], fn ($query) => $query->whereRaw('1 = 0'))
            ->when($siteIds !== [], fn ($query) => $query->whereIn('site_id', $siteIds))
            ->whereHas('site', fn ($site) => $site
                ->where('is_active', true)
                ->where('archived', false)
                ->whereNull('archived_at'));
        $siteSecretStats = (clone $siteSecretQuery)
            ->select('provider')
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw('SUM(CASE WHEN is_enabled = 1 THEN 1 ELSE 0 END) as enabled_count')
            ->selectRaw("SUM(CASE WHEN last_error IS NOT NULL AND last_error <> '' THEN 1 ELSE 0 END) as error_count")
            ->selectRaw("SUM(CASE WHEN (last_error IS NULL OR last_error = '') AND is_enabled = 1 AND last_tested_at IS NULL THEN 1 ELSE 0 END) as untested_count")
            ->selectRaw("SUM(CASE WHEN (last_error IS NULL OR last_error = '') AND is_enabled = 1 AND last_tested_at IS NOT NULL THEN 1 ELSE 0 END) as connected_count")
            ->selectRaw("SUM(CASE WHEN (last_error IS NULL OR last_error = '') AND is_enabled = 0 THEN 1 ELSE 0 END) as disabled_count")
            ->selectRaw('MAX(last_tested_at) as latest_tested_at')
            ->selectRaw('GROUP_CONCAT(DISTINCT CASE WHEN is_enabled = 1 AND capability IS NOT NULL THEN capability END) as enabled_capabilities')
            ->groupBy('provider')
            ->get()
            ->keyBy('provider');
        $siteSecretCapabilities = $siteSecretStats->map(fn ($row): array => collect(explode(',', (string) $row->enabled_capabilities))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all());
        $syncSummaries = $this->syncSummaries($siteIds, $this->access->canViewAllSites($viewer));
        $cursorQuery = ProviderCapabilityCursor::query()
            ->when(! $this->access->canViewAllSites($viewer), fn (Builder $query) => $siteIds === []
                ? $query->whereRaw('1 = 0')
                : $query->whereIn('site_id', $siteIds));
        $cursorRows = (clone $cursorQuery)
            ->select(['site_id', 'provider', 'capability'])
            ->selectRaw("'cursor' AS runtime_kind")
            ->addSelect(['retry_not_before', 'exception_count', 'last_started_at', 'last_completed_at', 'last_failed_at', 'last_partial_at'])
            ->selectRaw('NULL AS code')
            ->selectRaw('NULL AS occurred_at');
        $exceptionRows = ProviderCapabilityException::query()
            ->when(! $this->access->canViewAllSites($viewer), fn (Builder $query) => $siteIds === []
                ? $query->whereRaw('1 = 0')
                : $query->whereIn('site_id', $siteIds))
            ->select(['site_id', 'provider', 'capability'])
            ->selectRaw("'exception' AS runtime_kind")
            ->selectRaw('NULL AS retry_not_before')
            ->selectRaw('0 AS exception_count')
            ->selectRaw('NULL AS last_started_at')
            ->selectRaw('NULL AS last_completed_at')
            ->selectRaw('NULL AS last_failed_at')
            ->selectRaw('NULL AS last_partial_at')
            ->addSelect(['code', 'occurred_at']);
        $runtimeRows = $cursorRows
            ->unionAll($exceptionRows)
            ->get()
            ->groupBy('provider');

        $providers = collect(self::PROVIDERS)->map(function (array $catalog) use ($canManage, $canViewDevices, $configStats, $configs, $deviceCounts, $duplicateCounts, $eventsByProvider, $connections, $healthScopeSiteIds, $runtimeRows, $siteSecretCapabilities, $siteSecretStats, $syncSummaries, $unassignedCounts): array {
            $slug = $catalog['slug'];
            $connection = $connections->get($slug);
            $siteSecretStat = $siteSecretStats->get($slug);
            $siteSecretTotal = (int) ($siteSecretStat?->total_count ?? 0);
            $enabledSiteSecretCount = (int) ($siteSecretStat?->enabled_count ?? 0);
            $erroredSiteSecretCount = (int) ($siteSecretStat?->error_count ?? 0);
            $connectedSiteSecretCount = (int) ($siteSecretStat?->connected_count ?? 0);
            $untestedSiteSecretCount = (int) ($siteSecretStat?->untested_count ?? 0);
            $disabledSiteSecretCount = (int) ($siteSecretStat?->disabled_count ?? 0);
            $siteSecretExceptionCount = $erroredSiteSecretCount + $untestedSiteSecretCount;
            $providerConfigs = $configs->get($slug, collect())->values();
            $providerConfigStat = $configStats->get($slug);
            $configTotal = (int) ($providerConfigStat?->total_count ?? 0);
            $unmappedCount = (int) ($providerConfigStat?->unmapped_count ?? 0);
            $sync = $syncSummaries->get($slug, $this->emptySyncSummary());
            $deviceCount = (int) ($deviceCounts[$slug] ?? 0);
            $unassigned = (int) ($unassignedCounts[$slug] ?? 0);
            $duplicateCandidates = (int) ($duplicateCounts[$slug] ?? 0);
            $unsupportedChecks = 0;
            $manifest = $this->adapters->manifest($slug);
            $contract = $this->runtimeContract($slug, $manifest);
            $nativeRuntimeOnly = $contract['state'] === 'native_runtime_only';
            if ($nativeRuntimeOnly) {
                $providerConfigs = collect();
                $configTotal = 0;
                $unmappedCount = 0;
                $sync = $this->emptySyncSummary();
            }
            $credentialConfigured = ! $nativeRuntimeOnly && ($connection !== null || $siteSecretTotal > 0);
            $connectionStatus = $nativeRuntimeOnly ? 'unavailable' : match (true) {
                $erroredSiteSecretCount > 0 => IntegrationProviderConnection::STATUS_ERROR,
                $connection?->requires_credential_replacement => IntegrationProviderConnection::STATUS_DISABLED,
                $connection !== null => $connection->status,
                $connectedSiteSecretCount > 0 => IntegrationProviderConnection::STATUS_CONNECTED,
                $untestedSiteSecretCount > 0 => IntegrationSiteCredentialsPresenter::STATE_UNTESTED,
                $disabledSiteSecretCount > 0 => IntegrationSiteCredentialsPresenter::STATE_DISABLED,
                default => 'not_configured',
            };
            $runtimeCapabilities = collect($manifest->capabilities)
                ->map(fn (string $capability): string => Str::of(class_basename($capability))
                    ->beforeLast('Capability')
                    ->snake()
                    ->toString())
                ->values();
            $providerRuntimeRows = $runtimeRows->get($slug, collect());
            $providerCursors = $providerRuntimeRows->where('runtime_kind', 'cursor');
            $providerRuntimeExceptions = $providerRuntimeRows->where('runtime_kind', 'exception');
            $providerHealthSiteIds = $healthScopeSiteIds->get($slug, collect());
            $health = $this->providerHealthSummary(
                $runtimeCapabilities->contains('observation_collection'),
                $providerHealthSiteIds,
                $providerCursors
                    ->where('capability', ObservationCollectionCapability::class)
                    ->filter(fn (ProviderCapabilityCursor $cursor): bool => $providerHealthSiteIds
                        ->containsStrict((int) $cursor->site_id)),
                $providerRuntimeExceptions
                    ->where('capability', ObservationCollectionCapability::class)
                    ->filter(fn (ProviderCapabilityCursor $exception): bool => $providerHealthSiteIds
                        ->containsStrict((int) $exception->site_id)),
                $canManage ? "/security-devices/integrations/{$slug}" : null,
            );
            $syncAt = $nativeRuntimeOnly ? null : ($sync['at'] ?? $connection?->last_synced_at);
            $syncFreshness = $syncAt === null
                ? 'never'
                : (($sync['stale_scope_count'] ?? 0) > 0 || $syncAt->lt(now()->subHours(self::STALE_SYNC_HOURS)) ? 'stale' : 'current');

            $exceptions = collect();
            if (! $credentialConfigured && ! $nativeRuntimeOnly) {
                $exceptions->push($this->exception('missing_credentials', 'Credentials are not configured.', 'Add credentials', $canManage ? "/security-devices/integrations/{$slug}" : null));
            }
            if ($nativeRuntimeOnly && $connection !== null) {
                $exceptions->push($this->exception('legacy_cloud_credential', 'A legacy Queclink cloud credential is stored but is not used or considered connected.', 'Remove legacy credential', $canManage ? '/security-devices/integrations/queclink?tab=ims' : null));
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
            if (! $nativeRuntimeOnly && in_array($sync['status'], [IntegrationSyncLog::STATUS_FAILED, IntegrationSyncLog::STATUS_PARTIAL], true)) {
                $exceptions->push($this->exception('integration_error', 'The latest provider sync did not complete successfully.', 'Review sync diagnostics', $canManage ? "/security-devices/integrations/{$slug}" : null, max(1, $sync['items_errored'])));
            } elseif (! $nativeRuntimeOnly && $connection?->status === IntegrationProviderConnection::STATUS_ERROR) {
                $exceptions->push($this->exception('integration_error', 'The provider connection needs attention.', 'Test the connection', $canManage ? "/security-devices/integrations/{$slug}" : null));
            }
            if (! $nativeRuntimeOnly && $connectionStatus === IntegrationProviderConnection::STATUS_DISABLED) {
                $exceptions->push($this->exception('connection_disabled', 'Provider collection and webhook intake are deliberately disabled until a replacement credential is tested.', 'Replace and test credentials', $canManage ? "/security-devices/integrations/{$slug}" : null));
            }
            if (! $nativeRuntimeOnly && $syncFreshness === 'stale') {
                $exceptions->push($this->exception('stale_sync', 'One or more latest sync scopes are more than 24 hours old.', 'Review sync schedule', $canManage ? "/security-devices/integrations/{$slug}" : null, max(1, (int) ($sync['stale_site_count'] ?? 0))));
            }
            if (in_array($health['state'], ['not_run', 'stale', 'partial', 'failed'], true)) {
                $exceptions->push($this->exception(
                    'health_collection_'.$health['state'],
                    $health['summary'],
                    $health['action'],
                    $health['href'],
                ));
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
                'connected' => $connectionStatus === IntegrationProviderConnection::STATUS_CONNECTED,
                'last_tested_at' => $nativeRuntimeOnly
                    ? null
                    : ($connection?->last_tested_at?->toISOString()
                        ?? (filled($siteSecretStat?->latest_tested_at) ? Carbon::parse($siteSecretStat->latest_tested_at)->toISOString() : null)),
                'last_synced_at' => $syncAt?->toISOString(),
                'device_count' => $deviceCount,
                'events_24h' => (int) ($eventsByProvider[$slug] ?? 0),
                'site_mapping' => [
                    'total' => $configTotal,
                    'mapped' => $configTotal - $unmappedCount,
                    'unmapped' => $unmappedCount,
                    'sites' => $providerConfigs->map(fn (IntegrationSiteConfig $config): array => [
                        'id' => $config->site_id,
                        'name' => $config->site_name,
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
                    'state' => $runtimeCapabilities->intersect(['observation_collection', 'event_collection', 'webhook_verification', 'topology_collection', 'snapshot_collection'])->isNotEmpty()
                        ? 'supported'
                        : 'capability_absent',
                    'scope' => 'provider',
                    'note' => 'Monitoring support is taken from the typed provider manifest. An absent capability is not treated as healthy or silently emulated.',
                ],
                'health' => $health,
                'runtime' => [
                    'version' => $manifest->version,
                    'contract_state' => $contract['state'],
                    'contract_label' => $contract['label'],
                    'contract_note' => $contract['note'],
                    'capabilities' => $runtimeCapabilities,
                    'page_limit' => $manifest->pageLimit,
                    'minimum_interval_seconds' => $manifest->minimumIntervalSeconds,
                    'backfill_limit' => $manifest->backfillLimit,
                    'cursor_scopes' => $providerCursors->count(),
                    'partial_scopes' => $providerCursors->whereNotNull('retry_not_before')->count(),
                    'exception_count' => max((int) $providerCursors->sum('exception_count'), $providerRuntimeExceptions->count()),
                    'latest_completed_at' => $providerCursors->max('last_completed_at')?->toIso8601String(),
                    'latest_exception_at' => filled($providerRuntimeExceptions->max('occurred_at'))
                        ? Carbon::parse($providerRuntimeExceptions->max('occurred_at'))->toIso8601String()
                        : null,
                    'exception_codes' => $providerRuntimeExceptions->pluck('code')->filter()->unique()->sort()->values(),
                    'disconnect_ready' => $canManage && $credentialConfigured && ! $nativeRuntimeOnly
                        && $connectionStatus !== IntegrationProviderConnection::STATUS_DISABLED,
                    'revoke_ready' => $canManage && $credentialConfigured && ! $nativeRuntimeOnly
                        && $connectionStatus !== IntegrationProviderConnection::STATUS_DISABLED,
                ],
                'exceptions' => $exceptions->values()->all(),
                'exception_count' => $exceptions->sum('count'),
            ]);

            if ($canManage) {
                $provider['credential'] = [
                    'configured' => $credentialConfigured,
                    'reference' => $nativeRuntimeOnly ? null : $connection?->secret_last4,
                    'reference_label' => ! $nativeRuntimeOnly && $connection?->secret_last4 ? 'Credential ending '.$connection->secret_last4 : null,
                    'display_state' => ! $nativeRuntimeOnly && $connection !== null
                        ? 'provider_connection_configured'
                        : ($siteSecretTotal > 0 ? 'site_credentials_configured' : 'not_configured'),
                    'rotation_state' => $nativeRuntimeOnly ? 'not_configured' : $this->rotationState($connection, $siteSecretTotal),
                    'rotation_cadence_days' => self::ROTATION_CADENCE_DAYS,
                    'rotated_at' => $connection?->rotated_at?->toISOString(),
                    'created_at' => $connection?->created_at?->toISOString(),
                    'last_tested_at' => $connection?->last_tested_at?->toISOString(),
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
                'providers_connected' => collect($providers)->where('connected', true)->count(),
                'providers_errored' => collect($providers)->where('connection_status', IntegrationProviderConnection::STATUS_ERROR)->count(),
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

    /** @return array{state: string, label: string, note: string} */
    private function runtimeContract(string $provider, IntegrationCapabilityManifest $manifest): array
    {
        if ($manifest->capabilities === []) {
            return [
                'state' => 'native_runtime_only',
                'label' => 'Native operations only',
                'note' => $provider === 'queclink'
                    ? 'No verified Queclink cloud API contract is enabled. Direct TCP intake, canonical tracking, and governed Device Management remain available through their separate native contracts.'
                    : 'No provider cloud capability is declared. Only separately registered native runtime paths are available.',
            ];
        }

        if ($manifest->supports(TopologyCollectionCapability::class)) {
            $collectsEvents = $manifest->supports(EventCollectionCapability::class);
            $collectsObservations = $manifest->supports(ObservationCollectionCapability::class);
            $collectsSnapshots = $manifest->supports(SnapshotCollectionCapability::class);

            return [
                'state' => match (true) {
                    $collectsObservations && $collectsSnapshots => 'monitoring_topology_snapshot_collection',
                    $collectsObservations => 'monitoring_topology_collection',
                    $collectsSnapshots => 'topology_snapshot_collection',
                    default => 'topology_collection',
                },
                'label' => match (true) {
                    $collectsObservations && $collectsEvents && $collectsSnapshots => 'Monitoring, inventory, sync, topology, configuration and events',
                    $collectsObservations && $collectsSnapshots => 'Monitoring, inventory, sync, topology and configuration',
                    $collectsEvents && $collectsSnapshots => 'Inventory, sync, topology, configuration and events',
                    $collectsSnapshots => 'Inventory, sync, topology and configuration',
                    $collectsObservations && $collectsEvents => 'Monitoring, inventory, sync, topology and events',
                    $collectsObservations => 'Monitoring, inventory, sync and topology',
                    $collectsEvents => 'Inventory, sync, topology and events',
                    default => 'Inventory, sync and topology',
                },
                'note' => match (true) {
                    $collectsObservations && $collectsEvents && $collectsSnapshots => 'Authenticated provider monitoring, canonical Device synchronization, typed topology, governed read-only configuration snapshots, and typed event collection are implemented. Only declared runtime capabilities are used.',
                    $collectsObservations && $collectsSnapshots => 'Authenticated provider monitoring, canonical Device synchronization, typed topology, and governed read-only configuration snapshots are implemented. Only declared runtime capabilities are used.',
                    $collectsEvents && $collectsSnapshots => 'Authenticated inventory, canonical Device synchronization, typed topology, governed read-only configuration snapshots, and typed event collection are implemented. Only declared runtime capabilities are used.',
                    $collectsSnapshots => 'Authenticated inventory, canonical Device synchronization, typed topology, and governed read-only configuration snapshots are implemented. Only declared runtime capabilities are used.',
                    $collectsObservations && $collectsEvents => 'Authenticated provider monitoring, canonical Device synchronization, typed topology, and typed event collection are implemented. Only declared runtime capabilities are used.',
                    $collectsObservations => 'Authenticated provider monitoring, canonical Device synchronization, and typed topology collection are implemented. Only declared runtime capabilities are used.',
                    $collectsEvents => 'Authenticated inventory, canonical Device synchronization, typed topology, and typed event collection are implemented. Only declared runtime capabilities are used.',
                    default => 'Authenticated inventory, canonical Device synchronization, and typed topology collection are implemented. Only declared runtime capabilities are used.',
                },
            ];
        }

        if ($manifest->supports(InventoryDiscoveryCapability::class)
            && $manifest->supports(DeviceSyncCapability::class)) {
            $collectsObservations = $manifest->supports(ObservationCollectionCapability::class);
            if ($manifest->supports(WebhookVerificationCapability::class)) {
                return [
                    'state' => $collectsObservations
                        ? 'monitoring_inventory_sync_webhook'
                        : 'inventory_sync_webhook',
                    'label' => $collectsObservations
                        ? 'Monitoring, inventory, sync and signed events'
                        : 'Inventory, sync and signed events',
                    'note' => $collectsObservations
                        ? 'Authenticated provider status monitoring, canonical Device synchronization, and replay-protected signed webhook events are implemented. Topology is not inferred when its contract is absent.'
                        : 'Authenticated provider inventory, canonical Device synchronization, and replay-protected signed webhook events are implemented. Polling or topology is not inferred when its contract is absent.',
                ];
            }

            if ($collectsObservations) {
                return [
                    'state' => 'monitoring_inventory_sync',
                    'label' => 'Monitoring, inventory and sync',
                    'note' => 'Authenticated provider status monitoring and canonical Device synchronization are implemented. Topology and events are not inferred when their contracts are absent.',
                ];
            }

            return [
                'state' => 'inventory_sync',
                'label' => 'Inventory and sync',
                'note' => 'Authenticated provider inventory and canonical Device synchronization are implemented. Topology and event collection are not inferred when their contracts are absent.',
            ];
        }

        return [
            'state' => 'connection_health_only',
            'label' => 'Connection check only',
            'note' => $provider === 'queclink'
                ? 'The cloud adapter can test authentication only. Cloud inventory, sync, and event collection remain unavailable; native TCP monitoring and governed Device Management continue separately.'
                : 'The provider adapter can test authentication only. Inventory, synchronization, topology, and event collection remain unavailable until their typed contracts are implemented.',
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

    /**
     * @param  Collection<int, int>  $siteIds
     * @param  Collection<int, ProviderCapabilityCursor>  $cursors
     * @param  Collection<int, ProviderCapabilityCursor>  $exceptions
     * @return array{state: string, freshness: string, last_attempted_at: ?string, last_collected_at: ?string, summary: string, action: string, href: ?string}
     */
    private function providerHealthSummary(
        bool $supported,
        Collection $siteIds,
        Collection $cursors,
        Collection $exceptions,
        ?string $href,
    ): array {
        if (! $supported) {
            return [
                'state' => 'unsupported',
                'freshness' => 'unsupported',
                'last_attempted_at' => null,
                'last_collected_at' => null,
                'summary' => 'This provider does not declare a typed health observation capability.',
                'action' => 'Review provider support',
                'href' => $href,
            ];
        }

        $siteIds = $siteIds
            ->map(fn (mixed $siteId): int => (int) $siteId)
            ->unique()
            ->values();
        $cursorsBySite = $cursors->keyBy(fn (ProviderCapabilityCursor $cursor): int => (int) $cursor->site_id);
        $latestExceptionBySite = $exceptions
            ->groupBy(fn (ProviderCapabilityCursor $exception): int => (int) $exception->site_id)
            ->map(fn (Collection $rows): ?string => $rows->max('occurred_at'));
        $staleBefore = now()->subHours(self::STALE_SYNC_HOURS);
        $abandonedBefore = now()->subMinutes(self::HEALTH_COLLECTION_GRACE_MINUTES);
        $scopeStates = $siteIds->map(function (int $siteId) use ($abandonedBefore, $cursorsBySite, $latestExceptionBySite, $staleBefore): string {
            $cursor = $cursorsBySite->get($siteId);
            if (! $cursor) {
                return 'not_run';
            }

            $started = $cursor->last_started_at;
            $completed = $cursor->last_completed_at;
            $failed = $cursor->last_failed_at;
            $partial = $cursor->last_partial_at;
            $latestException = filled($latestExceptionBySite->get($siteId))
                ? Carbon::parse($latestExceptionBySite->get($siteId))
                : null;
            $attemptOpen = $started !== null
                && ($completed === null || $started->gt($completed));

            return match (true) {
                $failed !== null && ($completed === null || $failed->gte($completed)) => 'failed',
                $attemptOpen && $started->lt($abandonedBefore) => 'failed',
                $attemptOpen => 'collecting',
                $partial !== null && ($completed === null || $partial->gte($completed)) => 'partial',
                $cursor->retry_not_before !== null => 'partial',
                $latestException !== null && $completed !== null && $latestException->gte($completed) => 'partial',
                $completed === null => 'not_run',
                $completed->lt($staleBefore) => 'stale',
                default => 'current',
            };
        });
        $state = collect(['failed', 'partial', 'not_run', 'stale', 'collecting', 'current'])
            ->first(fn (string $candidate): bool => $scopeStates->containsStrict($candidate))
            ?? 'not_run';
        $completedScopes = $siteIds->map(fn (int $siteId) => $cursorsBySite->get($siteId)?->last_completed_at);
        $freshness = match (true) {
            $siteIds->isEmpty() || $completedScopes->containsStrict(null) => 'never',
            $completedScopes->contains(fn ($completed): bool => $completed->lt($staleBefore)) => 'stale',
            default => 'current',
        };
        $latestStarted = $cursors->max('last_started_at');
        $latestCompleted = $cursors->max('last_completed_at');
        [$summary, $action] = match ($state) {
            'not_run' => ['Health collection has not completed for every mapped Site.', 'Review integration schedule'],
            'failed' => ['A mapped Site health collection failed or stopped before completion.', 'Review health diagnostics'],
            'collecting' => ['Health collection is currently in progress.', 'Review health diagnostics'],
            'partial' => ['A mapped Site health collection completed with provider or item exceptions.', 'Review health diagnostics'],
            'stale' => ['At least one mapped Site has health evidence more than 24 hours old.', 'Review sync schedule'],
            default => ['Health collection completed successfully for every mapped Site, including valid zero-result collections.', 'No action required'],
        };

        return [
            'state' => $state,
            'freshness' => $freshness,
            'last_attempted_at' => $latestStarted?->toISOString(),
            'last_collected_at' => $latestCompleted?->toISOString(),
            'summary' => $summary,
            'action' => $action,
            'href' => $state === 'current' ? null : $href,
        ];
    }

    /** @return Collection<string, array{status: ?string, at: ?Carbon, items_processed: int, items_errored: int, stale_site_count: int, affected_site_count: int, stale_scope_count: int}> */
    private function syncSummaries(array $siteIds, bool $canViewAllSites): Collection
    {
        $scoped = IntegrationSyncLog::query()
            ->when(
                $canViewAllSites,
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

    private function rotationState(?IntegrationProviderConnection $connection, int $siteCredentials = 0): string
    {
        if (! $connection) {
            return $siteCredentials > 0 ? 'unknown' : 'not_configured';
        }
        $reference = $connection->rotated_at ?? $connection->created_at;
        if (! $reference) {
            return 'unknown';
        }

        return $reference->lte(now()->subDays(self::ROTATION_CADENCE_DAYS)) ? 'rotation_due' : 'current';
    }
}
