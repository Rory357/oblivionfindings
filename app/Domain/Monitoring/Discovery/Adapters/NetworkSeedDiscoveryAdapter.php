<?php

namespace App\Domain\Monitoring\Discovery\Adapters;

use App\Domain\Monitoring\Adapters\IcmpProbeAdapter;
use App\Domain\Monitoring\Adapters\TcpProbeAdapter;
use App\Domain\Monitoring\Adapters\TlsProbeAdapter;
use App\Domain\Monitoring\Data\AuthorisedProbeContext;
use App\Domain\Monitoring\Data\ProbeTarget;
use App\Domain\Monitoring\Discovery\Contracts\DiscoveryAdapter;
use App\Domain\Monitoring\Discovery\Contracts\DiscoveryThrottle;
use App\Domain\Monitoring\Discovery\Data\DiscoveredIdentity;
use App\Domain\Monitoring\Discovery\Data\DiscoveryProbeResult;
use App\Domain\Monitoring\Discovery\Data\DiscoveryTarget;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Exceptions\EgressDenied;
use App\Domain\Monitoring\Services\CidrMatcher;
use App\Domain\Monitoring\Services\EgressPolicy;
use Throwable;

final class NetworkSeedDiscoveryAdapter implements DiscoveryAdapter
{
    public function __construct(
        private readonly CidrMatcher $cidrs,
        private readonly EgressPolicy $egress,
        private readonly IcmpProbeAdapter $icmp,
        private readonly TcpProbeAdapter $tcp,
        private readonly TlsProbeAdapter $tls,
        private readonly SnmpInventoryDiscoveryAdapter $snmp,
        private readonly DiscoveryThrottle $throttle,
    ) {}

    public function begin(DiscoveryScope $scope): void
    {
        $this->throttle->reset((int) $scope->packets_per_second);
    }

    public function targets(DiscoveryScope $scope): iterable
    {
        $limit = max(0, min(65536, (int) $scope->max_targets_per_run));
        $seen = [];
        $count = 0;

        foreach ($scope->seed_hosts ?? [] as $seed) {
            if ($count >= $limit || ! is_string($seed)) {
                break;
            }
            $target = new DiscoveryTarget($seed, 'seed');
            if (isset($seen[$target->key()]) || $this->targetExcluded($scope, $target->host)) {
                continue;
            }
            $seen[$target->key()] = true;
            $count++;
            yield $target;
        }

        foreach ($scope->cidrs ?? [] as $cidr) {
            if ($count >= $limit || ! is_string($cidr)) {
                break;
            }
            foreach ($this->cidrs->expand($cidr, $limit - $count, $scope->exclusions ?? []) as $address) {
                $target = new DiscoveryTarget($address, 'cidr');
                if (isset($seen[$target->key()])) {
                    continue;
                }
                $seen[$target->key()] = true;
                $count++;
                yield $target;

                if ($count >= $limit) {
                    break 2;
                }
            }
        }
    }

    public function discover(DiscoveryScope $scope, DiscoveryTarget $target): DiscoveryProbeResult
    {
        if ($scope->collector_id !== null) {
            return DiscoveryProbeResult::failed('collector_runtime_pending');
        }

        $protocols = collect($scope->protocols ?? [])
            ->filter(fn (mixed $protocol): bool => is_string($protocol))
            ->map(fn (string $protocol): string => strtolower($protocol))
            ->unique()
            ->values()
            ->all();
        $allowedPorts = $this->allowedPorts($scope);

        try {
            $base = $this->egress->authoriseDiscovery(
                (int) $scope->site_id,
                array_values($scope->cidrs ?? []),
                $allowedPorts,
                ProbeTarget::icmp($target->host),
            );
        } catch (EgressDenied) {
            return DiscoveryProbeResult::failed('egress_denied');
        } catch (Throwable) {
            return DiscoveryProbeResult::failed('authorisation_failure');
        }

        if (collect($base->addresses)->contains(fn (string $address): bool => $this->targetExcluded($scope, $address))) {
            return DiscoveryProbeResult::excluded();
        }

        $reachable = false;
        $signals = [];
        $openPorts = [];
        $certificateFingerprint = null;
        $snmpIdentity = null;

        if (in_array('icmp', $protocols, true)) {
            $this->throttle->acquire();
            $observation = $this->icmp->probe(new AuthorisedProbeContext(
                monitorId: 0,
                siteId: (int) $scope->site_id,
                deviceId: 0,
                kind: MonitorKind::Icmp,
                target: $base,
                config: [],
            ));
            $signals[] = $observation->reasonCode;
            $reachable = $reachable || $observation->state === MonitorState::Healthy;
        }

        if (in_array('tcp', $protocols, true)) {
            foreach ($this->portsFor($scope, 'tcp') as $port) {
                $probeHosts = $target->isAddress() ? $base->addresses : [$target->host];
                foreach ($probeHosts as $probeHost) {
                    $this->throttle->acquire();
                    try {
                        $authorised = $this->egress->authoriseDiscovery(
                            (int) $scope->site_id,
                            array_values($scope->cidrs ?? []),
                            $allowedPorts,
                            ProbeTarget::tcp($probeHost, $port),
                        );
                    } catch (Throwable) {
                        $signals[] = 'egress_denied';

                        continue;
                    }
                    $observation = $this->tcp->probe(new AuthorisedProbeContext(
                        monitorId: 0,
                        siteId: (int) $scope->site_id,
                        deviceId: 0,
                        kind: MonitorKind::Tcp,
                        target: $authorised,
                        config: ['port' => $port],
                    ));
                    $signals[] = $observation->reasonCode;
                    if ($observation->state === MonitorState::Healthy) {
                        $reachable = true;
                        $openPorts[] = $port;
                        break;
                    }
                }
            }
        }

        if (in_array('tls', $protocols, true)) {
            foreach ($this->portsFor($scope, 'tls', [443]) as $port) {
                $probeHosts = $target->isAddress() ? $base->addresses : [$target->host];
                foreach ($probeHosts as $probeHost) {
                    $this->throttle->acquire();
                    try {
                        $authorised = $this->egress->authoriseDiscovery(
                            (int) $scope->site_id,
                            array_values($scope->cidrs ?? []),
                            $allowedPorts,
                            ProbeTarget::tls($probeHost, $port),
                        );
                    } catch (Throwable) {
                        $signals[] = 'egress_denied';

                        continue;
                    }
                    $observation = $this->tls->probe(new AuthorisedProbeContext(
                        monitorId: 0,
                        siteId: (int) $scope->site_id,
                        deviceId: 0,
                        kind: MonitorKind::Tls,
                        target: $authorised,
                        config: ['warn_days' => 30],
                    ));
                    $signals[] = $observation->reasonCode;
                    if (in_array($observation->state, [MonitorState::Healthy, MonitorState::Degraded], true)
                        || ($observation->state === MonitorState::Failed && $observation->latencyMs !== null)) {
                        $reachable = true;
                        $openPorts[] = $port;
                    }
                    $fingerprint = $observation->evidence['peer_fingerprint_sha256'] ?? null;
                    if (is_string($fingerprint) && preg_match('/^[a-f0-9]{64}$/', $fingerprint) === 1) {
                        $certificateFingerprint = $fingerprint;
                    }
                    if ($reachable) {
                        break;
                    }
                }
            }
        }

        if (in_array('snmp', $protocols, true)) {
            $this->throttle->acquire();
            $snmpIdentity = $this->snmp->discover($scope, $target);
            if ($snmpIdentity !== null) {
                $reachable = true;
                $signals[] = 'snmp_inventory_collected';
            }
        }

        $hostname = $target->isAddress() ? null : $target->host;
        if (! $reachable && in_array('dns', $protocols, true) && $hostname !== null && $base->addresses !== []) {
            $reachable = true;
            $signals[] = 'dns_seed_resolved';
        }

        if (! $reachable) {
            $capabilityPending = array_intersect($protocols, ['snmp', 'provider']) !== [];

            return DiscoveryProbeResult::unresolved($capabilityPending ? 'capability_pending' : 'no_response');
        }

        $openPorts = array_values(array_unique($openPorts));
        sort($openPorts);
        $fingerprint = $openPorts === []
            ? (in_array('reply', $signals, true) ? 'network:icmp' : null)
            : 'network:tcp:'.implode(',', $openPorts);

        return DiscoveryProbeResult::found(new DiscoveredIdentity(
            provider: $snmpIdentity?->provider,
            providerId: $snmpIdentity?->providerId,
            serialNumber: $snmpIdentity?->serialNumber,
            hardwareId: $snmpIdentity?->hardwareId,
            macAddresses: $snmpIdentity?->macAddresses ?? [],
            certificateFingerprint: $certificateFingerprint ?? $snmpIdentity?->certificateFingerprint,
            hostname: $snmpIdentity?->hostname ?? $hostname,
            addresses: array_values(array_unique([
                ...$base->addresses,
                ...($snmpIdentity?->addresses ?? []),
            ])),
            fingerprint: $snmpIdentity?->fingerprint ?? $fingerprint,
        ));
    }

    /** @return list<int> */
    private function allowedPorts(DiscoveryScope $scope): array
    {
        $ports = collect($scope->port_bounds ?? [])->flatten()
            ->filter(fn (mixed $port): bool => is_int($port) && $port >= 1 && $port <= 65535)
            ->merge(in_array('dns', $scope->protocols ?? [], true) ? [53] : [])
            ->merge(in_array('http', $scope->protocols ?? [], true) ? [80, 443] : [])
            ->merge(in_array('tls', $scope->protocols ?? [], true) ? [443] : [])
            ->merge(in_array('snmp', $scope->protocols ?? [], true) ? [161] : [])
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $ports === [] ? [1] : $ports;
    }

    /** @param list<int> $default @return list<int> */
    private function portsFor(DiscoveryScope $scope, string $kind, array $default = []): array
    {
        $ports = ($scope->port_bounds ?? [])[$kind] ?? $default;

        return is_array($ports)
            ? collect($ports)->filter(fn (mixed $port): bool => is_int($port))->unique()->sort()->values()->all()
            : [];
    }

    private function targetExcluded(DiscoveryScope $scope, string $target): bool
    {
        foreach ($scope->exclusions ?? [] as $exclusion) {
            if (! is_string($exclusion) || trim($exclusion) === '') {
                continue;
            }
            $exclusion = trim($exclusion);
            if (strtolower(rtrim($exclusion, '.')) === strtolower(rtrim($target, '.'))) {
                return true;
            }
            try {
                if (str_contains($exclusion, '/') && $this->cidrs->contains($exclusion, $target)) {
                    return true;
                }
                if (! str_contains($exclusion, '/')
                    && $this->cidrs->canonicalAddress($exclusion) === $this->cidrs->canonicalAddress($target)) {
                    return true;
                }
            } catch (Throwable) {
                // Hostname and MAC exclusions are handled before or after identity collection.
            }
        }

        return false;
    }
}
