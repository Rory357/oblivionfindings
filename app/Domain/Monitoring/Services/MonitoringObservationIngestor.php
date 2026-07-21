<?php

namespace App\Domain\Monitoring\Services;

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
    public function __construct(private readonly MonitoringObservationScopeGuard $scopeGuard) {}

    public function ingest(
        Monitor $monitor,
        ObservationInput $input,
        int $siteId,
        int $deviceId,
        mixed $collectorReference,
    ): ObservationResult {
        return DB::transaction(function () use (
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
                throw new RuntimeScopeViolation(
                    'Observation device does not match its canonical monitor.',
                );
            }

            if ($locked->collector_id !== null) {
                $collector = MonitoringCollector::query()
                    ->lockForUpdate()
                    ->findOrFail($locked->collector_id);
                $locked->setRelation('collector', $collector);
            }

            $this->scopeGuard->assertCollectorReference($locked, $collectorReference);
            $this->scopeGuard->assertCanonicalSite($locked, $siteId);

            $existing = $locked->observations()
                ->where('source_key', $input->sourceKey)
                ->first();

            if ($existing) {
                return new ObservationResult(
                    observation: $existing,
                    duplicate: true,
                    stateChanged: false,
                    from: $locked->current_state,
                    to: $locked->current_state,
                    deviceEvent: null,
                );
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

            $from = $locked->current_state;
            $to = $input->state;

            $locked->last_observation_at = $input->observedAt;

            if ($to === $from) {
                $this->clearPendingState($locked);
                $locked->save();

                return $this->result($observation, $from, $from);
            }

            $requiredConfirmations = $this->requiredConfirmations($locked, $to);
            $pendingCount = $locked->pending_state === $to
                ? $locked->pending_count + 1
                : 1;

            if ($pendingCount < $requiredConfirmations) {
                $locked->pending_state = $to;
                $locked->pending_count = $pendingCount;
                $locked->save();

                return $this->result($observation, $from, $from);
            }

            $locked->current_state = $to;
            $locked->last_state_changed_at = $input->observedAt;
            $this->clearPendingState($locked);
            $locked->save();

            $deviceEvent = $this->createAvailabilityEvent(
                monitor: $locked,
                observation: $observation,
                input: $input,
                from: $from,
                to: $to,
            );

            return new ObservationResult(
                observation: $observation,
                duplicate: false,
                stateChanged: true,
                from: $from,
                to: $to,
                deviceEvent: $deviceEvent,
            );
        });
    }

    private function requiredConfirmations(Monitor $monitor, MonitorState $state): int
    {
        if (in_array($state, [MonitorState::Unknown, MonitorState::Stale], true)) {
            return 1;
        }

        if ($state === MonitorState::Healthy) {
            return max(1, $monitor->profile->recovery_confirmations);
        }

        if ($state->isFailure()) {
            return max(1, $monitor->profile->failure_confirmations);
        }

        return 1;
    }

    private function clearPendingState(Monitor $monitor): void
    {
        $monitor->pending_state = null;
        $monitor->pending_count = 0;
    }

    private function result(
        MonitorObservation $observation,
        MonitorState $from,
        MonitorState $to,
    ): ObservationResult {
        return new ObservationResult(
            observation: $observation,
            duplicate: false,
            stateChanged: false,
            from: $from,
            to: $to,
            deviceEvent: null,
        );
    }

    private function createAvailabilityEvent(
        Monitor $monitor,
        MonitorObservation $observation,
        ObservationInput $input,
        MonitorState $from,
        MonitorState $to,
    ): ?DeviceEvent {
        if (! $monitor->affects_availability) {
            return null;
        }

        $eventType = match (true) {
            $to === MonitorState::Failed => 'offline',
            $from === MonitorState::Failed && $to === MonitorState::Healthy => 'online',
            default => null,
        };

        if ($eventType === null) {
            return null;
        }

        return DeviceEvent::create([
            'device_id' => $monitor->device_id,
            'event_type' => $eventType,
            'severity' => $to === MonitorState::Failed ? 'high' : 'info',
            'source' => 'oblivion_monitoring',
            'occurred_at' => $input->observedAt,
            'payload' => [
                'monitor_id' => $monitor->id,
                'observation_id' => $observation->id,
                'from_state' => $from->value,
                'to_state' => $to->value,
                'target' => $monitor->target,
                'latency_ms' => $input->latencyMs,
                'source_key' => $input->sourceKey,
            ],
        ]);
    }
}
