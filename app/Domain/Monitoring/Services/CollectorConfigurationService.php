<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Discovery\Services\DiscoveryRunner;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandAttempt;
use App\Domain\SecurityDevices\Management\Services\UnifiAccessCollectorCommandDescriptor;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class CollectorConfigurationService
{
    public function __construct(
        private CanonicalDeviceSiteResolver $sites,
        private CredentialLeaseProvider $credentials,
        private CollectorCredentialLeaseSealer $credentialSealer,
        private DiscoveryRunner $discovery,
        private UnifiAccessCollectorCommandDescriptor $collectorCommands,
    ) {}

    public function signedEnvelope(MonitoringCollector $collector, int $afterSequence): string
    {
        if ($afterSequence < 0) {
            throw new DomainException('Collector configuration checkpoint is invalid.');
        }

        return DB::transaction(function () use ($collector, $afterSequence): string {
            $locked = MonitoringCollector::query()->whereKey($collector->id)->lockForUpdate()->firstOrFail();
            if ($locked->revoked_at !== null || $locked->status === 'revoked'
                || ! is_string($locked->public_key) || ! is_string($locked->client_certificate_fingerprint)) {
                throw new DomainException('Collector is unavailable.');
            }
            if ($afterSequence > (int) $locked->configuration_sequence) {
                throw new DomainException('Collector configuration checkpoint is ahead of central state.');
            }

            $checks = [];
            $commands = [];
            $devices = [];
            $cidrs = [];
            $protocols = [];
            $discoveryRuns = $this->discovery->collectorWork(
                $locked,
                max(1, min(4096, (int) config(
                    'monitoring.collector.max_discovery_targets_per_configuration',
                    512,
                ))),
            );
            foreach ($discoveryRuns as $run) {
                $cidrs = [...$cidrs, ...($run['cidrs'] ?? [])];
                $protocols = [...$protocols, ...($run['protocols'] ?? [])];
            }
            Monitor::query()
                ->with(['profile:id,is_active,interval_seconds', 'device:id'])
                ->where('collector_id', $locked->id)
                ->where('is_enabled', true)
                ->orderBy('id')
                ->get()
                ->each(function (Monitor $monitor) use ($locked, &$checks, &$devices, &$cidrs, &$protocols): void {
                    if ($monitor->profile === null || ! $monitor->profile->is_active) {
                        return;
                    }
                    try {
                        if ($this->sites->resolve((int) $monitor->device_id) !== (int) $locked->site_id) {
                            return;
                        }
                        $check = $this->check($locked, $monitor);
                    } catch (RuntimeException) {
                        return;
                    }
                    if ($check === null) {
                        return;
                    }
                    $deviceId = (string) $monitor->device_id;
                    $target = (string) $monitor->target;
                    $devices[$deviceId] ??= [];
                    $devices[$deviceId][] = $target;
                    $devices[$deviceId] = array_values(array_unique($devices[$deviceId]));
                    $cidrs[] = str_contains($target, ':') ? $target.'/128' : $target.'/32';
                    $protocols[] = $check['protocol'];
                    $checks[] = $check;
                });

            DeviceCommandAttempt::query()
                ->with(['request.device'])
                ->where('runtime', 'collector')
                ->whereIn('status', [
                    CommandAttemptStatus::Dispatching->value,
                    CommandAttemptStatus::Accepted->value,
                    CommandAttemptStatus::Running->value,
                ])
                ->whereHas('request', function ($query) use ($locked): void {
                    $query->where('collector_id', $locked->id)
                        ->whereIn('status', [
                            CommandStatus::Dispatching->value,
                            CommandStatus::Accepted->value,
                            CommandStatus::Running->value,
                        ]);
                })
                ->orderBy('id')
                ->limit(max(1, min(100, (int) config('monitoring.collector.max_commands_per_configuration', 100))))
                ->get()
                ->each(function (DeviceCommandAttempt $attempt) use (
                    $locked,
                    &$commands,
                    &$devices,
                    &$cidrs,
                    &$protocols,
                ): void {
                    $request = $attempt->request;
                    if ($request === null
                        || $request->expires_at?->isPast()
                        || (int) $request->site_id !== (int) $locked->site_id
                        || (int) $request->collector_id !== (int) $locked->id) {
                        return;
                    }
                    try {
                        $command = $this->collectorCommands->build($request, $attempt, $locked);
                    } catch (\Throwable) {
                        return;
                    }
                    $deviceId = (string) $request->device_id;
                    $target = (string) $command['target'];
                    $devices[$deviceId] ??= [];
                    $devices[$deviceId][] = $target;
                    $devices[$deviceId] = array_values(array_unique($devices[$deviceId]));
                    $cidrs[] = str_contains($target, ':') ? $target.'/128' : $target.'/32';
                    $protocols[] = (string) $command['protocol'];
                    $commands[] = $command;
                });

            if ($checks === [] && $discoveryRuns === [] && $commands === []) {
                throw new DomainException('Collector has no executable work in its approved Site scope.');
            }
            $maximumChecks = max(1, min(10_000, (int) config(
                'monitoring.collector.max_checks_per_configuration',
                10_000,
            )));
            if (count($checks) > $maximumChecks) {
                throw new DomainException('Collector configuration exceeds its bounded check limit.');
            }
            $sequence = (int) $locked->configuration_sequence + 1;
            $now = CarbonImmutable::now('UTC');
            $lifetime = max(60, min(3600, (int) config('monitoring.collector.configuration_lifetime_seconds', 600)));
            $payload = [
                'version' => $commands !== [] ? 3 : ($discoveryRuns === [] ? 1 : 2),
                'collector_id' => (string) $locked->collector_uuid,
                'site_id' => (int) $locked->site_id,
                'sequence' => $sequence,
                'issued_at' => $now->format(DATE_ATOM),
                'expires_at' => $now->addSeconds($lifetime)->format(DATE_ATOM),
                'revoked' => false,
                'scope' => [
                    'cidrs' => array_values(array_unique($cidrs)),
                    'devices' => $devices,
                    'protocols' => array_values(array_unique($protocols)),
                    'rate_limits' => [
                        'max_checks_per_run' => max(1, count($checks)),
                        'packets_per_second' => max(
                            1,
                            min(1000, (int) config('monitoring.collector.default_packets_per_second', 50)),
                        ),
                    ],
                ],
                'checks' => $checks,
            ];
            if ($discoveryRuns !== []) {
                $payload['discovery_runs'] = $discoveryRuns;
            }
            if ($commands !== []) {
                $payload['commands'] = $commands;
            }
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $secret = $this->signingSecretKey();
            $envelope = json_encode([
                'payload' => base64_encode($json),
                'signature' => base64_encode(sodium_crypto_sign_detached($json, $secret)),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $locked->forceFill(['configuration_sequence' => $sequence])->save();
            foreach ($commands as $command) {
                DeviceCommandAttempt::query()
                    ->where('attempt_uuid', $command['attempt_uuid'])
                    ->whereNull('provider_request_reference')
                    ->update([
                        'provider_request_reference' => "collector:{$locked->collector_uuid}:config:{$sequence}",
                    ]);
            }

            return $envelope;
        }, 3);
    }

    /** @return array<string, mixed>|null */
    private function check(MonitoringCollector $collector, Monitor $monitor): ?array
    {
        $target = trim((string) $monitor->target);
        if (filter_var($target, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $kind = $monitor->kind instanceof MonitorKind ? $monitor->kind : MonitorKind::tryFrom((string) $monitor->kind);
        $protocol = match ($kind) {
            MonitorKind::Icmp => 'icmp',
            MonitorKind::Tcp => 'tcp',
            MonitorKind::Dns => 'dns',
            MonitorKind::Http => $this->httpProtocol($monitor->config),
            MonitorKind::Tls => 'tls',
            MonitorKind::Snmp, MonitorKind::SnmpInterface => 'snmp',
            MonitorKind::SshInventory => 'ssh',
            MonitorKind::WinRmInventory => 'winrm',
            default => null,
        };
        if ($protocol === null) {
            return null;
        }
        $config = is_array($monitor->config) ? $monitor->config : [];
        $allowed = match ($protocol) {
            'icmp' => ['timeout_seconds'],
            'tcp' => ['port', 'timeout_seconds'],
            'dns' => ['query', 'record_type', 'port', 'timeout_seconds'],
            'http', 'https' => ['url', 'timeout_seconds'],
            'tls' => ['server_name', 'port', 'timeout_seconds'],
            'snmp' => ['timeout_seconds'],
            'ssh' => ['operation', 'timeout_seconds'],
            'winrm' => ['operation', 'port', 'timeout_seconds'],
            default => [],
        };
        $check = [
            'id' => (string) $monitor->id,
            'device_id' => (string) $monitor->device_id,
            'protocol' => $protocol,
            'target' => $target,
            'interval_seconds' => max(30, min(86_400, (int) $monitor->profile->interval_seconds)),
        ];
        foreach ($allowed as $key) {
            $value = $config[$key] ?? null;
            if (is_int($value) || is_string($value)) {
                $check[$key] = $value;
            }
        }

        if (in_array($protocol, ['snmp', 'ssh', 'winrm'], true)) {
            $reference = $config['credential_reference'] ?? null;
            if (! is_string($reference) || $reference === '' || strlen($reference) > 512) {
                return null;
            }
            $lease = $this->credentials->acquire(
                (int) $collector->site_id,
                $reference,
                ["monitoring.collector.{$protocol}.read"],
            );
            $check['credential_lease'] = $this->credentialSealer->seal(
                $collector,
                (string) $monitor->device_id,
                $protocol,
                $target,
                $lease,
            );
        }

        return $check;
    }

    /** @param array<string, mixed>|null $config */
    private function httpProtocol(?array $config): ?string
    {
        $scheme = strtolower((string) parse_url((string) ($config['url'] ?? ''), PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $scheme : null;
    }

    private function signingSecretKey(): string
    {
        $encoded = config('monitoring.collector.signing_secret_key');
        $decoded = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (! is_string($decoded) || strlen($decoded) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Collector configuration signing key is unavailable.');
        }

        return $decoded;
    }
}
