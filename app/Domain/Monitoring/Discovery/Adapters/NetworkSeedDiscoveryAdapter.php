<?php

namespace App\Domain\Monitoring\Discovery\Adapters;

use App\Domain\Monitoring\Adapters\IcmpProbeAdapter;
use App\Domain\Monitoring\Adapters\TcpProbeAdapter;
use App\Domain\Monitoring\Adapters\TlsProbeAdapter;
use App\Domain\Monitoring\Data\AuthorisedProbeContext;
use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\ProbeTarget;
use App\Domain\Monitoring\Discovery\Contracts\DiscoveryAdapter;
use App\Domain\Monitoring\Discovery\Contracts\DiscoveryThrottle;
use App\Domain\Monitoring\Discovery\Data\DiscoveredIdentity;
use App\Domain\Monitoring\Discovery\Data\DiscoveryProbeResult;
use App\Domain\Monitoring\Discovery\Data\DiscoveryTarget;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Discovery\Support\LocalLanDiscoveryScanner;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Exceptions\EgressDenied;
use App\Domain\Monitoring\Services\CidrMatcher;
use App\Domain\Monitoring\Services\EgressPolicy;
use Throwable;

final class NetworkSeedDiscoveryAdapter implements DiscoveryAdapter
{
    /** @var list<int> */
    private const array DEFAULT_DISCOVERY_TCP_PORTS = [
        22, 80, 161, 443, 515, 554, 631, 1883, 3389, 5000, 5001, 5985, 5986, 8000, 8080, 8443, 9100,
    ];

    public function __construct(
        private readonly CidrMatcher $cidrs,
        private readonly EgressPolicy $egress,
        private readonly IcmpProbeAdapter $icmp,
        private readonly TcpProbeAdapter $tcp,
        private readonly TlsProbeAdapter $tls,
        private readonly SnmpInventoryDiscoveryAdapter $snmp,
        private readonly DiscoveryThrottle $throttle,
        private readonly ?LocalLanDiscoveryScanner $lanScanner = null,
    ) {}

    public function begin(DiscoveryScope $scope): void
    {
        $this->throttle->reset((int) $scope->packets_per_second);
        if ($this->lanScanner !== null && $scope->collector_id === null) {
            $this->lanScanner->beginScope(
                approvedCidrs: array_values(array_filter($scope->cidrs ?? [], 'is_string')),
                protocols: array_values(array_filter($scope->protocols ?? [], 'is_string')),
                exclusions: array_values(array_filter($scope->exclusions ?? [], 'is_string')),
                packetsPerSecond: max(1, (int) $scope->packets_per_second),
                acquireToken: fn () => $this->throttle->acquire(),
            );
        }
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

        if ($this->lanScanner !== null) {
            return $this->discoverWithLanScanner($scope, $target, $base, $protocols, $allowedPorts);
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

    /**
     * @param  list<string>  $protocols
     * @param  list<int>  $allowedPorts
     */
    private function discoverWithLanScanner(
        DiscoveryScope $scope,
        DiscoveryTarget $target,
        AuthorizedProbeTarget $base,
        array $protocols,
        array $allowedPorts,
    ): DiscoveryProbeResult {
        $scanner = $this->lanScanner;
        if ($scanner === null) {
            return DiscoveryProbeResult::unresolved('no_response');
        }

        $reachable = false;
        $signals = [];
        $signalBag = [];
        $openPorts = [];
        $macAddresses = [];
        $certificateFingerprint = null;
        $snmpIdentity = null;
        $primaryIp = $base->addresses[0] ?? null;

        if ($primaryIp !== null) {
            if (in_array('arp', $protocols, true)) {
                $arpMac = $scanner->macForIp($primaryIp);
                if ($arpMac !== null) {
                    $reachable = true;
                    $macAddresses[] = $arpMac;
                    $signalBag['arp_seen'] = true;
                    $signals[] = 'arp_resolved';
                }
            }

            $multicast = $scanner->multicastSignalsForIp($primaryIp);
            if ($multicast !== []) {
                $reachable = true;
                $signalBag = array_merge($signalBag, $multicast);
                $signals[] = 'multicast_discovered';
            }
        }

        $skipSlowDeadL2Host = $primaryIp !== null
            && $scanner->isDirectlyAttachedSubnet()
            && in_array('arp', $protocols, true)
            && ! $reachable;

        if (in_array('icmp', $protocols, true) && ! $skipSlowDeadL2Host) {
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
            if ($observation->state === MonitorState::Healthy) {
                $reachable = true;
                $signalBag['icmp_reachable'] = true;
            }
        }

        if (in_array('tcp', $protocols, true)) {
            $configuredTcpPorts = $this->portsFor($scope, 'tcp', self::DEFAULT_DISCOVERY_TCP_PORTS);
            $probeHosts = $target->isAddress() ? $base->addresses : [$target->host];
            foreach ($probeHosts as $probeHost) {
                $authorisedPorts = [];
                $portsToCheck = $skipSlowDeadL2Host
                    ? array_values(array_intersect($configuredTcpPorts, [80, 443, 554]))
                    : $configuredTcpPorts;
                foreach ($portsToCheck as $port) {
                    try {
                        $this->egress->authoriseDiscovery(
                            (int) $scope->site_id,
                            array_values($scope->cidrs ?? []),
                            $allowedPorts,
                            ProbeTarget::tcp($probeHost, $port),
                        );
                        $authorisedPorts[] = $port;
                    } catch (Throwable) {
                        $signals[] = 'egress_denied';
                    }
                }
                if ($authorisedPorts !== []) {
                    $foundPorts = $scanner->probeTcpPorts(
                        $probeHost,
                        $authorisedPorts,
                        fn () => $this->throttle->acquire(),
                        $skipSlowDeadL2Host ? 0.12 : ($reachable ? 0.45 : 0.3),
                    );
                    if ($foundPorts !== []) {
                        $reachable = true;
                        $openPorts = array_values(array_unique([...$openPorts, ...$foundPorts]));
                        break;
                    }
                }
            }
        }

        if ($reachable
            && $macAddresses === []
            && $primaryIp !== null
            && in_array('arp', $protocols, true)
            && $scanner->isDirectlyAttachedSubnet()) {
            $refreshedMac = $scanner->macForIp($primaryIp, true);
            if ($refreshedMac !== null) {
                $macAddresses[] = $refreshedMac;
                $signalBag['arp_seen'] = true;
            }
        }

        if (in_array('tls', $protocols, true) && ($reachable || ! $skipSlowDeadL2Host)) {
            $tlsPorts = $this->portsFor($scope, 'tls', [443, 8443]);
            foreach ($tlsPorts as $port) {
                if (in_array('tcp', $protocols, true)
                    && in_array($port, $this->portsFor($scope, 'tcp', self::DEFAULT_DISCOVERY_TCP_PORTS), true)
                    && ! in_array($port, $openPorts, true)) {
                    continue;
                }
                foreach ($base->addresses as $address) {
                    try {
                        $this->egress->authoriseDiscovery(
                            (int) $scope->site_id,
                            array_values($scope->cidrs ?? []),
                            $allowedPorts,
                            ProbeTarget::tls($address, $port),
                        );
                    } catch (Throwable) {
                        continue;
                    }
                    $this->throttle->acquire();
                    $tlsInfo = $scanner->inspectTlsCertificate($address, $port, 0.75);
                    if ($tlsInfo !== []) {
                        $reachable = true;
                        $openPorts[] = $port;
                        if (is_string($tlsInfo['certificate_fingerprint'] ?? null) && $certificateFingerprint === null) {
                            $certificateFingerprint = $tlsInfo['certificate_fingerprint'];
                        }
                        if (is_string($tlsInfo['tls_common_name'] ?? null) && ! isset($signalBag['tls_common_name'])) {
                            $signalBag['tls_common_name'] = $tlsInfo['tls_common_name'];
                        }
                        if (is_string($tlsInfo['tls_organization'] ?? null) && ! isset($signalBag['tls_organization'])) {
                            $signalBag['tls_organization'] = $tlsInfo['tls_organization'];
                        }
                        break 2;
                    }
                }
            }
        }

        if (in_array('http', $protocols, true) && ($reachable || ! $skipSlowDeadL2Host)) {
            $httpCandidates = array_values(array_unique([
                ...$this->portsFor($scope, 'http', [80, 443, 8080, 8443]),
                ...array_values(array_intersect($openPorts, [80, 443, 5000, 5001, 8000, 8080, 8443])),
            ]));
            $inspectedHttp = 0;
            foreach ($httpCandidates as $port) {
                if ($inspectedHttp >= 2) {
                    break;
                }
                if (in_array('tcp', $protocols, true)
                    && in_array($port, $this->portsFor($scope, 'tcp', self::DEFAULT_DISCOVERY_TCP_PORTS), true)
                    && ! in_array($port, $openPorts, true)) {
                    continue;
                }
                foreach ($base->addresses as $address) {
                    try {
                        $scheme = in_array($port, [443, 5001, 8443], true) ? 'https' : 'http';
                        $hostPart = str_contains($address, ':') ? "[{$address}]" : $address;
                        $this->egress->authoriseDiscovery(
                            (int) $scope->site_id,
                            array_values($scope->cidrs ?? []),
                            $allowedPorts,
                            ProbeTarget::http("{$scheme}://{$hostPart}:{$port}/"),
                        );
                    } catch (Throwable) {
                        continue;
                    }
                    $this->throttle->acquire();
                    $banner = $scanner->inspectHttpBanner($address, $port, in_array($port, [443, 5001, 8443], true), 0.75);
                    if ($banner !== []) {
                        $reachable = true;
                        $openPorts[] = $port;
                        foreach ($banner as $key => $value) {
                            if (! isset($signalBag[$key])) {
                                $signalBag[$key] = $value;
                            }
                        }
                        $inspectedHttp++;
                        break;
                    }
                }
            }
        }

        if (in_array('snmp', $protocols, true) && ($reachable || ! $skipSlowDeadL2Host)) {
            $this->throttle->acquire();
            $snmpIdentity = $this->snmp->discover($scope, $target);
            if ($snmpIdentity !== null) {
                $reachable = true;
                $signals[] = 'snmp_inventory_collected';
                foreach ($snmpIdentity->signals as $key => $value) {
                    if (! isset($signalBag[$key])) {
                        $signalBag[$key] = $value;
                    }
                }
            }
        }

        $seedHostname = $target->isAddress() ? null : $target->host;
        if (! $reachable && in_array('dns', $protocols, true) && $seedHostname !== null && $base->addresses !== []) {
            $reachable = true;
            $signals[] = 'dns_seed_resolved';
        }

        if (! $reachable) {
            $capabilityPending = array_intersect($protocols, ['snmp', 'provider']) !== [];

            return DiscoveryProbeResult::unresolved($capabilityPending ? 'capability_pending' : 'no_response');
        }

        if (in_array('dns', $protocols, true) && $primaryIp !== null) {
            $this->throttle->acquire();
            $ptr = $scanner->reverseDnsPtr($primaryIp, 0.25);
            if ($ptr !== null) {
                $signalBag['ptr_hostname'] = $ptr;
            }
        }

        $localHost = $primaryIp !== null ? $scanner->localHostnameForIp($primaryIp) : null;

        $macAddresses = array_values(array_unique([
            ...$macAddresses,
            ...($snmpIdentity?->macAddresses ?? []),
        ]));
        if ($macAddresses !== []) {
            $vendor = $scanner->lookupMacVendor($macAddresses[0]);
            if ($vendor !== null) {
                $signalBag['mac_vendor'] = $vendor;
            } else {
                $hex = preg_replace('/[^0-9A-Fa-f]/', '', $macAddresses[0]) ?? '';
                if (strlen($hex) === 12 && ((hexdec(substr($hex, 0, 2)) & 0x02) !== 0)) {
                    $signalBag['locally_administered_mac'] = true;
                }
            }
        }

        $openPorts = array_values(array_unique($openPorts));
        sort($openPorts);
        if ($openPorts !== []) {
            $signalBag['open_ports'] = $openPorts;
        }

        $resolvedHostname = $this->selectBestHostname([
            $snmpIdentity?->hostname,
            $seedHostname,
            is_string($signalBag['ptr_hostname'] ?? null) ? $signalBag['ptr_hostname'] : null,
            is_string($signalBag['mdns_hostname'] ?? null) ? $signalBag['mdns_hostname'] : null,
            $localHost,
            is_string($signalBag['tls_common_name'] ?? null) ? $signalBag['tls_common_name'] : null,
        ]);

        $fingerprint = $openPorts !== []
            ? 'network:tcp:'.implode(',', $openPorts)
            : (in_array('reply', $signals, true)
                ? 'network:icmp'
                : (in_array('arp_resolved', $signals, true) ? 'network:arp' : 'network:multicast'));

        return DiscoveryProbeResult::found(new DiscoveredIdentity(
            provider: $snmpIdentity?->provider,
            providerId: $snmpIdentity?->providerId,
            serialNumber: $snmpIdentity?->serialNumber,
            hardwareId: $snmpIdentity?->hardwareId,
            macAddresses: $macAddresses,
            certificateFingerprint: $certificateFingerprint ?? $snmpIdentity?->certificateFingerprint,
            hostname: $resolvedHostname,
            addresses: array_values(array_unique([
                ...$base->addresses,
                ...($snmpIdentity?->addresses ?? []),
            ])),
            fingerprint: $snmpIdentity?->fingerprint ?? $fingerprint,
            signals: $signalBag,
        ));
    }

    /** @param list<?string> $candidates */
    private function selectBestHostname(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }
            $clean = trim(rtrim(trim($candidate), '.'));
            if ($clean === '' || strlen($clean) > 253 || filter_var($clean, FILTER_VALIDATE_IP) !== false) {
                continue;
            }
            if (preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(?:\.(?!-)[a-z0-9-]{1,63}(?<!-))*$/i', $clean) === 1) {
                return strtolower($clean);
            }
        }

        return null;
    }

    /** @return list<int> */
    private function allowedPorts(DiscoveryScope $scope): array
    {
        $protocols = $scope->protocols ?? [];
        $ports = collect($scope->port_bounds ?? [])->flatten()
            ->filter(fn (mixed $port): bool => is_int($port) && $port >= 1 && $port <= 65535)
            ->merge(in_array('tcp', $protocols, true) && ! isset(($scope->port_bounds ?? [])['tcp']) ? self::DEFAULT_DISCOVERY_TCP_PORTS : [])
            ->merge(in_array('dns', $protocols, true) ? [53] : [])
            ->merge(in_array('http', $protocols, true) ? [80, 443, 5000, 5001, 8000, 8080, 8443] : [])
            ->merge(in_array('tls', $protocols, true) ? [443, 8443] : [])
            ->merge(in_array('snmp', $protocols, true) ? [161] : [])
            ->merge(in_array('mdns', $protocols, true) ? [5353] : [])
            ->merge(in_array('ssdp', $protocols, true) ? [1900] : [])
            ->merge(in_array('onvif', $protocols, true) ? [3702] : [])
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
