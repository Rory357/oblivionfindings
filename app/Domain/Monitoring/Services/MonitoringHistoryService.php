<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorObservation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class MonitoringHistoryService
{
    public const array RANGES = [
        '1h' => 3600,
        '24h' => 86400,
        '7d' => 604800,
        '30d' => 2592000,
    ];

    public const int MAX_DB_ROWS = 2400;

    public const int MAX_SERIES_POINTS = 240;

    public function __construct(
        private readonly EffectiveMonitoringPolicyResolver $policyResolver,
        private readonly CanonicalDeviceSiteResolver $siteResolver,
    ) {}

    /** @return array<string, mixed> */
    public function historyForMonitor(Monitor $monitor, string $range = '24h'): array
    {
        $normalizedRange = array_key_exists($range, self::RANGES) ? $range : '24h';
        $windowSeconds = self::RANGES[$normalizedRange];
        $now = CarbonImmutable::now();
        $windowStart = $now->subSeconds($windowSeconds);

        $monitor->loadMissing(['device', 'profile']);
        $device = $monitor->device;
        try {
            $siteId = $device ? $this->siteResolver->resolveForContext((int) $device->id) : null;
        } catch (\Throwable) {
            $siteId = null;
        }

        $baseQuery = MonitorObservation::query()
            ->where('monitor_id', $monitor->id)
            ->where('observed_at', '>=', $windowStart)
            ->where('observed_at', '<=', $now);

        $totalObservations = (clone $baseQuery)->count();

        /** @var Collection<int, MonitorObservation> $observations */
        $observations = (clone $baseQuery)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->limit(self::MAX_DB_ROWS)
            ->get([
                'id',
                'monitor_id',
                'state',
                'value',
                'unit',
                'latency_ms',
                'metrics',
                'observed_at',
            ])
            ->reverse()
            ->values();

        $points = $observations->map(fn (MonitorObservation $obs): array => $this->mapPoint($obs, $monitor))->values();

        $staleAfterSeconds = max(30, (int) ($monitor->profile?->stale_after_seconds ?? 300));
        $gapThresholdSeconds = max(120, $staleAfterSeconds * 2);

        $transitions = $this->extractTransitions($points);
        $gaps = $this->extractGaps($observations, $gapThresholdSeconds);
        $summary = $this->buildSummary($points, $totalObservations, $monitor, $staleAfterSeconds, $now);
        $series = $this->downsample($points, self::MAX_SERIES_POINTS);
        $primaryMetric = $this->primaryMetricMeta($monitor, $points);

        $threshold = $this->policyResolver->resolveThreshold($monitor, $siteId);
        $maintenanceContext = $siteId !== null
            ? $this->policyResolver->resolveMaintenanceContext($monitor, $device, $siteId, $now)
            : ['active' => null, 'upcoming' => null, 'life_safety_exempt' => false];
        $mapWindow = static fn (?array $window): ?array => $window === null ? null : [
            'id' => $window['id'],
            'name' => $window['name'],
            'starts_at' => $window['starts_at'],
            'ends_at' => $window['ends_at'],
            'timezone' => $window['timezone'] ?? 'Pacific/Auckland',
            'life_safety_protected' => $maintenanceContext['life_safety_exempt'],
        ];

        return [
            'monitor' => [
                'id' => (int) $monitor->id,
                'name' => $monitor->name,
                'kind' => $monitor->kind?->value ?? (string) $monitor->kind,
                'enabled' => (bool) $monitor->is_enabled,
                'reported_state' => $monitor->current_state?->value ?? 'unknown',
                'effective_state' => $monitor->effective_state?->value ?? ($monitor->current_state?->value ?? 'unknown'),
                'stale_after_seconds' => $staleAfterSeconds,
                'last_observation_at' => $monitor->last_observation_at?->toIso8601String(),
                'device' => $device ? [
                    'id' => (int) $device->id,
                    'name' => $device->name,
                    'domain' => $device->domain,
                    'category' => $device->category,
                    'href' => "/security-devices/devices/{$device->id}",
                ] : null,
            ],
            'range' => [
                'key' => $normalizedRange,
                'seconds' => $windowSeconds,
                'from' => $windowStart->toIso8601String(),
                'to' => $now->toIso8601String(),
                'total_observations' => $totalObservations,
                'sampled_observations' => $observations->count(),
                'shown_points' => count($series),
                'downsampled' => $totalObservations > count($series),
                'truncated_read' => $totalObservations > self::MAX_DB_ROWS,
            ],
            'primary_metric' => $primaryMetric,
            'thresholds' => [
                'source_kind' => $threshold['scope_kind'] ?? $threshold['source'],
                'rule_name' => $threshold['rule_name'],
                'metric_key' => $threshold['metric_key'] ?? $primaryMetric['key'],
                'direction' => $threshold['direction'],
                'warning_rising' => $threshold['warning_rising'],
                'warning_falling' => $threshold['warning_falling'],
                'critical_rising' => $threshold['critical_rising'],
                'critical_falling' => $threshold['critical_falling'],
            ],
            'maintenance' => [
                'active_window' => $mapWindow($maintenanceContext['active']),
                'upcoming_window' => $mapWindow($maintenanceContext['upcoming']),
            ],
            'summary' => $summary,
            'series' => $series,
            'transitions' => $transitions,
            'gaps' => $gaps,
        ];
    }

    /** @return array<string, mixed> */
    private function mapPoint(MonitorObservation $obs, Monitor $monitor): array
    {
        $metrics = is_array($obs->metrics) ? $obs->metrics : [];
        $inUtilisation = $this->floatMetric($metrics, ['in_utilization_pct', 'in_utilisation_pct']);
        $outUtilisation = $this->floatMetric($metrics, ['out_utilization_pct', 'out_utilisation_pct']);
        $inBps = $this->floatMetric($metrics, ['in_bps', 'inbound_bps']);
        $outBps = $this->floatMetric($metrics, ['out_bps', 'outbound_bps']);
        $errors = $this->intMetric($metrics, ['errors', 'error_count']);
        $discards = $this->intMetric($metrics, ['discards', 'discard_count']);
        $packetLoss = $this->floatMetric($metrics, ['packet_loss_pct']);

        $numericValue = $obs->value !== null && is_numeric($obs->value) ? (float) $obs->value : null;
        if ($numericValue === null && $monitor->kind === MonitorKind::SnmpInterface) {
            $maxUtil = collect([$inUtilisation, $outUtilisation])->filter(fn ($v) => $v !== null)->max();
            $numericValue = $maxUtil !== null ? (float) $maxUtil : null;
        }
        if ($numericValue === null && $obs->latency_ms !== null) {
            $numericValue = (float) $obs->latency_ms;
        }

        return [
            'observed_at' => $obs->observed_at?->toIso8601String(),
            'state' => $obs->state?->value ?? (string) $obs->state,
            'value' => $numericValue,
            'raw_value' => $obs->value !== null && is_numeric($obs->value) ? (float) $obs->value : null,
            'unit' => $obs->unit ?? ($monitor->kind === MonitorKind::SnmpInterface && $numericValue !== null ? '%' : ($obs->latency_ms !== null && $obs->value === null ? 'ms' : null)),
            'latency_ms' => $obs->latency_ms,
            'in_bps' => $inBps,
            'out_bps' => $outBps,
            'in_utilization_pct' => $inUtilisation,
            'out_utilization_pct' => $outUtilisation,
            'errors' => $errors,
            'discards' => $discards,
            'packet_loss_pct' => $packetLoss,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $points
     * @return array<string, mixed>
     */
    private function primaryMetricMeta(Monitor $monitor, Collection $points): array
    {
        $kind = $monitor->kind;
        $unit = $points->pluck('unit')->filter()->last();

        if ($kind === MonitorKind::SnmpInterface) {
            return [
                'key' => 'utilization',
                'label' => 'Interface utilisation & traffic',
                'unit' => is_string($unit) ? $unit : '%',
                'is_interface' => true,
            ];
        }

        return [
            'key' => 'value',
            'label' => match ($kind) {
                MonitorKind::Icmp, MonitorKind::Tcp, MonitorKind::Dns, MonitorKind::Http => 'Response latency',
                MonitorKind::Tls => 'Certificate validity',
                default => 'Observed metric',
            },
            'unit' => is_string($unit) ? $unit : null,
            'is_interface' => false,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $points
     * @return array<string, mixed>
     */
    private function buildSummary(
        Collection $points,
        int $totalObservations,
        Monitor $monitor,
        int $staleAfterSeconds,
        CarbonImmutable $now,
    ): array {
        $numeric = $points
            ->pluck('value')
            ->filter(fn (mixed $v): bool => is_int($v) || is_float($v))
            ->map(fn (mixed $v): float => (float) $v)
            ->values();

        $healthyCount = $points->where('state', 'healthy')->count();
        $degradedCount = $points->where('state', 'degraded')->count();
        $failedCount = $points->where('state', 'failed')->count();
        $availabilityPct = $points->isEmpty()
            ? null
            : round(($healthyCount / $points->count()) * 100, 2);

        $latest = $points->last();
        $isStale = $monitor->last_observation_at === null
            || $monitor->last_observation_at->lt($now->subSeconds($staleAfterSeconds));

        return [
            'sample_count' => $totalObservations,
            'min' => $numeric->isEmpty() ? null : round((float) $numeric->min(), 3),
            'max' => $numeric->isEmpty() ? null : round((float) $numeric->max(), 3),
            'average' => $numeric->isEmpty() ? null : round((float) $numeric->avg(), 3),
            'latest' => is_array($latest) && isset($latest['value']) && is_numeric($latest['value'])
                ? round((float) $latest['value'], 3)
                : null,
            'unit' => is_array($latest) ? ($latest['unit'] ?? null) : null,
            'availability_pct' => $availabilityPct,
            'healthy_samples' => $healthyCount,
            'degraded_samples' => $degradedCount,
            'failed_samples' => $failedCount,
            'freshness' => $monitor->last_observation_at === null
                ? 'never_observed'
                : ($isStale ? 'stale' : 'fresh'),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $points
     * @return list<array<string, mixed>>
     */
    private function extractTransitions(Collection $points): array
    {
        $transitions = [];
        $previousState = null;

        foreach ($points as $point) {
            $state = (string) ($point['state'] ?? 'unknown');
            if ($previousState !== null && $state !== $previousState) {
                $transitions[] = [
                    'observed_at' => $point['observed_at'],
                    'from_state' => $previousState,
                    'to_state' => $state,
                    'value' => $point['value'],
                    'unit' => $point['unit'],
                ];
            }
            $previousState = $state;
        }

        return array_slice(array_reverse($transitions), 0, 50);
    }

    /**
     * @param  Collection<int, MonitorObservation>  $observations
     * @return list<array<string, mixed>>
     */
    private function extractGaps(Collection $observations, int $gapThresholdSeconds): array
    {
        $gaps = [];
        $previous = null;

        foreach ($observations as $obs) {
            if ($previous !== null && $previous->observed_at !== null && $obs->observed_at !== null) {
                $delta = (int) $previous->observed_at->diffInSeconds($obs->observed_at);
                if ($delta > $gapThresholdSeconds) {
                    $gaps[] = [
                        'from' => $previous->observed_at->toIso8601String(),
                        'to' => $obs->observed_at->toIso8601String(),
                        'duration_seconds' => $delta,
                    ];
                }
            }
            $previous = $obs;
        }

        return array_slice($gaps, -25);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $points
     * @return list<array<string, mixed>>
     */
    private function downsample(Collection $points, int $maxPoints): array
    {
        $all = $points->values()->all();
        $count = count($all);
        if ($count <= $maxPoints) {
            return $all;
        }

        $bucketSize = $count / $maxPoints;
        $sampled = [];

        for ($i = 0; $i < $maxPoints; $i++) {
            $start = (int) floor($i * $bucketSize);
            $end = min($count - 1, (int) floor(($i + 1) * $bucketSize) - 1);
            if ($end < $start) {
                $end = $start;
            }
            if ($i === 0) {
                $sampled[] = $all[0];

                continue;
            }
            if ($i === $maxPoints - 1) {
                $sampled[] = $all[$count - 1];

                continue;
            }

            $chosen = $all[$end];
            for ($j = $start; $j <= $end; $j++) {
                if (in_array($all[$j]['state'], ['failed', 'degraded'], true)) {
                    $chosen = $all[$j];
                    if ($all[$j]['state'] === 'failed') {
                        break;
                    }
                }
            }
            $sampled[] = $chosen;
        }

        return $sampled;
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  list<string>  $keys
     */
    private function floatMetric(array $metrics, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = data_get($metrics, $key);
            if ($value !== null && is_numeric($value)) {
                return round((float) $value, 3);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  list<string>  $keys
     */
    private function intMetric(array $metrics, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = data_get($metrics, $key);
            if ($value !== null && is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }
}
