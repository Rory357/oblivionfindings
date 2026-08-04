<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Adapters\SnmpV3ProbeAdapter;
use App\Domain\Monitoring\Data\AuthorisedProbeContext;
use App\Domain\Monitoring\Data\ObservationInput;
use App\Domain\Monitoring\Data\ObservationResult;
use App\Domain\Monitoring\Data\ProbeTarget;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Exceptions\RuntimeScopeViolation;
use App\Domain\Monitoring\Jobs\BuildSnmpTopologySnapshot;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\Monitoring\Protocols\Snmp\SnmpPollResult;
use Throwable;

final class MonitorCheckRunner
{
    private const DIRECT_KINDS = [
        MonitorKind::Icmp,
        MonitorKind::Tcp,
        MonitorKind::Dns,
        MonitorKind::Http,
        MonitorKind::Tls,
        MonitorKind::Snmp,
        MonitorKind::SshInventory,
        MonitorKind::WinRmInventory,
    ];

    public function __construct(
        private readonly CanonicalDeviceSiteResolver $deviceSiteResolver,
        private readonly EgressPolicy $egressPolicy,
        private readonly ProbeAdapterRegistry $adapters,
        private readonly MonitoringObservationIngestor $ingestor,
        private readonly ConfigurationSnapshotService $snapshots,
    ) {}

    public function run(int $monitorId, string $scheduleKey): ObservationResult
    {
        $this->assertScheduleKey($scheduleKey);
        $monitor = Monitor::query()
            ->with(['device', 'profile', 'collector'])
            ->find($monitorId);
        if ($monitor === null || $monitor->device === null || $monitor->profile === null) {
            throw new RuntimeScopeViolation('Direct monitor is unavailable.');
        }
        if (! $monitor->is_enabled) {
            throw new RuntimeScopeViolation('Direct monitor is disabled.');
        }
        if (! $monitor->profile->is_active) {
            throw new RuntimeScopeViolation('Direct monitor profile is inactive.');
        }
        if ($monitor->collector_id !== null || $monitor->collector !== null) {
            throw new RuntimeScopeViolation('Collector-backed monitors cannot run on the central direct-check worker.');
        }
        if (! in_array($monitor->kind, self::DIRECT_KINDS, true)) {
            throw new RuntimeScopeViolation('Monitor kind is not a direct protocol check.');
        }

        try {
            $siteId = $this->deviceSiteResolver->resolve((int) $monitor->device_id);
        } catch (Throwable) {
            throw new RuntimeScopeViolation('Direct monitor does not resolve to one active canonical Site.');
        }

        $sourceKey = "runtime:{$monitor->id}:{$scheduleKey}";
        $existing = MonitorObservation::query()
            ->where('monitor_id', $monitor->id)
            ->where('source_key', $sourceKey)
            ->first();
        if ($existing !== null) {
            return new ObservationResult(
                observation: $existing,
                duplicate: true,
                stateChanged: false,
                from: $monitor->current_state,
                to: $monitor->current_state,
                deviceEvent: null,
            );
        }

        $rawTarget = $this->probeTarget($monitor);
        $authorised = $this->egressPolicy->authorise(
            $siteId,
            (int) $monitor->device_id,
            $rawTarget,
        );
        $adapter = $this->adapters->for($monitor->kind);
        $context = new AuthorisedProbeContext(
            monitorId: (int) $monitor->id,
            siteId: $siteId,
            deviceId: (int) $monitor->device_id,
            kind: $monitor->kind,
            target: $authorised,
            config: $this->probeConfig($monitor),
        );
        $poll = null;
        if ($monitor->kind === MonitorKind::Snmp && $adapter instanceof SnmpV3ProbeAdapter) {
            $poll = $adapter->poll($context);
            $observation = $adapter->observationFor($poll, $context->config);
        } else {
            $observation = $adapter->probe($context);
        }

        if (in_array($monitor->kind, [MonitorKind::SshInventory, MonitorKind::WinRmInventory], true)
            && in_array($observation->state, [MonitorState::Healthy, MonitorState::Degraded], true)) {
            $this->snapshots->captureFromInventory(
                $monitor->device,
                $siteId,
                $observation,
            );
        }

        $result = $this->ingestor->ingest(
            monitor: $monitor,
            input: new ObservationInput(
                sourceKey: $sourceKey,
                state: $observation->state,
                observedAt: $observation->observedAt,
                value: $observation->value,
                unit: $observation->unit,
                latencyMs: $observation->latencyMs,
                message: $observation->reasonCode,
                metrics: [
                    ...$observation->evidence,
                    'reason_code' => $observation->reasonCode,
                    'protocol_kind' => $monitor->kind->value,
                ],
            ),
            siteId: $siteId,
            deviceId: (int) $monitor->device_id,
            collectorReference: null,
        );

        if ($poll instanceof SnmpPollResult && $adapter instanceof SnmpV3ProbeAdapter
            && $this->isRootSnmpMonitor($monitor)) {
            $this->fanOutSnmpInterfaces($monitor, $poll, $adapter, $scheduleKey, $siteId);
            if ($poll->topologyCompletedSources !== []) {
                BuildSnmpTopologySnapshot::dispatch(
                    siteId: $siteId,
                    deviceId: (int) $monitor->device_id,
                    checkpoint: "monitor:{$monitor->id}:{$scheduleKey}",
                    observations: array_map(
                        fn ($observation): array => $observation->jsonSerialize(),
                        $poll->topologyObservations,
                    ),
                    completedSources: $poll->topologyCompletedSources,
                )->afterCommit();
            }
        }

        return $result;
    }

    public static function validScheduleKey(string $scheduleKey): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9:._+-]{0,189}$/', $scheduleKey) === 1;
    }

    private function assertScheduleKey(string $scheduleKey): void
    {
        if (! self::validScheduleKey($scheduleKey)) {
            throw new \InvalidArgumentException('Monitoring schedule key is invalid.');
        }
    }

    private function probeTarget(Monitor $monitor): ProbeTarget
    {
        $config = is_array($monitor->config) ? $monitor->config : [];

        return match ($monitor->kind) {
            MonitorKind::Icmp => ProbeTarget::icmp($this->targetValue($monitor, $config, 'host')),
            MonitorKind::Tcp => ProbeTarget::tcp(
                $this->targetValue($monitor, $config, 'host'),
                $this->requiredPort($config, 'port'),
            ),
            MonitorKind::Dns => ProbeTarget::dns(
                $this->targetValue($monitor, $config, 'server'),
                $this->optionalPort($config, 'port', 53),
            ),
            MonitorKind::Http => ProbeTarget::http($this->targetValue($monitor, $config, 'url')),
            MonitorKind::Tls => ProbeTarget::tls(
                $this->targetValue($monitor, $config, 'host'),
                $this->optionalPort($config, 'port', 443),
            ),
            MonitorKind::Snmp => ProbeTarget::snmp(
                $this->targetValue($monitor, $config, 'host'),
                $this->optionalPort($config, 'port', 161),
            ),
            MonitorKind::SshInventory => ProbeTarget::ssh(
                $this->targetValue($monitor, $config, 'host'),
                $this->optionalPort($config, 'port', 22),
            ),
            MonitorKind::WinRmInventory => ProbeTarget::winrm(
                $this->targetValue($monitor, $config, 'url'),
            ),
            default => throw new RuntimeScopeViolation('Monitor kind is not a direct protocol check.'),
        };
    }

    /** @return array<string, mixed> */
    private function probeConfig(Monitor $monitor): array
    {
        $config = is_array($monitor->config) ? $monitor->config : [];
        if ($monitor->kind !== MonitorKind::Snmp) {
            return $config;
        }

        $previous = MonitorObservation::query()
            ->where('monitor_id', $monitor->id)
            ->latest('observed_at')
            ->latest('id')
            ->value('metrics');
        if (is_string($previous)) {
            $previous = json_decode($previous, true);
        }
        if (is_array($previous)) {
            $config['previous_metrics'] = $previous;
        }

        return $config;
    }

    private function isRootSnmpMonitor(Monitor $monitor): bool
    {
        $config = is_array($monitor->config) ? $monitor->config : [];

        return ! array_key_exists('if_index', $config)
            && ! array_key_exists('interface_index', $config)
            && ! array_key_exists('sensor_index', $config);
    }

    private function fanOutSnmpInterfaces(
        Monitor $root,
        SnmpPollResult $poll,
        SnmpV3ProbeAdapter $adapter,
        string $scheduleKey,
        int $siteId,
    ): void {
        $rootConfig = is_array($root->config) ? $root->config : [];
        Monitor::query()
            ->where('device_id', $root->device_id)
            ->where('kind', MonitorKind::SnmpInterface->value)
            ->where('is_enabled', true)
            ->whereNull('collector_id')
            ->whereHas('profile', fn ($query) => $query->where('is_active', true))
            ->orderBy('id')
            ->each(function (Monitor $interface) use ($root, $rootConfig, $poll, $adapter, $scheduleKey, $siteId): void {
                $config = is_array($interface->config) ? $interface->config : [];
                $parentId = $config['parent_monitor_id'] ?? null;
                $sameCredential = is_string($config['credential_reference'] ?? null)
                    && hash_equals(
                        (string) ($rootConfig['credential_reference'] ?? ''),
                        (string) $config['credential_reference'],
                    );
                if (($parentId !== null && (! is_int($parentId) || $parentId !== $root->id))
                    || ($parentId === null && (! $sameCredential || $interface->target !== $root->target))) {
                    return;
                }

                $previous = MonitorObservation::query()
                    ->where('monitor_id', $interface->id)
                    ->latest('observed_at')
                    ->latest('id')
                    ->value('metrics');
                if (is_string($previous)) {
                    $previous = json_decode($previous, true);
                }
                $config['previous_metrics'] = is_array($previous) ? $previous : [];

                try {
                    $observation = $adapter->observationFor($poll, $config);
                } catch (Throwable) {
                    return;
                }

                $this->ingestor->ingest(
                    monitor: $interface,
                    input: new ObservationInput(
                        sourceKey: "runtime:{$interface->id}:{$scheduleKey}",
                        state: $observation->state,
                        observedAt: $observation->observedAt,
                        value: $observation->value,
                        unit: $observation->unit,
                        latencyMs: $observation->latencyMs,
                        message: $observation->reasonCode,
                        metrics: [
                            ...$observation->evidence,
                            'reason_code' => $observation->reasonCode,
                            'protocol_kind' => MonitorKind::SnmpInterface->value,
                            'parent_monitor_id' => (int) $root->id,
                        ],
                    ),
                    siteId: $siteId,
                    deviceId: (int) $root->device_id,
                    collectorReference: null,
                );
            });
    }

    /** @param array<string, mixed> $config */
    private function targetValue(Monitor $monitor, array $config, string $key): string
    {
        $stored = trim((string) $monitor->target);
        $configured = $config[$key] ?? null;
        if ($configured !== null && (! is_string($configured) || trim($configured) === '')) {
            throw new RuntimeScopeViolation('Direct monitor target configuration is invalid.');
        }
        $configured = is_string($configured) ? trim($configured) : null;
        if ($configured !== null && $stored !== '' && $configured !== $stored) {
            throw new RuntimeScopeViolation('Direct monitor target sources conflict.');
        }
        $target = $configured ?? $stored;
        if ($target === '') {
            throw new RuntimeScopeViolation('Direct monitor target is missing.');
        }

        return $target;
    }

    /** @param array<string, mixed> $config */
    private function requiredPort(array $config, string $key): int
    {
        $port = $config[$key] ?? null;
        if (! is_int($port) || $port < 1 || $port > 65535) {
            throw new RuntimeScopeViolation('Direct monitor port configuration is invalid.');
        }

        return $port;
    }

    /** @param array<string, mixed> $config */
    private function optionalPort(array $config, string $key, int $default): int
    {
        if (! array_key_exists($key, $config)) {
            return $default;
        }

        return $this->requiredPort($config, $key);
    }
}
