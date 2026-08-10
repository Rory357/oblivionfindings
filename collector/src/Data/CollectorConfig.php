<?php

namespace Oblivion\Collector\Data;

use DateTimeImmutable;
use Oblivion\Collector\Exceptions\ConfigurationRejected;

final readonly class CollectorConfig
{
    /**
     * @param  array{cidrs: list<string>, devices: array<string, list<string>>, protocols: list<string>, rate_limits: array{max_checks_per_run: int, packets_per_second: int}}  $scope
     * @param  list<array<string, mixed>>  $checks
     * @param  list<array<string, mixed>>  $discoveryRuns
     * @param  list<array<string, mixed>>  $commands
     */
    public function __construct(
        public int $version,
        public string $collectorId,
        public int $siteId,
        public int $sequence,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $expiresAt,
        public bool $revoked,
        public array $scope,
        public array $checks,
        public array $discoveryRuns = [],
        public array $commands = [],
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        try {
            $scope = $payload['scope'] ?? null;
            if (! is_array($scope)) {
                throw new ConfigurationRejected('Configuration scope is missing.');
            }

            $cidrs = self::stringList($scope['cidrs'] ?? null, 'network scope');
            $protocols = self::stringList($scope['protocols'] ?? null, 'protocol scope');
            $version = self::positiveInt($payload, 'version');
            $siteId = self::positiveInt($payload, 'site_id');
            $discoveryRuns = self::discoveryRuns($payload['discovery_runs'] ?? [], $version, $siteId);
            $commands = self::commands($payload['commands'] ?? [], $version);
            $devices = $scope['devices'] ?? null;
            if (! is_array($devices) || (array_is_list($devices) && $devices !== []) || count($devices) > 4096
                || ($devices === [] && $discoveryRuns === [] && $commands === [])) {
                throw new ConfigurationRejected('Configuration Device scope is invalid.');
            }

            $normalisedDevices = [];
            foreach ($devices as $deviceId => $targets) {
                $deviceId = is_int($deviceId) && $deviceId > 0 ? (string) $deviceId : $deviceId;
                if (! is_string($deviceId) || $deviceId === '' || strlen($deviceId) > 128) {
                    throw new ConfigurationRejected('Configuration Device identity is invalid.');
                }
                $normalisedDevices[$deviceId] = self::stringList($targets, 'Device target scope');
            }

            $checks = $payload['checks'] ?? null;
            if (! is_array($checks) || ! array_is_list($checks) || count($checks) > 10_000
                || ($checks === [] && $discoveryRuns === [] && $commands === [])) {
                throw new ConfigurationRejected('Configuration checks are invalid.');
            }
            foreach ($checks as $check) {
                if (! is_array($check)) {
                    throw new ConfigurationRejected('Configuration check is invalid.');
                }
            }
            $rateLimits = $scope['rate_limits'] ?? [
                'max_checks_per_run' => max(1, count($checks)),
                'packets_per_second' => 1000,
            ];
            if (! is_array($rateLimits)
                || ! is_int($rateLimits['max_checks_per_run'] ?? null)
                || $rateLimits['max_checks_per_run'] < count($checks)
                || $rateLimits['max_checks_per_run'] > 10_000
                || ! is_int($rateLimits['packets_per_second'] ?? null)
                || $rateLimits['packets_per_second'] < 1
                || $rateLimits['packets_per_second'] > 1000) {
                throw new ConfigurationRejected('Configuration rate limit is invalid.');
            }

            $issuedAt = new DateTimeImmutable(self::requiredString($payload, 'issued_at'));
            $expiresAt = new DateTimeImmutable(self::requiredString($payload, 'expires_at'));

            return new self(
                version: $version,
                collectorId: self::requiredString($payload, 'collector_id'),
                siteId: $siteId,
                sequence: self::positiveInt($payload, 'sequence'),
                issuedAt: $issuedAt,
                expiresAt: $expiresAt,
                revoked: ($payload['revoked'] ?? null) === true,
                scope: [
                    'cidrs' => $cidrs,
                    'devices' => $normalisedDevices,
                    'protocols' => $protocols,
                    'rate_limits' => [
                        'max_checks_per_run' => $rateLimits['max_checks_per_run'],
                        'packets_per_second' => $rateLimits['packets_per_second'],
                    ],
                ],
                checks: array_values($checks),
                discoveryRuns: $discoveryRuns,
                commands: $commands,
            );
        } catch (ConfigurationRejected $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new ConfigurationRejected('Configuration payload is malformed.', previous: $exception);
        }
    }

    /** @return list<array<string, mixed>> */
    private static function commands(mixed $value, int $version): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 100) {
            throw new ConfigurationRejected('Configuration commands are invalid.');
        }
        if ($version < 3 && $value !== []) {
            throw new ConfigurationRejected('Configuration commands require contract version 3.');
        }
        foreach ($value as $command) {
            if (! is_array($command) || array_is_list($command)) {
                throw new ConfigurationRejected('Configuration command is invalid.');
            }
        }

        return array_values($value);
    }

    /** @param array<string, mixed> $payload */
    private static function requiredString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (! is_string($value) || $value === '' || strlen($value) > 512) {
            throw new ConfigurationRejected("Configuration {$key} is invalid.");
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private static function positiveInt(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;
        if (! is_int($value) || $value < 1) {
            throw new ConfigurationRejected("Configuration {$key} is invalid.");
        }

        return $value;
    }

    /** @return list<array<string, mixed>> */
    private static function discoveryRuns(mixed $value, int $version, int $siteId): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 16) {
            throw new ConfigurationRejected('Configuration discovery runs are invalid.');
        }
        if ($version === 1 && $value !== []) {
            throw new ConfigurationRejected('Configuration discovery runs require contract version 2.');
        }

        $runs = [];
        $targetCount = 0;
        foreach ($value as $run) {
            $allowed = [
                'id', 'site_id', 'cidrs', 'protocols', 'exclusions', 'port_bounds',
                'packets_per_second', 'targets',
            ];
            if (! is_array($run) || array_is_list($run) || array_diff(array_keys($run), $allowed) !== []) {
                throw new ConfigurationRejected('Configuration discovery run is invalid.');
            }
            $id = $run['id'] ?? null;
            if (! is_string($id)
                || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $id) !== 1
                || ($run['site_id'] ?? null) !== $siteId) {
                throw new ConfigurationRejected('Configuration discovery identity is invalid.');
            }
            $cidrs = self::stringList($run['cidrs'] ?? null, 'discovery network scope');
            $protocols = self::stringList($run['protocols'] ?? null, 'discovery protocol scope');
            $exclusions = self::optionalStringList($run['exclusions'] ?? [], 'discovery exclusions', 1024);
            $ports = self::portBounds($run['port_bounds'] ?? []);
            $rate = $run['packets_per_second'] ?? null;
            $targets = $run['targets'] ?? null;
            if (! is_int($rate) || $rate < 1 || $rate > 1000
                || ! is_array($targets) || ! array_is_list($targets) || $targets === [] || count($targets) > 512) {
                throw new ConfigurationRejected('Configuration discovery bounds are invalid.');
            }
            $normalisedTargets = [];
            foreach ($targets as $target) {
                if (! is_array($target) || array_is_list($target)
                    || count($target) !== 2 || array_diff(array_keys($target), ['target', 'source']) !== []
                    || ! is_string($target['target']) || $target['target'] === '' || strlen($target['target']) > 253
                    || ! in_array($target['source'] ?? null, ['seed', 'cidr'], true)) {
                    throw new ConfigurationRejected('Configuration discovery target is invalid.');
                }
                $normalisedTargets[] = $target;
            }
            $targetCount += count($normalisedTargets);
            if ($targetCount > 512) {
                throw new ConfigurationRejected('Configuration discovery target limit is exceeded.');
            }
            $runs[] = [
                'id' => strtolower($id),
                'site_id' => $siteId,
                'cidrs' => $cidrs,
                'protocols' => $protocols,
                'exclusions' => $exclusions,
                'port_bounds' => $ports,
                'packets_per_second' => $rate,
                'targets' => $normalisedTargets,
            ];
        }

        return $runs;
    }

    /** @return list<string> */
    private static function optionalStringList(mixed $value, string $label, int $maximum): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $maximum) {
            throw new ConfigurationRejected("Configuration {$label} is invalid.");
        }
        foreach ($value as $item) {
            if (! is_string($item) || $item === '' || strlen($item) > 2048) {
                throw new ConfigurationRejected("Configuration {$label} is invalid.");
            }
        }

        return array_values(array_unique($value));
    }

    /** @return array<string, list<int>> */
    private static function portBounds(mixed $value): array
    {
        if (! is_array($value) || (array_is_list($value) && $value !== []) || count($value) > 9) {
            throw new ConfigurationRejected('Configuration discovery ports are invalid.');
        }
        $normalised = [];
        $count = 0;
        foreach ($value as $protocol => $ports) {
            if (! is_string($protocol)
                || ! in_array($protocol, ['tcp', 'dns', 'http', 'tls', 'snmp', 'syslog', 'flow', 'provider'], true)
                || ! is_array($ports) || ! array_is_list($ports)) {
                throw new ConfigurationRejected('Configuration discovery ports are invalid.');
            }
            foreach ($ports as $port) {
                if (! is_int($port) || $port < 1 || $port > 65535 || ++$count > 128) {
                    throw new ConfigurationRejected('Configuration discovery ports are invalid.');
                }
            }
            $normalised[$protocol] = array_values(array_unique($ports));
        }

        return $normalised;
    }

    /** @return list<string> */
    private static function stringList(mixed $value, string $label): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === [] || count($value) > 4096) {
            throw new ConfigurationRejected("Configuration {$label} is invalid.");
        }

        foreach ($value as $item) {
            if (! is_string($item) || $item === '' || strlen($item) > 512) {
                throw new ConfigurationRejected("Configuration {$label} is invalid.");
            }
        }

        return array_values(array_unique($value));
    }
}
