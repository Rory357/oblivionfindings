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
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Throwable;
use UnexpectedValueException;

/**
 * Bounded, SSRF-safe ONVIF Profile S/T snapshot and PTZ client for camera profiles.
 *
 * Official specifications implemented:
 *   - ONVIF Core Specification (WS-UsernameToken PasswordDigest):
 *     https://www.onvif.org/specs/core/ONVIF-Core-Specification.pdf
 *   - ONVIF Media Service Specification (GetProfiles, GetSnapshotUri):
 *     https://www.onvif.org/specs/srv/streaming/ONVIF-Streaming-Spec.pdf
 *   - ONVIF PTZ Service Specification (ContinuousMove, Stop, GotoHomePosition):
 *     https://www.onvif.org/specs/srv/ptz/ONVIF-PTZ-Service-Spec.pdf
 */
final class OnvifCameraProfileClient
{
    /** @var list<int> */
    private const ALLOWED_PORTS = [80, 443, 8000, 8080, 8443, 8899];

    private const MAX_SNAPSHOT_BYTES = 2_097_152;

    /** @var array<string, array{pan: float, tilt: float, zoom: float}> */
    public const PTZ_VELOCITIES = [
        'pan_left' => ['pan' => -0.5, 'tilt' => 0.0, 'zoom' => 0.0],
        'pan_right' => ['pan' => 0.5, 'tilt' => 0.0, 'zoom' => 0.0],
        'tilt_up' => ['pan' => 0.0, 'tilt' => 0.5, 'zoom' => 0.0],
        'tilt_down' => ['pan' => 0.0, 'tilt' => -0.5, 'zoom' => 0.0],
        'zoom_in' => ['pan' => 0.0, 'tilt' => 0.0, 'zoom' => 0.5],
        'zoom_out' => ['pan' => 0.0, 'tilt' => 0.0, 'zoom' => -0.5],
    ];

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

    public static function supportsPtz(Device $device): bool
    {
        if (strtolower((string) $device->subcategory) === 'ptz_camera') {
            return true;
        }

        foreach ([$device->meta ?? [], $device->config ?? []] as $source) {
            foreach (['onvif.ptz_supported', 'capabilities.ptz', 'observed.capabilities.ptz'] as $path) {
                $value = data_get($source, $path);
                if ($value === true || (is_string($value) && in_array(strtolower(trim($value)), ['true', 'supported', 'enabled'], true))) {
                    return true;
                }
            }
        }

        return false;
    }

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
        $endpoint = $this->authoriseCameraEndpoint($device);
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
            if ($endpoint['site_id'] !== null) {
                $lease = $this->acquireOptionalCredential($device, $endpoint['site_id']);
                $material = $lease ? $lease->material() : [];
            }

            [$username, $password] = $this->extractCredentials($material);
            $mediaUrl = $endpoint['base_url'].$endpoint['media_path'];

            $profilesXml = $this->soapEnvelope(
                '<trt:GetProfiles xmlns:trt="http://www.onvif.org/ver10/media/wsdl"/>',
                $username,
                $password,
            );

            $profilesResponse = $this->sendHttp(
                'POST',
                $mediaUrl,
                ['Content-Type' => 'application/soap+xml; charset=utf-8; action="http://www.onvif.org/ver10/media/wsdl/GetProfiles"'],
                $profilesXml,
                3000,
            );

            if (in_array($profilesResponse['status'], [401, 403], true)) {
                return [
                    'ok' => false,
                    'reason_code' => 'authentication_required',
                    'message' => 'This camera requires ONVIF username and password credentials on Setup before snapshots can be fetched.',
                    'data_uri' => null,
                    'captured_at' => $capturedAt,
                ];
            }

            if ($profilesResponse['status'] < 200 || $profilesResponse['status'] >= 300 || $profilesResponse['body'] === '') {
                return [
                    'ok' => false,
                    'reason_code' => $profilesResponse['status'] === 0 ? 'unreachable' : 'not_supported',
                    'message' => $profilesResponse['status'] === 0
                        ? 'Could not reach this camera on its ONVIF service port. Check network connectivity or Site hub routing.'
                        : 'This camera did not respond to an ONVIF Media GetProfiles request.',
                    'data_uri' => null,
                    'captured_at' => $capturedAt,
                ];
            }

            $profileToken = $this->extractFirstProfileToken($profilesResponse['body']);
            if ($profileToken === null) {
                return [
                    'ok' => false,
                    'reason_code' => 'no_media_profile',
                    'message' => 'The camera responded, but no ONVIF media profile token was returned.',
                    'data_uri' => null,
                    'captured_at' => $capturedAt,
                ];
            }

            $escapedToken = htmlspecialchars($profileToken, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $snapshotUriXml = $this->soapEnvelope(
                '<trt:GetSnapshotUri xmlns:trt="http://www.onvif.org/ver10/media/wsdl"><trt:ProfileToken>'.$escapedToken.'</trt:ProfileToken></trt:GetSnapshotUri>',
                $username,
                $password,
            );

            $uriResponse = $this->sendHttp(
                'POST',
                $mediaUrl,
                ['Content-Type' => 'application/soap+xml; charset=utf-8; action="http://www.onvif.org/ver10/media/wsdl/GetSnapshotUri"'],
                $snapshotUriXml,
                3000,
            );

            if ($uriResponse['status'] < 200 || $uriResponse['status'] >= 300) {
                return [
                    'ok' => false,
                    'reason_code' => 'snapshot_uri_unavailable',
                    'message' => 'This camera did not supply an ONVIF snapshot URI.',
                    'data_uri' => null,
                    'captured_at' => $capturedAt,
                ];
            }

            $snapshotUri = $this->extractSnapshotUri($uriResponse['body']);
            $safeSnapshotUrl = $snapshotUri !== null
                ? $this->validateSameHostSnapshotUrl($snapshotUri, $endpoint['ip'])
                : null;

            if ($safeSnapshotUrl === null) {
                return [
                    'ok' => false,
                    'reason_code' => 'invalid_snapshot_uri',
                    'message' => 'The camera returned a snapshot URI outside its authorised IP address.',
                    'data_uri' => null,
                    'captured_at' => $capturedAt,
                ];
            }

            $headers = ['Accept' => 'image/jpeg, image/png, image/webp'];
            if ($username !== null && $password !== null) {
                $headers['Authorization'] = 'Basic '.base64_encode($username.':'.$password);
            }

            $imageResponse = $this->sendHttp('GET', $safeSnapshotUrl, $headers, null, 4000);
            unset($headers['Authorization']);

            if ($imageResponse['status'] < 200 || $imageResponse['status'] >= 300 || $imageResponse['body'] === '') {
                return [
                    'ok' => false,
                    'reason_code' => $imageResponse['status'] === 401 ? 'authentication_required' : 'snapshot_fetch_failed',
                    'message' => $imageResponse['status'] === 401
                        ? 'The camera snapshot endpoint rejected the current credentials.'
                        : 'Could not download the still image from the camera snapshot URI.',
                    'data_uri' => null,
                    'captured_at' => $capturedAt,
                ];
            }

            if (strlen($imageResponse['body']) > self::MAX_SNAPSHOT_BYTES) {
                return [
                    'ok' => false,
                    'reason_code' => 'snapshot_too_large',
                    'message' => 'The camera snapshot exceeded the 2 MB safety limit.',
                    'data_uri' => null,
                    'captured_at' => $capturedAt,
                ];
            }

            $mime = $this->detectImageMime($imageResponse['body']);
            if ($mime === null) {
                return [
                    'ok' => false,
                    'reason_code' => 'invalid_image',
                    'message' => 'The camera response was not a valid JPEG, PNG, or WebP image.',
                    'data_uri' => null,
                    'captured_at' => $capturedAt,
                ];
            }

            return [
                'ok' => true,
                'reason_code' => 'captured',
                'message' => 'Snapshot captured from camera via ONVIF.',
                'data_uri' => 'data:'.$mime.';base64,'.base64_encode($imageResponse['body']),
                'captured_at' => $capturedAt,
            ];
        } finally {
            foreach ($material as &$secret) {
                if (is_string($secret) && $secret !== '' && function_exists('sodium_memzero')) {
                    sodium_memzero($secret);
                }
            }
            unset($secret);

            if (isset($password) && is_string($password) && $password !== '' && function_exists('sodium_memzero')) {
                sodium_memzero($password);
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
     *     action: string
     * }
     */
    public function performPtz(Device $device, string $action): array
    {
        if (! self::supportsPtz($device)) {
            return [
                'ok' => false,
                'reason_code' => 'ptz_not_supported',
                'message' => 'This camera is not marked as a PTZ model.',
                'action' => $action,
            ];
        }

        if (! isset(self::PTZ_VELOCITIES[$action]) && ! in_array($action, ['stop', 'home'], true)) {
            return [
                'ok' => false,
                'reason_code' => 'invalid_action',
                'message' => 'Choose a supported PTZ action.',
                'action' => $action,
            ];
        }

        $endpoint = $this->authoriseCameraEndpoint($device);
        if (! $endpoint['ok']) {
            return [
                'ok' => false,
                'reason_code' => $endpoint['reason_code'],
                'message' => $endpoint['message'],
                'action' => $action,
            ];
        }

        $lease = null;
        $material = [];

        try {
            if ($endpoint['site_id'] !== null) {
                $lease = $this->acquireOptionalCredential($device, $endpoint['site_id']);
                $material = $lease ? $lease->material() : [];
            }

            [$username, $password] = $this->extractCredentials($material);
            $mediaUrl = $endpoint['base_url'].$endpoint['media_path'];
            $ptzUrl = $endpoint['base_url'].$endpoint['ptz_path'];

            $profilesResponse = $this->sendHttp(
                'POST',
                $mediaUrl,
                ['Content-Type' => 'application/soap+xml; charset=utf-8; action="http://www.onvif.org/ver10/media/wsdl/GetProfiles"'],
                $this->soapEnvelope('<trt:GetProfiles xmlns:trt="http://www.onvif.org/ver10/media/wsdl"/>', $username, $password),
                3000,
            );

            if (in_array($profilesResponse['status'], [401, 403], true)) {
                return [
                    'ok' => false,
                    'reason_code' => 'authentication_required',
                    'message' => 'Save ONVIF camera credentials on Setup before sending PTZ movements.',
                    'action' => $action,
                ];
            }

            $profileToken = $profilesResponse['status'] >= 200 && $profilesResponse['status'] < 300
                ? $this->extractFirstProfileToken($profilesResponse['body'])
                : null;

            if ($profileToken === null) {
                return [
                    'ok' => false,
                    'reason_code' => $profilesResponse['status'] === 0 ? 'unreachable' : 'not_supported',
                    'message' => $profilesResponse['status'] === 0
                        ? 'Could not reach this camera on its ONVIF service port.'
                        : 'The camera did not return an ONVIF media profile for PTZ control.',
                    'action' => $action,
                ];
            }

            $escapedToken = htmlspecialchars($profileToken, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            if ($action === 'stop') {
                $soapAction = 'http://www.onvif.org/ver20/ptz/wsdl/Stop';
                $bodyXml = '<tptz:Stop xmlns:tptz="http://www.onvif.org/ver20/ptz/wsdl">'
                    .'<tptz:ProfileToken>'.$escapedToken.'</tptz:ProfileToken>'
                    .'<tptz:PanTilt>true</tptz:PanTilt><tptz:Zoom>true</tptz:Zoom>'
                    .'</tptz:Stop>';
            } elseif ($action === 'home') {
                $soapAction = 'http://www.onvif.org/ver20/ptz/wsdl/GotoHomePosition';
                $bodyXml = '<tptz:GotoHomePosition xmlns:tptz="http://www.onvif.org/ver20/ptz/wsdl">'
                    .'<tptz:ProfileToken>'.$escapedToken.'</tptz:ProfileToken>'
                    .'</tptz:GotoHomePosition>';
            } else {
                $velocity = self::PTZ_VELOCITIES[$action];
                $soapAction = 'http://www.onvif.org/ver20/ptz/wsdl/ContinuousMove';
                $bodyXml = '<tptz:ContinuousMove xmlns:tptz="http://www.onvif.org/ver20/ptz/wsdl" xmlns:tt="http://www.onvif.org/ver10/schema">'
                    .'<tptz:ProfileToken>'.$escapedToken.'</tptz:ProfileToken>'
                    .'<tptz:Velocity>'
                    .sprintf('<tt:PanTilt x="%0.2f" y="%0.2f"/>', $velocity['pan'], $velocity['tilt'])
                    .sprintf('<tt:Zoom x="%0.2f"/>', $velocity['zoom'])
                    .'</tptz:Velocity>'
                    .'<tptz:Timeout>PT1S</tptz:Timeout>'
                    .'</tptz:ContinuousMove>';
            }

            $ptzResponse = $this->sendHttp(
                'POST',
                $ptzUrl,
                ['Content-Type' => 'application/soap+xml; charset=utf-8; action="'.$soapAction.'"'],
                $this->soapEnvelope($bodyXml, $username, $password),
                3000,
            );

            if ($ptzResponse['status'] < 200 || $ptzResponse['status'] >= 300) {
                return [
                    'ok' => false,
                    'reason_code' => $ptzResponse['status'] === 401 ? 'authentication_required' : 'ptz_failed',
                    'message' => $ptzResponse['status'] === 401
                        ? 'The camera rejected PTZ authentication.'
                        : 'The camera did not accept that ONVIF PTZ command.',
                    'action' => $action,
                ];
            }

            return [
                'ok' => true,
                'reason_code' => 'sent',
                'message' => match ($action) {
                    'stop' => 'PTZ movement stopped.',
                    'home' => 'Camera moving to home preset.',
                    default => 'PTZ movement sent.',
                },
                'action' => $action,
            ];
        } finally {
            foreach ($material as &$secret) {
                if (is_string($secret) && $secret !== '' && function_exists('sodium_memzero')) {
                    sodium_memzero($secret);
                }
            }
            unset($secret);

            if (isset($password) && is_string($password) && $password !== '' && function_exists('sodium_memzero')) {
                sodium_memzero($password);
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
     *     ip?: string,
     *     site_id?: int|null,
     *     base_url?: string,
     *     media_path?: string,
     *     ptz_path?: string
     * }
     */
    private function authoriseCameraEndpoint(Device $device): array
    {
        $rawIp = trim((string) ($device->ip_address ?? ''));
        if ($rawIp === '') {
            return [
                'ok' => false,
                'reason_code' => 'missing_address',
                'message' => 'Add an IP address to this camera before using ONVIF snapshot or PTZ controls.',
            ];
        }

        if (filter_var($rawIp, FILTER_VALIDATE_IP) === false) {
            return [
                'ok' => false,
                'reason_code' => 'invalid_address',
                'message' => 'Save a numeric LAN IP address on this camera before using ONVIF controls.',
            ];
        }

        try {
            $canonicalIp = $this->cidrMatcher->canonicalAddress($rawIp);
        } catch (Throwable) {
            return [
                'ok' => false,
                'reason_code' => 'invalid_address',
                'message' => 'The camera IP address is not valid.',
            ];
        }

        $port = (int) (data_get($device->config, 'onvif.port') ?? data_get($device->meta, 'onvif.port') ?? 80);
        if (! in_array($port, self::ALLOWED_PORTS, true)) {
            $port = 80;
        }
        $scheme = in_array($port, [443, 8443], true) ? 'https' : 'http';

        try {
            $siteId = $this->siteResolver->resolve((int) $device->id);
        } catch (UnexpectedValueException) {
            $siteId = null;
        }

        if ($siteId === null || $siteId < 1) {
            return [
                'ok' => false,
                'reason_code' => 'missing_site_scope',
                'message' => 'Assign this camera to an active Site with an approved discovery subnet before using ONVIF controls.',
            ];
        }

        $approvedCidrs = $this->approvedSiteCidrsForAddress($siteId, $canonicalIp);
        if ($approvedCidrs === []) {
            return [
                'ok' => false,
                'reason_code' => 'out_of_site_scope',
                'message' => 'Add this camera\'s subnet to an active discovery scope for its Site before using ONVIF controls.',
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
                'message' => 'That IP address is reserved or blocked by outbound network policy.',
            ];
        }

        $hostBracketed = str_contains($canonicalIp, ':') ? "[{$canonicalIp}]" : $canonicalIp;
        $defaultPort = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
        $baseUrl = $scheme.'://'.$hostBracketed.($defaultPort ? '' : ':'.$port);

        $mediaPath = $this->safeServicePath(
            data_get($device->config, 'onvif.media_path') ?? data_get($device->meta, 'onvif.media_path'),
            '/onvif/media_service',
        );
        $ptzPath = $this->safeServicePath(
            data_get($device->config, 'onvif.ptz_path') ?? data_get($device->meta, 'onvif.ptz_path'),
            '/onvif/ptz_service',
        );

        return [
            'ok' => true,
            'reason_code' => 'authorised',
            'message' => 'Camera ONVIF endpoint authorised.',
            'ip' => $canonicalIp,
            'site_id' => $siteId,
            'base_url' => $baseUrl,
            'media_path' => $mediaPath,
            'ptz_path' => $ptzPath,
        ];
    }

    private function safeServicePath(mixed $candidate, string $fallback): string
    {
        if (! is_string($candidate)) {
            return $fallback;
        }

        $trimmed = trim($candidate);
        if ($trimmed === '' || ! str_starts_with($trimmed, '/') || str_starts_with($trimmed, '//')) {
            return $fallback;
        }

        return preg_match('/^\/[A-Za-z0-9_.\/-]{1,120}$/', $trimmed) === 1 ? $trimmed : $fallback;
    }

    /**
     * Return active approved discovery CIDRs for `$siteId` that cover `$canonicalIp` without excluding it.
     *
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
            if ($this->addressExcludedByScope($canonicalIp, $scope->exclusions ?? [])) {
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

    /**
     * @param  array<int, mixed>  $exclusions
     */
    private function addressExcludedByScope(string $canonicalIp, array $exclusions): bool
    {
        foreach ($exclusions as $exclusion) {
            if (! is_string($exclusion) || trim($exclusion) === '') {
                continue;
            }
            $trimmed = trim($exclusion);
            try {
                if (str_contains($trimmed, '/') && $this->cidrMatcher->contains($trimmed, $canonicalIp)) {
                    return true;
                }
                if (! str_contains($trimmed, '/')
                    && $this->cidrMatcher->canonicalAddress($trimmed) === $canonicalIp) {
                    return true;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return false;
    }

    private function acquireOptionalCredential(Device $device, int $siteId): ?CredentialLease
    {
        $providers = array_values(array_unique(array_filter([
            strtolower(trim((string) $device->provider)),
            'onvif',
        ])));

        $reference = CredentialReference::query()
            ->where('site_id', $siteId)
            ->whereIn('provider', $providers)
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
     * @return array{0: string|null, 1: string|null}
     */
    private function extractCredentials(array $material): array
    {
        $username = null;
        foreach (['username', 'user', 'onvif_username'] as $key) {
            if (isset($material[$key]) && is_string($material[$key]) && trim($material[$key]) !== '') {
                $username = trim($material[$key]);
                break;
            }
        }

        $password = null;
        foreach (['password', 'secret', 'onvif_password'] as $key) {
            if (isset($material[$key]) && is_string($material[$key]) && $material[$key] !== '') {
                $password = $material[$key];
                break;
            }
        }

        return [$username, $password];
    }

    private function soapEnvelope(string $bodyXml, ?string $username, ?string $password): string
    {
        $headerXml = '';
        if ($username !== null && $password !== null) {
            $nonceRaw = random_bytes(16);
            $created = gmdate('Y-m-d\TH:i:s\Z');
            $digest = base64_encode(sha1($nonceRaw.$created.$password, true));
            $escapedUser = htmlspecialchars($username, ENT_XML1 | ENT_QUOTES, 'UTF-8');

            $headerXml = '<s:Header>'
                .'<Security s:mustUnderstand="1" xmlns="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">'
                .'<UsernameToken>'
                .'<Username>'.$escapedUser.'</Username>'
                .'<Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordDigest">'.$digest.'</Password>'
                .'<Nonce EncodingType="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary">'.base64_encode($nonceRaw).'</Nonce>'
                .'<Created xmlns="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd">'.$created.'</Created>'
                .'</UsernameToken>'
                .'</Security>'
                .'</s:Header>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope">'
            .$headerXml
            .'<s:Body>'.$bodyXml.'</s:Body>'
            .'</s:Envelope>';
    }

    private function extractFirstProfileToken(string $xml): ?string
    {
        $doc = $this->parseSafeXml($xml);
        if (! $doc) {
            return null;
        }

        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query('//*[local-name()="Profiles"]/@token');
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $token = trim((string) $nodes->item(0)?->nodeValue);

        return $token !== '' && strlen($token) <= 120 ? $token : null;
    }

    private function extractSnapshotUri(string $xml): ?string
    {
        $doc = $this->parseSafeXml($xml);
        if (! $doc) {
            return null;
        }

        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query('//*[local-name()="MediaUri"]/*[local-name()="Uri"] | //*[local-name()="Uri"]');
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $uri = trim((string) $nodes->item(0)?->nodeValue);

        return $uri !== '' && strlen($uri) <= 512 ? $uri : null;
    }

    private function parseSafeXml(string $xml): ?DOMDocument
    {
        if ($xml === '' || strlen($xml) > 262_144 || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument;
            $loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);

            return $loaded ? $doc : null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function validateSameHostSnapshotUrl(string $rawUrl, string $authorisedCanonicalIp): ?string
    {
        $parts = parse_url($rawUrl);
        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = trim((string) ($parts['host'] ?? ''), '[]');
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        try {
            $snapshotIp = $this->cidrMatcher->canonicalAddress($host);
        } catch (Throwable) {
            return null;
        }

        if (! hash_equals($authorisedCanonicalIp, $snapshotIp)) {
            return null;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        if (! in_array($port, self::ALLOWED_PORTS, true)) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '/');
        if ($path === '' || ! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }
        $query = isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== ''
            ? '?'.$parts['query']
            : '';

        $hostBracketed = str_contains($snapshotIp, ':') ? "[{$snapshotIp}]" : $snapshotIp;
        $defaultPort = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);

        return $scheme.'://'.$hostBracketed.($defaultPort ? '' : ':'.$port).$path.$query;
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
                ? $pending->withBody((string) $body, $headers['Content-Type'] ?? 'application/soap+xml; charset=utf-8')->post($url)
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
