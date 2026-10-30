<?php

namespace App\Services\System;

use App\Domain\Monitoring\Models\MonitoringRuntimeHeartbeat;
use App\Domain\Monitoring\Services\MonitoringRuntimeHealthService;
use App\Domain\Monitoring\Transports\NativeIcmpTransport;
use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerRestoreProbe;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointReleaseCatalogue;
use App\Jobs\System\RecordDefaultWorkerHeartbeatJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class ServerReadinessService
{
    public const string SCHEDULER_CACHE_KEY = 'system:scheduler:last_heartbeat_at';

    public const string CHECKLIST_CACHE_KEY = 'system:readiness:checklist_summary';

    private const int SCHEDULER_STALE_SECONDS = 300;

    /** @var array<string, string> */
    private const array MONITORING_POOL_LABELS = [
        'events' => 'Device events',
        'checks' => 'Health checks',
        'discovery' => 'Network discovery',
        'provider' => 'Vendor integrations',
        'topology' => 'Network map',
        'maintenance' => 'Upkeep and cleanup',
        'orchestration' => 'Check scheduling',
        'commands' => 'Device commands',
    ];

    public function __construct(
        private readonly MonitoringRuntimeHealthService $runtimeHealth,
        private readonly NativeIcmpTransport $icmpTransport,
    ) {}

    /**
     * @return array{
     *   overall_status: 'pass'|'fail',
     *   passed_count: int,
     *   failed_count: int,
     *   total_count: int,
     *   checked_at: string,
     *   checks: list<array{
     *     key: string,
     *     title: string,
     *     status: 'pass'|'fail',
     *     summary: string,
     *     detail: string,
     *     fix: ?string,
     *     subitems?: list<array{
     *       key: string,
     *       label: string,
     *       queue: string,
     *       status: 'pass'|'fail',
     *       detail: string
     *     }>
     *   }>
     * }
     */
    public function evaluate(bool $useCachedPing = false): array
    {
        $checks = [
            $this->checkRedis(),
            $this->checkScheduler(),
            $this->checkWorkerPools(),
            $this->checkPingPermission($useCachedPing),
            $this->checkCollectorKeys(),
            $this->checkReleaseKey(),
            $this->checkCredentialStore(),
        ];

        $passedCount = collect($checks)->where('status', 'pass')->count();
        $totalCount = count($checks);
        $failedCount = $totalCount - $passedCount;
        $checkedAt = now()->utc()->toIso8601String();
        $overallStatus = $failedCount === 0 ? 'pass' : 'fail';

        try {
            Cache::put(self::CHECKLIST_CACHE_KEY, [
                'overall_status' => $overallStatus,
                'passed_count' => $passedCount,
                'failed_count' => $failedCount,
                'total_count' => $totalCount,
                'checked_at' => $checkedAt,
            ], 60);
        } catch (Throwable) {
            // Ignore cache write errors during readiness checks.
        }

        return [
            'overall_status' => $overallStatus,
            'passed_count' => $passedCount,
            'failed_count' => $failedCount,
            'total_count' => $totalCount,
            'checked_at' => $checkedAt,
            'checks' => $checks,
        ];
    }

    /**
     * Fast, non-blocking readiness summary for the Overview first-run checklist.
     *
     * Never reports ready unless all 7 readiness checks have passed. Avoids blocking
     * on Redis sockets or spawning ping subprocesses when fast local checks already fail.
     *
     * @return array{ready: bool, passed_count: ?int, total_count: int}
     */
    public function checklistSummary(): array
    {
        try {
            $cached = Cache::get(self::CHECKLIST_CACHE_KEY);
            if (
                is_array($cached)
                && isset($cached['overall_status'], $cached['passed_count'], $cached['total_count'])
            ) {
                return [
                    'ready' => $cached['overall_status'] === 'pass',
                    'passed_count' => (int) $cached['passed_count'],
                    'total_count' => (int) $cached['total_count'],
                ];
            }
        } catch (Throwable) {
            // Fall through to local evaluation.
        }

        $fastChecks = [
            $this->checkScheduler(),
            $this->checkWorkerPools(),
            $this->checkCollectorKeys(),
            $this->checkReleaseKey(),
            $this->checkCredentialStore(),
        ];

        if (collect($fastChecks)->contains(fn (array $check): bool => ($check['status'] ?? 'fail') !== 'pass')) {
            return [
                'ready' => false,
                'passed_count' => null,
                'total_count' => 7,
            ];
        }

        $full = $this->evaluate(useCachedPing: true);

        return [
            'ready' => $full['overall_status'] === 'pass',
            'passed_count' => $full['passed_count'],
            'total_count' => $full['total_count'],
        ];
    }

    /** @return array<string, mixed> */
    private function checkRedis(): array
    {
        $queueDriver = (string) config('queue.default', 'sync');
        $cacheDriver = (string) config('cache.default', 'database');

        $reachable = false;
        try {
            $response = Redis::connection()->command('ping');
            $reachable = $response === true
                || $response === 1
                || strtoupper(ltrim((string) $response, '+')) === 'PONG';
        } catch (Throwable) {
            $reachable = false;
        }

        if ($reachable) {
            return [
                'key' => 'redis',
                'title' => 'Redis',
                'status' => 'pass',
                'summary' => 'Connected and responding',
                'detail' => sprintf(
                    'Redis replied to a live ping. Active queue driver: %s · cache store: %s.',
                    $this->driverLabel($queueDriver),
                    $this->driverLabel($cacheDriver),
                ),
                'fix' => null,
            ];
        }

        return [
            'key' => 'redis',
            'title' => 'Redis',
            'status' => 'fail',
            'summary' => 'Not reachable',
            'detail' => 'Oblivion could not reach the Redis server used for real-time device queues, locks and replay protection.',
            'fix' => 'Start the Redis service on your server, confirm the Redis host, port and password in the server configuration, and make sure the PHP Redis extension is enabled.',
        ];
    }

    /** @return array<string, mixed> */
    private function checkScheduler(): array
    {
        $lastHeartbeat = $this->latestSchedulerHeartbeat();
        $ageSeconds = $lastHeartbeat !== null
            ? max(0, (int) $lastHeartbeat->diffInSeconds(now()))
            : null;

        if ($lastHeartbeat !== null && $ageSeconds !== null && $ageSeconds <= self::SCHEDULER_STALE_SECONDS) {
            return [
                'key' => 'scheduler',
                'title' => 'Scheduler',
                'status' => 'pass',
                'summary' => 'Running every minute',
                'detail' => sprintf('Last scheduler tick recorded %s.', $this->formatAge($ageSeconds)),
                'fix' => null,
            ];
        }

        $detail = $ageSeconds !== null
            ? sprintf('The last scheduler tick was %s (expected at least once every 5 minutes).', $this->formatAge($ageSeconds))
            : 'No scheduler tick has been recorded yet on this server.';

        return [
            'key' => 'scheduler',
            'title' => 'Scheduler',
            'status' => 'fail',
            'summary' => $ageSeconds !== null ? 'Behind schedule' : 'Not running',
            'detail' => $detail,
            'fix' => 'Add a system cron entry (Linux) or Task Scheduler job (Windows) that runs the Oblivion task scheduler every minute, then wait one minute and refresh this page.',
        ];
    }

    /** @return array<string, mixed> */
    private function checkWorkerPools(): array
    {
        $staleAfter = max(60, (int) config('monitoring.runtime.worker_heartbeat_stale_seconds', 180));
        $pools = [];

        $defaultHeartbeat = $this->parseCachedTimestamp(RecordDefaultWorkerHeartbeatJob::CACHE_KEY);
        $defaultAge = $defaultHeartbeat !== null
            ? max(0, (int) $defaultHeartbeat->diffInSeconds(now()))
            : null;
        $queueDriver = (string) config('queue.default', 'sync');

        $defaultRunning = $queueDriver !== 'sync'
            && $defaultAge !== null
            && $defaultAge <= max($staleAfter, self::SCHEDULER_STALE_SECONDS);

        $pools[] = [
            'key' => 'default',
            'label' => 'General background jobs',
            'queue' => 'default',
            'status' => $defaultRunning ? 'pass' : 'fail',
            'detail' => match (true) {
                $defaultRunning => sprintf('Running · heartbeat %s', $this->formatAge((int) $defaultAge)),
                $queueDriver === 'sync' => 'Queue is set to inline mode instead of a background worker',
                $defaultAge !== null => sprintf('Heartbeat delayed (%s)', $this->formatAge($defaultAge)),
                default => 'No recent worker heartbeat recorded',
            },
        ];

        $monitoringWorkers = $this->safeMonitoringWorkerStates();
        $configuredQueues = (array) config('monitoring.queues', []);

        foreach (self::MONITORING_POOL_LABELS as $component => $label) {
            $queueName = (string) ($configuredQueues[$component] ?? ('monitoring-'.$component));
            $worker = $monitoringWorkers[$component] ?? [
                'state' => 'not_observed',
                'heartbeat_age_seconds' => null,
                'dispatch_lag_seconds' => null,
            ];

            $isAvailable = ($worker['state'] ?? 'not_observed') === 'available';
            $age = isset($worker['heartbeat_age_seconds']) && is_int($worker['heartbeat_age_seconds'])
                ? max(0, $worker['heartbeat_age_seconds'])
                : null;

            $pools[] = [
                'key' => $component,
                'label' => $label,
                'queue' => $queueName,
                'status' => $isAvailable ? 'pass' : 'fail',
                'detail' => match ($worker['state'] ?? 'not_observed') {
                    'available' => $age !== null
                        ? sprintf('Running · heartbeat %s', $this->formatAge($age))
                        : 'Running',
                    'stale' => $age !== null
                        ? sprintf('Heartbeat delayed (%s)', $this->formatAge($age))
                        : 'Heartbeat delayed',
                    'unavailable' => 'Queued work is waiting with no active worker',
                    default => 'No recent worker heartbeat recorded',
                },
            ];
        }

        $passingPools = collect($pools)->where('status', 'pass')->count();
        $totalPools = count($pools);
        $failingLabels = collect($pools)
            ->where('status', 'fail')
            ->pluck('label')
            ->values()
            ->all();

        if ($passingPools === $totalPools) {
            return [
                'key' => 'worker_pools',
                'title' => 'Worker pools',
                'status' => 'pass',
                'summary' => sprintf('%d of %d pools running', $passingPools, $totalPools),
                'detail' => 'Every background worker pool consumed a recent heartbeat.',
                'fix' => null,
                'subitems' => $pools,
            ];
        }

        return [
            'key' => 'worker_pools',
            'title' => 'Worker pools',
            'status' => 'fail',
            'summary' => sprintf('%d of %d pools running', $passingPools, $totalPools),
            'detail' => sprintf(
                'Pools needing attention: %s.',
                implode(', ', $failingLabels),
            ),
            'fix' => 'Start or restart the background queue worker processes in Supervisor (Linux) or Windows Services so every pool listed below has an active worker, and ensure the task scheduler is running to send worker heartbeats.',
            'subitems' => $pools,
        ];
    }

    /** @return array<string, mixed> */
    private function checkPingPermission(bool $useCache): array
    {
        $probe = function (): array {
            $result = $this->icmpTransport->probeLoopback(1);

            return [
                'reachable' => $result->reachable,
                'latency_ms' => $result->latencyMs,
                'reason' => $result->reasonCode,
            ];
        };

        try {
            $outcome = $useCache
                ? Cache::remember('system:readiness:ping_loopback', 30, $probe)
                : $probe();
        } catch (Throwable) {
            $outcome = [
                'reachable' => false,
                'latency_ms' => null,
                'reason' => 'probe_unavailable',
            ];
        }

        if (($outcome['reachable'] ?? false) === true) {
            $latency = isset($outcome['latency_ms']) && is_numeric($outcome['latency_ms'])
                ? sprintf('%.1f ms', (float) $outcome['latency_ms'])
                : 'under 1 ms';

            return [
                'key' => 'ping_permission',
                'title' => 'Ping permission',
                'status' => 'pass',
                'summary' => 'Allowed',
                'detail' => sprintf('Local ping check succeeded (%s reply).', $latency),
                'fix' => null,
            ];
        }

        $reasonLabel = match ((string) ($outcome['reason'] ?? 'probe_unavailable')) {
            'probe_unavailable' => 'ping binary or raw/ICMP permission unavailable',
            'timeout' => 'local ping timed out',
            'unreachable' => 'local loopback reported unreachable',
            default => 'no valid echo reply received',
        };

        $fix = PHP_OS_FAMILY === 'Windows'
            ? 'Confirm Windows ping.exe can be run by the web server and worker service accounts and that local loopback ICMP traffic (127.0.0.1) is not blocked by Windows Firewall.'
            : 'Install the iputils-ping package on this Linux host and allow unprivileged ICMP echo sockets for the web and worker service group (sysctl net.ipv4.ping_group_range) or grant cap_net_raw on the ping binary.';

        return [
            'key' => 'ping_permission',
            'title' => 'Ping permission',
            'status' => 'fail',
            'summary' => 'Blocked or unavailable',
            'detail' => sprintf('This server could not complete a local ping test (%s).', $reasonLabel),
            'fix' => $fix,
        ];
    }

    /** @return array<string, mixed> */
    private function checkCollectorKeys(): array
    {
        $missing = [];

        $activeKeyId = config('monitoring.signing.active_key_id');
        $signingKeys = (array) config('monitoring.signing.keys', []);
        if (! is_string($activeKeyId) || $activeKeyId === '' || ! isset($signingKeys[$activeKeyId])) {
            $missing[] = 'runtime message signing key';
        }

        $collectorSecret = config('monitoring.collector.signing_secret_key');
        $decodedCollectorSecret = is_string($collectorSecret) ? base64_decode($collectorSecret, true) : false;
        if (! is_string($decodedCollectorSecret) || strlen($decodedCollectorSecret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            $missing[] = 'site hub configuration signing key';
        }

        $caCertPath = config('monitoring.collector.ca_certificate_path');
        $caKeyPath = config('monitoring.collector.ca_private_key_path');
        $caReadable = is_string($caCertPath) && $caCertPath !== '' && is_file($caCertPath) && is_readable($caCertPath)
            && is_string($caKeyPath) && $caKeyPath !== '' && is_file($caKeyPath) && is_readable($caKeyPath);

        if (! $caReadable) {
            $missing[] = 'site hub certificate authority files';
        }

        if ($missing === []) {
            return [
                'key' => 'collector_keys',
                'title' => 'Collector keys',
                'status' => 'pass',
                'summary' => 'Configured',
                'detail' => 'Runtime message signing key, site hub configuration signing key and certificate authority files are present and readable.',
                'fix' => null,
            ];
        }

        return [
            'key' => 'collector_keys',
            'title' => 'Collector keys',
            'status' => 'fail',
            'summary' => 'Missing or unreadable',
            'detail' => sprintf('Missing or unreadable on this server: %s.', implode(', ', $missing)),
            'fix' => 'Generate and configure the runtime signing key, the 64-byte Ed25519 site hub signing key, and readable certificate authority PEM files on the server so remote site hubs can enrol and verify signed work.',
        ];
    }

    /** @return array<string, mixed> */
    private function checkReleaseKey(): array
    {
        $publicKey = config('endpoint_management.release_public_key');
        $decoded = is_string($publicKey) && $publicKey !== ''
            ? base64_decode($publicKey, true)
            : false;

        $validKey = is_string($decoded)
            && strlen($decoded) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            && base64_encode($decoded) === $publicKey;

        if (! $validKey) {
            return [
                'key' => 'release_key',
                'title' => 'Release key',
                'status' => 'fail',
                'summary' => 'Not configured',
                'detail' => 'No valid 32-byte Ed25519 publisher public key is configured to verify agent and site hub installer releases.',
                'fix' => 'Configure the base64-encoded 32-byte Ed25519 release public key in the server configuration so Oblivion can verify signed Windows and Linux download manifests.',
            ];
        }

        $catalogueReady = false;
        try {
            EndpointReleaseCatalogue::configured()->catalogue();
            $catalogueReady = true;
        } catch (Throwable) {
            $catalogueReady = false;
        }

        return [
            'key' => 'release_key',
            'title' => 'Release key',
            'status' => 'pass',
            'summary' => 'Configured',
            'detail' => $catalogueReady
                ? 'Publisher public key is valid and a signed download manifest is verified on disk.'
                : 'Publisher public key is valid. No signed download manifest has been published to the release folder yet.',
            'fix' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function checkCredentialStore(): array
    {
        $driver = (string) config('monitoring.credentials.driver', 'database');

        if (! in_array($driver, ['database', 'vault'], true)) {
            return [
                'key' => 'credential_store',
                'title' => 'Credential store',
                'status' => 'fail',
                'summary' => 'Disabled',
                'detail' => 'Device password and API key storage is turned off on this server.',
                'fix' => 'Set the monitoring credential store to the encrypted Oblivion database store (default) or configure an HTTPS HashiCorp Vault server.',
            ];
        }

        $healthy = false;
        try {
            $healthy = app(SecretManagerRestoreProbe::class)->healthy();
        } catch (Throwable) {
            $healthy = false;
        }

        $driverName = $driver === 'vault'
            ? 'HashiCorp Vault'
            : 'Encrypted database store';

        if ($healthy) {
            return [
                'key' => 'credential_store',
                'title' => 'Credential store',
                'status' => 'pass',
                'summary' => $driverName.' ready',
                'detail' => $driver === 'vault'
                    ? 'HashiCorp Vault is reachable over HTTPS and ready to issue short-lived credential leases.'
                    : 'Device passwords and API keys are encrypted with the application key in the Oblivion database.',
                'fix' => null,
            ];
        }

        $fix = $driver === 'vault'
            ? 'Verify the HashiCorp Vault HTTPS address, token and unsealed status, or switch to the default encrypted database store if you do not run Vault.'
            : 'Run pending database migrations so the encrypted secrets table exists and confirm the application encryption key is set.';

        return [
            'key' => 'credential_store',
            'title' => 'Credential store',
            'status' => 'fail',
            'summary' => $driverName.' unavailable',
            'detail' => sprintf('%s did not pass its readiness check.', $driverName),
            'fix' => $fix,
        ];
    }

    private function latestSchedulerHeartbeat(): ?Carbon
    {
        $candidates = [];

        $cached = $this->parseCachedTimestamp(self::SCHEDULER_CACHE_KEY);
        if ($cached !== null) {
            $candidates[] = $cached;
        }

        try {
            $runtimeHeartbeat = MonitoringRuntimeHeartbeat::query()->max('last_dispatched_at');
            if ($runtimeHeartbeat !== null) {
                $candidates[] = Carbon::parse($runtimeHeartbeat);
            }
        } catch (Throwable) {
            // Ignore if table is not migrated yet.
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (Carbon $left, Carbon $right): int => $right->getTimestamp() <=> $left->getTimestamp());

        return $candidates[0];
    }

    private function parseCachedTimestamp(string $key): ?Carbon
    {
        try {
            $raw = Cache::get($key);

            return is_string($raw) && $raw !== '' ? Carbon::parse($raw) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, array{state: string, heartbeat_age_seconds: ?int, dispatch_lag_seconds: ?int}> */
    private function safeMonitoringWorkerStates(): array
    {
        try {
            return $this->runtimeHealth->workerStates();
        } catch (Throwable) {
            return [];
        }
    }

    private function driverLabel(string $driver): string
    {
        return match ($driver) {
            'redis' => 'Redis',
            'database' => 'Database',
            'sync' => 'Inline (sync)',
            'file' => 'Local file',
            'array' => 'In-memory',
            default => ucfirst(str_replace(['_', '-'], ' ', $driver)),
        };
    }

    private function formatAge(int $seconds): string
    {
        if ($seconds < 5) {
            return 'just now';
        }
        if ($seconds < 60) {
            return sprintf('%d s ago', $seconds);
        }
        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return $minutes === 1 ? '1 min ago' : sprintf('%d min ago', $minutes);
        }
        $hours = intdiv($minutes, 60);

        return $hours === 1 ? '1 hr ago' : sprintf('%d hr ago', $hours);
    }
}
