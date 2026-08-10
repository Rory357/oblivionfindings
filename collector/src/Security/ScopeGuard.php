<?php

namespace Oblivion\Collector\Security;

use DateTimeImmutable;
use Oblivion\Collector\Data\CollectorConfig;
use Oblivion\Collector\Exceptions\ScopeViolation;

final readonly class ScopeGuard
{
    private const array SUPPORTED_PROTOCOLS = [
        'icmp', 'tcp', 'dns', 'http', 'https', 'tls', 'snmp', 'ssh', 'winrm',
    ];

    private const array CREDENTIALED_PROTOCOLS = ['snmp', 'ssh', 'winrm'];

    private const array DISCOVERY_PROTOCOLS = [
        'icmp', 'tcp', 'dns', 'http', 'tls', 'snmp', 'syslog', 'flow', 'provider',
    ];

    public function __construct(private CollectorConfig $config) {}

    public function assertCheck(array $check, DateTimeImmutable $at): void
    {
        foreach (['id', 'device_id', 'protocol', 'target'] as $field) {
            if (! isset($check[$field]) || ! is_string($check[$field]) || $check[$field] === '') {
                throw new ScopeViolation("Check {$field} is invalid.");
            }
        }

        if (strlen($check['id']) > 128 || strlen($check['device_id']) > 128) {
            throw new ScopeViolation('Check identity is invalid.');
        }

        $signed = array_find(
            $this->config->checks,
            fn (array $candidate): bool => ($candidate['id'] ?? null) === $check['id'],
        );
        if ($signed === null || $signed !== $check) {
            throw new ScopeViolation('Check is not present exactly in the signed configuration.');
        }

        $this->rejectExecutableFields($check);
        $this->assertTarget($check['device_id'], $check['protocol'], $check['target'], $at);

        if (in_array($check['protocol'], self::CREDENTIALED_PROTOCOLS, true)) {
            $lease = $check['credential_lease'] ?? null;
            if (! is_array($lease)) {
                throw new ScopeViolation('Credential lease is required for this protocol.');
            }
            $this->assertCredentialLease($lease, $check, $at);
        } elseif (array_key_exists('credential_lease', $check)) {
            throw new ScopeViolation('Credential lease is not valid for this protocol.');
        }
    }

    /** @param array<string, mixed> $command */
    public function assertCommand(array $command, DateTimeImmutable $at): void
    {
        if ($this->config->version < 3) {
            throw new ScopeViolation('Command work requires collector configuration version 3.');
        }
        $id = $command['attempt_uuid'] ?? null;
        $signed = is_string($id) ? array_find(
            $this->config->commands,
            fn (array $candidate): bool => ($candidate['attempt_uuid'] ?? null) === $id,
        ) : null;
        if ($signed === null || $signed !== $command) {
            throw new ScopeViolation('Command is not present exactly in the signed configuration.');
        }
        $allowed = [
            'command_uuid', 'attempt_uuid', 'attempt_number', 'site_id', 'device_id',
            'capability', 'provider', 'adapter', 'protocol', 'target', 'expires_at',
            'idempotency_hash', 'contract_hash', 'parameters', 'expected_state',
            'endpoint', 'credential_lease',
        ];
        if (array_diff(array_keys($command), $allowed) !== []
            || array_diff($allowed, array_keys($command)) !== []) {
            throw new ScopeViolation('Command contract fields are invalid.');
        }
        $uuid = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i';
        if (! is_string($command['command_uuid']) || preg_match($uuid, $command['command_uuid']) !== 1
            || ! is_string($command['attempt_uuid']) || preg_match($uuid, $command['attempt_uuid']) !== 1
            || ! is_int($command['attempt_number']) || $command['attempt_number'] < 1
            || $command['site_id'] !== $this->config->siteId
            || ! is_string($command['device_id']) || preg_match('/\A[1-9][0-9]{0,18}\z/', $command['device_id']) !== 1
            || $command['capability'] !== 'access.door.unlock_timed'
            || $command['provider'] !== 'unifi'
            || $command['adapter'] !== 'unifi_access_timed_unlock_v1'
            || $command['protocol'] !== 'command.unifi_access'
            || ! is_string($command['target'])
            || ! is_string($command['idempotency_hash']) || preg_match('/\A[0-9a-f]{64}\z/', $command['idempotency_hash']) !== 1
            || ! is_string($command['contract_hash']) || preg_match('/\A[0-9a-f]{64}\z/', $command['contract_hash']) !== 1) {
            throw new ScopeViolation('Command identity and typed adapter scope are invalid.');
        }
        try {
            $expiresAt = is_string($command['expires_at']) ? new DateTimeImmutable($command['expires_at']) : null;
        } catch (\Throwable) {
            $expiresAt = null;
        }
        if ($this->config->revoked || $this->config->expiresAt <= $at
            || $expiresAt === null || $expiresAt <= $at) {
            throw new ScopeViolation('Signed collector command is expired or revoked.');
        }
        $parameters = $command['parameters'];
        if (! is_array($parameters) || array_keys($parameters) !== ['duration_seconds']
            || ! is_int($parameters['duration_seconds'])
            || $parameters['duration_seconds'] < 5 || $parameters['duration_seconds'] > 60
            || $command['expected_state'] !== ['locked' => true]) {
            throw new ScopeViolation('Command parameters or expected state are invalid.');
        }
        $endpoint = $command['endpoint'];
        $endpointKeys = [
            'scheme', 'host', 'port', 'address', 'door_id', 'connect_timeout_seconds',
            'response_timeout_seconds', 'max_response_bytes',
        ];
        if (! is_array($endpoint) || array_diff(array_keys($endpoint), $endpointKeys) !== []
            || array_diff($endpointKeys, array_keys($endpoint)) !== []
            || $endpoint['scheme'] !== 'https'
            || ! is_string($endpoint['host']) || $endpoint['host'] === '' || strlen($endpoint['host']) > 253
            || preg_match('/[\x00-\x20\x7f\/@\\\\]/', $endpoint['host']) === 1
            || ! in_array($endpoint['port'], [443, 12445], true)
            || $endpoint['address'] !== $command['target']
            || ! is_string($endpoint['door_id']) || preg_match($uuid, $endpoint['door_id']) !== 1
            || ! is_int($endpoint['connect_timeout_seconds']) || $endpoint['connect_timeout_seconds'] < 1 || $endpoint['connect_timeout_seconds'] > 10
            || ! is_int($endpoint['response_timeout_seconds']) || $endpoint['response_timeout_seconds'] < 1 || $endpoint['response_timeout_seconds'] > 30
            || ! is_int($endpoint['max_response_bytes']) || $endpoint['max_response_bytes'] < 1024 || $endpoint['max_response_bytes'] > 65_536) {
            throw new ScopeViolation('Command endpoint is invalid or unbounded.');
        }

        $this->rejectExecutableFields($command);
        if (! in_array($command['protocol'], $this->config->scope['protocols'], true)) {
            throw new ScopeViolation('Command protocol is outside the signed scope.');
        }
        $this->assertScopedTarget($command['device_id'], $command['target']);
        if (! is_array($command['credential_lease'])) {
            throw new ScopeViolation('Credential lease is required for this command.');
        }
        $this->assertCredentialLease($command['credential_lease'], $command, $at);
    }

    /** @param array<string, mixed> $run */
    public function assertDiscoveryRun(array $run, DateTimeImmutable $at): void
    {
        if ($this->config->version < 2) {
            throw new ScopeViolation('Discovery work requires collector configuration version 2.');
        }
        $id = $run['id'] ?? null;
        $signed = is_string($id) ? array_find(
            $this->config->discoveryRuns,
            fn (array $candidate): bool => ($candidate['id'] ?? null) === $id,
        ) : null;
        if ($signed === null || $signed !== $run) {
            throw new ScopeViolation('Discovery run is not present exactly in the signed configuration.');
        }
        if ($this->config->revoked || $this->config->expiresAt <= $at
            || ($run['site_id'] ?? null) !== $this->config->siteId) {
            throw new ScopeViolation('Signed collector discovery configuration is expired revoked or Site-mismatched.');
        }
        $this->rejectExecutableFields($run);

        $protocols = $run['protocols'] ?? null;
        $cidrs = $run['cidrs'] ?? null;
        $targets = $run['targets'] ?? null;
        if (! is_array($protocols) || ! array_is_list($protocols) || $protocols === []
            || ! is_array($cidrs) || ! array_is_list($cidrs) || $cidrs === []
            || ! is_array($targets) || ! array_is_list($targets) || $targets === []) {
            throw new ScopeViolation('Discovery run scope is invalid.');
        }
        foreach ($protocols as $protocol) {
            if (! is_string($protocol)
                || ! in_array($protocol, self::DISCOVERY_PROTOCOLS, true)
                || ! in_array($protocol, $this->config->scope['protocols'], true)) {
                throw new ScopeViolation('Discovery protocol is outside the signed scope.');
            }
        }
        foreach ($cidrs as $cidr) {
            if (! is_string($cidr) || ! in_array($cidr, $this->config->scope['cidrs'], true)) {
                throw new ScopeViolation('Discovery network is outside the signed scope.');
            }
            $this->contains($cidr, explode('/', $cidr, 2)[0]);
        }
        $seen = [];
        foreach ($targets as $target) {
            $value = is_array($target) ? ($target['target'] ?? null) : null;
            if (! is_string($value) || $value === '' || isset($seen[strtolower($value)])) {
                throw new ScopeViolation('Discovery target is invalid or duplicated.');
            }
            if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
                if (! array_any($cidrs, fn (string $cidr): bool => $this->contains($cidr, $value))) {
                    throw new ScopeViolation('Discovery target is outside the signed network scope.');
                }
                foreach ($run['exclusions'] as $exclusion) {
                    if ((str_contains($exclusion, '/') && $this->contains($exclusion, $value))
                        || (filter_var($exclusion, FILTER_VALIDATE_IP) !== false
                            && inet_pton($exclusion) === inet_pton($value))) {
                        throw new ScopeViolation('Discovery target is excluded by signed policy.');
                    }
                }
            }
            $seen[strtolower($value)] = true;
        }
    }

    /** @param array<string, mixed> $run */
    public function assertDiscoveryConnection(
        array $run,
        string $target,
        string $address,
        string $protocol,
        DateTimeImmutable $at,
    ): void {
        $this->assertDiscoveryRun($run, $at);
        if (! in_array($protocol, $run['protocols'], true)
            || ! in_array($protocol, $this->config->scope['protocols'], true)) {
            throw new ScopeViolation('Discovery protocol is outside the signed run.');
        }
        if (filter_var($address, FILTER_VALIDATE_IP) === false
            || ! array_any($run['targets'], fn (array $candidate): bool => ($candidate['target'] ?? null) === $target)) {
            throw new ScopeViolation('Discovery connection target is outside the signed run.');
        }
        if (filter_var($target, FILTER_VALIDATE_IP) !== false
            && inet_pton($target) !== inet_pton($address)) {
            throw new ScopeViolation('Discovery connection address does not match its signed target.');
        }
        if (! array_any($run['cidrs'], fn (string $cidr): bool => $this->contains($cidr, $address))) {
            throw new ScopeViolation('Discovery connection address is outside the signed network scope.');
        }
        foreach ($run['exclusions'] as $exclusion) {
            if (strcasecmp(rtrim($exclusion, '.'), rtrim($target, '.')) === 0
                || (str_contains($exclusion, '/') && $this->contains($exclusion, $address))
                || (filter_var($exclusion, FILTER_VALIDATE_IP) !== false
                    && inet_pton($exclusion) === inet_pton($address))) {
                throw new ScopeViolation('Discovery connection is excluded by signed policy.');
            }
        }
    }

    public function assertTarget(string $deviceId, string $protocol, string $target, DateTimeImmutable $at): void
    {
        if ($this->config->revoked || $this->config->expiresAt <= $at) {
            throw new ScopeViolation('Signed collector configuration is expired or revoked.');
        }

        $protocol = strtolower($protocol);
        if (! in_array($protocol, self::SUPPORTED_PROTOCOLS, true)
            || ! in_array($protocol, $this->config->scope['protocols'], true)
        ) {
            throw new ScopeViolation('Check protocol is outside the signed scope.');
        }

        $this->assertScopedTarget($deviceId, $target);
    }

    private function assertScopedTarget(string $deviceId, string $target): void
    {
        if (filter_var($target, FILTER_VALIDATE_IP) === false) {
            throw new ScopeViolation('Target must be a pinned numeric address.');
        }

        $insideNetwork = false;
        foreach ($this->config->scope['cidrs'] as $cidr) {
            if ($this->contains($cidr, $target)) {
                $insideNetwork = true;
                break;
            }
        }
        if (! $insideNetwork) {
            throw new ScopeViolation('Target is outside the signed network scope.');
        }

        $deviceTargets = $this->config->scope['devices'][$deviceId] ?? null;
        if (! is_array($deviceTargets) || ! in_array($target, $deviceTargets, true)) {
            throw new ScopeViolation('Target is outside the signed Device scope.');
        }
    }

    /** @param array<string, mixed> $lease @param array<string, mixed> $check */
    private function assertCredentialLease(array $lease, array $check, DateTimeImmutable $at): void
    {
        $expected = [
            'collector_id' => $this->config->collectorId,
            'site_id' => $this->config->siteId,
            'device_id' => $check['device_id'],
            'protocol' => $check['protocol'],
            'target' => $check['target'],
        ];
        foreach ($expected as $field => $value) {
            if (($lease[$field] ?? null) !== $value) {
                throw new ScopeViolation('Credential lease scope does not match the signed check.');
            }
        }

        $expiresAt = $lease['expires_at'] ?? null;
        try {
            $expiry = is_string($expiresAt) ? new DateTimeImmutable($expiresAt) : null;
        } catch (\Throwable) {
            $expiry = null;
        }
        if ($expiry === null || $expiry <= $at) {
            throw new ScopeViolation('Credential lease is expired or invalid.');
        }

        if (($lease['version'] ?? null) !== 1 || array_key_exists('material', $lease)) {
            throw new ScopeViolation('Plaintext credential lease material is forbidden.');
        }
        $sealed = $lease['sealed_material'] ?? null;
        $decoded = is_string($sealed) ? base64_decode($sealed, true) : false;
        if (! is_string($decoded)
            || strlen($decoded) <= SODIUM_CRYPTO_BOX_SEALBYTES
            || strlen($decoded) > 1_100_000) {
            throw new ScopeViolation('Credential lease ciphertext is invalid.');
        }
    }

    /** @param array<string, mixed> $values */
    private function rejectExecutableFields(array $values): void
    {
        $forbidden = ['command', 'shell', 'script', 'executable', 'argv', 'powershell'];
        foreach ($values as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $forbidden, true)) {
                throw new ScopeViolation('Executable fields are forbidden.');
            }
            if (is_array($value)) {
                $this->rejectExecutableFields($value);
            }
        }
    }

    private function contains(string $cidr, string $address): bool
    {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2 || filter_var($parts[0], FILTER_VALIDATE_IP) === false || ! ctype_digit($parts[1])) {
            throw new ScopeViolation('Signed network scope contains an invalid CIDR.');
        }

        $network = inet_pton($parts[0]);
        $candidate = inet_pton($address);
        if ($network === false || $candidate === false || strlen($network) !== strlen($candidate)) {
            return false;
        }

        $prefix = (int) $parts[1];
        $maximum = strlen($network) * 8;
        if ($prefix < 0 || $prefix > $maximum) {
            throw new ScopeViolation('Signed network scope contains an invalid CIDR.');
        }

        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;
        if (substr($network, 0, $wholeBytes) !== substr($candidate, 0, $wholeBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($network[$wholeBytes]) & $mask) === (ord($candidate[$wholeBytes]) & $mask);
    }
}
