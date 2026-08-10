<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Data\MonitorScheduleResult;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\RuntimeMessageType;
use App\Domain\Monitoring\Jobs\RunMonitorCheck;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

final class MonitorScheduler
{
    public const int CHUNK_SIZE = 500;

    private const array DIRECT_KINDS = [
        MonitorKind::Icmp,
        MonitorKind::Tcp,
        MonitorKind::Dns,
        MonitorKind::Http,
        MonitorKind::Tls,
        MonitorKind::Snmp,
        MonitorKind::SshInventory,
        MonitorKind::WinRmInventory,
    ];

    private const array ENROLLED_COLLECTOR_STATES = ['online', 'offline'];

    private const array SECRET_KEYS = [
        'api_key',
        'authorization',
        'community',
        'cookie',
        'credential',
        'credentials',
        'password',
        'private_key',
        'secret',
        'token',
    ];

    public function __construct(
        private readonly CanonicalDeviceSiteResolver $deviceSiteResolver,
        private readonly MonitoringOutboxPublisher $outbox,
    ) {}

    public function dispatchDue(CarbonInterface $requestedAt): MonitorScheduleResult
    {
        $at = CarbonImmutable::instance($requestedAt)->utc()->startOfMinute();
        $schedulerKey = intdiv($at->timestamp, 60) * 60;
        $storeName = (string) config('monitoring.delivery.sequence_lock_store', 'redis');
        $driver = config("cache.stores.{$storeName}.driver");
        $localTestingLock = app()->environment('testing')
            && (bool) config('monitoring.delivery.allow_local_sequence_lock_for_tests', false);

        if ($driver !== 'redis' && ! $localTestingLock) {
            throw new RuntimeException('Monitoring scheduling requires a shared Redis lock store.');
        }

        $lock = Cache::store($storeName)->lock(
            "monitoring:schedule:{$schedulerKey}",
            120,
        );

        // This is a once-per-minute execution lease, not a critical-section lock.
        // It deliberately expires rather than releasing when scanning completes so
        // a second scheduler process cannot repeat the same minute's dispatch.
        if (! $lock->get()) {
            return MonitorScheduleResult::locked();
        }

        $scanned = 0;
        $chunks = 0;
        $directDispatched = 0;
        $collectorConfigurations = 0;
        $omitted = 0;
        /** @var array<int, int|null> $canonicalSites */
        $canonicalSites = [];

        Monitor::query()
            ->where('is_enabled', true)
            ->whereHas('profile', fn ($query) => $query
                ->where('is_active', true)
                ->where('interval_seconds', '>', 0))
            ->with([
                'profile:id,interval_seconds,is_active',
                'collector:id,collector_uuid,site_id,status',
            ])
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $monitors) use (
                $at,
                &$scanned,
                &$chunks,
                &$directDispatched,
                &$collectorConfigurations,
                &$omitted,
                &$canonicalSites,
            ): void {
                $chunks++;

                foreach ($monitors as $monitor) {
                    $scanned++;
                    $interval = (int) $monitor->profile?->interval_seconds;
                    if ($interval < 1) {
                        $omitted++;

                        continue;
                    }

                    $scheduleEpoch = intdiv($at->timestamp, $interval) * $interval;
                    if ($monitor->last_observation_at !== null
                        && $monitor->last_observation_at->getTimestamp() >= $scheduleEpoch) {
                        continue;
                    }

                    $deviceId = (int) $monitor->device_id;
                    if (! array_key_exists($deviceId, $canonicalSites)) {
                        try {
                            $canonicalSites[$deviceId] = $this->deviceSiteResolver->resolve($deviceId);
                        } catch (Throwable) {
                            $canonicalSites[$deviceId] = null;
                        }
                    }
                    $siteId = $canonicalSites[$deviceId];
                    if ($siteId === null) {
                        $omitted++;

                        continue;
                    }

                    $scheduleKey = (string) $scheduleEpoch;
                    $kind = MonitorKind::tryFrom((string) $monitor->getRawOriginal('kind'));
                    if ($kind === null) {
                        $omitted++;

                        continue;
                    }
                    if ($monitor->collector_id === null) {
                        if (! in_array($kind, self::DIRECT_KINDS, true)) {
                            $omitted++;

                            continue;
                        }

                        RunMonitorCheck::dispatch((int) $monitor->id, $scheduleKey);
                        $directDispatched++;

                        continue;
                    }

                    if (! $this->publishCollectorConfiguration($monitor, $kind, $siteId, $scheduleKey)) {
                        $omitted++;

                        continue;
                    }

                    $collectorConfigurations++;
                }
            });

        return new MonitorScheduleResult(
            lockAcquired: true,
            scanned: $scanned,
            chunks: $chunks,
            directDispatched: $directDispatched,
            collectorConfigurations: $collectorConfigurations,
            omitted: $omitted,
        );
    }

    private function publishCollectorConfiguration(
        Monitor $monitor,
        MonitorKind $kind,
        int $siteId,
        string $scheduleKey,
    ): bool {
        $collector = $monitor->collector;
        if (! $collector instanceof MonitoringCollector
            || (int) $collector->site_id !== $siteId
            || ! in_array(strtolower(trim((string) $collector->status)), self::ENROLLED_COLLECTOR_STATES, true)
            || ! Str::isUuid((string) $collector->collector_uuid)) {
            return false;
        }

        $target = trim((string) $monitor->target);
        if ($target === '' || strlen($target) > 2048) {
            return false;
        }

        try {
            $config = $this->safeCollectorConfig($monitor->config);
        } catch (UnexpectedValueException) {
            return false;
        }

        $this->outbox->stage(
            type: RuntimeMessageType::Configuration,
            stream: 'collector-configuration',
            source: "central:collector:{$collector->collector_uuid}",
            idempotencyKey: "monitor:{$monitor->id}:schedule:{$scheduleKey}",
            payload: [
                'contract_version' => 1,
                'action' => 'run_monitor_check',
                'schedule_key' => $scheduleKey,
                'site_id' => $siteId,
                'collector_id' => (int) $collector->id,
                'collector_uuid' => (string) $collector->collector_uuid,
                'monitor' => [
                    'id' => (int) $monitor->id,
                    'device_id' => (int) $monitor->device_id,
                    'kind' => $kind->value,
                    'target' => $target,
                    'config' => $config,
                    'interval_seconds' => (int) $monitor->profile->interval_seconds,
                ],
            ],
        );

        return true;
    }

    /** @return array<string|int, mixed> */
    private function safeCollectorConfig(mixed $config): array
    {
        if (! is_array($config)) {
            throw new UnexpectedValueException('Collector monitor configuration is invalid.');
        }

        return $this->validateCollectorConfig($config);
    }

    /**
     * @param  array<string|int, mixed>  $values
     * @return array<string|int, mixed>
     */
    private function validateCollectorConfig(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $normalised = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $key));
                $isReference = str_ends_with($normalised, '_reference')
                    || str_ends_with($normalised, '_ref')
                    || str_ends_with($normalised, '_id');
                if (! $isReference && in_array($normalised, self::SECRET_KEYS, true)) {
                    throw new UnexpectedValueException('Collector monitor secrets must use an opaque credential reference.');
                }
            }

            if (is_array($value)) {
                $this->validateCollectorConfig($value);

                continue;
            }
            if (! is_scalar($value) && $value !== null) {
                throw new UnexpectedValueException('Collector monitor configuration contains an unsupported value.');
            }
        }

        return $values;
    }
}
