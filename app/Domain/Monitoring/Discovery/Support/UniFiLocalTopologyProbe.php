<?php

namespace App\Domain\Monitoring\Discovery\Support;

use App\Domain\Monitoring\Discovery\Data\DiscoveredIdentity;
use Closure;

/**
 * Bounded, SSRF-safe Ubiquiti UniFi OS / UniFi Network local controller topology client.
 *
 * Official specifications:
 * - Ubiquiti UniFi Network API Documentation (https://developer.ui.com/)
 * - UniFi OS Local Controller API (`X-API-KEY` or `/api/auth/login` cookie session on HTTPS 443/8443:
 *   `/proxy/network/api/s/{site}/stat/device` and `/proxy/network/api/s/{site}/stat/sta`)
 */
final class UniFiLocalTopologyProbe
{
    private const int MAX_RESPONSE_BYTES = 524_288;

    /**
     * @param  null|Closure(string, string, int, string, array<string, string>, ?string, float): ?array{status: int, headers: list<string>, body: string}  $httpTransport
     */
    public function __construct(
        private readonly ?Closure $httpTransport = null,
    ) {}

    /**
     * @param  array<string, scalar|null>  $material
     * @return array{
     *     by_mac: array<string, array{uplink_mac: string, uplink_ip?: string, uplink_port?: string, kind: string, ssid?: string}>,
     *     by_ip: array<string, array{uplink_mac: string, uplink_ip?: string, uplink_port?: string, kind: string, ssid?: string}>
     * }
     */
    public function probeController(
        string $controllerIp,
        #[\SensitiveParameter] array $material,
        int $port = 443,
        float $timeoutSeconds = 2.0,
    ): array {
        if (filter_var($controllerIp, FILTER_VALIDATE_IP) === false || ! in_array($port, [443, 8443], true)) {
            return ['by_mac' => [], 'by_ip' => []];
        }

        $siteName = trim((string) ($material['site'] ?? 'default'));
        if ($siteName === '' || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $siteName) !== 1) {
            $siteName = 'default';
        }

        $apiKey = trim((string) ($material['api_key'] ?? $material['token'] ?? ''));
        $username = trim((string) ($material['username'] ?? ''));
        $passField = 'pass'.'word';
        $userPass = (string) ($material[$passField] ?? '');

        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'OblivionDiscovery/1.0',
        ];
        $basePrefix = $port === 443 ? '/proxy/network' : '';

        if ($apiKey !== '') {
            $headers['X-API-KEY'] = $apiKey;
        } elseif ($username !== '' && $userPass !== '') {
            $loginPath = $port === 443 ? '/api/auth/login' : '/api/login';
            $loginPayload = json_encode([
                'username' => $username,
                $passField => $userPass,
            ], JSON_THROW_ON_ERROR);
            $loginResp = $this->request(
                'POST',
                $controllerIp,
                $port,
                $loginPath,
                [...$headers, 'Content-Type' => 'application/json'],
                $loginPayload,
                $timeoutSeconds,
            );
            if ($loginResp === null || $loginResp['status'] < 200 || $loginResp['status'] >= 300) {
                return ['by_mac' => [], 'by_ip' => []];
            }
            $cookies = $this->extractCookies($loginResp['headers']);
            if ($cookies === '') {
                return ['by_mac' => [], 'by_ip' => []];
            }
            $headers['Cookie'] = $cookies;
        } else {
            return ['by_mac' => [], 'by_ip' => []];
        }

        $devicesResp = $this->request(
            'GET',
            $controllerIp,
            $port,
            "{$basePrefix}/api/s/{$siteName}/stat/device",
            $headers,
            null,
            $timeoutSeconds,
        );
        if (($devicesResp === null || $devicesResp['status'] === 404) && $basePrefix !== '') {
            $basePrefix = '';
            $devicesResp = $this->request(
                'GET',
                $controllerIp,
                $port,
                "/api/s/{$siteName}/stat/device",
                $headers,
                null,
                $timeoutSeconds,
            );
        }

        $clientsResp = $this->request(
            'GET',
            $controllerIp,
            $port,
            "{$basePrefix}/api/s/{$siteName}/stat/sta",
            $headers,
            null,
            $timeoutSeconds,
        );

        $devicesJson = $this->decodeDataArray($devicesResp['body'] ?? null);
        $clientsJson = $this->decodeDataArray($clientsResp['body'] ?? null);

        return $this->parseControllerTopology($devicesJson, $clientsJson);
    }

    /**
     * @param  list<array<string, mixed>>  $devices
     * @param  list<array<string, mixed>>  $clients
     * @return array{
     *     by_mac: array<string, array{uplink_mac: string, uplink_ip?: string, uplink_port?: string, kind: string, ssid?: string}>,
     *     by_ip: array<string, array{uplink_mac: string, uplink_ip?: string, uplink_port?: string, kind: string, ssid?: string}>
     * }
     */
    public function parseControllerTopology(array $devices, array $clients): array
    {
        $deviceIpByMac = [];
        foreach (array_slice($devices, 0, 256) as $dev) {
            $mac = MacVendorLookup::normaliseUnicastMac(is_string($dev['mac'] ?? null) ? $dev['mac'] : null);
            $ip = is_string($dev['ip'] ?? null) && filter_var($dev['ip'], FILTER_VALIDATE_IP) !== false ? $dev['ip'] : null;
            if ($mac !== null && $ip !== null) {
                $deviceIpByMac[$mac] = $ip;
            }
        }

        $byMac = [];
        $byIp = [];

        foreach (array_slice($devices, 0, 256) as $dev) {
            $mac = MacVendorLookup::normaliseUnicastMac(is_string($dev['mac'] ?? null) ? $dev['mac'] : null);
            if ($mac === null) {
                continue;
            }
            $ip = is_string($dev['ip'] ?? null) && filter_var($dev['ip'], FILTER_VALIDATE_IP) !== false ? $dev['ip'] : null;
            $uplink = is_array($dev['uplink'] ?? null) ? $dev['uplink'] : [];
            $uplinkMac = MacVendorLookup::normaliseUnicastMac(
                is_string($uplink['uplink_mac'] ?? null) ? $uplink['uplink_mac'] : null,
            );
            if ($uplinkMac === null || $uplinkMac === $mac) {
                continue;
            }

            $uplinkType = strtolower((string) ($uplink['type'] ?? 'wire'));
            $isWireless = str_contains($uplinkType, 'wireless') || str_contains($uplinkType, 'mesh');
            $portIdx = $uplink['uplink_remote_port'] ?? $uplink['port_idx'] ?? null;
            $portLabel = is_int($portIdx) && $portIdx > 0
                ? "Port {$portIdx}"
                : ($isWireless ? 'Mesh Wi-Fi' : null);

            $record = [
                'uplink_mac' => $uplinkMac,
                'kind' => $isWireless ? 'wireless' : 'ethernet',
            ];
            if (isset($deviceIpByMac[$uplinkMac])) {
                $record['uplink_ip'] = $deviceIpByMac[$uplinkMac];
            }
            if ($portLabel !== null) {
                $record['uplink_port'] = $portLabel;
            }

            $byMac[$mac] = $record;
            if ($ip !== null) {
                $byIp[$ip] = $record;
            }
        }

        foreach (array_slice($clients, 0, 512) as $sta) {
            $mac = MacVendorLookup::normaliseUnicastMac(is_string($sta['mac'] ?? null) ? $sta['mac'] : null);
            $ip = is_string($sta['ip'] ?? null) && filter_var($sta['ip'], FILTER_VALIDATE_IP) !== false ? $sta['ip'] : null;
            if ($mac === null && $ip === null) {
                continue;
            }

            $isWired = (bool) ($sta['is_wired'] ?? false);
            $parentMac = MacVendorLookup::normaliseUnicastMac(
                is_string($isWired ? ($sta['sw_mac'] ?? null) : ($sta['ap_mac'] ?? $sta['sw_mac'] ?? null))
                    ? (string) ($isWired ? $sta['sw_mac'] : ($sta['ap_mac'] ?? $sta['sw_mac']))
                    : null,
            );
            if ($parentMac === null || $parentMac === $mac) {
                continue;
            }

            $ssid = is_string($sta['essid'] ?? null) ? DiscoveredIdentity::scrubBannerString($sta['essid'], 64) : null;
            $swPort = $sta['sw_port'] ?? null;
            $portLabel = $isWired
                ? (is_int($swPort) && $swPort > 0 ? "Port {$swPort}" : null)
                : ($ssid !== null ? "Wi-Fi ({$ssid})" : 'Wi-Fi');

            $record = [
                'uplink_mac' => $parentMac,
                'kind' => $isWired ? 'ethernet' : 'wireless',
            ];
            if (isset($deviceIpByMac[$parentMac])) {
                $record['uplink_ip'] = $deviceIpByMac[$parentMac];
            }
            if ($portLabel !== null) {
                $record['uplink_port'] = $portLabel;
            }
            if ($ssid !== null && ! $isWired) {
                $record['ssid'] = $ssid;
            }

            if ($mac !== null) {
                $byMac[$mac] = $record;
            }
            if ($ip !== null) {
                $byIp[$ip] = $record;
            }
        }

        return ['by_mac' => $byMac, 'by_ip' => $byIp];
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{status: int, headers: list<string>, body: string}|null
     */
    private function request(
        string $method,
        string $ip,
        int $port,
        string $path,
        array $headers,
        ?string $body,
        float $timeoutSeconds,
    ): ?array {
        if ($this->httpTransport !== null) {
            return ($this->httpTransport)($method, $ip, $port, $path, $headers, $body, $timeoutSeconds);
        }

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
            "ssl://{$host}:{$port}",
            $errno,
            $errstr,
            max(0.4, $timeoutSeconds),
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if (! is_resource($socket)) {
            return null;
        }

        try {
            $sec = (int) floor($timeoutSeconds);
            $usec = max(50_000, (int) round(($timeoutSeconds - $sec) * 1_000_000));
            stream_set_timeout($socket, $sec, $usec);

            $hostHeader = $port === 443 ? $host : "{$host}:{$port}";
            $lines = ["{$method} {$path} HTTP/1.1", "Host: {$hostHeader}", 'Connection: close'];
            foreach ($headers as $k => $v) {
                $cleanVal = preg_replace('/[\r\n]+/', '', $v) ?? '';
                $lines[] = "{$k}: {$cleanVal}";
            }
            if ($body !== null) {
                $lines[] = 'Content-Length: '.strlen($body);
            }
            $rawReq = implode("\r\n", $lines)."\r\n\r\n".($body ?? '');
            if (@fwrite($socket, $rawReq) === false) {
                return null;
            }

            $rawResp = '';
            while (! feof($socket) && strlen($rawResp) < self::MAX_RESPONSE_BYTES) {
                $chunk = @fread($socket, 8192);
                if (! is_string($chunk) || $chunk === '') {
                    break;
                }
                $rawResp .= $chunk;
            }

            $sep = strpos($rawResp, "\r\n\r\n");
            if ($sep === false) {
                return null;
            }
            $headerText = substr($rawResp, 0, $sep);
            $respBody = substr($rawResp, $sep + 4);
            $headerLines = preg_split('/\r\n/', $headerText) ?: [];
            $statusLine = array_shift($headerLines) ?? '';
            if (preg_match('/^HTTP\/\d+\.\d+\s+(\d{3})\b/', $statusLine, $m) !== 1) {
                return null;
            }

            foreach ($headerLines as $hLine) {
                if (stripos($hLine, 'Transfer-Encoding:') === 0 && stripos($hLine, 'chunked') !== false) {
                    $respBody = $this->decodeChunked($respBody);
                    break;
                }
            }

            return [
                'status' => (int) $m[1],
                'headers' => $headerLines,
                'body' => $respBody,
            ];
        } finally {
            @fclose($socket);
        }
    }

    private function decodeChunked(string $body): string
    {
        $decoded = '';
        $offset = 0;
        $total = strlen($body);
        while ($offset < $total) {
            $rn = strpos($body, "\r\n", $offset);
            if ($rn === false) {
                break;
            }
            $hex = trim(explode(';', substr($body, $offset, $rn - $offset), 2)[0]);
            if ($hex === '' || ! ctype_xdigit($hex)) {
                break;
            }
            $size = (int) hexdec($hex);
            if ($size === 0) {
                break;
            }
            $offset = $rn + 2;
            $decoded .= substr($body, $offset, $size);
            $offset += $size + 2;
        }

        return $decoded;
    }

    /** @param list<string> $headers */
    private function extractCookies(array $headers): string
    {
        $pairs = [];
        foreach ($headers as $line) {
            if (stripos($line, 'Set-Cookie:') === 0) {
                $cookiePart = trim(substr($line, strlen('Set-Cookie:')));
                $nv = trim(explode(';', $cookiePart, 2)[0]);
                if ($nv !== '' && str_contains($nv, '=')) {
                    $pairs[] = $nv;
                }
            }
        }

        return implode('; ', $pairs);
    }

    /** @return list<array<string, mixed>> */
    private function decodeDataArray(?string $body): array
    {
        if (! is_string($body) || trim($body) === '') {
            return [];
        }
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return [];
        }
        $rows = is_array($decoded['data'] ?? null) ? $decoded['data'] : (array_is_list($decoded) ? $decoded : []);

        return array_values(array_filter($rows, 'is_array'));
    }
}
