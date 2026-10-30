<?php

namespace App\Domain\SecurityDevices\Cctv\Services;

use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\Monitoring\Data\ProbeTarget;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Exceptions\EgressDenied;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Monitoring\Services\CidrMatcher;
use App\Domain\Monitoring\Services\EgressPolicy;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialRotationStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Credentials\Services\CredentialLeaseLifecycleService;
use App\Domain\SecurityDevices\Models\Device;
use Closure;
use Illuminate\Support\Facades\Http;
use Throwable;
use UnexpectedValueException;

/**
 * SSRF-guarded, Site-scope-checked client for Ubiquiti UniFi Protect Local Integration API v1.
 *
 * Official specification:
 *   - Ubiquiti UniFi Protect Integration API v1:
 *     https://developer.ui.com/protect
 *   - Uses `X-API-KEY` header against `https://{consoleIp}/proxy/protect/integration/v1/*`
 *     (never undocumented `/api/bootstrap`).
 */
final class UniFiProtectLocalCameraClient
{
    private const int MAX_SNAPSHOT_BYTES = 2_097_152;

    /**
     * @param  (Closure(string, string, array<string, string>, ?string, int): array{status: int, content_type: string, body: string})|null  $httpTransport
     */
    public function __construct(
        private readonly CanonicalDeviceSiteResolver $siteResolver,
        private readonly CidrMatcher $cidrMatcher,
        private readonly EgressPolicy $egressPolicy,
        private readonly CredentialLeaseProvider $leases,
        private readonly CredentialLeaseLifecycleService $leaseLifecycle,
        private readonly ?Closure $httpTransport = null,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     reason_code: string,
     *     message: string,
     *     data_uri: string|null,
     *     captured_at: string
     * }
     */
    public function captureSnapshot(Device $device): array
    {
        $capturedAt = now()->toImmutable()->toISOString();
        $endpoint = $this->authoriseConsoleEndpoint($device);
        if (! $endpoint['ok']) {
            return [
                'ok' => false,
                'reason_code' => $endpoint['reason_code'],
                'message' => $endpoint['message'],
                'data_uri' => null,
                'captured_at' => $capturedAt,
            ];
        }

        $lease = null;
        $material = [];

        try {
            $lease = $this->acquireProtectCredential($endpoint['site_id']);
            $material = $lease ? $lease->material() : [];
            $apiKey = $this->extractApiKey($material);

            if ($apiKey === null) {
                return [
                    'ok' => false,
                    'reason_code' => 'authentication_required',
                    'message' => 'Save a local UniFi Protect API key credential for this Site on Setup before fetching Protect snapshots.',
                    'data_uri' => null,
                    'captured_at' => $capturedAt,
                ];
            }

            $cameraId = $this->resolveProtectCameraId($device, $endpoint['base_url'], $apiKey);
            if ($cameraId === null) {
                return [
                    'ok' => false,
                    'reason_code' => 'camera_not_mapped',
                    'message' => 'Could not match this camera on the Site\'s UniFi Protect console. Check its MAC address or Protect camera ID.',
                    'data_uri' => null,
                    'captured_at' => $capturedAt,
                ];
            }

            $snapshotUrl = $endpoint['base_url'].'/proxy/protect/integration/v1/cameras/'.rawurlencode($cameraId).'/snapshot?highQuality=true';
            $resp = $this->sendHttp(
                'GET',
                $snapshotUrl,
                [
                    'Accept' => 'image/jpeg, image/png, image/webp',
                    'X-API-KEY' => $apiKey,
                ],
                null,
                4000,
            );

            if (in_array($resp['status'], [401, 403], true)) {
                return [
                    'ok' => false,
                    'reason_code' => 'authentication_required',
                    'message' => 'The UniFi Protect console rejected the Site API key.',
                    'data_uri' => null,
                    'captured_at' => $capturedAt,
                ];
            }

            if ($resp['status'] < 200 || $resp['status'] >= 300 || $resp['body'] === '') {
                return [
                    'ok' => false,
                    'reason_code' => $resp['status'] === 0 ? 'unreachable' : 'snapshot_fetch_failed',
                    'message' => $resp['status'] === 0
                        ? 'Could not reach the UniFi Protect console directly. If the console is on a private Site LAN, run the Site hub.'
                        : 'The UniFi Protect console did not return a camera snapshot.',
                    'data_uri' => null,
                    'captured_at' => $capturedAt,
                ];
            }

            if (strlen($resp['body']) > self::MAX_SNAPSHOT_BYTES) {
                return [
                    'ok' => false,
                    'reason_code' => 'snapshot_too_large',
                    'message' => 'The UniFi Protect snapshot exceeded the 2 MB safety limit.',
                    'data_uri' => null,
                    'captured_at' => $capturedAt,
                ];
            }

            $mime = $this->detectImageMime($resp['body']);
            if ($mime === null) {
                return [
                    'ok' => false,
                    'reason_code' => 'invalid_image',
                    'message' => 'The UniFi Protect response was not a valid JPEG, PNG, or WebP image.',
                    'data_uri' => null,
                    'captured_at' => $capturedAt,
                ];
            }

            return [
                'ok' => true,
                'reason_code' => 'captured',
                'message' => 'Snapshot captured from UniFi Protect local API.',
                'data_uri' => 'data:'.$mime.';base64,'.base64_encode($resp['body']),
                'captured_at' => $capturedAt,
            ];
        } finally {
            foreach ($material as &$secret) {
                if (is_string($secret) && $secret !== '' && function_exists('sodium_memzero')) {
                    sodium_memzero($secret);
                }
            }
            unset($secret);

            if (isset($apiKey) && is_string($apiKey) && $apiKey !== '' && function_exists('sodium_memzero')) {
                sodium_memzero($apiKey);
            }

            if ($lease !== null) {
                $this->leaseLifecycle->release($lease);
            }
        }
    }

    /**
     * @return array{
     *     ok: bool,
     *     reason_code: string,
     *     message: string,
     *     profiles: list<array{token: string, name: string, encoding: string|null, stream_uri: string|null, snapshot_uri: string|null}>,
     *     device_info: array{manufacturer: string|null, model: string|null, firmware_version: string|null, serial_number: string|null}
     * }
     */
    public function probeStreams(Device $device): array
    {
        $emptyInfo = [
            'manufacturer' => 'Ubiquiti',
            'model' => $device->model,
            'firmware_version' => $device->firmware_version,
            'serial_number' => $device->serial_number,
        ];

        $endpoint = $this->authoriseConsoleEndpoint($device);
        if (! $endpoint['ok']) {
            return [
                'ok' => false,
                'reason_code' => $endpoint['reason_code'],
                'message' => $endpoint['message'],
                'profiles' => [],
                'device_info' => $emptyInfo,
            ];
        }

        $lease = null;
        $material = [];

        try {
            $lease = $this->acquireProtectCredential($endpoint['site_id']);
            $material = $lease ? $lease->material() : [];
            $apiKey = $this->extractApiKey($material);

            if ($apiKey === null) {
                return [
                    'ok' => false,
                    'reason_code' => 'authentication_required',
                    'message' => 'Save a local UniFi Protect API key credential for this Site before inspecting streams.',
                    'profiles' => [],
                    'device_info' => $emptyInfo,
                ];
            }

            $cameraId = $this->resolveProtectCameraId($device, $endpoint['base_url'], $apiKey);
            if ($cameraId === null) {
                return [
                    'ok' => false,
                    'reason_code' => 'camera_not_mapped',
                    'message' => 'Could not match this camera on the UniFi Protect console.',
                    'profiles' => [],
                    'device_info' => $emptyInfo,
                ];
            }

            $resp = $this->sendHttp(
                'POST',
                $endpoint['base_url'].'/proxy/protect/integration/v1/cameras/'.rawurlencode($cameraId).'/rtsps-stream',
                [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-API-KEY' => $apiKey,
                ],
                json_encode(['qualities' => ['high', 'low']], JSON_THROW_ON_ERROR),
                3000,
            );

            if ($resp['status'] < 200 || $resp['status'] >= 300) {
                return [
                    'ok' => false,
                    'reason_code' => in_array($resp['status'], [401, 403], true) ? 'authentication_required' : 'stream_unavailable',
                    'message' => 'The UniFi Protect console did not supply RTSPS streams for this camera.',
                    'profiles' => [],
                    'device_info' => $emptyInfo,
                ];
            }

            $decoded = json_decode($resp['body'], true);
            $profiles = [];
            if (is_array($decoded)) {
                foreach (['high' => 'High quality RTSPS', 'low' => 'Low quality RTSPS'] as $quality => $label) {
                    if (is_string($decoded[$quality] ?? null) && $decoded[$quality] !== '') {
                        $hostOut = str_contains($endpoint['ip'], ':') ? '['.$endpoint['ip'].']' : $endpoint['ip'];
                        $profiles[] = [
                            'token' => $quality,
                            'name' => $label,
                            'encoding' => 'H264',
                            'stream_uri' => 'rtsps://'.$hostOut.':7441/[redacted-stream]',
                            'snapshot_uri' => $endpoint['base_url'].'/proxy/protect/integration/v1/cameras/'.rawurlencode($cameraId).'/snapshot',
                        ];
                    }
                }
            }

            return [
                'ok' => $profiles !== [],
                'reason_code' => $profiles !== [] ? 'probed' : 'stream_unavailable',
                'message' => $profiles !== [] ? 'UniFi Protect RTSPS streams available.' : 'No active RTSPS streams returned.',
                'profiles' => $profiles,
                'device_info' => $emptyInfo,
            ];
        } finally {
            foreach ($material as &$secret) {
                if (is_string($secret) && $secret !== '' && function_exists('sodium_memzero')) {
                    sodium_memzero($secret);
                }
            }
            unset($secret);

            if (isset($apiKey) && is_string($apiKey) && $apiKey !== '' && function_exists('sodium_memzero')) {
                sodium_memzero($apiKey);
            }

            if ($lease !== null) {
                $this->leaseLifecycle->release($lease);
            }
        }
    }

    /**
     * @return array{ok: bool, reason_code: string, message: string, ip?: string, site_id?: int, base_url?: string}
     */
    private function authoriseConsoleEndpoint(Device $device): array
    {
        try {
            $siteId = $this->siteResolver->resolve((int) $device->id);
        } catch (UnexpectedValueException) {
            $siteId = null;
        }

        if ($siteId === null || $siteId < 1) {
            return [
                'ok' => false,
                'reason_code' => 'missing_site_scope',
                'message' => 'Assign this UniFi Protect camera to an active Site first.',
            ];
        }

        $rawConsoleIp = trim((string) (
            data_get($device->config, 'protect.console_ip')
            ?? data_get($device->meta, 'protect.console_ip')
            ?? $device->ip_address
            ?? ''
        ));

        if ($rawConsoleIp === '' || filter_var($rawConsoleIp, FILTER_VALIDATE_IP) === false) {
            return [
                'ok' => false,
                'reason_code' => 'missing_address',
                'message' => 'Configure the UniFi OS console IP address on this camera or Site.',
            ];
        }

        try {
            $canonicalIp = $this->cidrMatcher->canonicalAddress($rawConsoleIp);
        } catch (Throwable) {
            return [
                'ok' => false,
                'reason_code' => 'invalid_address',
                'message' => 'The UniFi Protect console IP address is not valid.',
            ];
        }

        $port = (int) (data_get($device->config, 'protect.port') ?? 443);
        if (! in_array($port, [443, 8443], true)) {
            $port = 443;
        }

        $approvedCidrs = $this->approvedSiteCidrsForAddress($siteId, $canonicalIp);
        if ($approvedCidrs === []) {
            return [
                'ok' => false,
                'reason_code' => 'out_of_site_scope',
                'message' => 'Add the UniFi Protect console subnet to an active discovery scope for this Site.',
            ];
        }

        try {
            $this->egressPolicy->authoriseDiscovery(
                siteId: $siteId,
                approvedCidrs: $approvedCidrs,
                allowedPorts: [$port],
                target: ProbeTarget::tcp($canonicalIp, $port),
            );
        } catch (EgressDenied) {
            return [
                'ok' => false,
                'reason_code' => 'address_blocked',
                'message' => 'That console IP address is blocked by outbound network policy.',
            ];
        }

        $hostOut = str_contains($canonicalIp, ':') ? "[{$canonicalIp}]" : $canonicalIp;
        $baseUrl = 'https://'.$hostOut.($port === 443 ? '' : ':'.$port);

        return [
            'ok' => true,
            'reason_code' => 'authorised',
            'message' => 'UniFi Protect endpoint authorised.',
            'ip' => $canonicalIp,
            'site_id' => $siteId,
            'base_url' => $baseUrl,
        ];
    }

    private function resolveProtectCameraId(Device $device, string $baseUrl, #[\SensitiveParameter] string $apiKey): ?string
    {
        foreach ([
            data_get($device->external_ref, 'protect_camera_id'),
            data_get($device->config, 'protect.camera_id'),
            data_get($device->meta, 'protect.camera_id'),
        ] as $candidate) {
            if (is_string($candidate) && preg_match('/\A[A-Za-z0-9_-]{4,64}\z/', trim($candidate)) === 1) {
                return trim($candidate);
            }
        }

        $deviceMac = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string) $device->mac_address) ?? '');
        if (strlen($deviceMac) !== 12) {
            return null;
        }

        $resp = $this->sendHttp(
            'GET',
            $baseUrl.'/proxy/protect/integration/v1/cameras',
            [
                'Accept' => 'application/json',
                'X-API-KEY' => $apiKey,
            ],
            null,
            3000,
        );

        if ($resp['status'] < 200 || $resp['status'] >= 300 || $resp['body'] === '') {
            return null;
        }

        $decoded = json_decode($resp['body'], true);
        if (! is_array($decoded)) {
            return null;
        }

        $rows = array_is_list($decoded) ? $decoded : (is_array($decoded['data'] ?? null) ? $decoded['data'] : []);
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rowMac = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string) ($row['mac'] ?? '')) ?? '');
            $id = is_string($row['id'] ?? null) ? trim($row['id']) : '';
            if ($rowMac === $deviceMac && preg_match('/\A[A-Za-z0-9_-]{4,64}\z/', $id) === 1) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function approvedSiteCidrsForAddress(int $siteId, string $canonicalIp): array
    {
        $scopes = DiscoveryScope::query()
            ->where('site_id', $siteId)
            ->where('status', 'active')
            ->orderBy('id')
            ->limit(64)
            ->get(['cidrs', 'exclusions']);

        $approved = [];
        foreach ($scopes as $scope) {
            $excluded = false;
            foreach ($scope->exclusions ?? [] as $exclusion) {
                if (! is_string($exclusion) || trim($exclusion) === '') {
                    continue;
                }
                $trimmed = trim($exclusion);
                try {
                    if ((str_contains($trimmed, '/') && $this->cidrMatcher->contains($trimmed, $canonicalIp))
                        || (! str_contains($trimmed, '/') && $this->cidrMatcher->canonicalAddress($trimmed) === $canonicalIp)) {
                        $excluded = true;
                        break;
                    }
                } catch (Throwable) {
                    continue;
                }
            }
            if ($excluded) {
                continue;
            }
            foreach ($scope->cidrs ?? [] as $cidr) {
                if (! is_string($cidr) || trim($cidr) === '') {
                    continue;
                }
                try {
                    if ($this->cidrMatcher->contains($cidr, $canonicalIp)) {
                        $approved[] = $cidr;
                    }
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return array_values(array_unique($approved));
    }

    private function acquireProtectCredential(int $siteId): ?CredentialLease
    {
        $reference = CredentialReference::query()
            ->where('site_id', $siteId)
            ->whereIn('provider', ['unifi_protect', 'unifi'])
            ->whereIn('purpose', ['device_management', 'monitoring'])
            ->where('status', CredentialReferenceStatus::Active->value)
            ->whereNotIn('rotation_status', [
                CredentialRotationStatus::Overdue->value,
                CredentialRotationStatus::Failed->value,
            ])
            ->orderByDesc('id')
            ->first();

        if (! $reference) {
            return null;
        }

        $capabilities = array_values(array_filter($reference->capabilities ?? [], 'is_string'));
        if ($capabilities === []) {
            return null;
        }

        try {
            return $this->leases->acquire($siteId, $reference->reference_key, [$capabilities[0]]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $material
     */
    private function extractApiKey(array $material): ?string
    {
        foreach (['protect_api_key', 'api_key', 'token'] as $key) {
            if (isset($material[$key]) && is_string($material[$key]) && trim($material[$key]) !== '') {
                return trim($material[$key]);
            }
        }

        return null;
    }

    private function detectImageMime(string $bytes): ?string
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

    /**
     * @param  array<string, string>  $headers
     * @return array{status: int, content_type: string, body: string}
     */
    private function sendHttp(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeoutMs,
    ): array {
        if ($this->httpTransport !== null) {
            return ($this->httpTransport)($method, $url, $headers, $body, $timeoutMs);
        }

        try {
            $pending = Http::withHeaders($headers)
                ->timeout(max(1, (int) ceil($timeoutMs / 1000)))
                ->connectTimeout(2)
                ->withoutRedirecting()
                ->withoutVerifying();

            $response = strtoupper($method) === 'POST'
                ? $pending->withBody((string) $body, $headers['Content-Type'] ?? 'application/json')->post($url)
                : $pending->get($url);

            return [
                'status' => $response->status(),
                'content_type' => (string) $response->header('Content-Type'),
                'body' => $response->body(),
            ];
        } catch (Throwable) {
            return [
                'status' => 0,
                'content_type' => '',
                'body' => '',
            ];
        }
    }
}
