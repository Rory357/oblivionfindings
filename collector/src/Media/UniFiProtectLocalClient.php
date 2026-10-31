<?php

namespace Oblivion\Collector\Media;

use Closure;
use DateTimeImmutable;
use Oblivion\Collector\Discovery\LanDiscoveryScanner;
use Oblivion\Collector\Discovery\MacVendorLookup;
use Oblivion\Collector\Exceptions\ScopeViolation;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\ScopeGuard;

/**
 * Bounded, scope-checked Ubiquiti UniFi Protect Local Integration API v1 client for the Site hub.
 *
 * Official specification:
 *   - Ubiquiti UniFi Protect Integration API v1:
 *     https://developer.ui.com/protect
 *   - Endpoints on local UniFi OS Console (HTTPS 443 with `X-API-KEY` header):
 *     GET  /proxy/protect/integration/v1/meta/info
 *     GET  /proxy/protect/integration/v1/cameras
 *     GET  /proxy/protect/integration/v1/cameras/{id}/snapshot
 *     POST /proxy/protect/integration/v1/cameras/{id}/rtsps-stream
 */
final class UniFiProtectLocalClient
{
    private const int MAX_JSON_BYTES = 524_288;

    private const int MAX_SNAPSHOT_BYTES = 2_097_152;

    /**
     * @param  null|Closure(string, string, int, string, array<string, string>, ?string, float, int): ?array{status: int, headers: list<string>, body: string}  $httpTransport
     */
    public function __construct(
        private readonly ?Closure $httpTransport = null,
    ) {}

    /**
     * Execute a scope-checked camera inventory probe using a sealed collector credential lease.
     *
     * @param  array<string, mixed>  $sealedLease
     * @return list<array{id: string, name: string, model: string|null, state: string, mac: string|null, ptz_supported: bool}>
     */
    public function listCamerasWithLease(
        ScopeGuard $scopeGuard,
        CredentialLeaseDecryptor $decryptor,
        array $sealedLease,
        string $consoleIp,
        string $leaseDeviceId,
        string $leaseTarget,
        DateTimeImmutable $at,
    ): array {
        $scopeGuard->assertAddressInCidrs($consoleIp, $at);
        if (($sealedLease['device_id'] ?? null) !== $leaseDeviceId
            || ($sealedLease['protocol'] ?? null) !== 'https'
            || ($sealedLease['target'] ?? null) !== $leaseTarget) {
            throw new ScopeViolation('Credential lease scope does not match UniFi Protect target.');
        }

        $material = $decryptor->open(['credential_lease' => $sealedLease], $at);
        try {
            return $this->listCameras($consoleIp, $material);
        } finally {
            foreach ($material as &$secret) {
                if (is_string($secret) && $secret !== '') {
                    sodium_memzero($secret);
                }
            }
            unset($secret);
        }
    }

    /**
     * @param  array<string, scalar|null>  $material
     * @return array{ok: bool, application_version: string|null}
     */
    public function fetchApplicationInfo(
        string $consoleIp,
        #[\SensitiveParameter] array $material,
        int $port = 443,
        float $timeoutSeconds = 2.5,
    ): array {
        $headers = $this->authHeaders($material);
        if ($headers === null || ! $this->validEndpoint($consoleIp, $port)) {
            return ['ok' => false, 'application_version' => null];
        }

        $resp = $this->request(
            'GET',
            $consoleIp,
            $port,
            '/proxy/protect/integration/v1/meta/info',
            $headers,
            null,
            $timeoutSeconds,
            self::MAX_JSON_BYTES,
        );

        if ($resp === null || $resp['status'] < 200 || $resp['status'] >= 300) {
            return ['ok' => false, 'application_version' => null];
        }

        $decoded = json_decode($resp['body'], true);
        $version = is_array($decoded) && is_string($decoded['applicationVersion'] ?? null)
            ? LanDiscoveryScanner::scrubBannerString($decoded['applicationVersion'], 64)
            : null;

        return ['ok' => true, 'application_version' => $version];
    }

    /**
     * @param  array<string, scalar|null>  $material
     * @return list<array{id: string, name: string, model: string|null, state: string, mac: string|null, ptz_supported: bool}>
     */
    public function listCameras(
        string $consoleIp,
        #[\SensitiveParameter] array $material,
        int $port = 443,
        float $timeoutSeconds = 3.0,
    ): array {
        $headers = $this->authHeaders($material);
        if ($headers === null || ! $this->validEndpoint($consoleIp, $port)) {
            return [];
        }

        $resp = $this->request(
            'GET',
            $consoleIp,
            $port,
            '/proxy/protect/integration/v1/cameras',
            $headers,
            null,
            $timeoutSeconds,
            self::MAX_JSON_BYTES,
        );

        if ($resp === null || $resp['status'] < 200 || $resp['status'] >= 300) {
            return [];
        }

        $decoded = json_decode($resp['body'], true);
        if (! is_array($decoded)) {
            return [];
        }

        $rows = array_is_list($decoded) ? $decoded : (is_array($decoded['data'] ?? null) ? $decoded['data'] : []);
        $cameras = [];

        foreach (array_slice($rows, 0, 128) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = is_string($row['id'] ?? null) ? trim($row['id']) : '';
            if (! self::validCameraId($id)) {
                continue;
            }
            $name = is_string($row['name'] ?? null) ? (LanDiscoveryScanner::scrubBannerString($row['name'], 96) ?? $id) : $id;
            $model = is_string($row['modelKey'] ?? $row['type'] ?? null)
                ? LanDiscoveryScanner::scrubBannerString((string) ($row['modelKey'] ?? $row['type']), 64)
                : null;
            $state = is_string($row['state'] ?? null) ? strtoupper(trim($row['state'])) : 'UNKNOWN';
            $mac = MacVendorLookup::normaliseUnicastMac(is_string($row['mac'] ?? null) ? $row['mac'] : null);
            $featureFlags = is_array($row['featureFlags'] ?? null) ? $row['featureFlags'] : [];
            $ptz = ($featureFlags['isPtz'] ?? false) === true || ($featureFlags['hasPtz'] ?? false) === true;

            $cameras[] = [
                'id' => $id,
                'name' => $name,
                'model' => $model,
                'state' => $state,
                'mac' => $mac,
                'ptz_supported' => $ptz,
            ];
        }

        return $cameras;
    }

    /**
     * @param  array<string, scalar|null>  $material
     * @return array{ok: bool, reason_code: string, mime: string|null, bytes: string|null}
     */
    public function captureSnapshot(
        string $consoleIp,
        string $cameraId,
        #[\SensitiveParameter] array $material,
        int $port = 443,
        float $timeoutSeconds = 4.0,
    ): array {
        if (! self::validCameraId($cameraId) || ! $this->validEndpoint($consoleIp, $port)) {
            return ['ok' => false, 'reason_code' => 'invalid_target', 'mime' => null, 'bytes' => null];
        }

        $headers = $this->authHeaders($material, 'image/jpeg, image/png, image/webp');
        if ($headers === null) {
            return ['ok' => false, 'reason_code' => 'missing_api_key', 'mime' => null, 'bytes' => null];
        }

        $resp = $this->request(
            'GET',
            $consoleIp,
            $port,
            '/proxy/protect/integration/v1/cameras/'.rawurlencode($cameraId).'/snapshot?highQuality=true',
            $headers,
            null,
            $timeoutSeconds,
            self::MAX_SNAPSHOT_BYTES + 1,
        );

        if ($resp === null || $resp['status'] === 0) {
            return ['ok' => false, 'reason_code' => 'unreachable', 'mime' => null, 'bytes' => null];
        }
        if (in_array($resp['status'], [401, 403], true)) {
            return ['ok' => false, 'reason_code' => 'authentication_required', 'mime' => null, 'bytes' => null];
        }
        if ($resp['status'] < 200 || $resp['status'] >= 300 || $resp['body'] === '') {
            return ['ok' => false, 'reason_code' => 'snapshot_fetch_failed', 'mime' => null, 'bytes' => null];
        }
        if (strlen($resp['body']) > self::MAX_SNAPSHOT_BYTES) {
            return ['ok' => false, 'reason_code' => 'snapshot_too_large', 'mime' => null, 'bytes' => null];
        }

        $mime = self::detectImageMime($resp['body']);
        if ($mime === null) {
            return ['ok' => false, 'reason_code' => 'invalid_image', 'mime' => null, 'bytes' => null];
        }

        return [
            'ok' => true,
            'reason_code' => 'captured',
            'mime' => $mime,
            'bytes' => $resp['body'],
        ];
    }

    /**
     * Request RTSPS stream endpoints on the local UniFi Protect console, returning
     * sanitised same-host `rtsps://` URLs with stream tokens redacted from metadata.
     *
     * @param  array<string, scalar|null>  $material
     * @param  list<string>  $qualities
     * @return array{ok: bool, reason_code: string, streams: array<string, string>}
     */
    public function fetchSanitisedRtspsStreams(
        string $consoleIp,
        string $cameraId,
        #[\SensitiveParameter] array $material,
        array $qualities = ['high'],
        int $port = 443,
        float $timeoutSeconds = 3.0,
    ): array {
        if (! self::validCameraId($cameraId) || ! $this->validEndpoint($consoleIp, $port)) {
            return ['ok' => false, 'reason_code' => 'invalid_target', 'streams' => []];
        }

        $headers = $this->authHeaders($material);
        if ($headers === null) {
            return ['ok' => false, 'reason_code' => 'missing_api_key', 'streams' => []];
        }

        $allowedQualities = array_values(array_intersect($qualities, ['high', 'medium', 'low', 'package']));
        if ($allowedQualities === []) {
            $allowedQualities = ['high'];
        }

        $payload = json_encode(['qualities' => $allowedQualities], JSON_THROW_ON_ERROR);
        $resp = $this->request(
            'POST',
            $consoleIp,
            $port,
            '/proxy/protect/integration/v1/cameras/'.rawurlencode($cameraId).'/rtsps-stream',
            [...$headers, 'Content-Type' => 'application/json'],
            $payload,
            $timeoutSeconds,
            self::MAX_JSON_BYTES,
        );

        if ($resp === null || $resp['status'] < 200 || $resp['status'] >= 300) {
            return [
                'ok' => false,
                'reason_code' => $resp === null ? 'unreachable' : (in_array($resp['status'], [401, 403], true) ? 'authentication_required' : 'stream_unavailable'),
                'streams' => [],
            ];
        }

        $decoded = json_decode($resp['body'], true);
        if (! is_array($decoded)) {
            return ['ok' => false, 'reason_code' => 'invalid_response', 'streams' => []];
        }

        $sanitised = [];
        foreach ($allowedQualities as $quality) {
            $uri = $decoded[$quality] ?? null;
            if (! is_string($uri) || $uri === '') {
                continue;
            }
            $clean = self::sanitiseProtectRtspsUrl($uri, $consoleIp);
            if ($clean !== null) {
                $sanitised[$quality] = $clean;
            }
        }

        return [
            'ok' => $sanitised !== [],
            'reason_code' => $sanitised !== [] ? 'ready' : 'stream_unavailable',
            'streams' => $sanitised,
        ];
    }

    public static function sanitiseProtectRtspsUrl(string $rawUrl, string $consoleIp): ?string
    {
        $parts = parse_url($rawUrl);
        if (! is_array($parts)) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['rtsps', 'rtsp'], true)) {
            return null;
        }
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) === false || $host !== $consoleIp) {
            return null;
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : 7441;
        if (! in_array($port, [554, 7441, 7447], true)) {
            return null;
        }
        // Strip single-use stream alias/token path segment so metadata never leaks stream credentials
        $hostOut = str_contains($consoleIp, ':') ? "[{$consoleIp}]" : $consoleIp;

        return "{$scheme}://{$hostOut}:{$port}/[redacted-stream]";
    }

    public static function validCameraId(string $cameraId): bool
    {
        return preg_match('/\A[A-Za-z0-9_-]{4,64}\z/', $cameraId) === 1;
    }

    public static function detectImageMime(string $bytes): ?string
    {
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
            return 'image/png';
        }
        if (strlen($bytes) >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        return null;
    }

    private function validEndpoint(string $consoleIp, int $port): bool
    {
        return filter_var($consoleIp, FILTER_VALIDATE_IP) !== false && in_array($port, [443, 8443], true);
    }

    /**
     * @param  array<string, scalar|null>  $material
     * @return array<string, string>|null
     */
    private function authHeaders(#[\SensitiveParameter] array $material, string $accept = 'application/json'): ?array
    {
        $apiKey = trim((string) ($material['protect_api_key'] ?? $material['api_key'] ?? $material['token'] ?? ''));
        if ($apiKey === '') {
            return null;
        }

        return [
            'Accept' => $accept,
            'User-Agent' => 'OblivionCollector/1.0',
            'X-API-KEY' => $apiKey,
        ];
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
        int $maxBytes,
    ): ?array {
        if ($this->httpTransport !== null) {
            return ($this->httpTransport)($method, $ip, $port, $path, $headers, $body, $timeoutSeconds, $maxBytes);
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
            max(0.5, $timeoutSeconds),
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
            while (! feof($socket) && strlen($rawResp) < ($maxBytes + 8192)) {
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

            return [
                'status' => (int) $m[1],
                'headers' => $headerLines,
                'body' => substr($respBody, 0, $maxBytes),
            ];
        } finally {
            @fclose($socket);
        }
    }
}
