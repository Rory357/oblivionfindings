<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Data\MetricSample;
use App\Domain\Monitoring\Data\ObservationInput;
use App\Domain\Monitoring\Data\ObservationResult;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Exceptions\RuntimeScopeViolation;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use Illuminate\Support\Facades\DB;

final class MonitoringObservationIngestor
{
    public function __construct(
        private readonly MonitoringObservationScopeGuard $scopeGuard,
        private readonly MonitorStateMachine $stateMachine,
        private readonly MaintenanceEvaluator $maintenance,
        private readonly DependencyEvaluator $dependencies,
        private readonly MetricIngestService $metrics,
    ) {}

    public function ingest(
        Monitor $monitor,
        ObservationInput $input,
        int $siteId,
        int $deviceId,
        mixed $collectorReference,
    ): ObservationResult {
        $result = DB::transaction(function () use (
            $monitor,
            $input,
            $siteId,
            $deviceId,
            $collectorReference,
        ): ObservationResult {
            $locked = Monitor::query()
                ->with(['profile', 'device', 'collector'])
                ->lockForUpdate()
                ->findOrFail($monitor->getKey());

            if ((int) $locked->device_id !== $deviceId) {
                throw new RuntimeScopeViolation('Observation device does not match its canonical monitor.');
            }

            if ($locked->collector_id !== null) {
                $collector = MonitoringCollector::query()->lockForUpdate()->findOrFail($locked->collector_id);
                $locked->setRelation('collector', $collector);
            }

            $this->scopeGuard->assertCollectorReference($locked, $collectorReference);
            $this->scopeGuard->assertCanonicalSite($locked, $siteId);

            $existing = $locked->observations()->where('source_key', $input->sourceKey)->first();
            if ($existing) {
                $state = $locked->effective_state ?? $locked->current_state;

                return new ObservationResult($existing, true, false, $state, $state, null);
            }

            $observation = MonitorObservation::create([
                'monitor_id' => $locked->id,
                'source_key' => $input->sourceKey,
                'state' => $input->state,
                'value' => $input->value,
                'unit' => $input->unit,
                'latency_ms' => $input->latencyMs,
                'message' => $input->message,
                'metrics' => $input->metrics,
                'observed_at' => $input->observedAt,
                'ingested_at' => now(),
            ]);

            $effectiveFrom = $locked->effective_state ?? $locked->current_state;
            $transition = $this->stateMachine->decide($locked, $input);
            $locked->last_observation_at = $input->observedAt;
            $locked->pending_state = $transition->pendingState;
            $locked->pending_count = $transition->pendingCount;
            $locked->pending_since_at = $transition->pendingSinceAt;

            if ($transition->stateChanged) {
                $locked->current_state = $transition->confirmedState;
                $locked->last_state_changed_at = $input->observedAt;
            }

            // Dependency evaluation reads the persisted confirmed state. The
            // observation itself remains immutable even when its notification
            // is suppressed by policy.
            $locked->save();
            $locked->refresh()->load('profile');

            [$effectiveTo, $rootCauseMonitorId, $suppressionReason] = $this->effectiveState($locked, $input);
            $effectiveChanged = $effectiveTo !== $effectiveFrom;
            $locked->effective_state = $effectiveTo;
            $locked->root_cause_monitor_id = $rootCauseMonitorId;
            $locked->suppression_reason = $suppressionReason;
            $locked->suppressed_at = $suppressionReason === null ? null : ($locked->suppressed_at ?? $input->observedAt);
            $locked->save();

            $deviceEvent = $this->createAvailabilityEvent(
                monitor: $locked,
                observation: $observation,
                input: $input,
                siteId: $siteId,
                from: $effectiveFrom,
                to: $effectiveTo,
            );

            return new ObservationResult(
                observation: $observation,
                duplicate: false,
                stateChanged: $transition->stateChanged || $effectiveChanged,
                from: $effectiveFrom,
                to: $effectiveTo,
                deviceEvent: $deviceEvent,
            );
        });

        $this->projectMetrics($monitor, $input, $siteId);

        return $result;
    }

    private function projectMetrics(Monitor $monitor, ObservationInput $input, int $siteId): void
    {
        if (! is_string(config('monitoring.storage.timeseries.url'))
            || config('monitoring.storage.timeseries.url') === '') {
            return;
        }

        $monitor->loadMissing('device');
        if ($monitor->device === null) {
            throw new RuntimeScopeViolation('Metric projection requires a canonical Device.');
        }
        $domain = (string) $monitor->device->domain;
        [$dataClass, $privacyClass] = match ($domain) {
            'tracking' => ['tracking_telemetry', 'sensitive'],
            'iot_healthcare' => ['healthcare_telemetry', 'sensitive'],
            'security' => ['security_telemetry', 'restricted'],
            default => ['operational', 'standard'],
        };
        $dimensions = array_filter([
            'if_index' => $input->metrics['if_index'] ?? $input->metrics['interface_index'] ?? null,
            'interface' => $input->metrics['interface_name'] ?? $input->metrics['if_name'] ?? null,
            'sensor_index' => $input->metrics['sensor_index'] ?? null,
            'protocol_kind' => $input->metrics['protocol_kind'] ?? null,
        ], fn (mixed $value): bool => (is_string($value) || is_int($value) || is_bool($value))
            && strlen((string) $value) <= 128);
        $samples = [];
        if ($input->value !== null && $input->unit !== null
            && preg_match('/^[a-z][a-z0-9_.-]{0,31}$/', $input->unit) === 1) {
            $samples[] = ['monitor.value', $input->value, $input->unit];
        }
        if ($input->latencyMs !== null) {
            $samples[] = ['monitor.latency', $input->latencyMs, 'milliseconds'];
        }
        foreach ($input->metrics as $key => $value) {
            if (! is_string($key) || (! is_int($value) && ! is_float($value))
                || ! is_finite((float) $value)
                || in_array($key, ['if_index', 'interface_index', 'sensor_index', 'parent_monitor_id', 'latency_ms'], true)
                || preg_match('/credential|password|secret|token|authorization|cookie|raw/i', $key) === 1) {
                continue;
            }
            $metric = strtolower((string) preg_replace('/[^a-z0-9_.-]+/i', '_', $key));
            if (preg_match('/^[a-z][a-z0-9_.-]{0,115}$/', $metric) !== 1) {
                continue;
            }
            $samples[] = ['observation.'.$metric, $value, $this->unitForMetric($metric)];
        }

        foreach ($samples as [$metric, $value, $unit]) {
            $this->metrics->writeForDevice($monitor->device, $siteId, new MetricSample(
                metric: $metric,
                value: $value,
                unit: $unit,
                dimensions: $dimensions,
                observedAt: $input->observedAt,
                source: 'oblivion_observation',
                dataClass: $dataClass,
                privacyClass: $privacyClass,
            ), (int) $monitor->id);
        }
    }

    private function unitForMetric(string $metric): string
    {
        return match (true) {
            str_ends_with($metric, '_pct'), str_ends_with($metric, '_percent'), str_contains($metric, 'utilization'), str_contains($metric, 'utilisation') => 'percent',
            str_ends_with($metric, '_bps') => 'bits_per_second',
            str_ends_with($metric, '_kbps') => 'kilobits_per_second',
            str_ends_with($metric, '_mbps') => 'megabits_per_second',
            str_ends_with($metric, '_gbps') => 'gigabits_per_second',
            str_ends_with($metric, '_seconds') => 'seconds',
            str_contains($metric, 'bytes') => 'bytes',
            str_contains($metric, 'latency') => 'milliseconds',
            str_contains($metric, 'temperature') => 'celsius',
            default => 'count',
        };
    }

    /** @return array{MonitorState, ?int, ?string} */
    private function effectiveState(Monitor $monitor, ObservationInput $input): array
    {
        if ($monitor->current_state->isFailure()) {
            $window = $this->maintenance->activeWindow($monitor, $input->observedAt);
            if ($window !== null && $window->policy === 'suppress_notifications_and_ticketing') {
                return [MonitorState::Suppressed, null, 'maintenance'];
            }

            $dependency = $this->dependencies->evaluate($monitor, $input->observedAt);
            if ($dependency->effectiveState === MonitorState::Suppressed) {
                return [MonitorState::Suppressed, $dependency->rootCauseMonitorId, 'dependency'];
            }
        }

        return [$monitor->current_state, null, null];
    }

    private function createAvailabilityEvent(
        Monitor $monitor,
        MonitorObservation $observation,
        ObservationInput $input,
        int $siteId,
        MonitorState $from,
        MonitorState $to,
    ): ?DeviceEvent {
        if (! $monitor->affects_availability || $to === MonitorState::Suppressed) {
            return null;
        }

        $eventType = match (true) {
            $to === MonitorState::Failed && $from !== MonitorState::Failed => 'offline',
            $from === MonitorState::Failed && $to === MonitorState::Healthy => 'online',
            default => null,
        };

        if ($eventType === null) {
            return null;
        }

        $rootMonitorId = $monitor->root_cause_monitor_id ?? $monitor->id;
        $correlationKey = hash(
            'sha256',
            "site:{$siteId}:device:{$monitor->device_id}:root:{$rootMonitorId}:condition:availability",
        );

        return DeviceEvent::create([
            'device_id' => $monitor->device_id,
            'event_type' => $eventType,
            'severity' => $to === MonitorState::Failed ? 'high' : 'info',
            'source' => 'oblivion_monitoring',
            'occurred_at' => $input->observedAt,
            'payload' => [
                'monitor_id' => $monitor->id,
                'observation_id' => $observation->id,
                'root_cause_monitor_id' => $rootMonitorId,
                'monitor_correlation_key' => $correlationKey,
                'site_id' => $siteId,
                'from_state' => $from->value,
                'to_state' => $to->value,
                'target' => $monitor->target,
                'latency_ms' => $input->latencyMs,
                'source_key' => $input->sourceKey,
                'message' => $input->message,
            ],
        ]);
    }
}
