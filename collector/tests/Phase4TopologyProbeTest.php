<?php

use Oblivion\Collector\Data\CollectorConfig;
use Oblivion\Collector\Discovery\LanDiscoveryScanner;
use Oblivion\Collector\Discovery\SnmpTopologyProbe;
use Oblivion\Collector\Discovery\UniFiLocalTopologyProbe;
use Oblivion\Collector\Exceptions\ConfigurationRejected;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\ScopeGuard;

/**
 * Helper to encode ASN.1 BER TLVs for SNMPv2c GetResponse-PDU fixtures.
 */
function phase4BerLen(int $n): string
{
    if ($n < 128) {
        return chr($n);
    }
    if ($n < 256) {
        return "\x81".chr($n);
    }

    return "\x82".chr(($n >> 8) & 0xFF).chr($n & 0xFF);
}

function phase4BerTlv(int $tag, string $body): string
{
    return chr($tag).phase4BerLen(strlen($body)).$body;
}

function phase4BerInt(int $value): string
{
    if ($value >= 0 && $value < 128) {
        return "\x02\x01".chr($value);
    }

    $bytes = '';
    $v = $value;
    while ($v > 0) {
        $bytes = chr($v & 0xFF).$bytes;
        $v >>= 8;
    }
    if ((ord($bytes[0]) & 0x80) !== 0) {
        $bytes = "\x00".$bytes;
    }

    return phase4BerTlv(0x02, $bytes);
}

function phase4BerOid(string $dotted): string
{
    $parts = array_map('intval', explode('.', $dotted));
    $bytes = chr($parts[0] * 40 + $parts[1]);
    for ($i = 2, $c = count($parts); $i < $c; $i++) {
        $v = $parts[$i];
        $enc = chr($v & 0x7F);
        while (($v >>= 7) > 0) {
            $enc = chr(($v & 0x7F) | 0x80).$enc;
        }
        $bytes .= $enc;
    }

    return phase4BerTlv(0x06, $bytes);
}

function phase4ReadTlv(string $buf, int &$pos): array
{
    $tag = ord($buf[$pos++]);
    $lb = ord($buf[$pos++]);
    if (($lb & 0x80) === 0) {
        $len = $lb;
    } else {
        $nb = $lb & 0x7F;
        $len = 0;
        for ($i = 0; $i < $nb; $i++) {
            $len = ($len << 8) | ord($buf[$pos++]);
        }
    }
    $val = substr($buf, $pos, $len);
    $pos += $len;

    return ['tag' => $tag, 'val' => $val, 'raw' => chr($tag).phase4BerLen($len).$val];
}

function phase4CompareOid(string $a, string $b): int
{
    $pa = array_map('intval', explode('.', $a));
    $pb = array_map('intval', explode('.', $b));
    $n = min(count($pa), count($pb));
    for ($i = 0; $i < $n; $i++) {
        if ($pa[$i] !== $pb[$i]) {
            return $pa[$i] <=> $pb[$i];
        }
    }

    return count($pa) <=> count($pb);
}

/**
 * Extract request-id TLV and requested OID string from an outbound SNMPv2c GetNextRequest packet.
 *
 * @return array{req_id_tlv: string, oid: string}
 */
function phase4ParseSnmpV2Request(string $packet): array
{
    $pos = 0;
    $msg = phase4ReadTlv($packet, $pos);
    expect($msg['tag'])->toBe(0x30);

    $mPos = 0;
    phase4ReadTlv($msg['val'], $mPos); // version
    phase4ReadTlv($msg['val'], $mPos); // community
    $pdu = phase4ReadTlv($msg['val'], $mPos);
    expect(in_array($pdu['tag'], [0xA0, 0xA1], true))->toBeTrue();

    $pPos = 0;
    $reqId = phase4ReadTlv($pdu['val'], $pPos);
    phase4ReadTlv($pdu['val'], $pPos); // error-status
    phase4ReadTlv($pdu['val'], $pPos); // error-index
    $vbl = phase4ReadTlv($pdu['val'], $pPos);

    $vblPos = 0;
    $vb = phase4ReadTlv($vbl['val'], $vblPos);
    $vbPos = 0;
    $oidTlv = phase4ReadTlv($vb['val'], $vbPos);
    expect($oidTlv['tag'])->toBe(0x06);

    $oidBytes = $oidTlv['val'];
    $first = ord($oidBytes[0]);
    $arcs = [intdiv($first, 40), $first % 40];
    $acc = 0;
    for ($i = 1, $n = strlen($oidBytes); $i < $n; $i++) {
        $b = ord($oidBytes[$i]);
        $acc = ($acc << 7) | ($b & 0x7F);
        if (($b & 0x80) === 0) {
            $arcs[] = $acc;
            $acc = 0;
        }
    }

    return [
        'req_id_tlv' => $reqId['raw'],
        'oid' => implode('.', $arcs),
    ];
}

function phase4BuildSnmpV2Response(string $reqIdTlv, string $oid, string $valueTlv): string
{
    $vb = phase4BerTlv(0x30, phase4BerOid($oid).$valueTlv);
    $vbl = phase4BerTlv(0x30, $vb);
    $pdu = phase4BerTlv(0xA2, $reqIdTlv."\x02\x01\x00\x02\x01\x00".$vbl);

    return phase4BerTlv(0x30, "\x02\x01\x01".phase4BerTlv(0x04, 'public').$pdu);
}

it('walks SNMPv2c LLDP, CDP, Bridge FDB, and ARP subtrees including binary control-byte MACs and IPv4s', function (): void {
    // Ordered MIB table entries (strictly lexicographic within each subtree)
    $mib = [
        // IF-MIB::ifName
        SnmpTopologyProbe::OID_IF_NAME.'.2' => phase4BerTlv(0x04, 'Gi1/0/2'),
        SnmpTopologyProbe::OID_IF_NAME.'.4' => phase4BerTlv(0x04, 'Gi1/0/4'),

        // LLDP local port id + remote table (binary MAC contains 0x00 and 0x11 control bytes!)
        SnmpTopologyProbe::OID_LLDP_LOC_PORT_ID.'.2' => phase4BerTlv(0x04, 'Gi1/0/2'),
        SnmpTopologyProbe::OID_LLDP_REM_CHASSIS_SUBTYPE.'.0.2.1' => phase4BerInt(4),
        SnmpTopologyProbe::OID_LLDP_REM_CHASSIS_ID.'.0.2.1' => phase4BerTlv(0x04, "\x24\x5a\x4c\x00\x11\x22"),
        SnmpTopologyProbe::OID_LLDP_REM_PORT_ID.'.0.2.1' => phase4BerTlv(0x04, 'eth0'),
        SnmpTopologyProbe::OID_LLDP_REM_SYS_NAME.'.0.2.1' => phase4BerTlv(0x04, 'harbour-ap-01'),

        // Cisco CDP cache (binary IPv4 172.16.0.5 contains 0x10 and 0x00,0x05 control bytes!)
        SnmpTopologyProbe::OID_CDP_CACHE_ADDRESS.'.4.1' => phase4BerTlv(0x04, "\xac\x10\x00\x05"),
        SnmpTopologyProbe::OID_CDP_CACHE_DEVICE_ID.'.4.1' => phase4BerTlv(0x04, 'core-sw-02.local'),
        SnmpTopologyProbe::OID_CDP_CACHE_DEVICE_PORT.'.4.1' => phase4BerTlv(0x04, 'TenGigabitEthernet1/1'),
        SnmpTopologyProbe::OID_CDP_CACHE_PLATFORM.'.4.1' => phase4BerTlv(0x04, 'cisco C9200L'),

        // BRIDGE-MIB dot1dBasePortIfIndex + dot1dTpFdbPort (68.25.182.10.11.12 => 44:19:b6:0a:0b:0c)
        SnmpTopologyProbe::OID_DOT1D_BASE_PORT_IFINDEX.'.4' => phase4BerInt(4),
        SnmpTopologyProbe::OID_DOT1D_TP_FDB_PORT.'.68.25.182.10.11.12' => phase4BerInt(4),

        // IP-MIB ARP table
        SnmpTopologyProbe::OID_IP_NET_TO_MEDIA_PHYS.'.1.172.16.0.1' => phase4BerTlv(0x04, "\x70\xa7\x41\x11\x22\x33"),
    ];

    $orderedOids = array_keys($mib);
    usort($orderedOids, 'phase4CompareOid');

    $probe = new SnmpTopologyProbe(function (string $ip, int $port, string $packet) use ($mib, $orderedOids): string {
        $parsed = phase4ParseSnmpV2Request($packet);
        $requested = $parsed['oid'];

        foreach ($orderedOids as $candidate) {
            if (phase4CompareOid($candidate, $requested) > 0) {
                return phase4BuildSnmpV2Response($parsed['req_id_tlv'], $candidate, $mib[$candidate]);
            }
        }

        // Past the end of MIB: return endOfMibView (0x82 0x00)
        return phase4BuildSnmpV2Response($parsed['req_id_tlv'], $requested, "\x82\x00");
    });

    $result = $probe->probeTopology('172.16.0.2', ['community' => 'public']);

    expect($result)->toHaveKeys(['lldp_neighbors', 'cdp_neighbors', 'bridge_fdb', 'snmp_arp'])
        ->and($result['lldp_neighbors'][0])->toMatchArray([
            'local_port' => 'Gi1/0/2',
            'remote_mac' => '24:5A:4C:00:11:22',
            'remote_port' => 'eth0',
            'remote_name' => 'harbour-ap-01',
        ])
        ->and($result['cdp_neighbors'][0])->toMatchArray([
            'local_port' => 'Gi1/0/4',
            'remote_ip' => '172.16.0.5',
            'remote_port' => 'TenGigabitEthernet1/1',
            'remote_name' => 'core-sw-02.local',
            'platform' => 'cisco C9200L',
        ])
        ->and($result['bridge_fdb'][0])->toMatchArray([
            'port' => 'Gi1/0/4',
            'mac' => '44:19:B6:0A:0B:0C',
        ])
        ->and($result['snmp_arp'][0])->toMatchArray([
            'ip' => '172.16.0.1',
            'mac' => '70:A7:41:11:22:33',
        ]);
});

it('terminates SNMP subtree walk immediately if a broken agent repeats the same OID', function (): void {
    $calls = 0;
    $probe = new SnmpTopologyProbe(function (string $ip, int $port, string $packet) use (&$calls): string {
        $calls++;
        $parsed = phase4ParseSnmpV2Request($packet);

        // Broken agent keeps returning exact same OID forever
        return phase4BuildSnmpV2Response(
            $parsed['req_id_tlv'],
            SnmpTopologyProbe::OID_IF_NAME.'.1',
            phase4BerTlv(0x04, 'eth0'),
        );
    });

    $result = $probe->probeTopology('172.16.0.3', ['community' => 'public']);

    expect($result)->toBeArray()
        ->and($calls)->toBeLessThan(30);
});

it('maps UniFi local controller device uplinks and wireless client AP associations safely', function (): void {
    $requests = [];
    $probe = new UniFiLocalTopologyProbe(function (
        string $method,
        string $ip,
        int $port,
        string $path,
        array $headers,
    ) use (&$requests): array {
        $requests[] = [$method, $ip, $port, $path, $headers['X-API-KEY'] ?? null];

        if (str_ends_with($path, '/stat/device')) {
            return [
                'status' => 200,
                'headers' => ['HTTP/1.1 200 OK', 'Content-Type: application/json'],
                'body' => json_encode([
                    'data' => [
                        [
                            'mac' => '70:A7:41:00:00:01',
                            'ip' => '172.16.0.1',
                            'type' => 'ugw',
                        ],
                        [
                            'mac' => '24:5A:4C:00:00:02',
                            'ip' => '172.16.0.10',
                            'type' => 'uap',
                            'uplink' => [
                                'uplink_mac' => '70:A7:41:00:00:01',
                                'uplink_remote_port' => 3,
                                'type' => 'wire',
                            ],
                        ],
                    ],
                ], JSON_THROW_ON_ERROR),
            ];
        }

        return [
            'status' => 200,
            'headers' => ['HTTP/1.1 200 OK', 'Content-Type: application/json'],
            'body' => json_encode([
                'data' => [
                    [
                        'mac' => '44:19:B6:AA:BB:CC',
                        'ip' => '172.16.0.61',
                        'is_wired' => false,
                        'ap_mac' => '24:5A:4C:00:00:02',
                        'essid' => 'Harbour-Staff',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ];
    });

    $map = $probe->probeController('172.16.0.1', ['api_key' => 'test-unifi-key', 'site' => 'default']);

    expect($requests)->toHaveCount(2)
        ->and($requests[0][3])->toBe('/proxy/network/api/s/default/stat/device')
        ->and($map['by_mac']['24:5A:4C:00:00:02'])->toMatchArray([
            'uplink_mac' => '70:A7:41:00:00:01',
            'uplink_ip' => '172.16.0.1',
            'uplink_port' => 'Port 3',
            'kind' => 'ethernet',
        ])
        ->and($map['by_ip']['172.16.0.61'])->toMatchArray([
            'uplink_mac' => '24:5A:4C:00:00:02',
            'uplink_ip' => '172.16.0.10',
            'uplink_port' => 'Wi-Fi (Harbour-Staff)',
            'kind' => 'wireless',
            'ssid' => 'Harbour-Staff',
        ]);

    // Rejects non-IP hostnames or unapproved ports
    expect($probe->probeController('unifi.internal', ['api_key' => 'x']))->toBe(['by_mac' => [], 'by_ip' => []])
        ->and($probe->probeController('172.16.0.1', ['api_key' => 'x'], 8080))->toBe(['by_mac' => [], 'by_ip' => []]);
});

it('sanitizes topology discovery signals and strips malformed MACs, IPs, and secret tokens', function (): void {
    $sanitized = LanDiscoveryScanner::sanitizeSignals([
        'is_subnet_gateway' => true,
        'arp_gateway_ip' => '172.16.0.1',
        'arp_gateway_mac' => '70-A7-41-11-22-33',
        'lldp_neighbors' => [
            [
                'local_port' => 'Gi1/0/2',
                'remote_mac' => '24:5A:4C:AA:BB:CC',
                'remote_port' => 'eth0',
                'remote_name' => 'ap-lounge',
            ],
            [
                'local_port' => 'Gi1/0/9',
                'remote_mac' => 'FF:FF:FF:FF:FF:FF', // broadcast MAC must be stripped
            ],
        ],
        'cdp_neighbors' => [
            [
                'local_port' => 'Gi1/0/4',
                'remote_ip' => '172.16.0.5',
                'remote_port' => 'Gi0/1',
                'remote_name' => 'sw-wing-b',
                'platform' => 'C9200L',
            ],
        ],
        'bridge_fdb' => [
            ['port' => 'Gi1/0/6', 'mac' => '44:19:B6:01:02:03'],
            ['port' => 'Gi1/0/7', 'mac' => 'not-a-mac'],
        ],
        'unifi_uplink' => [
            'uplink_mac' => '70:A7:41:11:22:33',
            'uplink_ip' => '172.16.0.1',
            'uplink_port' => 'Port 3',
            'kind' => 'ethernet',
            'api_key' => 'SECRET-MUST-NOT-PASS',
        ],
        'community' => 'SECRET-SNMP-COMMUNITY',
    ]);

    expect($sanitized['is_subnet_gateway'])->toBeTrue()
        ->and($sanitized['arp_gateway_ip'])->toBe('172.16.0.1')
        ->and($sanitized['arp_gateway_mac'])->toBe('70:A7:41:11:22:33')
        ->and($sanitized['lldp_neighbors'][0]['remote_mac'])->toBe('24:5A:4C:AA:BB:CC')
        ->and($sanitized['lldp_neighbors'][1]['remote_mac'] ?? null)->toBeNull()
        ->and($sanitized['cdp_neighbors'][0]['remote_ip'])->toBe('172.16.0.5')
        ->and($sanitized['bridge_fdb'])->toHaveCount(1)
        ->and($sanitized['bridge_fdb'][0]['mac'])->toBe('44:19:B6:01:02:03')
        ->and($sanitized['unifi_uplink'])->not->toHaveKey('api_key')
        ->and($sanitized)->not->toHaveKey('community');
});

it('preserves and validates sealed unifi_lease in CollectorConfig, ScopeGuard, and CredentialLeaseDecryptor', function (): void {
    $runId = '018f0000-0000-7000-8000-000000000901';
    $sealedUnifiLease = sealedCollectorCredentialLease(
        ['api_key' => 'unifi-local-api-token', 'controller_ip' => '10.44.0.1'],
        [
            'device_id' => 'discovery:'.$runId,
            'protocol' => 'https',
            'target' => '10.44.0.0/24',
        ],
    );

    $basePayload = [
        'version' => 2,
        'collector_id' => '2df1d87c-2d04-4e57-80ab-8a15f39c944d',
        'site_id' => 9,
        'sequence' => 7,
        'issued_at' => '2026-07-23T11:55:00+00:00',
        'expires_at' => '2026-07-23T13:00:00+00:00',
        'revoked' => false,
        'scope' => [
            'cidrs' => ['10.44.0.0/24'],
            'devices' => [],
            'protocols' => ['icmp', 'tcp', 'tls'],
            'rate_limits' => ['max_checks_per_run' => 1, 'packets_per_second' => 20],
        ],
        'checks' => [],
        'discovery_runs' => [[
            'id' => $runId,
            'site_id' => 9,
            'cidrs' => ['10.44.0.0/24'],
            'protocols' => ['icmp', 'tcp', 'tls'],
            'exclusions' => [],
            'port_bounds' => ['tcp' => [22], 'tls' => [443]],
            'packets_per_second' => 20,
            'targets' => [
                ['target' => '10.44.0.1', 'source' => 'cidr'],
            ],
            'unifi_lease' => $sealedUnifiLease,
        ]],
    ];

    $config = CollectorConfig::fromPayload($basePayload);
    expect($config->discoveryRuns[0]['unifi_lease'])->toBe($sealedUnifiLease);

    $guard = new ScopeGuard($config);
    $guard->assertDiscoveryRun($config->discoveryRuns[0], collectorNow());

    $decryptor = new CredentialLeaseDecryptor(collectorIdentitySecretKey());
    $opened = $decryptor->open(['credential_lease' => $config->discoveryRuns[0]['unifi_lease']], collectorNow());
    expect($opened['api_key'])->toBe('unifi-local-api-token')
        ->and($opened['controller_ip'])->toBe('10.44.0.1');

    $invalidPayload = $basePayload;
    $invalidPayload['discovery_runs'][0]['unifi_lease'] = ['list-item-not-associative'];
    expect(fn () => CollectorConfig::fromPayload($invalidPayload))->toThrow(ConfigurationRejected::class);
});
