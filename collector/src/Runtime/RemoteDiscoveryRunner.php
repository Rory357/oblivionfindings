<?php

namespace Oblivion\Collector\Runtime;

use Closure;
use DateTimeImmutable;
use Oblivion\Collector\Exceptions\ScopeViolation;
use Oblivion\Collector\Security\ScopeGuard;
use RuntimeException;
use Symfony\Component\Process\Process;

final class RemoteDiscoveryRunner
{
    private int $nextProbeAt = 0;

    /** @param null|Closure(array<string, mixed>, array{target: string, source: string}, list<string>, DateTimeImmutable): array<string, mixed> $probe */
    public function __construct(
        private readonly ScopeGuard $scope,
        private readonly ?Closure $probe = null,
    ) {}

    /**
     * @param  array<string, mixed>  $run
     * @param  list<string>  $completedIds
     * @return list<array{item_id: string, payload: array<string, mixed>}>
     */
    public function run(
        array $run,
        string $collectorId,
        array $completedIds = [],
        ?DateTimeImmutable $at = null,
    ): array {
        $at ??= new DateTimeImmutable('now');
        $this->scope->assertDiscoveryRun($run, $at);
        if ($collectorId === '' || strlen($collectorId) > 128) {
            throw new ScopeViolation('Collector discovery identity is invalid.');
        }

        $results = [];
        foreach ($run['targets'] as $target) {
            $itemId = hash('sha256', $collectorId.'|discovery|'.$run['id'].'|'.$target['target']);
            if (in_array($itemId, $completedIds, true)) {
                continue;
            }
            $addresses = $this->addresses($run, $target['target'], $at);
            if ($addresses === []) {
                $result = [
                    'outcome' => 'unresolved',
                    'failure_code' => 'dns_resolution_failed',
                    'identity' => null,
                ];
            } else {
                try {
                    $result = $this->probe === null
                        ? $this->probe($run, $target, $addresses, $at)
                        : ($this->probe)($run, $target, $addresses, $at);
                } catch (ScopeViolation $exception) {
                    throw $exception;
                } catch (\Throwable) {
                    $result = [
                        'outcome' => 'failed',
                        'failure_code' => 'adapter_failure',
                        'identity' => null,
                    ];
                }
            }
            $result = $this->normaliseResult($result, $addresses, $target['target']);
            $payload = [
                'item_type' => 'discovery_result',
                'run_id' => $run['id'],
                'target' => $target['target'],
                'observed_at' => $at->format(DATE_ATOM),
                'outcome' => $result['outcome'],
                'failure_code' => $result['failure_code'],
                'identity' => $result['identity'],
            ];
            if ($payload['outcome'] !== 'found') {
                unset($payload['identity']);
            } else {
                unset($payload['failure_code']);
            }
            $results[] = ['item_id' => $itemId, 'payload' => $payload];
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $run
     * @param  array{target: string, source: string}  $target
     * @param  list<string>  $addresses
     * @return array{outcome: string, failure_code: ?string, identity: ?array<string, mixed>}
     */
    private function probe(array $run, array $target, array $addresses, DateTimeImmutable $at): array
    {
        $protocols = array_values(array_intersect($run['protocols'], ['icmp', 'tcp', 'dns', 'http', 'tls']));
        if ($protocols === []) {
            return ['outcome' => 'unresolved', 'failure_code' => 'capability_pending', 'identity' => null];
        }

        $reachable = ! filter_var($target['target'], FILTER_VALIDATE_IP)
            && in_array('dns', $protocols, true);
        $signals = $reachable ? ['dns'] : [];
        $openPorts = [];

        if (in_array('icmp', $protocols, true)) {
            foreach ($addresses as $address) {
                $this->scope->assertDiscoveryConnection($run, $target['target'], $address, 'icmp', $at);
                $this->wait((int) $run['packets_per_second']);
                $arguments = PHP_OS_FAMILY === 'Windows'
                    ? ['ping', '-n', '1', '-w', '3000', $address]
                    : ['ping', '-c', '1', '-W', '3', $address];
                $process = new Process($arguments);
                $process->setTimeout(4);
                $process->run();
                if ($process->isSuccessful()) {
                    $reachable = true;
                    $signals[] = 'icmp';
                    break;
                }
            }
        }

        foreach ($this->ports($run) as [$protocol, $port]) {
            foreach ($addresses as $address) {
                $this->scope->assertDiscoveryConnection($run, $target['target'], $address, $protocol, $at);
                $this->wait((int) $run['packets_per_second']);
                $socket = @stream_socket_client(
                    'tcp://'.$this->socketAddress($address).':'.$port,
                    $errorNumber,
                    $errorMessage,
                    3,
                    STREAM_CLIENT_CONNECT,
                );
                if (is_resource($socket)) {
                    fclose($socket);
                    $reachable = true;
                    $signals[] = $protocol;
                    $openPorts[] = $port;
                    break;
                }
            }
        }

        if (! $reachable) {
            return ['outcome' => 'unresolved', 'failure_code' => 'no_response', 'identity' => null];
        }
        $signals = array_values(array_unique($signals));
        sort($signals);
        $openPorts = array_values(array_unique($openPorts));
        sort($openPorts);

        return [
            'outcome' => 'found',
            'failure_code' => null,
            'identity' => [
                'mac_addresses' => [],
                'certificate_fingerprint' => null,
                'hostname' => filter_var($target['target'], FILTER_VALIDATE_IP) === false
                    ? strtolower(rtrim($target['target'], '.'))
                    : null,
                'addresses' => $addresses,
                'fingerprint' => 'network:'.implode(',', $signals)
                    .($openPorts === [] ? '' : ':'.implode(',', $openPorts)),
            ],
        ];
    }

    /** @param array<string, mixed> $run @return list<array{string, int}> */
    private function ports(array $run): array
    {
        $ports = [];
        foreach (['tcp', 'http', 'tls'] as $protocol) {
            if (! in_array($protocol, $run['protocols'], true)) {
                continue;
            }
            $defaults = match ($protocol) {
                'http' => [80, 443],
                'tls' => [443],
                default => [],
            };
            foreach ($run['port_bounds'][$protocol] ?? $defaults as $port) {
                $ports[$protocol.':'.$port] = [$protocol, $port];
            }
        }

        return array_values($ports);
    }

    /** @param array<string, mixed> $run @return list<string> */
    private function addresses(array $run, string $target, DateTimeImmutable $at): array
    {
        if (filter_var($target, FILTER_VALIDATE_IP) !== false) {
            $addresses = [$target];
        } else {
            $records = @dns_get_record($target, DNS_A | DNS_AAAA);
            $addresses = [];
            foreach (is_array($records) ? array_slice($records, 0, 32) : [] as $record) {
                $address = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($address)) {
                    $addresses[] = $address;
                }
            }
        }

        $protocol = $run['protocols'][0];
        $authorised = [];
        foreach (array_values(array_unique($addresses)) as $address) {
            try {
                $this->scope->assertDiscoveryConnection($run, $target, $address, $protocol, $at);
                $authorised[] = $address;
            } catch (ScopeViolation) {
                // A hostname may resolve to mixed public/private answers. Only signed CIDR answers survive.
            }
        }

        return array_slice($authorised, 0, 16);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  list<string>  $authorisedAddresses
     * @return array{outcome: string, failure_code: ?string, identity: ?array<string, mixed>}
     */
    private function normaliseResult(array $result, array $authorisedAddresses, string $target): array
    {
        $outcome = $result['outcome'] ?? null;
        if (! in_array($outcome, ['found', 'failed', 'unresolved'], true)) {
            throw new RuntimeException('Collector discovery probe result is invalid.');
        }
        if ($outcome !== 'found') {
            $reason = $result['failure_code'] ?? null;
            if (! is_string($reason) || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $reason) !== 1) {
                throw new RuntimeException('Collector discovery probe result is invalid.');
            }

            return ['outcome' => $outcome, 'failure_code' => $reason, 'identity' => null];
        }

        $identity = $result['identity'] ?? null;
        $allowed = ['mac_addresses', 'certificate_fingerprint', 'hostname', 'addresses', 'fingerprint'];
        if (! is_array($identity) || array_is_list($identity)
            || array_diff(array_keys($identity), $allowed) !== []) {
            throw new RuntimeException('Collector discovery identity is invalid.');
        }
        $addresses = $identity['addresses'] ?? [];
        if (! is_array($addresses) || ! array_is_list($addresses) || $addresses === []
            || array_any($addresses, fn (mixed $address): bool => ! is_string($address)
                || ! in_array($address, $authorisedAddresses, true))) {
            throw new RuntimeException('Collector discovery identity addresses are outside the signed scope.');
        }
        $macs = $identity['mac_addresses'] ?? [];
        if (! is_array($macs) || ! array_is_list($macs) || count($macs) > 64
            || array_any($macs, fn (mixed $mac): bool => ! is_string($mac) || strlen($mac) > 64)) {
            throw new RuntimeException('Collector discovery identity is invalid.');
        }
        foreach (['certificate_fingerprint', 'hostname', 'fingerprint'] as $field) {
            $value = $identity[$field] ?? null;
            if ($value !== null && (! is_string($value) || $value === '' || strlen($value) > 2048)) {
                throw new RuntimeException('Collector discovery identity is invalid.');
            }
        }

        return [
            'outcome' => 'found',
            'failure_code' => null,
            'identity' => [
                'mac_addresses' => array_values($macs),
                'certificate_fingerprint' => $identity['certificate_fingerprint'] ?? null,
                'hostname' => $identity['hostname'] ?? (filter_var($target, FILTER_VALIDATE_IP) ? null : $target),
                'addresses' => array_values(array_unique($addresses)),
                'fingerprint' => $identity['fingerprint'] ?? null,
            ],
        ];
    }

    private function wait(int $packetsPerSecond): void
    {
        $interval = intdiv(1_000_000_000, max(1, min(1000, $packetsPerSecond)));
        $remaining = $this->nextProbeAt - hrtime(true);
        if ($remaining > 0) {
            usleep((int) ceil($remaining / 1000));
        }
        $this->nextProbeAt = hrtime(true) + $interval;
    }

    private function socketAddress(string $address): string
    {
        return str_contains($address, ':') ? "[{$address}]" : $address;
    }
}
