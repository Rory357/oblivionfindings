<?php

use Oblivion\Collector\Data\CollectorConfig;
use Oblivion\Collector\Discovery\LanDiscoveryScanner;
use Oblivion\Collector\Discovery\MacVendorLookup;
use Oblivion\Collector\Discovery\SnmpSystemProbe;
use Oblivion\Collector\Runtime\ProbeRunner;
use Oblivion\Collector\Runtime\RemoteDiscoveryRunner;
use Oblivion\Collector\Security\ScopeGuard;
use Symfony\Component\Process\Process;

it('rejects Windows destination host unreachable false positives while accepting genuine echo replies', function (): void {
    $unreachableWindows = <<<'OUT'
Pinging 172.16.0.55 with 32 bytes of data:
Reply from 172.16.0.93: Destination host unreachable.

Ping statistics for 172.16.0.55:
    Packets: Sent = 1, Received = 1, Lost = 0 (0% loss),
OUT;

    $genuineWindowsSubMs = <<<'OUT'
Pinging 172.16.0.1 with 32 bytes of data:
Reply from 172.16.0.1: bytes=32 time<1ms TTL=64
OUT;

    $genuineWindowsMs = <<<'OUT'
Pinging 172.16.0.232 with 32 bytes of data:
Reply from 172.16.0.232: bytes=32 time=4ms TTL=128
OUT;

    $genuineLinux = <<<'OUT'
PING 172.16.0.1 (172.16.0.1) 56(84) bytes of data.
64 bytes from 172.16.0.1: icmp_seq=1 ttl=64 time=0.84 ms
OUT;

    expect(ProbeRunner::hasGenuineEchoReply('172.16.0.55', $unreachableWindows))->toBeFalse()
        ->and(ProbeRunner::hasGenuineEchoReply('172.16.0.1', $genuineWindowsSubMs))->toBeTrue()
        ->and(ProbeRunner::hasGenuineEchoReply('172.16.0.232', $genuineWindowsMs))->toBeTrue()
        ->and(ProbeRunner::hasGenuineEchoReply('172.16.0.1', $genuineLinux))->toBeTrue()
        ->and(ProbeRunner::hasGenuineEchoReply('172.16.0.99', $genuineLinux))->toBeFalse();
});

it('resolves IEEE OUI and MA-M MAC vendors and ignores locally administered MACs', function (): void {
    $lookup = new MacVendorLookup;

    expect($lookup->lookup('24:5A:4C:11:22:33'))->toContain('Ubiquiti')
        ->and($lookup->lookup('44:19:B6:AA:BB:CC'))->toContain('Hikvision')
        ->and($lookup->lookup('AC:CC:8E:01:02:03'))->toContain('Axis')
        ->and($lookup->lookup('00-90-C2-12-34-56'))->not->toBeNull()
        ->and($lookup->isLocallyAdministered('DA:A1:19:00:11:22'))->toBeTrue()
        ->and($lookup->lookup('DA:A1:19:00:11:22'))->toBeNull();
});

it('queries SNMPv2c system MIB over UDP and parses BER sysDescr, sysObjectID, and sysName', function (): void {
    $responderScript = <<<'PHP'
$sock = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
if (!is_resource($sock)) { exit(2); }
$name = stream_socket_get_name($sock, false);
$port = (int) substr((string) strrchr((string) $name, ':'), 1);
fwrite(STDOUT, $port . "\n");
fflush(STDOUT);
stream_set_timeout($sock, 3);
$peer = '';
$pkt = stream_socket_recvfrom($sock, 2048, 0, $peer);
if (!is_string($pkt) || $pkt === '') { exit(3); }
$pduPos = strpos($pkt, "\xa0");
if ($pduPos === false) { exit(4); }
$idx = $pduPos + 2;
if ((ord($pkt[$pduPos + 1]) & 0x80) !== 0) {
    $idx += (ord($pkt[$pduPos + 1]) & 0x7f);
}
if (ord($pkt[$idx]) !== 0x02) { exit(5); }
$reqIdLen = ord($pkt[$idx + 1]);
$reqIdTlv = substr($pkt, $idx, 2 + $reqIdLen);

$len = static function (int $n): string {
    return $n < 128 ? chr($n) : "\x81" . chr($n);
};
$tlv = static function (int $tag, string $body) use ($len): string {
    return chr($tag) . $len(strlen($body)) . $body;
};
$oid = static function (string $dotted) use ($tlv): string {
    $parts = array_map('intval', explode('.', $dotted));
    $bytes = chr($parts[0] * 40 + $parts[1]);
    for ($i = 2, $c = count($parts); $i < $c; $i++) {
        $v = $parts[$i];
        $enc = chr($v & 0x7f);
        while (($v >>= 7) > 0) {
            $enc = chr(($v & 0x7f) | 0x80) . $enc;
        }
        $bytes .= $enc;
    }
    return $tlv(0x06, $bytes);
};
$vb1 = $tlv(0x30, $oid('1.3.6.1.2.1.1.1.0') . $tlv(0x04, 'Ubiquiti UniFi Express 7 4.0.6'));
$vb2 = $tlv(0x30, $oid('1.3.6.1.2.1.1.2.0') . $oid('1.3.6.1.4.1.41112.1.4'));
$vb3 = $tlv(0x30, $oid('1.3.6.1.2.1.1.5.0') . $tlv(0x04, 'harbour-ux7'));
$vbl = $tlv(0x30, $vb1 . $vb2 . $vb3);
$respPdu = $tlv(0xA2, $reqIdTlv . "\x02\x01\x00\x02\x01\x00" . $vbl);
$respMsg = $tlv(0x30, "\x02\x01\x01" . $tlv(0x04, 'public') . $respPdu);
stream_socket_sendto($sock, $respMsg, 0, $peer);
fclose($sock);
PHP;

    $process = new Process([PHP_BINARY, '-r', $responderScript]);
    $process->start();

    $port = 0;
    $deadline = microtime(true) + 2.5;
    $buffer = '';
    while (microtime(true) < $deadline) {
        $buffer .= $process->getIncrementalOutput();
        if (str_contains($buffer, "\n")) {
            $port = (int) trim(explode("\n", $buffer, 2)[0]);
            break;
        }
        usleep(15_000);
    }

    expect($port)->toBeGreaterThan(0);

    $probe = new SnmpSystemProbe;
    $result = $probe->probeV2c('127.0.0.1', 'public', $port, 1.5);
    $process->wait();

    expect($result)->not->toBeNull()
        ->and($result['sys_descr'])->toBe('Ubiquiti UniFi Express 7 4.0.6')
        ->and($result['sys_object_id'])->toBe('1.3.6.1.4.1.41112.1.4')
        ->and($result['sys_name'])->toBe('harbour-ux7');
});

it('sanitizes discovery signals and emits extended protocol results without forbidden terms', function (): void {
    $sanitized = LanDiscoveryScanner::sanitizeSignals([
        'mac_vendor' => 'Hangzhou Hikvision Digital Technology',
        'onvif_hardware' => 'DS-2CD2387G2-LU',
        'http_title' => 'Camera Login password prompt',
        'snmp_community' => 'private-secret-community',
        'open_ports' => [554, 80, 8000, 80],
        'mdns_services' => ['_rtsp._tcp.local', 'shell_exec_bad'],
        'protocols_seen' => ['arp', 'onvif', 'tcp'],
    ]);

    expect($sanitized)->not->toHaveKey('snmp_community')
        ->and($sanitized['open_ports'])->toBe([80, 554, 8000])
        ->and(strtolower((string) json_encode($sanitized)))->not->toContain('password')
        ->and(strtolower((string) json_encode($sanitized)))->not->toContain('secret')
        ->and(strtolower((string) json_encode($sanitized)))->not->toContain('shell');

    $issuedAt = new DateTimeImmutable('2026-10-28T01:00:00Z');
    $expiresAt = new DateTimeImmutable('2026-10-28T02:00:00Z');

    $config = CollectorConfig::fromPayload([
        'version' => 3,
        'collector_id' => 'col-harbour-01',
        'site_id' => 1,
        'sequence' => 12,
        'issued_at' => $issuedAt->format(DATE_ATOM),
        'expires_at' => $expiresAt->format(DATE_ATOM),
        'revoked' => false,
        'scope' => [
            'cidrs' => ['172.16.0.0/24'],
            'devices' => [],
            'protocols' => ['icmp', 'arp', 'tcp', 'dns', 'http', 'tls', 'snmp', 'mdns', 'ssdp', 'onvif'],
            'rate_limits' => [
                'max_checks_per_run' => 10,
                'packets_per_second' => 25,
            ],
        ],
        'checks' => [],
        'discovery_runs' => [
            [
                'id' => '019a29f0-1111-7111-8111-111111111111',
                'site_id' => 1,
                'cidrs' => ['172.16.0.0/24'],
                'exclusions' => [],
                'protocols' => ['icmp', 'arp', 'tcp', 'dns', 'http', 'tls', 'snmp', 'mdns', 'ssdp', 'onvif'],
                'port_bounds' => [
                    'tcp' => [80, 443, 554, 8000],
                    'dns' => [53],
                    'http' => [80, 443],
                    'tls' => [443],
                    'snmp' => [161],
                    'mdns' => [5353],
                    'ssdp' => [1900],
                    'onvif' => [3702],
                ],
                'packets_per_second' => 25,
                'targets' => [
                    ['target' => '172.16.0.55', 'source' => 'cidr'],
                ],
            ],
        ],
    ]);

    $runner = new RemoteDiscoveryRunner(
        new ScopeGuard($config),
        fn (): array => [
            'outcome' => 'found',
            'identity' => [
                'hostname' => 'front-gate-cam.local',
                'addresses' => ['172.16.0.55'],
                'mac_addresses' => ['44:19:B6:11:22:33'],
                'certificate_fingerprint' => null,
                'fingerprint' => 'network:onvif,tcp:80,554,8000',
                'signals' => [
                    'mac_vendor' => 'Hangzhou Hikvision Digital Technology',
                    'onvif_hardware' => 'DS-2CD2387G2-LU',
                    'open_ports' => [80, 554, 8000],
                ],
            ],
        ],
    );

    $results = $runner->run(
        $config->discoveryRuns[0],
        'col-harbour-01',
        [],
        new DateTimeImmutable('2026-10-28T01:05:00Z'),
    );

    expect($results)->toHaveCount(1)
        ->and($results[0]['payload']['outcome'])->toBe('found')
        ->and($results[0]['payload']['identity']['signals']['onvif_hardware'])->toBe('DS-2CD2387G2-LU');
});
