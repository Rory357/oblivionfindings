<?php

namespace App\Domain\Monitoring\Discovery\Support;

use Closure;

/**
 * Pure-PHP ASN.1 BER SNMPv2c / SNMPv3 USM GetNextRequest (0xA1) bounded subtree walker
 * for Layer-2 / Layer-3 network topology discovery over UDP 161.
 *
 * Official specifications:
 * - RFC 3416 (https://datatracker.ietf.org/doc/html/rfc3416 — SNMPv2 GetNextRequest-PDU 0xA1 & endOfMibView 0x82)
 * - RFC 3414 / RFC 3826 / RFC 7860 (SNMPv3 USM HMAC-SHA & AES-128-CFB)
 * - IEEE Std 802.1AB-2016 LLDP-MIB (1.0.8802.1.1.2.1 — lldpLocPortTable & lldpRemTable)
 * - Cisco CISCO-CDP-MIB (1.3.6.1.4.1.9.9.23.1.2.1.1 — cdpCacheTable)
 * - RFC 2863 IF-MIB (https://datatracker.ietf.org/doc/html/rfc2863 — ifName 1.3.6.1.2.1.31.1.1.1.1 & ifDescr)
 * - RFC 4188 BRIDGE-MIB (https://datatracker.ietf.org/doc/html/rfc4188 — dot1dBasePortIfIndex & dot1dTpFdbPort)
 * - RFC 4363 Q-BRIDGE-MIB (https://datatracker.ietf.org/doc/html/rfc4363 — dot1qTpFdbPort)
 * - RFC 4293 IP-MIB (https://datatracker.ietf.org/doc/html/rfc4293 — ipNetToMediaPhysAddress)
 */
final class SnmpTopologyProbe
{
    public const string OID_IF_DESCR = '1.3.6.1.2.1.2.2.1.2';

    public const string OID_IF_NAME = '1.3.6.1.2.1.31.1.1.1.1';

    public const string OID_LLDP_LOC_PORT_ID = '1.0.8802.1.1.2.1.3.7.1.3';

    public const string OID_LLDP_REM_CHASSIS_SUBTYPE = '1.0.8802.1.1.2.1.4.1.1.4';

    public const string OID_LLDP_REM_CHASSIS_ID = '1.0.8802.1.1.2.1.4.1.1.5';

    public const string OID_LLDP_REM_PORT_ID = '1.0.8802.1.1.2.1.4.1.1.7';

    public const string OID_LLDP_REM_SYS_NAME = '1.0.8802.1.1.2.1.4.1.1.9';

    public const string OID_CDP_CACHE_ADDRESS = '1.3.6.1.4.1.9.9.23.1.2.1.1.4';

    public const string OID_CDP_CACHE_DEVICE_ID = '1.3.6.1.4.1.9.9.23.1.2.1.1.6';

    public const string OID_CDP_CACHE_DEVICE_PORT = '1.3.6.1.4.1.9.9.23.1.2.1.1.7';

    public const string OID_CDP_CACHE_PLATFORM = '1.3.6.1.4.1.9.9.23.1.2.1.1.8';

    public const string OID_DOT1D_BASE_PORT_IFINDEX = '1.3.6.1.2.1.17.1.4.1.2';

    public const string OID_DOT1D_TP_FDB_PORT = '1.3.6.1.2.1.17.4.3.1.2';

    public const string OID_DOT1Q_TP_FDB_PORT = '1.3.6.1.2.1.17.7.1.2.2.1.2';

    public const string OID_IP_NET_TO_MEDIA_PHYS = '1.3.6.1.2.1.4.22.1.2';

    private const int MAX_ROWS_PER_TABLE = 32;

    /**
     * @param  null|Closure(string, int, string, float): ?string  $transport
     */
    public function __construct(
        private readonly ?Closure $transport = null,
    ) {}

    /**
     * @param  array<string, scalar|null>|null  $material
     * @return array{
     *     lldp_neighbors?: list<array{local_port?: string, remote_mac?: string, remote_port?: string, remote_name?: string}>,
     *     cdp_neighbors?: list<array{local_port?: string, remote_ip?: string, remote_port?: string, remote_name?: string, platform?: string}>,
     *     bridge_fdb?: list<array{port: string, mac: string}>,
     *     snmp_arp?: list<array{ip: string, mac: string}>
     * }
     */
    public function probeTopology(
        string $address,
        #[\SensitiveParameter] ?array $material = null,
        int $port = 161,
        float $timeoutSeconds = 0.35,
    ): array {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return [];
        }

        $session = $this->openSession($address, $material, $port, $timeoutSeconds);
        if ($session === null) {
            return [];
        }

        $ifNames = $this->collectInterfaceNames($session);
        $lldp = $this->collectLldpNeighbors($session, $ifNames);
        $cdp = $this->collectCdpNeighbors($session, $ifNames);
        $bridgeFdb = $this->collectBridgeFdb($session, $ifNames);
        $snmpArp = $this->collectArpTable($session);

        $out = [];
        if ($lldp !== []) {
            $out['lldp_neighbors'] = $lldp;
        }
        if ($cdp !== []) {
            $out['cdp_neighbors'] = $cdp;
        }
        if ($bridgeFdb !== []) {
            $out['bridge_fdb'] = $bridgeFdb;
        }
        if ($snmpArp !== []) {
            $out['snmp_arp'] = $snmpArp;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $session
     * @return array<int, string>
     */
    private function collectInterfaceNames(array $session): array
    {
        $names = [];
        foreach ($this->walkSubtree($session, self::OID_IF_NAME, 48) as $suffix => $entry) {
            $ifIndex = (int) $suffix;
            $label = $this->cleanPrintable($entry['raw'], 64);
            if ($ifIndex > 0 && $label !== null) {
                $names[$ifIndex] = $label;
            }
        }
        if ($names === []) {
            foreach ($this->walkSubtree($session, self::OID_IF_DESCR, 48) as $suffix => $entry) {
                $ifIndex = (int) $suffix;
                $label = $this->cleanPrintable($entry['raw'], 64);
                if ($ifIndex > 0 && $label !== null) {
                    $names[$ifIndex] = $label;
                }
            }
        }

        return $names;
    }

    /**
     * @param  array<string, mixed>  $session
     * @param  array<int, string>  $ifNames
     * @return list<array{local_port?: string, remote_mac?: string, remote_port?: string, remote_name?: string}>
     */
    private function collectLldpNeighbors(array $session, array $ifNames): array
    {
        $chassisRows = $this->walkSubtree($session, self::OID_LLDP_REM_CHASSIS_ID, self::MAX_ROWS_PER_TABLE);
        $sysNameRows = $this->walkSubtree($session, self::OID_LLDP_REM_SYS_NAME, self::MAX_ROWS_PER_TABLE);
        if ($chassisRows === [] && $sysNameRows === []) {
            return [];
        }

        $subtypes = $this->walkSubtree($session, self::OID_LLDP_REM_CHASSIS_SUBTYPE, self::MAX_ROWS_PER_TABLE);
        $remPorts = $this->walkSubtree($session, self::OID_LLDP_REM_PORT_ID, self::MAX_ROWS_PER_TABLE);
        $locPorts = [];
        foreach ($this->walkSubtree($session, self::OID_LLDP_LOC_PORT_ID, 48) as $suffix => $entry) {
            $portNum = (int) $suffix;
            $label = $this->cleanPrintable($entry['raw'], 64);
            if ($portNum > 0 && $label !== null) {
                $locPorts[$portNum] = $label;
            }
        }

        $indexes = array_values(array_unique([...array_keys($chassisRows), ...array_keys($sysNameRows)]));
        $neighbors = [];
        foreach (array_slice($indexes, 0, self::MAX_ROWS_PER_TABLE) as $index) {
            $parts = explode('.', $index);
            $localPortNum = count($parts) >= 2 ? (int) $parts[1] : (int) $parts[0];
            $localPort = $locPorts[$localPortNum] ?? $ifNames[$localPortNum] ?? ($localPortNum > 0 ? "Port {$localPortNum}" : null);

            $remoteMac = null;
            if (isset($chassisRows[$index])) {
                $subtype = isset($subtypes[$index]) ? ($subtypes[$index]['int'] ?? 0) : 0;
                $rawChassis = $chassisRows[$index]['raw'];
                $remoteMac = ($subtype === 4 || strlen($rawChassis) === 6)
                    ? $this->formatBinaryOrTextMac($rawChassis)
                    : $this->formatBinaryOrTextMac($rawChassis);
            }

            $remotePort = isset($remPorts[$index]) ? $this->formatPortIdentifier($remPorts[$index]['raw']) : null;
            $remoteName = isset($sysNameRows[$index]) ? $this->cleanPrintable($sysNameRows[$index]['raw'], 128) : null;

            if ($remoteMac === null && $remoteName === null) {
                continue;
            }

            $row = [];
            if ($localPort !== null) {
                $row['local_port'] = $localPort;
            }
            if ($remoteMac !== null) {
                $row['remote_mac'] = $remoteMac;
            }
            if ($remotePort !== null) {
                $row['remote_port'] = $remotePort;
            }
            if ($remoteName !== null) {
                $row['remote_name'] = $remoteName;
            }
            $neighbors[] = $row;
        }

        return $neighbors;
    }

    /**
     * @param  array<string, mixed>  $session
     * @param  array<int, string>  $ifNames
     * @return list<array{local_port?: string, remote_ip?: string, remote_port?: string, remote_name?: string, platform?: string}>
     */
    private function collectCdpNeighbors(array $session, array $ifNames): array
    {
        $deviceIds = $this->walkSubtree($session, self::OID_CDP_CACHE_DEVICE_ID, self::MAX_ROWS_PER_TABLE);
        $addresses = $this->walkSubtree($session, self::OID_CDP_CACHE_ADDRESS, self::MAX_ROWS_PER_TABLE);
        if ($deviceIds === [] && $addresses === []) {
            return [];
        }

        $ports = $this->walkSubtree($session, self::OID_CDP_CACHE_DEVICE_PORT, self::MAX_ROWS_PER_TABLE);
        $platforms = $this->walkSubtree($session, self::OID_CDP_CACHE_PLATFORM, self::MAX_ROWS_PER_TABLE);

        $indexes = array_values(array_unique([...array_keys($deviceIds), ...array_keys($addresses)]));
        $neighbors = [];
        foreach (array_slice($indexes, 0, self::MAX_ROWS_PER_TABLE) as $index) {
            $parts = explode('.', $index);
            $ifIndex = (int) ($parts[0] ?? 0);
            $localPort = $ifNames[$ifIndex] ?? ($ifIndex > 0 ? "Port {$ifIndex}" : null);

            $remoteIp = null;
            if (isset($addresses[$index])) {
                $rawAddr = $addresses[$index]['raw'];
                if (strlen($rawAddr) === 4) {
                    $candidateIp = implode('.', array_map('ord', str_split($rawAddr)));
                    if (filter_var($candidateIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                        $remoteIp = $candidateIp;
                    }
                }
            }

            $remoteName = isset($deviceIds[$index]) ? $this->cleanPrintable($deviceIds[$index]['raw'], 128) : null;
            $remotePort = isset($ports[$index]) ? $this->cleanPrintable($ports[$index]['raw'], 64) : null;
            $platform = isset($platforms[$index]) ? $this->cleanPrintable($platforms[$index]['raw'], 96) : null;

            if ($remoteIp === null && $remoteName === null) {
                continue;
            }

            $row = [];
            if ($localPort !== null) {
                $row['local_port'] = $localPort;
            }
            if ($remoteIp !== null) {
                $row['remote_ip'] = $remoteIp;
            }
            if ($remotePort !== null) {
                $row['remote_port'] = $remotePort;
            }
            if ($remoteName !== null) {
                $row['remote_name'] = $remoteName;
            }
            if ($platform !== null) {
                $row['platform'] = $platform;
            }
            $neighbors[] = $row;
        }

        return $neighbors;
    }

    /**
     * @param  array<string, mixed>  $session
     * @param  array<int, string>  $ifNames
     * @return list<array{port: string, mac: string}>
     */
    private function collectBridgeFdb(array $session, array $ifNames): array
    {
        $fdbRows = $this->walkSubtree($session, self::OID_DOT1D_TP_FDB_PORT, 48);
        $isQBridge = false;
        if ($fdbRows === []) {
            $fdbRows = $this->walkSubtree($session, self::OID_DOT1Q_TP_FDB_PORT, 48);
            $isQBridge = $fdbRows !== [];
        }
        if ($fdbRows === []) {
            return [];
        }

        $bridgePortToIfIndex = [];
        foreach ($this->walkSubtree($session, self::OID_DOT1D_BASE_PORT_IFINDEX, 48) as $suffix => $entry) {
            $bridgePort = (int) $suffix;
            $ifIndex = $entry['int'] ?? 0;
            if ($bridgePort > 0 && $ifIndex > 0) {
                $bridgePortToIfIndex[$bridgePort] = $ifIndex;
            }
        }

        $entries = [];
        $seen = [];
        foreach ($fdbRows as $suffix => $entry) {
            $bridgePort = $entry['int'] ?? 0;
            if ($bridgePort <= 0) {
                continue;
            }
            $octets = array_map('intval', explode('.', $suffix));
            if ($isQBridge && count($octets) >= 7) {
                $octets = array_slice($octets, -6);
            }
            if (count($octets) !== 6) {
                continue;
            }
            $rawMac = implode('', array_map(fn (int $b): string => chr($b & 0xFF), $octets));
            $mac = $this->formatBinaryOrTextMac($rawMac);
            if ($mac === null || isset($seen[$mac])) {
                continue;
            }
            $ifIndex = $bridgePortToIfIndex[$bridgePort] ?? $bridgePort;
            $portLabel = $ifNames[$ifIndex] ?? "Port {$bridgePort}";
            $seen[$mac] = true;
            $entries[] = [
                'port' => $portLabel,
                'mac' => $mac,
            ];
            if (count($entries) >= 48) {
                break;
            }
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $session
     * @return list<array{ip: string, mac: string}>
     */
    private function collectArpTable(array $session): array
    {
        $rows = $this->walkSubtree($session, self::OID_IP_NET_TO_MEDIA_PHYS, 48);
        if ($rows === []) {
            return [];
        }

        $entries = [];
        foreach ($rows as $suffix => $entry) {
            $parts = explode('.', $suffix);
            if (count($parts) < 5) {
                continue;
            }
            $ipCandidate = implode('.', array_slice($parts, -4));
            if (filter_var($ipCandidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                continue;
            }
            $mac = $this->formatBinaryOrTextMac($entry['raw']);
            if ($mac === null) {
                continue;
            }
            $entries[] = ['ip' => $ipCandidate, 'mac' => $mac];
            if (count($entries) >= 48) {
                break;
            }
        }

        return $entries;
    }

    /**
     * @param  array<string, scalar|null>|null  $material
     * @return array<string, mixed>|null
     */
    private function openSession(
        string $address,
        #[\SensitiveParameter] ?array $material,
        int $port,
        float $timeoutSeconds,
    ): ?array {
        if (is_array($material) && (isset($material['security_name']) || isset($material['username']))) {
            $user = trim((string) ($material['security_name'] ?? $material['username'] ?? ''));
            $authProto = strtoupper(trim((string) ($material['auth_protocol'] ?? 'SHA')));
            $authPass = (string) ($material['auth_secret'] ?? $material['auth_passphrase'] ?? '');
            $privProto = strtoupper(trim((string) ($material['privacy_protocol'] ?? '')));
            $privPass = (string) ($material['privacy_secret'] ?? $material['privacy_passphrase'] ?? '');

            if ($user === '' || strlen($user) > 64 || strlen($authPass) < 8) {
                return null;
            }

            [$hashAlgo, $macLen] = match ($authProto) {
                'SHA', 'SHA1', 'SHA-1' => ['sha1', 12],
                'SHA224', 'SHA-224' => ['sha224', 16],
                'SHA256', 'SHA-256' => ['sha256', 24],
                'SHA384', 'SHA-384' => ['sha384', 32],
                'SHA512', 'SHA-512' => ['sha512', 48],
                default => [null, 0],
            };
            if ($hashAlgo === null) {
                return null;
            }

            $engine = $this->discoverV3Engine($address, $port, $timeoutSeconds);
            if ($engine === null || $engine['engine_id'] === '') {
                return null;
            }

            $usePriv = $privProto !== '' && strlen($privPass) >= 8 && in_array($privProto, ['AES', 'AES128', 'AES-128'], true);

            return [
                'version' => 'v3',
                'address' => $address,
                'port' => $port,
                'timeout' => $timeoutSeconds,
                'user' => $user,
                'hash_algo' => $hashAlgo,
                'mac_len' => $macLen,
                'engine' => $engine,
                'auth_key' => $this->localizeKey($hashAlgo, $authPass, $engine['engine_id']),
                'priv_key' => $usePriv ? substr($this->localizeKey($hashAlgo, $privPass, $engine['engine_id']), 0, 16) : null,
            ];
        }

        $community = is_array($material) && is_string($material['community'] ?? null) && $material['community'] !== ''
            ? $material['community']
            : 'public';
        if (strlen($community) > 255) {
            return null;
        }

        return [
            'version' => 'v2c',
            'address' => $address,
            'port' => $port,
            'timeout' => $timeoutSeconds,
            'community' => $community,
        ];
    }

    /**
     * @param  array<string, mixed>  $session
     * @return array<string, array{oid: string, tag: int, raw: string, int: ?int}>
     */
    private function walkSubtree(array $session, string $rootOid, int $maxRows): array
    {
        $rootPrefix = rtrim($rootOid, '.').'.';
        $currentOid = rtrim($rootOid, '.');
        $rows = [];

        for ($step = 0; $step < $maxRows; $step++) {
            $next = $this->getNext($session, $currentOid);
            if ($next === null) {
                break;
            }
            if (in_array($next['tag'], [0x80, 0x81, 0x82], true)) {
                break;
            }
            if (! str_starts_with($next['oid'], $rootPrefix)) {
                break;
            }
            if ($this->compareOids($next['oid'], $currentOid) <= 0) {
                break;
            }

            $suffix = substr($next['oid'], strlen($rootPrefix));
            if ($suffix !== '') {
                $rows[$suffix] = $next;
            }
            $currentOid = $next['oid'];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $session
     * @return array{oid: string, tag: int, raw: string, int: ?int}|null
     */
    private function getNext(array $session, string $oid): ?array
    {
        $requestId = random_int(10_000, 1_900_000_000);
        $pdu = $this->encodePdu(0xA1, $requestId, [$oid]);

        if ($session['version'] === 'v2c') {
            $packet = $this->encodeSequence(
                $this->encodeInteger(1)
                .$this->encodeOctetString((string) $session['community'])
                .$pdu,
            );
            $reply = $this->sendPacket((string) $session['address'], (int) $session['port'], $packet, (float) $session['timeout']);

            return $reply === null ? null : $this->decodeFirstVarbindV2c($reply, $requestId);
        }

        /** @var array{engine_id: string, boots: int, time: int} $engine */
        $engine = $session['engine'];
        $msgId = random_int(10_000, 1_900_000_000);
        $privKey = $session['priv_key'];
        $flags = is_string($privKey) ? "\x07" : "\x05";

        $plainScopedPdu = $this->encodeSequence(
            $this->encodeOctetString($engine['engine_id'])
            .$this->encodeOctetString('')
            .$pdu,
        );

        $privParams = '';
        $msgData = $plainScopedPdu;
        if (is_string($privKey)) {
            $privParams = random_bytes(8);
            $iv = pack('NN', $engine['boots'], $engine['time']).$privParams;
            $cipher = openssl_encrypt(
                $plainScopedPdu,
                'aes-128-cfb',
                $privKey,
                OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
                $iv,
            );
            if (! is_string($cipher)) {
                return null;
            }
            $msgData = $this->encodeOctetString($cipher);
        }

        $macLen = (int) $session['mac_len'];
        $authPlaceholder = str_repeat("\x81", $macLen);
        $usmSeq = $this->encodeSequence(
            $this->encodeOctetString($engine['engine_id'])
            .$this->encodeInteger($engine['boots'])
            .$this->encodeInteger($engine['time'])
            .$this->encodeOctetString((string) $session['user'])
            .$this->encodeOctetString($authPlaceholder)
            .$this->encodeOctetString($privParams),
        );
        $globalData = $this->encodeSequence(
            $this->encodeInteger($msgId)
            .$this->encodeInteger(65507)
            .$this->encodeOctetString($flags)
            .$this->encodeInteger(3),
        );
        $unsigned = $this->encodeSequence(
            $this->encodeInteger(3)
            .$globalData
            .$this->encodeOctetString($usmSeq)
            .$msgData,
        );
        $offset = strpos($unsigned, $authPlaceholder);
        if ($offset === false) {
            return null;
        }
        $zeroed = substr_replace($unsigned, str_repeat("\x00", $macLen), $offset, $macLen);
        $digest = substr(hash_hmac((string) $session['hash_algo'], $zeroed, (string) $session['auth_key'], true), 0, $macLen);
        $signed = substr_replace($unsigned, $digest, $offset, $macLen);

        $reply = $this->sendPacket((string) $session['address'], (int) $session['port'], $signed, (float) $session['timeout']);

        return $reply === null ? null : $this->decodeFirstVarbindV3($reply, $requestId, is_string($privKey) ? $privKey : null);
    }

    /** @return array{engine_id: string, boots: int, time: int}|null */
    private function discoverV3Engine(string $address, int $port, float $timeoutSeconds): ?array
    {
        $msgId = random_int(10_000, 1_000_000_000);
        $reqId = random_int(10_000, 1_000_000_000);
        $global = $this->encodeSequence(
            $this->encodeInteger($msgId)
            .$this->encodeInteger(65507)
            .$this->encodeOctetString("\x04")
            .$this->encodeInteger(3),
        );
        $usm = $this->encodeOctetString($this->encodeSequence(
            $this->encodeOctetString('')
            .$this->encodeInteger(0)
            .$this->encodeInteger(0)
            .$this->encodeOctetString('')
            .$this->encodeOctetString('')
            .$this->encodeOctetString(''),
        ));
        $scoped = $this->encodeSequence(
            $this->encodeOctetString('')
            .$this->encodeOctetString('')
            .$this->encodePdu(0xA0, $reqId, []),
        );
        $packet = $this->encodeSequence($this->encodeInteger(3).$global.$usm.$scoped);
        $reply = $this->sendPacket($address, $port, $packet, $timeoutSeconds);
        if ($reply === null) {
            return null;
        }

        $offset = 0;
        $outer = $this->readTlv($reply, $offset);
        if ($outer === null || $outer['tag'] !== 0x30) {
            return null;
        }
        $inner = 0;
        $this->readTlv($outer['value'], $inner);
        $this->readTlv($outer['value'], $inner);
        $sec = $this->readTlv($outer['value'], $inner);
        if ($sec === null || $sec['tag'] !== 0x04) {
            return null;
        }
        $u = 0;
        $usmSeq = $this->readTlv($sec['value'], $u);
        if ($usmSeq === null || $usmSeq['tag'] !== 0x30) {
            return null;
        }
        $s = 0;
        $engineId = $this->readTlv($usmSeq['value'], $s);
        $boots = $this->readTlv($usmSeq['value'], $s);
        $time = $this->readTlv($usmSeq['value'], $s);
        if ($engineId === null || $boots === null || $time === null || $engineId['tag'] !== 0x04) {
            return null;
        }

        return [
            'engine_id' => $engineId['value'],
            'boots' => max(0, $this->decodeInteger($boots['value'])),
            'time' => max(0, $this->decodeInteger($time['value'])),
        ];
    }

    private function sendPacket(string $address, int $port, string $packet, float $timeoutSeconds): ?string
    {
        if ($this->transport !== null) {
            return ($this->transport)($address, $port, $packet, $timeoutSeconds);
        }

        $host = str_contains($address, ':') ? "[{$address}]" : $address;
        $socket = @stream_socket_client(
            "udp://{$host}:{$port}",
            $errno,
            $errstr,
            max(0.15, $timeoutSeconds),
        );
        if (! is_resource($socket)) {
            return null;
        }

        try {
            $sec = (int) floor($timeoutSeconds);
            $usec = (int) round(($timeoutSeconds - $sec) * 1_000_000);
            stream_set_timeout($socket, $sec, max(40_000, $usec));
            if (@fwrite($socket, $packet) === false) {
                return null;
            }
            $response = @fread($socket, 8192);

            return is_string($response) && $response !== '' ? $response : null;
        } finally {
            @fclose($socket);
        }
    }

    private function localizeKey(string $algo, #[\SensitiveParameter] string $passphrase, string $engineId): string
    {
        $ctx = hash_init($algo);
        $len = strlen($passphrase);
        $repeats = intdiv(1_048_576, $len) + 1;
        $chunk = str_repeat($passphrase, min($repeats, 512));
        $remaining = 1_048_576;
        while ($remaining > 0) {
            $slice = strlen($chunk) <= $remaining ? $chunk : substr($chunk, 0, $remaining);
            hash_update($ctx, $slice);
            $remaining -= strlen($slice);
        }
        $ku = hash_final($ctx, true);

        return hash($algo, $ku.$engineId.$ku, true);
    }

    /** @return array{oid: string, tag: int, raw: string, int: ?int}|null */
    private function decodeFirstVarbindV2c(string $packet, int $expectedRequestId): ?array
    {
        $offset = 0;
        $outer = $this->readTlv($packet, $offset);
        if ($outer === null || $outer['tag'] !== 0x30) {
            return null;
        }
        $inner = 0;
        $this->readTlv($outer['value'], $inner);
        $this->readTlv($outer['value'], $inner);
        $pdu = $this->readTlv($outer['value'], $inner);
        if ($pdu === null || $pdu['tag'] !== 0xA2) {
            return null;
        }

        return $this->decodePduFirstVarbind($pdu['value'], $expectedRequestId);
    }

    /** @return array{oid: string, tag: int, raw: string, int: ?int}|null */
    private function decodeFirstVarbindV3(string $packet, int $expectedRequestId, ?string $privKey): ?array
    {
        $offset = 0;
        $outer = $this->readTlv($packet, $offset);
        if ($outer === null || $outer['tag'] !== 0x30) {
            return null;
        }
        $inner = 0;
        $this->readTlv($outer['value'], $inner);
        $this->readTlv($outer['value'], $inner);
        $sec = $this->readTlv($outer['value'], $inner);
        $msgData = $this->readTlv($outer['value'], $inner);
        if ($sec === null || $msgData === null) {
            return null;
        }

        $scopedBytes = null;
        if ($msgData['tag'] === 0x30) {
            $scopedBytes = $msgData['value'];
        } elseif ($msgData['tag'] === 0x04 && $privKey !== null) {
            $u = 0;
            $usmSeq = $this->readTlv($sec['value'], $u);
            if ($usmSeq === null || $usmSeq['tag'] !== 0x30) {
                return null;
            }
            $s = 0;
            $this->readTlv($usmSeq['value'], $s);
            $boots = $this->readTlv($usmSeq['value'], $s);
            $time = $this->readTlv($usmSeq['value'], $s);
            $this->readTlv($usmSeq['value'], $s);
            $this->readTlv($usmSeq['value'], $s);
            $priv = $this->readTlv($usmSeq['value'], $s);
            if ($boots === null || $time === null || $priv === null || strlen($priv['value']) !== 8) {
                return null;
            }
            $iv = pack('NN', max(0, $this->decodeInteger($boots['value'])), max(0, $this->decodeInteger($time['value']))).$priv['value'];
            $decrypted = openssl_decrypt($msgData['value'], 'aes-128-cfb', $privKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
            if (! is_string($decrypted)) {
                return null;
            }
            $d = 0;
            $scopedTlv = $this->readTlv($decrypted, $d);
            if ($scopedTlv === null || $scopedTlv['tag'] !== 0x30) {
                return null;
            }
            $scopedBytes = $scopedTlv['value'];
        }
        if ($scopedBytes === null) {
            return null;
        }

        $sp = 0;
        $this->readTlv($scopedBytes, $sp);
        $this->readTlv($scopedBytes, $sp);
        $pdu = $this->readTlv($scopedBytes, $sp);
        if ($pdu === null || $pdu['tag'] !== 0xA2) {
            return null;
        }

        return $this->decodePduFirstVarbind($pdu['value'], $expectedRequestId);
    }

    /** @return array{oid: string, tag: int, raw: string, int: ?int}|null */
    private function decodePduFirstVarbind(string $pduValue, int $expectedRequestId): ?array
    {
        $offset = 0;
        $reqId = $this->readTlv($pduValue, $offset);
        $errStatus = $this->readTlv($pduValue, $offset);
        $errIndex = $this->readTlv($pduValue, $offset);
        $varbindList = $this->readTlv($pduValue, $offset);
        if ($reqId === null || $errStatus === null || $errIndex === null || $varbindList === null || $varbindList['tag'] !== 0x30) {
            return null;
        }
        if ($this->decodeInteger($reqId['value']) !== $expectedRequestId || $this->decodeInteger($errStatus['value']) !== 0) {
            return null;
        }

        $listOffset = 0;
        $vb = $this->readTlv($varbindList['value'], $listOffset);
        if ($vb === null || $vb['tag'] !== 0x30) {
            return null;
        }
        $vbOffset = 0;
        $oidTlv = $this->readTlv($vb['value'], $vbOffset);
        $valTlv = $this->readTlv($vb['value'], $vbOffset);
        if ($oidTlv === null || $oidTlv['tag'] !== 0x06 || $valTlv === null) {
            return null;
        }
        $oid = $this->decodeOid($oidTlv['value']);
        if ($oid === null) {
            return null;
        }

        $intValue = in_array($valTlv['tag'], [0x02, 0x41, 0x42], true)
            ? $this->decodeInteger($valTlv['value'])
            : null;

        return [
            'oid' => $oid,
            'tag' => $valTlv['tag'],
            'raw' => $valTlv['value'],
            'int' => $intValue,
        ];
    }

    private function formatBinaryOrTextMac(string $raw): ?string
    {
        if (strlen($raw) === 6) {
            $hex = strtoupper(bin2hex($raw));

            return MacVendorLookup::normaliseUnicastMac(implode(':', str_split($hex, 2)));
        }

        return MacVendorLookup::normaliseUnicastMac(trim($raw));
    }

    private function formatPortIdentifier(string $raw): ?string
    {
        if (strlen($raw) === 6 && preg_match('/[\x00-\x1f\x7f-\xff]/', $raw) === 1) {
            return $this->formatBinaryOrTextMac($raw);
        }

        return $this->cleanPrintable($raw, 64);
    }

    private function cleanPrintable(string $raw, int $maxLength): ?string
    {
        $clean = preg_replace('/[\x00-\x1f\x7f]+/', ' ', $raw) ?? '';
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? '');
        if ($clean === '') {
            return null;
        }

        return substr($clean, 0, $maxLength);
    }

    private function compareOids(string $a, string $b): int
    {
        $pa = array_map('intval', explode('.', ltrim($a, '.')));
        $pb = array_map('intval', explode('.', ltrim($b, '.')));
        $n = min(count($pa), count($pb));
        for ($i = 0; $i < $n; $i++) {
            if ($pa[$i] !== $pb[$i]) {
                return $pa[$i] <=> $pb[$i];
            }
        }

        return count($pa) <=> count($pb);
    }

    /** @param list<string> $oids */
    private function encodePdu(int $pduTag, int $requestId, array $oids): string
    {
        $varbindBytes = '';
        foreach ($oids as $oid) {
            $varbindBytes .= $this->encodeSequence($this->encodeOid($oid)."\x05\x00");
        }
        $body = $this->encodeInteger($requestId)
            .$this->encodeInteger(0)
            .$this->encodeInteger(0)
            .$this->encodeSequence($varbindBytes);

        return chr($pduTag).$this->encodeLength(strlen($body)).$body;
    }

    private function encodeSequence(string $body): string
    {
        return "\x30".$this->encodeLength(strlen($body)).$body;
    }

    private function encodeOctetString(string $bytes): string
    {
        return "\x04".$this->encodeLength(strlen($bytes)).$bytes;
    }

    private function encodeInteger(int $value): string
    {
        if ($value === 0) {
            return "\x02\x01\x00";
        }
        $bytes = [];
        $temp = $value;
        while ($temp > 0) {
            array_unshift($bytes, $temp & 0xFF);
            $temp >>= 8;
        }
        if (($bytes[0] & 0x80) !== 0) {
            array_unshift($bytes, 0x00);
        }
        $raw = implode('', array_map('chr', $bytes));

        return "\x02".$this->encodeLength(strlen($raw)).$raw;
    }

    private function encodeOid(string $oid): string
    {
        $parts = array_map('intval', explode('.', ltrim($oid, '.')));
        if (count($parts) < 2) {
            return "\x06\x01\x00";
        }
        $encoded = chr($parts[0] * 40 + $parts[1]);
        for ($i = 2, $count = count($parts); $i < $count; $i++) {
            $subId = max(0, $parts[$i]);
            if ($subId === 0) {
                $encoded .= "\x00";

                continue;
            }
            $stack = [];
            while ($subId > 0) {
                array_unshift($stack, $subId & 0x7F);
                $subId >>= 7;
            }
            $last = count($stack) - 1;
            foreach ($stack as $idx => $sevenBits) {
                $encoded .= chr($idx === $last ? $sevenBits : ($sevenBits | 0x80));
            }
        }

        return "\x06".$this->encodeLength(strlen($encoded)).$encoded;
    }

    private function encodeLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = '';
        $temp = $length;
        while ($temp > 0) {
            $bytes = chr($temp & 0xFF).$bytes;
            $temp >>= 8;
        }

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    /** @return array{tag: int, value: string}|null */
    private function readTlv(string $buffer, int &$offset): ?array
    {
        $total = strlen($buffer);
        if ($offset + 2 > $total) {
            return null;
        }
        $tag = ord($buffer[$offset++]);
        $lenByte = ord($buffer[$offset++]);
        if (($lenByte & 0x80) === 0) {
            $length = $lenByte;
        } else {
            $numBytes = $lenByte & 0x7F;
            if ($numBytes < 1 || $numBytes > 3 || $offset + $numBytes > $total) {
                return null;
            }
            $length = 0;
            for ($i = 0; $i < $numBytes; $i++) {
                $length = ($length << 8) | ord($buffer[$offset++]);
            }
        }
        if ($length < 0 || $offset + $length > $total) {
            return null;
        }
        $value = substr($buffer, $offset, $length);
        $offset += $length;

        return ['tag' => $tag, 'value' => $value];
    }

    private function decodeInteger(string $bytes): int
    {
        if ($bytes === '' || strlen($bytes) > 8) {
            return 0;
        }
        $val = (ord($bytes[0]) & 0x80) !== 0 ? -1 : 0;
        for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
            $val = ($val << 8) | ord($bytes[$i]);
        }

        return $val;
    }

    private function decodeOid(string $bytes): ?string
    {
        $len = strlen($bytes);
        if ($len < 1) {
            return null;
        }
        $first = ord($bytes[0]);
        $firstArc = min(2, intdiv($first, 40));
        $secondArc = $first - ($firstArc * 40);
        $arcs = [(string) $firstArc, (string) $secondArc];

        $value = 0;
        for ($i = 1; $i < $len; $i++) {
            $byte = ord($bytes[$i]);
            $value = ($value << 7) | ($byte & 0x7F);
            if (($byte & 0x80) === 0) {
                $arcs[] = (string) $value;
                $value = 0;
                if (count($arcs) > 64) {
                    return null;
                }
            }
        }

        return implode('.', $arcs);
    }
}
