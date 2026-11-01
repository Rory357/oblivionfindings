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
        /** S&D Phase B (D21): the sensor gateways this hub relays for, as bcrypt hashes only. */
        public ?array $sensorRelay = null,
        /** S&D Phase E (E5): the encrypted relay (TLS on 8883): the same logins, plus this hub's certificate. */
        public ?array $sensorRelayTls = null,
        /** S&D Phase H: UniFi Access consoles this hub reads (doors, people, cards, events). */
        public ?array $unifiAccess = null,
    ) {}

    /** @return list<int> */
    public function allowedSiteIds(): array
    {
        $covered = $this->scope['covered_site_ids'] ?? [];
        $validCovered = is_array($covered)
            ? array_values(array_filter($covered, static fn (mixed $id): bool => is_int($id) && $id > 0))
            : [];

        return array_values(array_unique([$this->siteId, ...$validCovered]));
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        try {
            $scope = $payload['scope'] ?? null;
            if (! is_array($scope)) {
                throw new ConfigurationRejected('Configuration scope is missing.');
            }

            $version = self::positiveInt($payload, 'version');
            $siteId = self::positiveInt($payload, 'site_id');
            $coveredSiteIds = self::coveredSiteIds($scope['covered_site_ids'] ?? [], $siteId);
            $allowedSiteIds = array_values(array_unique([$siteId, ...$coveredSiteIds]));
            $sensorRelay = self::sensorRelay($payload['sensor_relay'] ?? null, $allowedSiteIds);
            $sensorRelayTls = self::sensorRelayTls($payload['sensor_relay_tls'] ?? null, $allowedSiteIds);
            $unifiAccess = self::unifiAccess($payload['unifi_access'] ?? null, $allowedSiteIds);
            $relays = $sensorRelay !== null || $sensorRelayTls !== null || $unifiAccess !== null;
            // A hub that only relays sensor readings has no network targets of its own.
            $relayOnly = $relays && ($scope['cidrs'] ?? null) === [];
            $cidrs = $relayOnly ? [] : self::stringList($scope['cidrs'] ?? null, 'network scope');
            $protocols = self::stringList($scope['protocols'] ?? null, 'protocol scope');
            if ($sensorRelay !== null && ! in_array('sensor_relay', $protocols, true)) {
                throw new ConfigurationRejected('Configuration sensor relay is outside the signed protocol scope.');
            }
            if ($sensorRelayTls !== null && ! in_array('sensor_relay_tls', $protocols, true)) {
                throw new ConfigurationRejected('Configuration encrypted sensor relay is outside the signed protocol scope.');
            }
            if ($unifiAccess !== null && ! in_array('unifi_access', $protocols, true)) {
                throw new ConfigurationRejected('Configuration UniFi Access is outside the signed protocol scope.');
            }
            $discoveryRuns = self::discoveryRuns($payload['discovery_runs'] ?? [], $version, $allowedSiteIds);
            $commands = self::commands($payload['commands'] ?? [], $version);
            $devices = $scope['devices'] ?? null;
            if (! is_array($devices) || (array_is_list($devices) && $devices !== []) || count($devices) > 4096
                || ($devices === [] && $discoveryRuns === [] && $commands === [] && ! $relays)) {
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
                || ($checks === [] && $discoveryRuns === [] && $commands === [] && ! $relays)) {
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
                    ...($coveredSiteIds === [] ? [] : ['covered_site_ids' => $coveredSiteIds]),
                ],
                checks: array_values($checks),
                discoveryRuns: $discoveryRuns,
                commands: $commands,
                sensorRelay: $sensorRelay,
                sensorRelayTls: $sensorRelayTls,
                unifiAccess: $unifiAccess,
            );
        } catch (ConfigurationRejected $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new ConfigurationRejected('Configuration payload is malformed.', previous: $exception);
        }
    }

    /**
     * @param  list<int>  $allowedSiteIds
     * @return array{listen_port: int, max_payload_bytes: int, logins: list<array{username: string, password_hash: string, site_id: int, topic_prefix: string}>}|null
     */
    public static function sensorRelay(mixed $value, array $allowedSiteIds): ?array
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value) || array_keys($value) !== ['listen_port', 'max_payload_bytes', 'logins']
            || ! is_int($value['listen_port']) || $value['listen_port'] < 1 || $value['listen_port'] > 65535
            || ! is_int($value['max_payload_bytes']) || $value['max_payload_bytes'] < 1024 || $value['max_payload_bytes'] > 65_536
            || ! is_array($value['logins']) || ! array_is_list($value['logins'])
            || $value['logins'] === [] || count($value['logins']) > 256) {
            throw new ConfigurationRejected('Configuration sensor relay is invalid.');
        }
        $usernames = [];
        foreach ($value['logins'] as $login) {
            if (! is_array($login) || array_keys($login) !== ['username', 'password_hash', 'site_id', 'topic_prefix']
                || ! is_string($login['username']) || preg_match('/\Agw-[a-z0-9]{12}\z/', $login['username']) !== 1
                || ! is_string($login['password_hash']) || preg_match('/\A\$2y\$\d{2}\$[.\/A-Za-z0-9]{53}\z/', $login['password_hash']) !== 1
                || ! is_int($login['site_id']) || ! in_array($login['site_id'], $allowedSiteIds, true)
                || $login['topic_prefix'] !== 'oblivion/'.$login['site_id'].'/'.$login['username'].'/'
                || isset($usernames[$login['username']])) {
                throw new ConfigurationRejected('Configuration sensor relay login is invalid.');
            }
            $usernames[$login['username']] = true;
        }

        return $value;
    }

    /**
     * S&D Phase E (E5): the encrypted relay. Port 8883 only, the same login rules as the plain relay,
     * and this hub's certificate with the sensors certificate that signed it.
     *
     * @param  list<int>  $allowedSiteIds
     * @return array{listen_port: int, max_payload_bytes: int, logins: list<array{username: string, password_hash: string, site_id: int, topic_prefix: string}>, certificate: string, ca_certificate: string}|null
     */
    public static function sensorRelayTls(mixed $value, array $allowedSiteIds): ?array
    {
        if ($value === null) {
            return null;
        }
        $pem = static fn (mixed $text): bool => is_string($text) && strlen($text) <= 8192
            && preg_match('/\A-----BEGIN CERTIFICATE-----\r?\n[A-Za-z0-9+\/=\r\n]+-----END CERTIFICATE-----\r?\n?\z/', $text) === 1;
        if (! is_array($value) || array_keys($value) !== ['listen_port', 'max_payload_bytes', 'logins', 'certificate', 'ca_certificate']
            || $value['listen_port'] !== 8883 || ! $pem($value['certificate']) || ! $pem($value['ca_certificate'])) {
            throw new ConfigurationRejected('Configuration encrypted sensor relay is invalid.');
        }
        self::sensorRelay([
            'listen_port' => $value['listen_port'],
            'max_payload_bytes' => $value['max_payload_bytes'],
            'logins' => $value['logins'],
        ], $allowedSiteIds);

        return $value;
    }

    /**
     * S&D Phase H: the UniFi Access consoles to read. Private addresses only, the console's own
     * ports, a trusted key or none (then the hub only reads the certificate and sends no token),
     * and a token sealed to this hub for exactly that console and address.
     *
     * @param  list<int>  $allowedSiteIds
     * @return array{consoles: list<array<string, mixed>>}|null
     */
    public static function unifiAccess(mixed $value, array $allowedSiteIds): ?array
    {
        if ($value === null) {
            return null;
        }
        $keys = ['console_id', 'site_id', 'address', 'port', 'trusted_pin', 'interval_seconds', 'events_since', 'credential_lease'];
        if (! is_array($value) || array_keys($value) !== ['consoles'] || ! is_array($value['consoles']) || ! array_is_list($value['consoles'])
            || $value['consoles'] === [] || count($value['consoles']) > 20) {
            throw new ConfigurationRejected('Configuration UniFi Access is invalid.');
        }
        foreach ($value['consoles'] as $console) {
            $lease = is_array($console) ? ($console['credential_lease'] ?? null) : null;
            if (! is_array($console) || array_keys($console) !== $keys
                || ! is_string($console['console_id']) || preg_match('/\A[1-9][0-9]{0,18}\z/', $console['console_id']) !== 1
                || ! is_int($console['site_id']) || ! in_array($console['site_id'], $allowedSiteIds, true)
                || ! is_string($console['address']) || filter_var($console['address'], FILTER_VALIDATE_IP) === false
                || filter_var($console['address'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
                || ! in_array($console['port'], [443, 12445], true)
                || ($console['trusted_pin'] !== null && (! is_string($console['trusted_pin']) || preg_match('/\Asha256\/\/[A-Za-z0-9+\/]{43}=\z/', $console['trusted_pin']) !== 1))
                || ! is_int($console['interval_seconds']) || $console['interval_seconds'] < 60 || $console['interval_seconds'] > 3600
                || ! is_string($console['events_since']) || strlen($console['events_since']) > 40
                || ! is_array($lease) || ($lease['protocol'] ?? null) !== 'access.unifi' || ($lease['target'] ?? null) !== $console['address']
                || ($lease['device_id'] ?? null) !== 'access-console-'.$console['console_id'] || ($lease['site_id'] ?? null) !== $console['site_id']) {
                throw new ConfigurationRejected('Configuration UniFi Access console is invalid.');
            }
        }

        return $value;
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

    /** @return list<int> */
    private static function coveredSiteIds(mixed $value, int $homeSiteId): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 256) {
            throw new ConfigurationRejected('Configuration covered Sites are invalid.');
        }

        $ids = [];
        foreach ($value as $item) {
            if (! is_int($item) || $item < 1) {
                throw new ConfigurationRejected('Configuration covered Sites are invalid.');
            }
            if ($item !== $homeSiteId) {
                $ids[] = $item;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<int>  $allowedSiteIds
     * @return list<array<string, mixed>>
     */
    private static function discoveryRuns(mixed $value, int $version, array $allowedSiteIds): array
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
                'packets_per_second', 'targets', 'snmp_lease', 'unifi_lease',
            ];
            if (! is_array($run) || array_is_list($run) || array_diff(array_keys($run), $allowed) !== []) {
                throw new ConfigurationRejected('Configuration discovery run is invalid.');
            }
            $id = $run['id'] ?? null;
            $runSiteId = $run['site_id'] ?? null;
            if (! is_string($id)
                || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $id) !== 1
                || ! is_int($runSiteId)
                || ! in_array($runSiteId, $allowedSiteIds, true)) {
                throw new ConfigurationRejected('Configuration discovery identity is invalid.');
            }
            $cidrs = self::stringList($run['cidrs'] ?? null, 'discovery network scope');
            $protocols = self::stringList($run['protocols'] ?? null, 'discovery protocol scope');
            $exclusions = self::optionalStringList($run['exclusions'] ?? [], 'discovery exclusions', 1024);
            $ports = self::portBounds($run['port_bounds'] ?? []);
            $rate = $run['packets_per_second'] ?? null;
            $targets = $run['targets'] ?? null;
            $snmpLease = $run['snmp_lease'] ?? null;
            if ($snmpLease !== null && (! is_array($snmpLease) || array_is_list($snmpLease))) {
                throw new ConfigurationRejected('Configuration discovery SNMP lease is invalid.');
            }
            $unifiLease = $run['unifi_lease'] ?? null;
            if ($unifiLease !== null && (! is_array($unifiLease) || array_is_list($unifiLease))) {
                throw new ConfigurationRejected('Configuration discovery UniFi lease is invalid.');
            }
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
            $normalisedRun = [
                'id' => strtolower($id),
                'site_id' => $runSiteId,
                'cidrs' => $cidrs,
                'protocols' => $protocols,
                'exclusions' => $exclusions,
                'port_bounds' => $ports,
                'packets_per_second' => $rate,
                'targets' => $normalisedTargets,
            ];
            if (is_array($snmpLease)) {
                $normalisedRun['snmp_lease'] = $snmpLease;
            }
            if (is_array($unifiLease)) {
                $normalisedRun['unifi_lease'] = $unifiLease;
            }
            $runs[] = $normalisedRun;
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
        if (! is_array($value) || (array_is_list($value) && $value !== []) || count($value) > 16) {
            throw new ConfigurationRejected('Configuration discovery ports are invalid.');
        }
        $normalised = [];
        $count = 0;
        $allowedProtocols = [
            'tcp', 'dns', 'http', 'tls', 'snmp', 'mdns', 'ssdp', 'onvif', 'syslog', 'flow', 'provider',
        ];
        foreach ($value as $protocol => $ports) {
            if (! is_string($protocol)
                || ! in_array($protocol, $allowedProtocols, true)
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
