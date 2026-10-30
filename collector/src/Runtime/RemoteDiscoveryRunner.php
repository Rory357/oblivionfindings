<?php

namespace Oblivion\Collector\Runtime;

use Closure;
use DateTimeImmutable;
use Oblivion\Collector\Discovery\LanDiscoveryScanner;
use Oblivion\Collector\Exceptions\ScopeViolation;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\ScopeGuard;
use RuntimeException;
use Symfony\Component\Process\Process;

final class RemoteDiscoveryRunner
{
    /** @var list<int> */
    private const array DEFAULT_DISCOVERY_TCP_PORTS = [
        22, 80, 443, 445, 515, 554, 631, 1883, 3389, 5000, 5985, 8000, 8080, 8443, 8554, 9100,
    ];

    private int $nextProbeAt = 0;

    /** @param null|Closure(array<string, mixed>, array{target: string, source: string}, list<string>, DateTimeImmutable): array<string, mixed> $probe */
    public function __construct(
        private readonly ScopeGuard $scope,
        private readonly ?Closure $probe = null,
        private readonly ?CredentialLeaseDecryptor $credentials = null,
        private readonly ?LanDiscoveryScanner $scanner = null,
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

        $pending = [];
        foreach ($run['targets'] as $target) {
            $itemId = hash('sha256', $collectorId.'|discovery|'.$run['id'].'|'.$target['target']);
            if (in_array($itemId, $completedIds, true)) {
                continue;
            }
            $pending[] = [$itemId, $target];
        }
        if ($pending === []) {
            return [];
        }

        $scanner = null;
        $snmpMaterial = null;
        $unifiMaterial = null;
        $unifiTopology = ['by_mac' => [], 'by_ip' => []];

        try {
            if ($this->probe === null) {
                if (is_array($run['snmp_lease'] ?? null) && $this->credentials !== null) {
                    $snmpMaterial = $this->credentials->open(['credential_lease' => $run['snmp_lease']], $at);
                }
                if (is_array($run['unifi_lease'] ?? null) && $this->credentials !== null) {
                    $unifiMaterial = $this->credentials->open(['credential_lease' => $run['unifi_lease']], $at);
                }
                $scanner = $this->scanner ?? new LanDiscoveryScanner;
                $scanner->beginScope(
                    $run['cidrs'],
                    $run['protocols'],
                    $run['exclusions'] ?? [],
                    (int) $run['packets_per_second'],
                    fn () => $this->wait((int) $run['packets_per_second']),
                );

                if (is_array($unifiMaterial) && $unifiMaterial !== []) {
                    $controllerIp = trim((string) ($unifiMaterial['controller_ip'] ?? $scanner->defaultGatewayIp() ?? ''));
                    $controllerPort = is_numeric($unifiMaterial['port'] ?? null) ? (int) $unifiMaterial['port'] : 443;
                    if (filter_var($controllerIp, FILTER_VALIDATE_IP) !== false
                        && array_any($run['targets'], fn (array $t): bool => ($t['target'] ?? null) === $controllerIp)) {
                        $unifiProto = in_array('tls', $run['protocols'], true)
                            ? 'tls'
                            : (in_array('http', $run['protocols'], true) ? 'http' : 'provider');
                        $this->scope->assertDiscoveryConnection($run, $controllerIp, $controllerIp, $unifiProto, $at);
                        $this->wait((int) $run['packets_per_second']);
                        $unifiTopology = $scanner->probeUniFiTopology($controllerIp, $unifiMaterial, $controllerPort);
                    }
                }
            }

            $results = [];
            foreach ($pending as [$itemId, $target]) {
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
                            ? $this->probe($run, $target, $addresses, $at, $scanner ?? new LanDiscoveryScanner, $snmpMaterial, $unifiTopology)
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
        } finally {
            if (is_array($snmpMaterial)) {
                $this->destroyMaterial($snmpMaterial);
            }
            if (is_array($unifiMaterial)) {
                $this->destroyMaterial($unifiMaterial);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $run
     * @param  array{target: string, source: string}  $target
     * @param  list<string>  $addresses
     * @param  array<string, scalar|null>|null  $snmpMaterial
     * @param  array{by_mac: array<string, array<string, mixed>>, by_ip: array<string, array<string, mixed>>}  $unifiTopology
     * @return array{outcome: string, failure_code: ?string, identity: ?array<string, mixed>}
     */
    private function probe(
        array $run,
        array $target,
        array $addresses,
        DateTimeImmutable $at,
        LanDiscoveryScanner $scanner,
        ?array $snmpMaterial = null,
        array $unifiTopology = ['by_mac' => [], 'by_ip' => []],
    ): array {
        $protocols = array_values(array_intersect(
            $run['protocols'],
            ['icmp', 'arp', 'tcp', 'dns', 'http', 'tls', 'snmp', 'mdns', 'ssdp', 'onvif'],
        ));
        if ($protocols === []) {
            return ['outcome' => 'unresolved', 'failure_code' => 'capability_pending', 'identity' => null];
        }

        $rate = (int) $run['packets_per_second'];
        $reachable = ! filter_var($target['target'], FILTER_VALIDATE_IP)
            && in_array('dns', $protocols, true);
        $seenProtocols = $reachable ? ['dns'] : [];
        $openPorts = [];
        $macAddresses = [];
        $certificateFingerprint = null;
        $discoveredHostname = filter_var($target['target'], FILTER_VALIDATE_IP) === false
            ? strtolower(rtrim($target['target'], '.'))
            : null;
        $richSignals = [];

        // 1. Pre-swept L2 ARP & multicast discovery evidence (ONVIF, SSDP/UPnP, mDNS)
        foreach ($addresses as $address) {
            if (in_array('arp', $protocols, true)) {
                $mac = $scanner->macForIp($address);
                if ($mac !== null) {
                    $this->scope->assertDiscoveryConnection($run, $target['target'], $address, 'arp', $at);
                    $macAddresses[] = $mac;
                    $reachable = true;
                    $seenProtocols[] = 'arp';
                }
            }

            $mcast = $scanner->multicastSignalsForIp($address);
            if ($mcast !== []) {
                if (in_array('onvif', $protocols, true)
                    && (isset($mcast['onvif_types']) || isset($mcast['onvif_scopes']) || isset($mcast['onvif_hardware']) || isset($mcast['onvif_name']))) {
                    $this->scope->assertDiscoveryConnection($run, $target['target'], $address, 'onvif', $at);
                    foreach (['onvif_types', 'onvif_scopes', 'onvif_hardware', 'onvif_name'] as $k) {
                        if (isset($mcast[$k])) {
                            $richSignals[$k] = $mcast[$k];
                        }
                    }
                    $reachable = true;
                    $seenProtocols[] = 'onvif';
                }

                if (in_array('ssdp', $protocols, true)
                    && (isset($mcast['ssdp_server']) || isset($mcast['ssdp_st']) || isset($mcast['upnp_friendly_name']) || isset($mcast['upnp_manufacturer']) || isset($mcast['upnp_model_name']) || isset($mcast['upnp_device_type']))) {
                    $this->scope->assertDiscoveryConnection($run, $target['target'], $address, 'ssdp', $at);
                    foreach (['ssdp_server', 'ssdp_st', 'upnp_friendly_name', 'upnp_manufacturer', 'upnp_model_name', 'upnp_device_type'] as $k) {
                        if (isset($mcast[$k])) {
                            $richSignals[$k] = $mcast[$k];
                        }
                    }
                    $reachable = true;
                    $seenProtocols[] = 'ssdp';
                }

                if (in_array('mdns', $protocols, true)
                    && (isset($mcast['mdns_services']) || isset($mcast['mdns_hostname']) || isset($mcast['mdns_model']))) {
                    $this->scope->assertDiscoveryConnection($run, $target['target'], $address, 'mdns', $at);
                    foreach (['mdns_services', 'mdns_hostname', 'mdns_model'] as $k) {
                        if (isset($mcast[$k])) {
                            $richSignals[$k] = $mcast[$k];
                        }
                    }
                    if ($discoveredHostname === null && is_string($mcast['mdns_hostname'] ?? null)) {
                        $discoveredHostname = strtolower($mcast['mdns_hostname']);
                    }
                    $reachable = true;
                    $seenProtocols[] = 'mdns';
                }
            }
        }

        $attachedWithArp = $scanner->isDirectlyAttachedSubnet() && in_array('arp', $protocols, true);

        // 2. ICMP Echo verification (run on reachable ARP hosts or whenever ARP prewarm cannot prove L2 absence)
        if (in_array('icmp', $protocols, true) && ($reachable || ! $attachedWithArp)) {
            foreach ($addresses as $address) {
                $this->scope->assertDiscoveryConnection($run, $target['target'], $address, 'icmp', $at);
                $this->wait($rate);
                $ipv6 = str_contains($address, ':');
                $arguments = PHP_OS_FAMILY === 'Windows'
                    ? ['ping', ...($ipv6 ? ['-6'] : ['-4']), '-n', '1', '-w', '400', $address]
                    : ['ping', ...($ipv6 ? ['-6'] : []), '-n', '-c', '1', '-W', '1', $address];
                $process = new Process($arguments);
                $process->setTimeout(2);
                $process->run();
                if ($process->isSuccessful() && ProbeRunner::hasGenuineEchoReply($address, $process->getOutput())) {
                    $reachable = true;
                    $seenProtocols[] = 'icmp';
                    break;
                }
            }
        }

        // 3. Fast async TCP port sweep across approved TCP/HTTP/TLS ports
        $portMap = $this->portsByProtocol($run);
        if ($portMap !== []) {
            $allPorts = array_keys($portMap);
            $skipSlowDeadL2 = $attachedWithArp && ! $reachable;
            $portsToCheck = $skipSlowDeadL2
                ? array_values(array_intersect($allPorts, [80, 443, 554]))
                : $allPorts;
            $tcpTimeout = $skipSlowDeadL2 ? 0.08 : 0.35;
            if ($portsToCheck !== []) {
                foreach ($addresses as $address) {
                    foreach ($portsToCheck as $port) {
                        foreach ($portMap[$port] ?? ['tcp'] as $proto) {
                            $this->scope->assertDiscoveryConnection($run, $target['target'], $address, $proto, $at);
                        }
                    }
                    $foundPorts = $scanner->probeTcpPorts(
                        $address,
                        $portsToCheck,
                        fn () => $this->wait($rate),
                        $tcpTimeout,
                    );
                    if ($foundPorts !== []) {
                        $reachable = true;
                        foreach ($foundPorts as $port) {
                            $openPorts[] = $port;
                            foreach ($portMap[$port] ?? ['tcp'] as $proto) {
                                $seenProtocols[] = $proto;
                            }
                        }
                    }
                }
            }
        }

        // 4. SNMPv2c / SNMPv3 system-MIB probe (runs on reachable hosts, or all hosts when not L2 ARP-filtered)
        if (in_array('snmp', $protocols, true) && ($reachable || ! $attachedWithArp)) {
            $snmpPorts = $run['port_bounds']['snmp'] ?? [161];
            $snmpPort = (int) ($snmpPorts[0] ?? 161);
            foreach ($addresses as $address) {
                $this->scope->assertDiscoveryConnection($run, $target['target'], $address, 'snmp', $at);
                $this->wait($rate);
                $snmpSys = $scanner->probeSnmpSystem($address, $snmpMaterial, $snmpPort);
                if ($snmpSys !== null) {
                    $reachable = true;
                    $seenProtocols[] = 'snmp';
                    if ($snmpSys['sys_descr'] !== null) {
                        $richSignals['snmp_sys_descr'] = $snmpSys['sys_descr'];
                    }
                    if ($snmpSys['sys_object_id'] !== null) {
                        $richSignals['snmp_sys_object_id'] = $snmpSys['sys_object_id'];
                    }
                    if ($snmpSys['sys_name'] !== null) {
                        $richSignals['snmp_sys_name'] = $snmpSys['sys_name'];
                        if ($discoveredHostname === null) {
                            $discoveredHostname = strtolower($snmpSys['sys_name']);
                        }
                    }

                    $this->wait($rate);
                    $snmpTopo = $scanner->probeSnmpTopology($address, $snmpMaterial, $snmpPort);
                    foreach (['lldp_neighbors', 'cdp_neighbors', 'bridge_fdb', 'snmp_arp'] as $tk) {
                        if (isset($snmpTopo[$tk]) && $snmpTopo[$tk] !== []) {
                            $richSignals[$tk] = $snmpTopo[$tk];
                        }
                    }
                    break;
                }
            }
        }

        if (! $reachable) {
            return ['outcome' => 'unresolved', 'failure_code' => 'no_response', 'identity' => null];
        }

        $openPorts = array_values(array_unique($openPorts));
        sort($openPorts);
        $primaryIp = $addresses[0];

        // 5. Enrich reachable host with refreshed ARP + IEEE OUI MAC vendor, TLS cert, HTTP banner, and DNS PTR
        if (in_array('arp', $protocols, true) && $macAddresses === []) {
            $refreshedMac = $scanner->macForIp($primaryIp, allowRefresh: true);
            if ($refreshedMac !== null) {
                $macAddresses[] = $refreshedMac;
                $seenProtocols[] = 'arp';
            }
        }
        $macAddresses = array_values(array_unique($macAddresses));
        if ($macAddresses !== []) {
            $vendor = $scanner->lookupMacVendor($macAddresses[0]);
            if ($vendor !== null) {
                $richSignals['mac_vendor'] = $vendor;
            }
        }

        if (in_array('arp', $protocols, true)) {
            $gatewayIp = $scanner->defaultGatewayIp();
            if ($gatewayIp !== null) {
                if ($primaryIp === $gatewayIp) {
                    $richSignals['is_subnet_gateway'] = true;
                } else {
                    $richSignals['arp_gateway_ip'] = $gatewayIp;
                    $gatewayMac = $scanner->macForIp($gatewayIp);
                    if ($gatewayMac !== null) {
                        $richSignals['arp_gateway_mac'] = $gatewayMac;
                    }
                }
            }
        }

        $unifiUplink = ($macAddresses !== [] && isset($unifiTopology['by_mac'][$macAddresses[0]]))
            ? $unifiTopology['by_mac'][$macAddresses[0]]
            : ($unifiTopology['by_ip'][$primaryIp] ?? null);
        if (is_array($unifiUplink)) {
            $richSignals['unifi_uplink'] = $unifiUplink;
        }

        if (in_array('tls', $protocols, true)) {
            $tlsCandidates = array_values(array_intersect($openPorts, $run['port_bounds']['tls'] ?? [443, 8443]));
            if ($tlsCandidates === [] && in_array(443, $openPorts, true)) {
                $tlsCandidates = [443];
            }
            foreach (array_slice($tlsCandidates, 0, 2) as $tlsPort) {
                $this->scope->assertDiscoveryConnection($run, $target['target'], $primaryIp, 'tls', $at);
                $this->wait($rate);
                $tlsInfo = $scanner->inspectTlsCertificate($primaryIp, $tlsPort);
                if (isset($tlsInfo['certificate_fingerprint']) && $certificateFingerprint === null) {
                    $certificateFingerprint = $tlsInfo['certificate_fingerprint'];
                }
                if (isset($tlsInfo['tls_common_name']) && ! isset($richSignals['tls_common_name'])) {
                    $richSignals['tls_common_name'] = $tlsInfo['tls_common_name'];
                }
                if (isset($tlsInfo['tls_organization']) && ! isset($richSignals['tls_organization'])) {
                    $richSignals['tls_organization'] = $tlsInfo['tls_organization'];
                }
            }
        }

        if (in_array('http', $protocols, true)) {
            $webCandidates = array_values(array_intersect($openPorts, $run['port_bounds']['http'] ?? [80, 443, 8080, 8443, 8000, 5000]));
            foreach (array_slice($webCandidates, 0, 2) as $webPort) {
                $this->scope->assertDiscoveryConnection($run, $target['target'], $primaryIp, 'http', $at);
                $this->wait($rate);
                $isTlsPort = in_array($webPort, [443, 8443], true);
                $banner = $scanner->inspectHttpBanner($primaryIp, $webPort, $isTlsPort);
                foreach (['http_status', 'http_server', 'http_title', 'http_auth_realm'] as $bk) {
                    if (isset($banner[$bk]) && ! isset($richSignals[$bk])) {
                        $richSignals[$bk] = $banner[$bk];
                    }
                }
                if (isset($richSignals['http_title']) || isset($richSignals['http_server']) || isset($richSignals['http_auth_realm'])) {
                    break;
                }
            }
        }

        if ($discoveredHostname === null) {
            $localName = $scanner->localHostnameForIp($primaryIp);
            if ($localName !== null) {
                $discoveredHostname = strtolower($localName);
            } elseif (in_array('dns', $protocols, true)) {
                $this->scope->assertDiscoveryConnection($run, $target['target'], $primaryIp, 'dns', $at);
                $this->wait($rate);
                $ptr = $scanner->reverseDnsPtr($primaryIp);
                if ($ptr !== null) {
                    $discoveredHostname = strtolower($ptr);
                }
            }
        }

        $seenProtocols = array_values(array_unique($seenProtocols));
        sort($seenProtocols);

        if ($openPorts !== []) {
            $richSignals['open_ports'] = $openPorts;
        }
        if ($seenProtocols !== []) {
            $richSignals['protocols_seen'] = $seenProtocols;
        }

        $cleanSignals = LanDiscoveryScanner::sanitizeSignals($richSignals);

        $identity = [
            'mac_addresses' => $macAddresses,
            'certificate_fingerprint' => $certificateFingerprint,
            'hostname' => $discoveredHostname,
            'addresses' => $addresses,
            'fingerprint' => 'network:'.implode(',', $seenProtocols)
                .($openPorts === [] ? '' : ':'.implode(',', $openPorts)),
        ];
        if ($cleanSignals !== []) {
            $identity['signals'] = $cleanSignals;
        }

        return [
            'outcome' => 'found',
            'failure_code' => null,
            'identity' => $identity,
        ];
    }

    /**
     * @param  array<string, mixed>  $run
     * @return array<int, list<string>>
     */
    private function portsByProtocol(array $run): array
    {
        $ports = [];
        foreach (['tcp', 'http', 'tls'] as $protocol) {
            if (! in_array($protocol, $run['protocols'], true)) {
                continue;
            }
            $defaults = match ($protocol) {
                'tcp' => self::DEFAULT_DISCOVERY_TCP_PORTS,
                'http' => [80, 443],
                'tls' => [443],
            };
            foreach ($run['port_bounds'][$protocol] ?? $defaults as $port) {
                $ports[(int) $port] ??= [];
                if (! in_array($protocol, $ports[(int) $port], true)) {
                    $ports[(int) $port][] = $protocol;
                }
            }
        }

        return $ports;
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
        $allowed = ['mac_addresses', 'certificate_fingerprint', 'hostname', 'addresses', 'fingerprint', 'signals'];
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
        if (array_key_exists('signals', $identity) && (! is_array($identity['signals']) || array_is_list($identity['signals']))) {
            // Empty signals `[]` is allowed and normalised away; non-empty list is rejected.
            if ($identity['signals'] !== []) {
                throw new RuntimeException('Collector discovery identity is invalid.');
            }
        }
        $cleanSignals = isset($identity['signals']) && is_array($identity['signals'])
            ? LanDiscoveryScanner::sanitizeSignals($identity['signals'])
            : [];

        $normalisedIdentity = [
            'mac_addresses' => array_values($macs),
            'certificate_fingerprint' => $identity['certificate_fingerprint'] ?? null,
            'hostname' => $identity['hostname'] ?? (filter_var($target, FILTER_VALIDATE_IP) ? null : $target),
            'addresses' => array_values(array_unique($addresses)),
            'fingerprint' => $identity['fingerprint'] ?? null,
        ];
        if ($cleanSignals !== []) {
            $normalisedIdentity['signals'] = $cleanSignals;
        }

        return [
            'outcome' => 'found',
            'failure_code' => null,
            'identity' => $normalisedIdentity,
        ];
    }

    /** @param array<string, scalar|null> $material */
    private function destroyMaterial(#[\SensitiveParameter] array &$material): void
    {
        foreach ($material as &$value) {
            if (is_string($value) && $value !== '') {
                sodium_memzero($value);
            }
            $value = null;
        }
        unset($value);
        $material = [];
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
}
