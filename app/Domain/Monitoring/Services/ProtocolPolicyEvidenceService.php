<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorDependency;
use App\Domain\Monitoring\Models\MonitoringCoverageExpectation;
use App\Domain\Monitoring\Models\MonitoringInbox;
use App\Domain\Monitoring\Models\MonitoringMaintenanceWindow;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\Monitoring\Models\ProviderCapabilityCursor;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Site;
use App\Services\Integration\Contracts\ObservationCollectionCapability;
use App\Services\Integration\IntegrationAdapterRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

final class ProtocolPolicyEvidenceService
{
    public function __construct(
        private readonly IntegrationAdapterRegistry $providers,
        private readonly CoverageAnalyzer $coverage,
        private readonly MonitoringRollupService $rollups,
    ) {}

    /** @return array<string, mixed> */
    public function report(int $windowMinutes): array
    {
        $now = CarbonImmutable::now('UTC');
        $since = $now->subMinutes($windowMinutes);
        $monitors = Monitor::query()
            ->with('profile')
            ->where('is_enabled', true)
            ->whereHas('profile', fn ($query) => $query->where('is_active', true))
            ->get();
        $latestObservations = MonitorObservation::query()
            ->whereIn('monitor_id', $monitors->pluck('id'))
            ->where('observed_at', '<=', $now)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('monitor_observations as newer_observations')
                    ->whereColumn('newer_observations.monitor_id', 'monitor_observations.monitor_id')
                    ->where(function ($query): void {
                        $query->whereColumn('newer_observations.observed_at', '>', 'monitor_observations.observed_at')
                            ->orWhere(function ($query): void {
                                $query->whereColumn('newer_observations.observed_at', 'monitor_observations.observed_at')
                                    ->whereColumn('newer_observations.id', '>', 'monitor_observations.id');
                            });
                    });
            })
            ->get(['id', 'monitor_id', 'state', 'observed_at']);
        $substantiveStates = [
            MonitorState::Healthy,
            MonitorState::Degraded,
            MonitorState::Failed,
        ];
        $freshMonitorIds = $latestObservations
            ->filter(fn (MonitorObservation $observation): bool => $observation->observed_at->betweenIncluded($since, $now)
                && in_array($observation->state, $substantiveStates, true))
            ->pluck('monitor_id')
            ->mapWithKeys(fn (mixed $id): array => [(int) $id => true]);

        $protocols = $this->protocolEvidence($monitors, $freshMonitorIds, $since, $now);
        $policy = $this->policyEvidence($monitors, $since, $now);
        $allVerified = collect($protocols)
            ->merge($policy)
            ->every(fn (array $row): bool => ($row['state'] ?? null) === 'verified');

        return [
            'observed_at' => $now->toIso8601String(),
            'window_minutes' => $windowMinutes,
            'execution_cursor' => $this->executionCursor($monitors, $latestObservations),
            'all_verified' => $allVerified,
            'protocols' => $protocols,
            'policy' => $policy,
        ];
    }

    /**
     * @param  Collection<int, Monitor>  $monitors
     * @param  Collection<int, bool>  $freshMonitorIds
     * @return array<string, array<string, int|string>>
     */
    private function protocolEvidence(
        Collection $monitors,
        Collection $freshMonitorIds,
        CarbonImmutable $since,
        CarbonImmutable $now,
    ): array {
        $definitions = [
            'icmp' => fn (Monitor $monitor): bool => $monitor->kind === MonitorKind::Icmp,
            'tcp' => fn (Monitor $monitor): bool => $monitor->kind === MonitorKind::Tcp,
            'dns' => fn (Monitor $monitor): bool => $monitor->kind === MonitorKind::Dns,
            'http' => fn (Monitor $monitor): bool => $monitor->kind === MonitorKind::Http
                && $this->httpScheme($monitor) === 'http',
            'https' => fn (Monitor $monitor): bool => $monitor->kind === MonitorKind::Http
                && $this->httpScheme($monitor) === 'https',
            'tls' => fn (Monitor $monitor): bool => $monitor->kind === MonitorKind::Tls,
            'snmp_v3' => fn (Monitor $monitor): bool => $monitor->kind === MonitorKind::Snmp
                && strtolower((string) data_get($monitor->config, 'version')) === 'v3',
            'ssh_read_only' => fn (Monitor $monitor): bool => $monitor->kind === MonitorKind::SshInventory,
            'winrm_read_only' => fn (Monitor $monitor): bool => $monitor->kind === MonitorKind::WinRmInventory,
        ];
        $rows = [];
        foreach ($definitions as $key => $matches) {
            $configured = $monitors->filter($matches);
            $fresh = $configured->filter(fn (Monitor $monitor): bool => $freshMonitorIds->has((int) $monitor->id));
            $rows[$key] = $this->row($fresh->isNotEmpty(), [
                'configured' => $configured->count(),
                'fresh' => $fresh->count(),
            ]);
        }

        foreach ([
            'snmp_traps' => 'central:snmp-traps:site:%',
            'syslog' => 'central:syslog:site:%',
            'flow' => 'central:flow:site:%',
        ] as $key => $source) {
            $processed = MonitoringInbox::query()
                ->where('source', 'like', $source)
                ->whereBetween('processed_at', [$since, $now])
                ->count();
            $rows[$key] = $this->row($processed > 0, ['processed' => $processed]);
        }

        foreach ($this->providers->providers() as $provider) {
            if (! $this->providers->hasCapability($provider, ObservationCollectionCapability::class)) {
                continue;
            }
            $configured = $monitors->filter(fn (Monitor $monitor): bool => $monitor->kind === MonitorKind::Provider
                && data_get($monitor->config, 'provider') === $provider);
            $fresh = $configured->filter(fn (Monitor $monitor): bool => $freshMonitorIds->has((int) $monitor->id));
            $mappedSiteIds = IntegrationSiteConfig::query()
                ->forProvider($provider)
                ->active()
                ->whereHas('site')
                ->pluck('site_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values();
            $freshSiteIds = $fresh
                ->map(function (Monitor $monitor): ?int {
                    try {
                        return app(CanonicalDeviceSiteResolver::class)->resolve((int) $monitor->device_id);
                    } catch (Throwable) {
                        return null;
                    }
                })
                ->filter(fn (mixed $id): bool => is_int($id) && $id > 0)
                ->unique()
                ->values();
            $freshMappedSites = $mappedSiteIds->intersect($freshSiteIds)->count();
            $completedSites = ProviderCapabilityCursor::query()
                ->where('provider', $provider)
                ->where('capability', ObservationCollectionCapability::class)
                ->whereIn('site_id', $mappedSiteIds)
                ->whereBetween('last_completed_at', [$since, $now])
                ->distinct()
                ->count('site_id');
            $connected = IntegrationProviderConnection::query()
                ->forProvider($provider)
                ->connected()
                ->exists();
            $rows['provider_'.$provider] = $this->row(
                $connected
                    && $mappedSiteIds->isNotEmpty()
                    && $completedSites === $mappedSiteIds->count()
                    && $freshMappedSites === $mappedSiteIds->count(),
                [
                    'connected' => $connected ? 1 : 0,
                    'mapped_sites' => $mappedSiteIds->count(),
                    'completed_sites' => $completedSites,
                    'configured' => $configured->count(),
                    'fresh' => $fresh->count(),
                    'fresh_sites' => $freshMappedSites,
                ],
            );
        }

        ksort($rows);

        return $rows;
    }

    /**
     * @param  Collection<int, Monitor>  $monitors
     * @return array<string, array<string, int|string>>
     */
    private function policyEvidence(
        Collection $monitors,
        CarbonImmutable $since,
        CarbonImmutable $now,
    ): array {
        $profiles = $monitors->pluck('profile')->filter()->unique('id')->values();
        $invalidProfiles = $profiles->filter(fn (MonitoringProfile $profile): bool => (int) $profile->failure_confirmations < 1
            || (int) $profile->recovery_confirmations < 1
            || (int) $profile->stale_after_seconds < (int) $profile->interval_seconds
            || $profile->rollup_policy !== 'worst_applicable');

        $coverageCounts = [
            'covered' => 0,
            'unsupported' => 0,
            'missing' => 0,
            'paused' => 0,
            'collection_failed' => 0,
            'not_configured' => 0,
            'scope_invalid' => 0,
        ];
        $devices = Device::query()
            ->whereIn('status', [
                DeviceStatus::Active->value,
                DeviceStatus::Degraded->value,
                DeviceStatus::Offline->value,
            ])
            ->get();
        $applicableDevices = 0;
        foreach ($devices as $device) {
            try {
                $results = $this->coverage->analyze($device);
                if ($results->isEmpty()) {
                    continue;
                }
                $applicableDevices++;
                foreach ($results as $result) {
                    if (array_key_exists($result->status, $coverageCounts)) {
                        $coverageCounts[$result->status]++;
                    } else {
                        $coverageCounts['scope_invalid']++;
                    }
                }
            } catch (Throwable) {
                $coverageCounts['scope_invalid']++;
            }
        }
        $coverageGaps = $coverageCounts['missing']
            + $coverageCounts['paused']
            + $coverageCounts['collection_failed']
            + $coverageCounts['not_configured']
            + $coverageCounts['scope_invalid'];

        $dependencyMonitorIds = MonitorDependency::query()
            ->where('is_active', true)
            ->pluck('downstream_monitor_id')
            ->mapWithKeys(fn (mixed $id): array => [(int) $id => true]);
        $suppressedWithinWindow = fn (Monitor $monitor): bool => $monitor->suppressed_at !== null
            && $monitor->suppressed_at->betweenIncluded($since, $now);
        $dependencySuppressions = $monitors
            ->filter(fn (Monitor $monitor): bool => $dependencyMonitorIds->has((int) $monitor->id))
            ->where('effective_state', MonitorState::Suppressed)
            ->where('suppression_reason', 'dependency')
            ->filter($suppressedWithinWindow)
            ->count();
        $recentMaintenanceWindows = MonitoringMaintenanceWindow::query()
            ->whereIn('status', ['active', 'completed'])
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $since)
            ->get();
        $maintenanceSuppressions = $monitors
            ->where('effective_state', MonitorState::Suppressed)
            ->where('suppression_reason', 'maintenance')
            ->filter($suppressedWithinWindow)
            ->filter(function (Monitor $monitor) use ($recentMaintenanceWindows): bool {
                try {
                    $siteId = app(CanonicalDeviceSiteResolver::class)->resolve((int) $monitor->device_id);
                } catch (Throwable) {
                    return false;
                }

                return $recentMaintenanceWindows->contains(
                    fn (MonitoringMaintenanceWindow $window): bool => (int) $window->site_id === $siteId
                        && ($window->monitor_id === null || (int) $window->monitor_id === (int) $monitor->id)
                        && ($window->device_id === null || (int) $window->device_id === (int) $monitor->device_id),
                );
            })
            ->count();

        $transitionEvents = DeviceEvent::query()
            ->where('source', 'oblivion_monitoring')
            ->whereIn('event_type', ['offline', 'online'])
            ->whereBetween('occurred_at', [$since, $now])
            ->get(['event_type', 'payload', 'occurred_at']);
        $confirmationMonitorIds = $monitors->filter(fn (Monitor $monitor): bool => (int) $monitor->profile->failure_confirmations > 1
            || (int) $monitor->profile->failure_duration_seconds > 0
            || (int) $monitor->profile->recovery_confirmations > 1
            || (int) $monitor->profile->recovery_duration_seconds > 0)
            ->pluck('id')
            ->mapWithKeys(fn (mixed $id): array => [(int) $id => true]);
        [$confirmedFailures, $confirmedRecoveries] = $this->transitionEvidence(
            $transitionEvents,
            $confirmationMonitorIds,
        );

        $hysteresisProfiles = $profiles->filter(fn (MonitoringProfile $profile): bool => $profile->rising_threshold !== null
            && $profile->falling_threshold !== null
            && (float) $profile->rising_threshold > (float) $profile->falling_threshold);
        $hysteresisExercises = $this->hysteresisExercises($monitors, $transitionEvents, $since, $now);

        $staleMonitors = $monitors->filter(function (Monitor $monitor) use ($now): bool {
            if ($monitor->last_observation_at === null || $monitor->current_state->isFailure()) {
                return false;
            }

            return $monitor->last_observation_at->lt($now->subSeconds((int) $monitor->profile->stale_after_seconds));
        })->count();
        $unknownObservations = MonitorObservation::query()
            ->whereIn('monitor_id', $monitors->pluck('id'))
            ->where('state', MonitorState::Unknown->value)
            ->whereBetween('observed_at', [$since, $now])
            ->count();

        [$baselineProfiles, $baselineExercises] = $this->baselineEvidence($monitors, $since, $now);
        [$deviceRollups, $siteRollups, $rollupFailures] = $this->rollupEvidence($devices, $now);

        return [
            'profiles' => $this->row($profiles->isNotEmpty() && $invalidProfiles->isEmpty(), [
                'active_assigned' => $profiles->count(),
                'invalid' => $invalidProfiles->count(),
            ]),
            'coverage' => $this->row(
                MonitoringCoverageExpectation::query()->where('is_active', true)->exists()
                    && $applicableDevices > 0
                    && $coverageCounts['covered'] > 0
                    && $coverageGaps === 0,
                [...$coverageCounts, 'applicable_devices' => $applicableDevices, 'gaps' => $coverageGaps],
            ),
            'dependencies' => $this->row($dependencyMonitorIds->isNotEmpty() && $dependencySuppressions > 0, [
                'active' => $dependencyMonitorIds->count(),
                'observed_suppressions' => $dependencySuppressions,
            ]),
            'maintenance' => $this->row($recentMaintenanceWindows->isNotEmpty() && $maintenanceSuppressions > 0, [
                'recent_windows' => $recentMaintenanceWindows->count(),
                'observed_suppressions' => $maintenanceSuppressions,
            ]),
            'confirmation' => $this->row($confirmedFailures > 0 && $confirmedRecoveries > 0, [
                'qualifying_monitors' => $confirmationMonitorIds->count(),
                'confirmed_failures' => $confirmedFailures,
                'confirmed_recoveries' => $confirmedRecoveries,
            ]),
            'hysteresis' => $this->row($hysteresisProfiles->isNotEmpty() && $hysteresisExercises > 0, [
                'configured_profiles' => $hysteresisProfiles->count(),
                'observed_exercises' => $hysteresisExercises,
            ]),
            'stale_unknown' => $this->row($staleMonitors > 0 && $unknownObservations > 0, [
                'derived_stale' => $staleMonitors,
                'unknown_observations' => $unknownObservations,
            ]),
            'baselines' => $this->row($baselineProfiles > 0 && $baselineExercises > 0, [
                'configured_profiles' => $baselineProfiles,
                'observed_exercises' => $baselineExercises,
            ]),
            'rollups' => $this->row($deviceRollups > 0 && $siteRollups > 0 && $rollupFailures === 0, [
                'device_rollups' => $deviceRollups,
                'site_rollups' => $siteRollups,
                'estate_rollups' => $rollupFailures === 0 ? 1 : 0,
                'failures' => $rollupFailures,
            ]),
        ];
    }

    private function httpScheme(Monitor $monitor): string
    {
        $url = data_get($monitor->config, 'url', $monitor->target);

        return is_string($url) ? strtolower((string) parse_url($url, PHP_URL_SCHEME)) : '';
    }

    /**
     * Build one opaque generation from persisted execution evidence only. The
     * report timestamp is deliberately excluded so rerunning the command over
     * unchanged observations, listener deliveries, provider cursors, and
     * suppressions cannot look like advancing supervised execution.
     *
     * @param  Collection<int, Monitor>  $monitors
     * @param  Collection<int, MonitorObservation>  $latestObservations
     */
    private function executionCursor(Collection $monitors, Collection $latestObservations): string
    {
        $components = $latestObservations->map(fn (MonitorObservation $observation): array => [
            'kind' => 'observation',
            'monitor' => (int) $observation->monitor_id,
            'record' => (int) $observation->id,
            'at' => $observation->observed_at->toISOString(),
        ])->all();

        foreach ([
            'snmp_traps' => 'central:snmp-traps:site:%',
            'syslog' => 'central:syslog:site:%',
            'flow' => 'central:flow:site:%',
        ] as $kind => $source) {
            $latest = MonitoringInbox::query()
                ->where('source', 'like', $source)
                ->whereNotNull('processed_at')
                ->orderByDesc('processed_at')
                ->orderByDesc('id')
                ->first(['id', 'processed_at']);
            if ($latest !== null) {
                $components[] = [
                    'kind' => $kind,
                    'record' => (int) $latest->id,
                    'at' => $latest->processed_at->toISOString(),
                ];
            }
        }

        foreach ($this->providers->providers() as $provider) {
            if (! $this->providers->hasCapability($provider, ObservationCollectionCapability::class)) {
                continue;
            }
            $mappedSiteIds = IntegrationSiteConfig::query()
                ->forProvider($provider)
                ->active()
                ->whereHas('site')
                ->pluck('site_id');
            foreach (ProviderCapabilityCursor::query()
                ->where('provider', $provider)
                ->where('capability', ObservationCollectionCapability::class)
                ->whereIn('site_id', $mappedSiteIds)
                ->get(['id', 'site_id', 'last_completed_at']) as $cursor) {
                $components[] = [
                    'kind' => 'provider_'.$provider,
                    'site' => (int) $cursor->site_id,
                    'record' => (int) $cursor->id,
                    'at' => $cursor->last_completed_at?->toISOString(),
                ];
            }
        }

        foreach ($monitors as $monitor) {
            if ($monitor->effective_state !== MonitorState::Suppressed
                || ! in_array($monitor->suppression_reason, ['dependency', 'maintenance'], true)
                || $monitor->suppressed_at === null) {
                continue;
            }
            $components[] = [
                'kind' => 'suppression_'.$monitor->suppression_reason,
                'monitor' => (int) $monitor->id,
                'at' => $monitor->suppressed_at->toISOString(),
            ];
        }

        $encoded = array_map(
            fn (array $component): string => json_encode($component, JSON_THROW_ON_ERROR),
            $components,
        );
        sort($encoded, SORT_STRING);

        return hash('sha256', implode("\n", $encoded));
    }

    /**
     * @param  Collection<int, DeviceEvent>  $events
     * @param  Collection<int, bool>  $monitorIds
     * @return array{int, int}
     */
    private function transitionEvidence(Collection $events, Collection $monitorIds): array
    {
        $failures = 0;
        $recoveries = 0;
        foreach ($monitorIds->keys() as $monitorId) {
            $monitorEvents = $events
                ->filter(fn (DeviceEvent $event): bool => (int) data_get($event->payload, 'monitor_id') === $monitorId)
                ->sortBy('occurred_at')
                ->values();
            $offline = $monitorEvents->first(fn (DeviceEvent $event): bool => $event->event_type === 'offline');
            if ($offline === null) {
                continue;
            }
            $failures++;
            if ($monitorEvents->contains(fn (DeviceEvent $event): bool => $event->event_type === 'online'
                && $event->occurred_at->gt($offline->occurred_at))) {
                $recoveries++;
            }
        }

        return [$failures, $recoveries];
    }

    /** @param Collection<int, Monitor> $monitors @param Collection<int, DeviceEvent> $events */
    private function hysteresisExercises(
        Collection $monitors,
        Collection $events,
        CarbonImmutable $since,
        CarbonImmutable $now,
    ): int {
        return $monitors->filter(function (Monitor $monitor) use ($events, $since, $now): bool {
            $rising = $monitor->profile->rising_threshold;
            $falling = $monitor->profile->falling_threshold;
            if ($rising === null || $falling === null || (float) $rising <= (float) $falling) {
                return false;
            }
            $observations = MonitorObservation::query()
                ->where('monitor_id', $monitor->id)
                ->whereNotNull('value')
                ->whereBetween('observed_at', [$since, $now])
                ->orderBy('observed_at')
                ->get(['value', 'observed_at']);
            $monitorEvents = $events
                ->filter(fn (DeviceEvent $event): bool => (int) data_get($event->payload, 'monitor_id') === (int) $monitor->id)
                ->sortBy('occurred_at')
                ->values();
            $offline = $monitorEvents->first(fn (DeviceEvent $event): bool => $event->event_type === 'offline');
            if ($offline === null) {
                return false;
            }
            $online = $monitorEvents->first(fn (DeviceEvent $event): bool => $event->event_type === 'online'
                && $event->occurred_at->gt($offline->occurred_at));
            if ($online === null) {
                return false;
            }
            $high = $observations->first(fn (MonitorObservation $observation): bool => (float) $observation->value >= (float) $rising
                && $observation->observed_at->lte($offline->occurred_at));
            if ($high === null) {
                return false;
            }
            $hold = $observations->first(fn (MonitorObservation $observation): bool => (float) $observation->value > (float) $falling
                && (float) $observation->value < (float) $rising
                && $observation->observed_at->gt($offline->occurred_at)
                && $observation->observed_at->lt($online->occurred_at));
            if ($hold === null) {
                return false;
            }

            return $observations->contains(fn (MonitorObservation $observation): bool => (float) $observation->value <= (float) $falling
                && $observation->observed_at->gt($hold->observed_at)
                && $observation->observed_at->lte($online->occurred_at));
        })->count();
    }

    /** @param Collection<int, Monitor> $monitors @return array{int, int} */
    private function baselineEvidence(Collection $monitors, CarbonImmutable $since, CarbonImmutable $now): array
    {
        $profiles = $monitors->pluck('profile')->filter(fn (MonitoringProfile $profile): bool => $profile->baseline_deviation_multiplier !== null
            && $profile->rising_threshold === null)
            ->unique('id')
            ->values();
        $exercises = 0;
        foreach ($monitors as $monitor) {
            $profile = $monitor->profile;
            if ($profile->baseline_deviation_multiplier === null || $profile->rising_threshold !== null) {
                continue;
            }
            $windowStart = $now->subSeconds((int) $profile->baseline_window_seconds);
            if ($windowStart->lt($since)) {
                $windowStart = $since;
            }
            $values = MonitorObservation::query()
                ->where('monitor_id', $monitor->id)
                ->whereNotNull('value')
                ->whereBetween('observed_at', [$windowStart, $now])
                ->orderBy('observed_at')
                ->pluck('value')
                ->map(fn (mixed $value): float => (float) $value)
                ->values();
            $minimum = max(2, (int) $profile->baseline_minimum_samples);
            if ($values->count() <= $minimum) {
                continue;
            }
            $baseline = $values->slice(0, $values->count() - 1);
            $candidate = (float) $values->last();
            if ($baseline->count() < $minimum) {
                continue;
            }
            $mean = (float) $baseline->average();
            $variance = $baseline->sum(fn (float $value): float => ($value - $mean) ** 2) / max(1, $baseline->count() - 1);
            $deviation = (float) $profile->baseline_deviation_multiplier * sqrt($variance);
            if ($candidate < $mean - $deviation || $candidate > $mean + $deviation) {
                $exercises++;
            }
        }

        return [$profiles->count(), $exercises];
    }

    /** @param Collection<int, Device> $devices @return array{int, int, int} */
    private function rollupEvidence(Collection $devices, CarbonImmutable $now): array
    {
        $deviceRollups = 0;
        $siteRollups = 0;
        $failures = 0;
        $siteIds = [];
        foreach ($devices as $device) {
            try {
                $deviceResult = $this->rollups->device($device, $now);
                if ((int) $deviceResult['monitor_count'] < 1) {
                    $failures++;

                    continue;
                }
                $siteIds[app(CanonicalDeviceSiteResolver::class)->resolve((int) $device->id)] = true;
                $deviceRollups++;
            } catch (Throwable) {
                $failures++;
            }
        }
        foreach (Site::query()->whereKey(array_keys($siteIds))->get() as $site) {
            try {
                $siteResult = $this->rollups->site($site, $now);
                if ((int) $siteResult['device_count'] < 1) {
                    $failures++;

                    continue;
                }
                $siteRollups++;
            } catch (Throwable) {
                $failures++;
            }
        }
        try {
            $estateResult = $this->rollups->estate($now);
            if ((int) $estateResult['site_count'] < $siteRollups || $siteRollups < 1) {
                $failures++;
            }
        } catch (Throwable) {
            $failures++;
        }

        return [$deviceRollups, $siteRollups, $failures];
    }

    /** @param array<string, int> $counts @return array<string, int|string> */
    private function row(bool $verified, array $counts): array
    {
        return ['state' => $verified ? 'verified' : 'not_verified', ...$counts];
    }
}
