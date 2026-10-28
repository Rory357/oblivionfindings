<?php

namespace Oblivion\Collector\Discovery;

use Closure;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Multi-protocol local subnet discovery helper for approved Site hub scopes:
 * - ARP / neighbour table inspection (Windows `arp -a` + `ipconfig /all`, Linux `/proc/net/arp` + `ip neigh`)
 * - mDNS / DNS-SD service discovery (RFC 6762 / RFC 6763, 224.0.0.251:5353)
 * - SSDP + SSRF-safe UPnP device description XML (UPnP Device Architecture 2.0, 239.255.255.250:1900)
 * - ONVIF WS-Discovery SOAP ProbeMatches (ONVIF Core Specification, 239.255.255.250:3702)
 * - Bounded HTTP banners, TLS Subject/SAN/SHA-256 certificate inspection, and UDP 53 reverse PTR
 * - Pure-PHP SNMPv2c / SNMPv3 system-MIB probes (RFC 3416 / RFC 3414 / RFC 3418)
 * - Public IEEE OUI / MA-M MAC vendor lookup
 */
final class LanDiscoveryScanner
{
    /** @var list<string> */
    private const array FORBIDDEN_SUBSTRINGS = [
        'credential',
        'password',
        'secret',
        'command',
        'shell',
    ];

    /** @var list<string> */
    private const array ALLOWED_SCALAR_SIGNAL_KEYS = [
        'mac_vendor',
        'http_server',
        'http_title',
        'http_auth_realm',
        'http_status',
        'tls_common_name',
        'tls_organization',
        'snmp_sys_descr',
        'snmp_sys_object_id',
        'snmp_sys_name',
        'ssdp_server',
        'ssdp_st',
        'upnp_friendly_name',
        'upnp_manufacturer',
        'upnp_model_name',
        'upnp_device_type',
        'onvif_hardware',
        'onvif_name',
        'onvif_types',
        'mdns_hostname',
        'mdns_model',
    ];

    /** @var list<string> */
    private const array MDNS_QUERY_SERVICES = [
        '_services._dns-sd._udp.local',
        '_http._tcp.local',
        '_https._tcp.local',
        '_rtsp._tcp.local',
        '_onvif._tcp.local',
        '_ipp._tcp.local',
        '_ipps._tcp.local',
        '_pdl-datastream._tcp.local',
        '_printer._tcp.local',
        '_smb._tcp.local',
        '_workstation._tcp.local',
        '_ssh._tcp.local',
        '_googlecast._tcp.local',
        '_airplay._tcp.local',
        '_raop._tcp.local',
        '_hap._tcp.local',
        '_sonos._tcp.local',
        '_mqtt._tcp.local',
    ];

    /** @var array<string, string> ip => normalised MAC */
    private array $arpByIp = [];

    /** @var array<string, array<string, mixed>> ip => multicast signals */
    private array $multicastByIp = [];

    /** @var list<string> */
    private array $dnsServers = [];

    private ?string $localHostIp = null;

    private ?string $localHostMac = null;

    private ?string $localHostname = null;

    private bool $directlyAttachedSubnet = false;

    public function __construct(
        private readonly MacVendorLookup $macVendors = new MacVendorLookup,
        private readonly SnmpSystemProbe $snmpProbe = new SnmpSystemProbe,
    ) {}

    public static function scrubBannerString(mixed $value, int $maxLength = 160): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $clean = preg_replace('/[\x00-\x1f\x7f]+/', ' ', $value) ?? '';
        $clean = preg_replace('/\s+/', ' ', trim($clean)) ?? '';
        foreach (self::FORBIDDEN_SUBSTRINGS as $forbidden) {
            $clean = preg_replace('/'.preg_quote($forbidden, '/').'/i', '', $clean) ?? $clean;
        }
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? '');
        if ($clean === '') {
            return null;
        }

        return mb_substr($clean, 0, max(16, min(256, $maxLength)));
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public static function sanitizeSignals(array $raw): array
    {
        $clean = [];

        foreach (self::ALLOWED_SCALAR_SIGNAL_KEYS as $key) {
            if (! array_key_exists($key, $raw)) {
                continue;
            }
            if ($key === 'http_status') {
                $status = $raw[$key];
                if (is_int($status) && $status >= 100 && $status <= 599) {
                    $clean[$key] = $status;
                }

                continue;
            }

            $scrubbed = self::scrubBannerString($raw[$key], 200);
            if ($scrubbed !== null) {
                $clean[$key] = $scrubbed;
            }
        }

        if (is_array($raw['open_ports'] ?? null)) {
            $ports = [];
            foreach (array_slice($raw['open_ports'], 0, 32) as $port) {
                if (is_int($port) && $port >= 1 && $port <= 65535) {
                    $ports[$port] = $port;
                }
            }
            if ($ports !== []) {
                ksort($ports);
                $clean['open_ports'] = array_values($ports);
            }
        }

        foreach (['mdns_services', 'onvif_scopes', 'protocols_seen'] as $listKey) {
            if (! is_array($raw[$listKey] ?? null)) {
                continue;
            }
            $items = [];
            foreach (array_slice($raw[$listKey], 0, 20) as $entry) {
                $scrubbed = self::scrubBannerString($entry, 160);
                if ($scrubbed !== null) {
                    $items[$scrubbed] = $scrubbed;
                }
            }
            if ($items !== []) {
                $clean[$listKey] = array_values($items);
            }
        }

        return $clean;
    }

    /**
     * @param  list<string>  $approvedCidrs
     * @param  list<string>  $protocols
     * @param  list<string>  $exclusions
     * @param  Closure(): void  $acquireToken
     */
    public function beginScope(
        array $approvedCidrs,
        array $protocols,
        array $exclusions,
        int $packetsPerSecond,
        Closure $acquireToken,
    ): void {
        $this->arpByIp = [];
        $this->multicastByIp = [];
        $this->dnsServers = [];
        $this->localHostIp = null;
        $this->localHostMac = null;
        $this->localHostname = null;
        $this->directlyAttachedSubnet = false;

        $protocols = array_map('strtolower', $protocols);
        $sampleIp = $this->sampleIpFromCidrs($approvedCidrs);
        $bindIp = $sampleIp !== null ? $this->detectRouteLocalIp($sampleIp) : null;
        $this->localHostIp = $bindIp;

        if ($bindIp !== null) {
            foreach ($approvedCidrs as $cidr) {
                if ($this->cidrContains($cidr, $bindIp)) {
                    $this->directlyAttachedSubnet = true;
                    break;
                }
            }
        }

        $this->discoverLocalInterfaceAndDns($bindIp);

        $isAllowedIp = fn (string $ip): bool => $this->isAuthorisedAddress($ip, $approvedCidrs, $exclusions);

        if (in_array('onvif', $protocols, true)) {
            $acquireToken();
            $this->sweepOnvifWsDiscovery($bindIp, $isAllowedIp);
        }

        if (in_array('ssdp', $protocols, true)) {
            $acquireToken();
            $this->sweepSsdp($bindIp, $isAllowedIp, $acquireToken);
        }

        if (in_array('mdns', $protocols, true)) {
            $acquireToken();
            $this->sweepMdns($bindIp, $isAllowedIp);
        }

        if (in_array('arp', $protocols, true)) {
            if ($this->directlyAttachedSubnet) {
                $this->prewarmLocalArpCache($approvedCidrs, $exclusions, $packetsPerSecond);
            }
            $this->refreshArpTable($approvedCidrs, $exclusions);
        }
    }

    public function isDirectlyAttachedSubnet(): bool
    {
        return $this->directlyAttachedSubnet;
    }

    public function hasPreSweepEvidence(string $ip): bool
    {
        return isset($this->arpByIp[$ip]) || isset($this->multicastByIp[$ip]);
    }

    public function macForIp(string $ip, bool $allowRefresh = false): ?string
    {
        if (isset($this->arpByIp[$ip])) {
            return $this->arpByIp[$ip];
        }
        if ($allowRefresh && $this->directlyAttachedSubnet) {
            $mac = $this->querySingleArpMac($ip);
            if ($mac !== null) {
                $this->arpByIp[$ip] = $mac;

                return $mac;
            }
        }

        return null;
    }

    public function lookupMacVendor(?string $mac): ?string
    {
        return $this->macVendors->lookup($mac);
    }

    /** @return array<string, mixed> */
    public function multicastSignalsForIp(string $ip): array
    {
        return $this->multicastByIp[$ip] ?? [];
    }

    public function localHostnameForIp(string $ip): ?string
    {
        if ($this->localHostIp !== null && $this->localHostIp === $ip) {
            return $this->localHostname;
        }

        return null;
    }

    /**
     * @param  list<int>  $ports
     * @param  Closure(): void  $acquireToken
     * @return list<int>
     */
    public function probeTcpPorts(string $ip, array $ports, Closure $acquireToken, float $timeoutSeconds = 0.45): array
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false || $ports === []) {
            return [];
        }

        $host = str_contains($ip, ':') ? "[{$ip}]" : $ip;
        $open = [];

        foreach (array_chunk(array_values(array_unique($ports)), 20) as $batch) {
            $sockets = [];
            $portById = [];

            foreach ($batch as $port) {
                if (! is_int($port) || $port < 1 || $port > 65535) {
                    continue;
                }
                $acquireToken();
                $sock = @stream_socket_client(
                    "tcp://{$host}:{$port}",
                    $errno,
                    $errstr,
                    0,
                    STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
                );
                if (is_resource($sock)) {
                    stream_set_blocking($sock, false);
                    $id = (int) $sock;
                    $sockets[$id] = $sock;
                    $portById[$id] = $port;
                }
            }

            $deadline = microtime(true) + max(0.15, $timeoutSeconds);
            while ($sockets !== [] && ($remaining = $deadline - microtime(true)) > 0) {
                $read = [];
                $write = array_values($sockets);
                $except = array_values($sockets);
                $sec = (int) floor($remaining);
                $usec = max(10_000, (int) round(($remaining - $sec) * 1_000_000));
                $ready = @stream_select($read, $write, $except, $sec, $usec);
                if ($ready === false || $ready === 0) {
                    break;
                }

                $failedIds = [];
                foreach ($except as $sock) {
                    $id = (int) $sock;
                    $failedIds[$id] = true;
                    @fclose($sock);
                    unset($sockets[$id]);
                }

                foreach ($write as $sock) {
                    $id = (int) $sock;
                    if (isset($failedIds[$id]) || ! isset($sockets[$id])) {
                        continue;
                    }
                    if (@stream_socket_get_name($sock, true) !== false) {
                        $open[] = $portById[$id];
                    }
                    @fclose($sock);
                    unset($sockets[$id]);
                }
            }

            foreach ($sockets as $sock) {
                @fclose($sock);
            }
        }

        $open = array_values(array_unique($open));
        sort($open);

        return $open;
    }

    /** @return array{http_status?: int, http_server?: string, http_title?: string, http_auth_realm?: string} */
    public function inspectHttpBanner(string $ip, int $port, bool $tls = false, float $timeoutSeconds = 0.8): array
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false || $port < 1 || $port > 65535) {
            return [];
        }

        $scheme = $tls ? 'ssl' : 'tcp';
        $host = str_contains($ip, ':') ? "[{$ip}]" : $ip;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'SNI_enabled' => true,
                'peer_name' => $ip,
                'disable_compression' => true,
            ],
        ]);

        $socket = @stream_socket_client(
            "{$scheme}://{$host}:{$port}",
            $errno,
            $errstr,
            max(0.25, $timeoutSeconds),
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if (! is_resource($socket)) {
            return [];
        }

        try {
            $sec = (int) floor($timeoutSeconds);
            $usec = max(50_000, (int) round(($timeoutSeconds - $sec) * 1_000_000));
            stream_set_timeout($socket, $sec, $usec);
            $hostHeader = ($port === 80 || $port === 443) ? $host : "{$host}:{$port}";
            $request = "GET / HTTP/1.1\r\nHost: {$hostHeader}\r\nUser-Agent: OblivionCollector/1.0\r\nAccept: text/html,*/*\r\nConnection: close\r\n\r\n";
            if (@fwrite($socket, $request) === false) {
                return [];
            }

            $raw = '';
            while (! feof($socket) && strlen($raw) < 16_384) {
                $chunk = @fread($socket, 4096);
                if (! is_string($chunk) || $chunk === '') {
                    break;
                }
                $raw .= $chunk;
                if (stripos($raw, '</title>') !== false) {
                    break;
                }
            }

            return $this->parseHttpBannerResponse($raw);
        } finally {
            @fclose($socket);
        }
    }

    /** @return array{certificate_fingerprint?: string, tls_common_name?: string, tls_organization?: string} */
    public function inspectTlsCertificate(string $ip, int $port = 443, float $timeoutSeconds = 0.85): array
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false || $port < 1 || $port > 65535) {
            return [];
        }

        $host = str_contains($ip, ':') ? "[{$ip}]" : $ip;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'SNI_enabled' => true,
                'peer_name' => $ip,
                'capture_peer_cert' => true,
                'disable_compression' => true,
            ],
        ]);

        $socket = @stream_socket_client(
            "ssl://{$host}:{$port}",
            $errno,
            $errstr,
            max(0.25, $timeoutSeconds),
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if (! is_resource($socket)) {
            return [];
        }

        try {
            $params = stream_context_get_params($socket);
            $cert = $params['options']['ssl']['peer_certificate'] ?? null;
            if ($cert === null) {
                return [];
            }

            $out = [];
            $fp = @openssl_x509_fingerprint($cert, 'sha256');
            if (is_string($fp) && preg_match('/^[a-f0-9]{64}$/i', $fp) === 1) {
                $out['certificate_fingerprint'] = strtolower($fp);
            }

            $parsed = @openssl_x509_parse($cert, true);
            if (is_array($parsed)) {
                $cnRaw = is_array($parsed['subject']['CN'] ?? null)
                    ? ($parsed['subject']['CN'][0] ?? null)
                    : ($parsed['subject']['CN'] ?? null);
                $orgRaw = is_array($parsed['subject']['O'] ?? null)
                    ? ($parsed['subject']['O'][0] ?? null)
                    : ($parsed['subject']['O'] ?? null);

                $cn = self::scrubBannerString($cnRaw, 160);
                $org = self::scrubBannerString($orgRaw, 160);
                if ($cn !== null) {
                    $out['tls_common_name'] = $cn;
                }
                if ($org !== null) {
                    $out['tls_organization'] = $org;
                }
            }

            return $out;
        } finally {
            @fclose($socket);
        }
    }

    public function reverseDnsPtr(string $ip, float $timeoutSeconds = 0.25): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        $servers = $this->dnsServers;
        if ($servers === [] && preg_match('/^(\d+\.\d+\.\d+)\.\d+$/', $ip, $m) === 1) {
            $servers = [$m[1].'.1'];
        }

        $reversed = implode('.', array_reverse(explode('.', $ip))).'.in-addr.arpa';
        $txId = random_int(1, 65534);
        $qname = $this->encodeDnsName($reversed);
        $packet = pack('nnnnnn', $txId, 0x0100, 1, 0, 0, 0).$qname.pack('nn', 12, 1);

        foreach (array_slice($servers, 0, 2) as $dnsIp) {
            if (filter_var($dnsIp, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            $sock = @stream_socket_client("udp://{$dnsIp}:53", $errno, $errstr, 0.2);
            if (! is_resource($sock)) {
                continue;
            }
            try {
                stream_set_timeout($sock, 0, max(50_000, (int) round($timeoutSeconds * 1_000_000)));
                @fwrite($sock, $packet);
                $reply = @fread($sock, 1024);
                if (! is_string($reply) || strlen($reply) < 12) {
                    continue;
                }
                $ptr = $this->decodeDnsPtrResponse($reply, $txId);
                if ($ptr !== null) {
                    return $ptr;
                }
            } finally {
                @fclose($sock);
            }
        }

        return null;
    }

    /**
     * @param  array<string, scalar|null>|null  $material
     * @return array{sys_descr: ?string, sys_object_id: ?string, sys_name: ?string}|null
     */
    public function probeSnmpSystem(string $ip, ?array $material = null, int $port = 161): ?array
    {
        if (is_array($material) && $material !== []) {
            if (isset($material['security_name']) || isset($material['username'])) {
                return $this->snmpProbe->probeV3($ip, $material, $port, 0.8);
            }
            if (is_string($material['community'] ?? null) && $material['community'] !== '') {
                return $this->snmpProbe->probeV2c($ip, $material['community'], $port, 0.6);
            }
        }

        return $this->snmpProbe->probeV2c($ip, 'public', $port, 0.45);
    }

    /**
     * @param  list<string>  $approvedCidrs
     * @param  list<string>  $exclusions
     */
    private function isAuthorisedAddress(string $ip, array $approvedCidrs, array $exclusions): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $inside = false;
        foreach ($approvedCidrs as $cidr) {
            if ($this->cidrContains($cidr, $ip) && ! $this->isIpv4NetworkOrBroadcast($cidr, $ip)) {
                $inside = true;
                break;
            }
        }
        if (! $inside) {
            return false;
        }

        $ipBin = @inet_pton($ip);
        foreach ($exclusions as $ex) {
            if (! is_string($ex) || trim($ex) === '') {
                continue;
            }
            $ex = trim($ex);
            if (str_contains($ex, '/') && $this->cidrContains($ex, $ip)) {
                return false;
            }
            if (filter_var($ex, FILTER_VALIDATE_IP) !== false && $ipBin !== false && @inet_pton($ex) === $ipBin) {
                return false;
            }
        }

        return true;
    }

    private function cidrContains(string $cidr, string $ip): bool
    {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2 || filter_var($parts[0], FILTER_VALIDATE_IP) === false || ! ctype_digit($parts[1])) {
            return false;
        }
        $net = @inet_pton($parts[0]);
        $cand = @inet_pton($ip);
        if ($net === false || $cand === false || strlen($net) !== strlen($cand)) {
            return false;
        }
        $prefix = (int) $parts[1];
        $max = strlen($net) * 8;
        if ($prefix < 0 || $prefix > $max) {
            return false;
        }
        $wholeBytes = intdiv($prefix, 8);
        $remBits = $prefix % 8;
        if (substr($net, 0, $wholeBytes) !== substr($cand, 0, $wholeBytes)) {
            return false;
        }
        if ($remBits === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remBits)) & 0xFF;

        return (ord($net[$wholeBytes]) & $mask) === (ord($cand[$wholeBytes]) & $mask);
    }

    private function isIpv4NetworkOrBroadcast(string $cidr, string $ip): bool
    {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2
            || filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || ! ctype_digit($parts[1])) {
            return false;
        }
        $prefix = (int) $parts[1];
        if ($prefix >= 31 || $prefix < 0) {
            return false;
        }
        $netLong = ip2long($parts[0]);
        $ipLong = ip2long($ip);
        if ($netLong === false || $ipLong === false) {
            return false;
        }
        $mask = $prefix === 0 ? 0 : ((-1 << (32 - $prefix)) & 0xFFFFFFFF);
        $network = $netLong & $mask;
        $broadcast = $network | ((~$mask) & 0xFFFFFFFF);

        return $ipLong === $network || $ipLong === $broadcast;
    }

    /**
     * @param  list<string>  $exclusions
     * @return list<string>
     */
    private function expandIpv4Cidr(string $cidr, int $limit, array $exclusions): array
    {
        if ($limit <= 0) {
            return [];
        }
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2
            || filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || ! ctype_digit($parts[1])) {
            return [];
        }
        $prefix = (int) $parts[1];
        if ($prefix < 8 || $prefix > 32) {
            return [];
        }
        $netLong = ip2long($parts[0]);
        if ($netLong === false) {
            return [];
        }
        $mask = $prefix === 0 ? 0 : ((-1 << (32 - $prefix)) & 0xFFFFFFFF);
        $network = $netLong & $mask;
        $broadcast = $network | ((~$mask) & 0xFFFFFFFF);
        $start = $prefix >= 31 ? $network : $network + 1;
        $end = $prefix >= 31 ? $broadcast : $broadcast - 1;

        $out = [];
        for ($cur = $start; $cur <= $end && count($out) < $limit; $cur++) {
            $ip = long2ip($cur);
            if (! is_string($ip)) {
                continue;
            }
            if ($this->isAuthorisedAddress($ip, [$cidr], $exclusions)) {
                $out[] = $ip;
            }
        }

        return $out;
    }

    /** @param list<string> $approvedCidrs */
    private function sampleIpFromCidrs(array $approvedCidrs): ?string
    {
        foreach ($approvedCidrs as $cidr) {
            if (! is_string($cidr)) {
                continue;
            }
            $expanded = $this->expandIpv4Cidr($cidr, 1, []);
            if ($expanded !== []) {
                return $expanded[0];
            }
        }

        return null;
    }

    private function detectRouteLocalIp(string $destinationIp): ?string
    {
        if (! function_exists('socket_create') || filter_var($destinationIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }
        $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sock === false) {
            return null;
        }
        try {
            if (@socket_connect($sock, $destinationIp, 80) && @socket_getsockname($sock, $localIp)) {
                return is_string($localIp) && filter_var($localIp, FILTER_VALIDATE_IP) !== false && $localIp !== '0.0.0.0'
                    ? $localIp
                    : null;
            }

            return null;
        } finally {
            @socket_close($sock);
        }
    }

    private function discoverLocalInterfaceAndDns(?string $bindIp): void
    {
        $rawHost = @gethostname();
        if (is_string($rawHost) && trim($rawHost) !== '') {
            $this->localHostname = self::scrubBannerString($rawHost, 253);
        }

        if (PHP_OS_FAMILY === 'Windows') {
            try {
                $proc = new Process(['ipconfig', '/all']);
                $proc->setTimeout(3);
                $proc->run();
                if ($proc->isSuccessful()) {
                    $this->parseWindowsIpconfigAll($proc->getOutput(), $bindIp);
                }
            } catch (Throwable) {
            }

            return;
        }

        if (is_file('/etc/resolv.conf')) {
            $resolv = @file_get_contents('/etc/resolv.conf');
            if (is_string($resolv)) {
                foreach (preg_split('/\R/', $resolv) ?: [] as $line) {
                    if (preg_match('/^\s*nameserver\s+([0-9.]+)\b/', $line, $m) === 1
                        && filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                        && ! str_starts_with($m[1], '127.')) {
                        $this->dnsServers[] = $m[1];
                    }
                }
            }
        }

        if ($bindIp !== null) {
            try {
                $proc = new Process(['ip', '-o', 'link', 'show']);
                $proc->setTimeout(2);
                $proc->run();
                $addrProc = new Process(['ip', '-o', '-4', 'addr', 'show']);
                $addrProc->setTimeout(2);
                $addrProc->run();
                if ($proc->isSuccessful() && $addrProc->isSuccessful()) {
                    $ifaceForIp = null;
                    foreach (preg_split('/\R/', $addrProc->getOutput()) ?: [] as $line) {
                        if (preg_match('/^\d+:\s+(\S+)\s+inet\s+'.preg_quote($bindIp, '/').'\//', $line, $m) === 1) {
                            $ifaceForIp = $m[1];
                            break;
                        }
                    }
                    if ($ifaceForIp !== null) {
                        foreach (preg_split('/\R/', $proc->getOutput()) ?: [] as $line) {
                            if (preg_match('/^\d+:\s+'.preg_quote($ifaceForIp, '/').'[:@].*?\blink\/ether\s+([0-9a-f:]{17})\b/i', $line, $m) === 1) {
                                $mac = MacVendorLookup::normaliseUnicastMac($m[1]);
                                if ($mac !== null) {
                                    $this->localHostMac = $mac;
                                    $this->arpByIp[$bindIp] = $mac;
                                }
                                break;
                            }
                        }
                    }
                }
            } catch (Throwable) {
            }
        }
    }

    private function parseWindowsIpconfigAll(string $output, ?string $bindIp): void
    {
        $blocks = preg_split('/\R(?=[A-Za-z0-9].*adapter\s)/i', $output) ?: [$output];
        foreach ($blocks as $block) {
            $mac = null;
            if (preg_match('/Physical Address[.\s]*:\s*([0-9A-Fa-f]{2}(?:-[0-9A-Fa-f]{2}){5})/i', $block, $m) === 1) {
                $mac = MacVendorLookup::normaliseUnicastMac($m[1]);
            }
            $ips = [];
            if (preg_match_all('/IPv4 Address[.\s]*:\s*([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)/i', $block, $matches) > 0) {
                $ips = $matches[1];
            }
            if ($bindIp !== null && in_array($bindIp, $ips, true)) {
                if ($mac !== null) {
                    $this->localHostMac = $mac;
                    $this->arpByIp[$bindIp] = $mac;
                }
                if (preg_match('/DNS Servers[.\s]*:\s*([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)/i', $block, $dm) === 1) {
                    $this->dnsServers[] = $dm[1];
                }
            }
        }
    }

    /**
     * @param  list<string>  $approvedCidrs
     * @param  list<string>  $exclusions
     */
    private function prewarmLocalArpCache(array $approvedCidrs, array $exclusions, int $packetsPerSecond): void
    {
        if (! function_exists('socket_create')) {
            return;
        }
        $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sock === false) {
            return;
        }
        socket_set_nonblock($sock);

        try {
            $effectiveRate = max(20, min(1000, $packetsPerSecond * 2));
            $delayUs = max(1000, intdiv(1_000_000, $effectiveRate));
            $sent = 0;

            foreach ($approvedCidrs as $cidr) {
                if (! is_string($cidr) || str_contains($cidr, ':')) {
                    continue;
                }
                foreach ($this->expandIpv4Cidr($cidr, 256 - $sent, $exclusions) as $ip) {
                    if ($ip === $this->localHostIp) {
                        continue;
                    }
                    @socket_sendto($sock, "\x00", 1, 0, $ip, 33434);
                    $sent++;
                    if ($sent % 8 === 0) {
                        usleep($delayUs * 8);
                    }
                    if ($sent >= 256) {
                        break 2;
                    }
                }
            }

            if ($sent > 0) {
                usleep(160_000);
            }
        } finally {
            @socket_close($sock);
        }
    }

    /**
     * @param  list<string>  $approvedCidrs
     * @param  list<string>  $exclusions
     */
    private function refreshArpTable(array $approvedCidrs, array $exclusions): void
    {
        if ($this->localHostIp !== null
            && $this->localHostMac !== null
            && $this->isAuthorisedAddress($this->localHostIp, $approvedCidrs, $exclusions)) {
            $this->arpByIp[$this->localHostIp] = $this->localHostMac;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            try {
                $proc = new Process(['arp', '-a']);
                $proc->setTimeout(3);
                $proc->run();
                if ($proc->isSuccessful()) {
                    $this->parseWindowsArpOutput($proc->getOutput(), $approvedCidrs, $exclusions);
                }
            } catch (Throwable) {
            }

            return;
        }

        if (is_file('/proc/net/arp')) {
            $contents = @file_get_contents('/proc/net/arp');
            if (is_string($contents)) {
                foreach (preg_split('/\R/', $contents) ?: [] as $idx => $line) {
                    if ($idx === 0 || trim($line) === '') {
                        continue;
                    }
                    $cols = preg_split('/\s+/', trim($line)) ?: [];
                    if (count($cols) < 4) {
                        continue;
                    }
                    [$ip, , $flags, $macRaw] = $cols;
                    if (strtolower($flags) === '0x0') {
                        continue;
                    }
                    $mac = MacVendorLookup::normaliseUnicastMac($macRaw);
                    if ($mac !== null && $this->isAuthorisedAddress($ip, $approvedCidrs, $exclusions)) {
                        $this->arpByIp[$ip] = $mac;
                    }
                }
            }
        }

        try {
            $proc = new Process(['ip', '-4', 'neigh', 'show']);
            $proc->setTimeout(2);
            $proc->run();
            if ($proc->isSuccessful()) {
                foreach (preg_split('/\R/', $proc->getOutput()) ?: [] as $line) {
                    if (preg_match('/^(\d+\.\d+\.\d+\.\d+)\s+.*?\blladdr\s+([0-9a-f:]{17})\b/i', trim($line), $m) === 1) {
                        $mac = MacVendorLookup::normaliseUnicastMac($m[2]);
                        if ($mac !== null && $this->isAuthorisedAddress($m[1], $approvedCidrs, $exclusions)) {
                            $this->arpByIp[$m[1]] = $mac;
                        }
                    }
                }
            }
        } catch (Throwable) {
        }
    }

    /**
     * @param  list<string>  $approvedCidrs
     * @param  list<string>  $exclusions
     */
    private function parseWindowsArpOutput(string $output, array $approvedCidrs, array $exclusions): void
    {
        $onLocalInterface = $this->localHostIp === null;
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/^\s*Interface:\s*([0-9.]+)/i', $line, $im) === 1) {
                $onLocalInterface = $this->localHostIp === null || $im[1] === $this->localHostIp;

                continue;
            }
            if (! $onLocalInterface) {
                continue;
            }
            if (preg_match('/^\s*(\d+\.\d+\.\d+\.\d+)\s+([0-9a-f]{2}(?:[:-][0-9a-f]{2}){5})\s+(\S+)/i', $line, $m) === 1) {
                $type = strtolower($m[3]);
                if ($type === 'invalid') {
                    continue;
                }
                $mac = MacVendorLookup::normaliseUnicastMac($m[2]);
                if ($mac !== null && $this->isAuthorisedAddress($m[1], $approvedCidrs, $exclusions)) {
                    $this->arpByIp[$m[1]] = $mac;
                }
            }
        }
    }

    private function querySingleArpMac(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }
        if (PHP_OS_FAMILY !== 'Windows' && is_file('/proc/net/arp')) {
            $contents = @file_get_contents('/proc/net/arp');
            if (is_string($contents)) {
                foreach (preg_split('/\R/', $contents) ?: [] as $line) {
                    $cols = preg_split('/\s+/', trim($line)) ?: [];
                    if (($cols[0] ?? null) === $ip && strtolower((string) ($cols[2] ?? '0x0')) !== '0x0') {
                        return MacVendorLookup::normaliseUnicastMac($cols[3] ?? null);
                    }
                }
            }

            return null;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            try {
                $proc = new Process(['arp', '-a', $ip]);
                $proc->setTimeout(2);
                $proc->run();
                if ($proc->isSuccessful() && preg_match('/\b'.preg_quote($ip, '/').'\s+([0-9a-f]{2}(?:-[0-9a-f]{2}){5})\s+dynamic\b/i', $proc->getOutput(), $m) === 1) {
                    return MacVendorLookup::normaliseUnicastMac($m[1]);
                }
            } catch (Throwable) {
            }
        }

        return null;
    }

    /** @param Closure(string): bool $isAllowedIp */
    private function sweepOnvifWsDiscovery(?string $bindIp, Closure $isAllowedIp): void
    {
        $sock = $this->openUdpMulticastSocket($bindIp);
        if ($sock === null) {
            return;
        }

        try {
            $uuid1 = $this->randomUuidV4();
            $uuid2 = $this->randomUuidV4();
            $nvtProbe = '<?xml version="1.0" encoding="UTF-8"?>'
                .'<e:Envelope xmlns:e="http://www.w3.org/2003/05/soap-envelope" '
                .'xmlns:w="http://schemas.xmlsoap.org/ws/2004/08/addressing" '
                .'xmlns:d="http://schemas.xmlsoap.org/ws/2005/04/discovery" '
                .'xmlns:dn="http://www.onvif.org/ver10/network/wsdl">'
                ."<e:Header><w:MessageID>uuid:{$uuid1}</w:MessageID>"
                .'<w:To>urn:schemas-xmlsoap-org:ws:2005:04:discovery</w:To>'
                .'<w:Action>http://schemas.xmlsoap.org/ws/2005/04/discovery/Probe</w:Action></e:Header>'
                .'<e:Body><d:Probe><d:Types>dn:NetworkVideoTransmitter</d:Types></d:Probe></e:Body></e:Envelope>';

            $deviceProbe = '<?xml version="1.0" encoding="UTF-8"?>'
                .'<e:Envelope xmlns:e="http://www.w3.org/2003/05/soap-envelope" '
                .'xmlns:w="http://schemas.xmlsoap.org/ws/2004/08/addressing" '
                .'xmlns:d="http://schemas.xmlsoap.org/ws/2005/04/discovery" '
                .'xmlns:tds="http://www.onvif.org/ver10/device/wsdl">'
                ."<e:Header><w:MessageID>uuid:{$uuid2}</w:MessageID>"
                .'<w:To>urn:schemas-xmlsoap-org:ws:2005:04:discovery</w:To>'
                .'<w:Action>http://schemas.xmlsoap.org/ws/2005/04/discovery/Probe</w:Action></e:Header>'
                .'<e:Body><d:Probe><d:Types>tds:Device</d:Types></d:Probe></e:Body></e:Envelope>';

            @socket_sendto($sock, $nvtProbe, strlen($nvtProbe), 0, '239.255.255.250', 3702);
            @socket_sendto($sock, $deviceProbe, strlen($deviceProbe), 0, '239.255.255.250', 3702);

            foreach ($this->collectUdpReplies($sock, 0.55, 64) as [$fromIp, $payload]) {
                if (! $isAllowedIp($fromIp)) {
                    continue;
                }
                $parsed = self::parseOnvifProbeMatchXml($payload);
                if ($parsed !== []) {
                    $this->mergeMulticastSignals($fromIp, $parsed);
                }
            }
        } finally {
            @socket_close($sock);
        }
    }

    /**
     * @param  Closure(string): bool  $isAllowedIp
     * @param  Closure(): void  $acquireToken
     */
    private function sweepSsdp(?string $bindIp, Closure $isAllowedIp, Closure $acquireToken): void
    {
        $sock = $this->openUdpMulticastSocket($bindIp);
        if ($sock === null) {
            return;
        }

        $locationsByIp = [];
        try {
            $msearch = "M-SEARCH * HTTP/1.1\r\n"
                ."HOST: 239.255.255.250:1900\r\n"
                ."MAN: \"ssdp:discover\"\r\n"
                ."MX: 1\r\n"
                ."ST: ssdp:all\r\n\r\n";

            @socket_sendto($sock, $msearch, strlen($msearch), 0, '239.255.255.250', 1900);

            foreach ($this->collectUdpReplies($sock, 0.65, 96) as [$fromIp, $payload]) {
                if (! $isAllowedIp($fromIp)) {
                    continue;
                }
                $headers = $this->parseSsdpHeaders($payload);
                $signals = [];
                if (isset($headers['server'])) {
                    $signals['ssdp_server'] = self::scrubBannerString($headers['server'], 200);
                }
                if (isset($headers['st']) && strtolower($headers['st']) !== 'upnp:rootdevice') {
                    $signals['ssdp_st'] = self::scrubBannerString($headers['st'], 200);
                }
                if (isset($headers['location']) && ! isset($locationsByIp[$fromIp])) {
                    $locationsByIp[$fromIp] = $headers['location'];
                }
                $this->mergeMulticastSignals($fromIp, array_filter($signals));
            }
        } finally {
            @socket_close($sock);
        }

        $fetched = 0;
        foreach ($locationsByIp as $responderIp => $locationUrl) {
            if ($fetched >= 16) {
                break;
            }
            $xmlSignals = $this->fetchSafeUpnpDescription($responderIp, $locationUrl, $acquireToken);
            if ($xmlSignals !== []) {
                $this->mergeMulticastSignals($responderIp, $xmlSignals);
                $fetched++;
            }
        }
    }

    /** @param Closure(string): bool $isAllowedIp */
    private function sweepMdns(?string $bindIp, Closure $isAllowedIp): void
    {
        $sock = $this->openUdpMulticastSocket($bindIp);
        if ($sock === null) {
            return;
        }

        try {
            $questions = '';
            $qCount = 0;
            foreach (self::MDNS_QUERY_SERVICES as $service) {
                $questions .= $this->encodeDnsName($service).pack('nn', 12, 0x8001);
                $qCount++;
            }
            $packet = pack('nnnnnn', 0, 0, $qCount, 0, 0, 0).$questions;
            @socket_sendto($sock, $packet, strlen($packet), 0, '224.0.0.251', 5353);

            foreach ($this->collectUdpReplies($sock, 0.65, 96) as [$fromIp, $payload]) {
                if (! $isAllowedIp($fromIp)) {
                    continue;
                }
                $parsed = self::parseMdnsPacket($payload);
                if ($parsed !== []) {
                    $this->mergeMulticastSignals($fromIp, $parsed);
                }
            }
        } finally {
            @socket_close($sock);
        }
    }

    /** @return \Socket|null */
    private function openUdpMulticastSocket(?string $bindIp): mixed
    {
        if (! function_exists('socket_create')) {
            return null;
        }
        $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sock === false) {
            return null;
        }
        @socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1);
        if ($bindIp !== null && filter_var($bindIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            @socket_bind($sock, $bindIp, 0);
            if (defined('IPPROTO_IP') && defined('IP_MULTICAST_IF')) {
                @socket_set_option($sock, IPPROTO_IP, IP_MULTICAST_IF, $bindIp);
            }
        } else {
            @socket_bind($sock, '0.0.0.0', 0);
        }
        socket_set_nonblock($sock);

        return $sock;
    }

    /**
     * @param  \Socket  $sock
     * @return list<array{string, string}>
     */
    private function collectUdpReplies(mixed $sock, float $windowSeconds, int $maxPackets): array
    {
        $replies = [];
        $deadline = microtime(true) + max(0.1, $windowSeconds);

        while (count($replies) < $maxPackets && ($remaining = $deadline - microtime(true)) > 0) {
            $read = [$sock];
            $write = null;
            $except = null;
            $sec = (int) floor($remaining);
            $usec = max(10_000, (int) round(($remaining - $sec) * 1_000_000));
            $ready = @socket_select($read, $write, $except, $sec, $usec);
            if ($ready === false || $ready === 0) {
                break;
            }

            while (count($replies) < $maxPackets) {
                $buf = '';
                $from = '';
                $port = 0;
                $bytes = @socket_recvfrom($sock, $buf, 8192, 0, $from, $port);
                if (! is_int($bytes) || $bytes <= 0 || ! is_string($from) || $from === '') {
                    break;
                }
                $replies[] = [$from, $buf];
            }
        }

        return $replies;
    }

    /**
     * @param  Closure(): void  $acquireToken
     * @return array<string, string>
     */
    private function fetchSafeUpnpDescription(string $responderIp, string $locationUrl, Closure $acquireToken): array
    {
        $parts = @parse_url(trim($locationUrl));
        if (! is_array($parts)) {
            return [];
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $path = (string) ($parts['path'] ?? '/');
        if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
            $path .= '?'.$parts['query'];
        }

        if (! in_array($scheme, ['http', 'https'], true)
            || $host !== $responderIp
            || $port < 1 || $port > 65535
            || strlen($path) > 512) {
            return [];
        }

        $acquireToken();
        $transportScheme = $scheme === 'https' ? 'ssl' : 'tcp';
        $sockHost = str_contains($responderIp, ':') ? "[{$responderIp}]" : $responderIp;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'disable_compression' => true,
            ],
        ]);
        $socket = @stream_socket_client(
            "{$transportScheme}://{$sockHost}:{$port}",
            $errno,
            $errstr,
            0.55,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if (! is_resource($socket)) {
            return [];
        }

        try {
            stream_set_timeout($socket, 0, 550_000);
            $req = "GET {$path} HTTP/1.1\r\nHost: {$sockHost}:{$port}\r\nUser-Agent: OblivionCollector/1.0\r\nConnection: close\r\n\r\n";
            if (@fwrite($socket, $req) === false) {
                return [];
            }
            $raw = '';
            while (! feof($socket) && strlen($raw) < 32_768) {
                $chunk = @fread($socket, 4096);
                if (! is_string($chunk) || $chunk === '') {
                    break;
                }
                $raw .= $chunk;
            }
            $sep = strpos($raw, "\r\n\r\n");
            if ($sep === false) {
                return [];
            }
            $body = substr($raw, $sep + 4);

            return self::parseUpnpDescriptionXml($body);
        } finally {
            @fclose($socket);
        }
    }

    /** @return array<string, mixed> */
    public static function parseOnvifProbeMatchXml(string $xml): array
    {
        if ($xml === '' || strlen($xml) > 32_768 || stripos($xml, '<!DOCTYPE') !== false) {
            return [];
        }

        $prev = libxml_use_internal_errors(true);
        try {
            $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
            if ($doc === false) {
                return [];
            }

            $typesNodes = $doc->xpath('//*[local-name()="ProbeMatch"]/*[local-name()="Types"]') ?: [];
            $scopesNodes = $doc->xpath('//*[local-name()="ProbeMatch"]/*[local-name()="Scopes"]') ?: [];

            $types = isset($typesNodes[0]) ? self::scrubBannerString((string) $typesNodes[0], 240) : null;
            $scopesRaw = isset($scopesNodes[0]) ? trim((string) $scopesNodes[0]) : '';
            $scopes = [];
            $hardware = null;
            $name = null;

            foreach (preg_split('/\s+/', $scopesRaw) ?: [] as $token) {
                $token = trim($token);
                if ($token === '' || count($scopes) >= 16) {
                    continue;
                }
                $cleanToken = self::scrubBannerString($token, 200);
                if ($cleanToken !== null) {
                    $scopes[] = $cleanToken;
                }
                $decoded = rawurldecode($token);
                if ($hardware === null && preg_match('~onvif://www\.onvif\.org/hardware/(.+)$~i', $decoded, $m) === 1) {
                    $hardware = self::scrubBannerString($m[1], 120);
                } elseif ($name === null && preg_match('~onvif://www\.onvif\.org/name/(.+)$~i', $decoded, $m) === 1) {
                    $name = self::scrubBannerString($m[1], 160);
                }
            }

            $out = [];
            if ($types !== null) {
                $out['onvif_types'] = $types;
            }
            if ($hardware !== null) {
                $out['onvif_hardware'] = $hardware;
            }
            if ($name !== null) {
                $out['onvif_name'] = $name;
            }
            if ($scopes !== []) {
                $out['onvif_scopes'] = $scopes;
            }

            return $out;
        } catch (Throwable) {
            return [];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    /** @return array<string, string> */
    public static function parseUpnpDescriptionXml(string $xml): array
    {
        if ($xml === '' || strlen($xml) > 65_536 || stripos($xml, '<!DOCTYPE') !== false) {
            return [];
        }

        $prev = libxml_use_internal_errors(true);
        try {
            $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
            if ($doc === false) {
                return [];
            }

            $fields = [
                'upnp_device_type' => ['deviceType', 200],
                'upnp_friendly_name' => ['friendlyName', 160],
                'upnp_manufacturer' => ['manufacturer', 120],
                'upnp_model_name' => ['modelName', 120],
            ];
            $out = [];
            foreach ($fields as $signalKey => [$xmlTag, $maxLen]) {
                $nodes = $doc->xpath("//*[local-name()='device']/*[local-name()='{$xmlTag}']") ?: [];
                if (isset($nodes[0])) {
                    $clean = self::scrubBannerString((string) $nodes[0], $maxLen);
                    if ($clean !== null) {
                        $out[$signalKey] = $clean;
                    }
                }
            }

            return $out;
        } catch (Throwable) {
            return [];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    /** @return array<string, mixed> */
    public static function parseMdnsPacket(string $packet): array
    {
        $len = strlen($packet);
        if ($len < 12) {
            return [];
        }
        $hdr = unpack('nid/nflags/nqdcount/nancount/nnscount/narcount', substr($packet, 0, 12));
        if (! is_array($hdr)) {
            return [];
        }
        $totalRr = (int) $hdr['ancount'] + (int) $hdr['nscount'] + (int) $hdr['arcount'];
        if ($totalRr <= 0) {
            return [];
        }

        $offset = 12;
        for ($q = 0; $q < (int) $hdr['qdcount'] && $offset < $len; $q++) {
            if (self::readDnsName($packet, $offset) === null || $offset + 4 > $len) {
                return [];
            }
            $offset += 4;
        }

        $services = [];
        $hostname = null;
        $model = null;

        for ($r = 0; $r < min(64, $totalRr) && $offset < $len; $r++) {
            $owner = self::readDnsName($packet, $offset);
            if ($owner === null || $offset + 10 > $len) {
                break;
            }
            $meta = unpack('ntype/nclass/Nttl/nrdlen', substr($packet, $offset, 10));
            $offset += 10;
            if (! is_array($meta)) {
                break;
            }
            $rdlen = (int) $meta['rdlen'];
            if ($rdlen < 0 || $offset + $rdlen > $len) {
                break;
            }
            $rdataStart = $offset;
            $offset += $rdlen;

            $type = (int) $meta['type'];
            self::collectMdnsServiceToken($owner, $services);

            if ($type === 12) {
                $ptrOffset = $rdataStart;
                $target = self::readDnsName($packet, $ptrOffset);
                if ($target !== null) {
                    self::collectMdnsServiceToken($target, $services);
                }
            } elseif ($type === 33 && $rdlen > 6) {
                $srvOffset = $rdataStart + 6;
                $srvTarget = self::readDnsName($packet, $srvOffset);
                if ($srvTarget !== null && $hostname === null) {
                    $hostname = self::cleanLocalHostname($srvTarget);
                }
            } elseif (($type === 1 || $type === 28) && $hostname === null) {
                $hostname = self::cleanLocalHostname($owner);
            } elseif ($type === 16 && $rdlen > 1) {
                $pos = $rdataStart;
                $end = $rdataStart + $rdlen;
                while ($pos < $end) {
                    $txtLen = ord($packet[$pos++]);
                    if ($txtLen <= 0 || $pos + $txtLen > $end) {
                        break;
                    }
                    $kv = substr($packet, $pos, $txtLen);
                    $pos += $txtLen;
                    if ($model === null && preg_match('/^(?:md|ty|model|am|product)=(.+)$/i', $kv, $m) === 1) {
                        $model = self::scrubBannerString($m[1], 120);
                    }
                }
            }
        }

        $out = [];
        if ($services !== []) {
            $out['mdns_services'] = array_slice(array_values(array_unique($services)), 0, 16);
        }
        if ($hostname !== null) {
            $out['mdns_hostname'] = $hostname;
        }
        if ($model !== null) {
            $out['mdns_model'] = $model;
        }

        return $out;
    }

    /** @param list<string> $services */
    private static function collectMdnsServiceToken(string $name, array &$services): void
    {
        if (preg_match('/(_[a-z0-9-]+(?:\._sub)?\._(?:tcp|udp))\.local$/i', strtolower($name), $m) === 1) {
            $svc = $m[1];
            if ($svc !== '_services._dns-sd._udp') {
                $services[] = $svc;
            }
        }
    }

    private static function cleanLocalHostname(string $fqdn): ?string
    {
        $clean = trim($fqdn, '.');
        if (str_contains($clean, '_')) {
            return null;
        }
        if (str_ends_with(strtolower($clean), '.local')) {
            $clean = substr($clean, 0, -6);
        }
        if (preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)$/i', $clean) !== 1) {
            return null;
        }

        return self::scrubBannerString($clean, 253);
    }

    /** @return array<string, string> */
    private function parseSsdpHeaders(string $raw): array
    {
        $headers = [];
        foreach (preg_split('/\r\n|\n/', $raw) ?: [] as $idx => $line) {
            if ($idx === 0 || ! str_contains($line, ':')) {
                continue;
            }
            [$k, $v] = explode(':', $line, 2);
            $key = strtolower(trim($k));
            $val = trim($v);
            if ($key !== '' && $val !== '') {
                $headers[$key] = $val;
            }
        }

        return $headers;
    }

    /** @return array{http_status?: int, http_server?: string, http_title?: string, http_auth_realm?: string} */
    private function parseHttpBannerResponse(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $parts = explode("\r\n\r\n", $raw, 2);
        if (count($parts) === 1) {
            $parts = explode("\n\n", $raw, 2);
        }
        $headerBlock = $parts[0] ?? '';
        $body = $parts[1] ?? '';
        $out = [];

        $lines = preg_split('/\r\n|\n/', $headerBlock) ?: [];
        if (isset($lines[0]) && preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})\b/i', $lines[0], $m) === 1) {
            $out['http_status'] = (int) $m[1];
        }
        foreach (array_slice($lines, 1) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$k, $v] = explode(':', $line, 2);
            $lk = strtolower(trim($k));
            if ($lk === 'server') {
                $srv = self::scrubBannerString($v, 160);
                if ($srv !== null) {
                    $out['http_server'] = $srv;
                }
            } elseif ($lk === 'www-authenticate' && preg_match('/realm="([^"]{1,120})"/i', $v, $rm) === 1) {
                $realm = self::scrubBannerString($rm[1], 120);
                if ($realm !== null) {
                    $out['http_auth_realm'] = $realm;
                }
            }
        }

        if ($body !== '' && preg_match('/<title[^>]*>(.*?)<\/title>/is', $body, $tm) === 1) {
            $decoded = html_entity_decode(strip_tags($tm[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $title = self::scrubBannerString($decoded, 160);
            if ($title !== null) {
                $out['http_title'] = $title;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $incoming */
    private function mergeMulticastSignals(string $ip, array $incoming): void
    {
        $existing = $this->multicastByIp[$ip] ?? [];
        foreach ($incoming as $k => $v) {
            if (is_array($v)) {
                $merged = array_values(array_unique([...($existing[$k] ?? []), ...$v]));
                $existing[$k] = array_slice($merged, 0, 20);
            } elseif ($v !== null && $v !== '' && ! isset($existing[$k])) {
                $existing[$k] = $v;
            }
        }
        $this->multicastByIp[$ip] = $existing;
    }

    private function encodeDnsName(string $name): string
    {
        $out = '';
        foreach (explode('.', trim($name, '.')) as $label) {
            if ($label === '' || strlen($label) > 63) {
                continue;
            }
            $out .= chr(strlen($label)).$label;
        }

        return $out."\x00";
    }

    private function decodeDnsPtrResponse(string $packet, int $expectedTxId): ?string
    {
        $len = strlen($packet);
        $hdr = unpack('nid/nflags/nqd/nan/nns/nar', substr($packet, 0, 12));
        if (! is_array($hdr) || (int) $hdr['id'] !== $expectedTxId || ((int) $hdr['flags'] & 0x000F) !== 0 || (int) $hdr['an'] < 1) {
            return null;
        }

        $offset = 12;
        for ($i = 0; $i < (int) $hdr['qd'] && $offset < $len; $i++) {
            if (self::readDnsName($packet, $offset) === null || $offset + 4 > $len) {
                return null;
            }
            $offset += 4;
        }

        for ($i = 0; $i < (int) $hdr['an'] && $offset < $len; $i++) {
            if (self::readDnsName($packet, $offset) === null || $offset + 10 > $len) {
                return null;
            }
            $meta = unpack('ntype/nclass/Nttl/nrdlen', substr($packet, $offset, 10));
            $offset += 10;
            if (! is_array($meta)) {
                return null;
            }
            $rdlen = (int) $meta['rdlen'];
            if ($rdlen < 1 || $offset + $rdlen > $len) {
                return null;
            }
            if ((int) $meta['type'] === 12) {
                $ptrOffset = $offset;
                $ptr = self::readDnsName($packet, $ptrOffset);
                if ($ptr !== null) {
                    return self::scrubBannerString(rtrim($ptr, '.'), 253);
                }
            }
            $offset += $rdlen;
        }

        return null;
    }

    private static function readDnsName(string $packet, int &$offset): ?string
    {
        $len = strlen($packet);
        $labels = [];
        $jumped = false;
        $nextOffset = $offset;
        $jumps = 0;
        $pos = $offset;

        while ($pos < $len) {
            $byte = ord($packet[$pos]);
            if ($byte === 0) {
                if (! $jumped) {
                    $nextOffset = $pos + 1;
                }
                $offset = $nextOffset;

                return implode('.', $labels);
            }
            if (($byte & 0xC0) === 0xC0) {
                if ($pos + 1 >= $len || ++$jumps > 16) {
                    return null;
                }
                if (! $jumped) {
                    $nextOffset = $pos + 2;
                    $jumped = true;
                }
                $pos = (($byte & 0x3F) << 8) | ord($packet[$pos + 1]);

                continue;
            }
            if (($byte & 0xC0) !== 0) {
                return null;
            }
            $pos++;
            if ($pos + $byte > $len || count($labels) > 32) {
                return null;
            }
            $labels[] = substr($packet, $pos, $byte);
            $pos += $byte;
        }

        return null;
    }

    private function randomUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
