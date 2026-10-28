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
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Throwable;
use UnexpectedValueException;

/**
 * Bounded, SSRF-safe ONVIF Profile S/T device, media, snapshot, and PTZ client.
 *
 * Supports Hikvision, Dahua, Uniview, Axis, Hanwha Vision, TP-Link Tapo/VIGI,
 * and standard ONVIF Profile S (Media1 H.264/JPEG) & Profile T (Media2 H.265/H.264)
 * cameras over WS-UsernameToken PasswordDigest and RFC 7616 HTTP Digest authentication.
 *
 * Official specifications implemented:
 *   - ONVIF Profile S & Profile T:
 *     https://www.onvif.org/profiles/profile-s/
 *     https://www.onvif.org/profiles/profile-t/
 *   - ONVIF Core & Device Management WSDL (GetDeviceInformation, GetServices):
 *     https://www.onvif.org/specs/core/ONVIF-Core-Specification.pdf
 *     https://www.onvif.org/ver10/device/wsdl/devicemgmt.wsdl
 *   - ONVIF Media1 & Media2 Service WSDL (GetProfiles, GetStreamUri, GetSnapshotUri):
 *     https://www.onvif.org/ver10/media/wsdl/media.wsdl
 *     https://www.onvif.org/ver20/media/wsdl/media.wsdl
 *   - ONVIF PTZ Service WSDL (ContinuousMove, Stop, GotoHomePosition):
 *     https://www.onvif.org/ver20/ptz/wsdl/ptz.wsdl
 *   - IETF RFC 7616 HTTP Digest Access Authentication:
 *     https://www.rfc-editor.org/rfc/rfc7616
 */
final class OnvifCameraProfileClient
{
    /** @var list<int> */
    private const ALLOWED_PORTS = [80, 443, 2020, 8000, 8080, 8443, 8899];

    /** @var list<int> */
    private const ALLOWED_RTSP_PORTS = [80, 443, 554, 10554, 7441, 7447, 8554, 8899];

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
     * @param  (Closure(string, string, array<string, string>, ?string, int): array{status: int, content_type: string, body: string, headers?: array<string, string>})|null  $httpTransport
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
     * Probe ONVIF Device Management (`GetDeviceInformation`, `GetServices`) and
     * Media1 / Media2 profiles (`GetProfiles`, `GetStreamUri`, `GetSnapshotUri`).
     *
     * Returned RTSP / HTTP URIs are validated to match the camera's authorised IP
     * and stripped of any embedded userinfo credentials.
     *
     * @return array{
     *     ok: bool,
     *     reason_code: string,
     *     message: string,
     *     profiles: list<array{token: string, name: string, media_version: string, encoding: string|null, stream_uri: string|null, snapshot_uri: string|null}>,
     *     device_info: array{manufacturer: string|null, model: string|null, firmware_version: string|null, serial_number: string|null}
     * }
     */
    public function probeStreams(Device $device): array
    {
        $emptyDeviceInfo = [
            'manufacturer' => null,
            'model' => null,
            'firmware_version' => null,
            'serial_number' => null,
        ];

        $endpoint = $this->authoriseCameraEndpoint($device);
        if (! $endpoint['ok']) {
            return [
                'ok' => false,
                'reason_code' => $endpoint['reason_code'],
                'message' => $endpoint['message'],
                'profiles' => [],
                'device_info' => $emptyDeviceInfo,
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
            $deviceUrl = $endpoint['base_url'].$endpoint['device_path'];

            $services = $this->discoverServicePaths($deviceUrl, $endpoint['ip'], $username, $password);
            $deviceInfo = $this->fetchDeviceInformation($deviceUrl, $username, $password);

            $media1Path = $services['media1'] ?? $endpoint['media_path'];
            $media2Path = $services['media2'] ?? $endpoint['media2_path'];

            $profiles = [];

            // Probe Media2 (ONVIF Profile T — H.265 / H.264) when advertised or configured
            if ($services['media2'] !== null || data_get($device->config, 'onvif.prefer_media2') === true) {
                $media2Result = $this->fetchMediaProfiles(
                    $endpoint['base_url'].$media2Path,
                    $endpoint['ip'],
                    'media2',
                    $username,
                    $password,
                );
                if ($media2Result['status'] === 401 || $media2Result['status'] === 403) {
                    return [
                        'ok' => false,
                        'reason_code' => 'authentication_required',
                        'message' => 'This camera requires ONVIF username and password credentials before profiles can be inspected.',
                        'profiles' => [],
                        'device_info' => $deviceInfo,
                    ];
                }
                $profiles = array_merge($profiles, $media2Result['profiles']);
            }

            // Probe Media1 (ONVIF Profile S — H.264 / JPEG)
            $media1Result = $this->fetchMediaProfiles(
                $endpoint['base_url'].$media1Path,
                $endpoint['ip'],
                'media1',
                $username,
                $password,
            );

            if ($profiles === [] && in_array($media1Result['status'], [401, 403], true)) {
                return [
                    'ok' => false,
                    'reason_code' => 'authentication_required',
                    'message' => 'This camera requires ONVIF username and password credentials before profiles can be inspected.',
                    'profiles' => [],
                    'device_info' => $deviceInfo,
                ];
            }

            if ($media1Result['profiles'] !== []) {
                $existingTokens = array_column($profiles, 'token');
                foreach ($media1Result['profiles'] as $profile) {
                    if (! in_array($profile['token'], $existingTokens, true)) {
                        $profiles[] = $profile;
                    }
                }
            }

            // Fallback: if Media1 returned nothing and Media2 wasn't tried yet, try default Media2 path
            if ($profiles === [] && $services['media2'] === null) {
                $fallbackMedia2 = $this->fetchMediaProfiles(
                    $endpoint['base_url'].$media2Path,
                    $endpoint['ip'],
                    'media2',
                    $username,
                    $password,
                );
                $profiles = $fallbackMedia2['profiles'];
            }

            if ($profiles === [] && $deviceInfo['manufacturer'] === null && $deviceInfo['model'] === null) {
                return [
                    'ok' => false,
                    'reason_code' => $media1Result['status'] === 0 ? 'unreachable' : 'not_supported',
                    'message' => $media1Result['status'] === 0
                        ? 'Could not reach this camera on its ONVIF service port.'
                        : 'This camera did not return ONVIF Profile S or Profile T media profiles.',
                    'profiles' => [],
                    'device_info' => $deviceInfo,
                ];
            }

            return [
                'ok' => true,
                'reason_code' => 'probed',
                'message' => sprintf('Discovered %d ONVIF media %s.', count($profiles), count($profiles) === 1 ? 'profile' : 'profiles'),
                'profiles' => array_slice($profiles, 0, 8),
                'device_info' => $deviceInfo,
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

            $profilesResponse = $this->sendSoapWithDigestFallback(
                $mediaUrl,
                'http://www.onvif.org/ver10/media/wsdl/GetProfiles',
                '<trt:GetProfiles xmlns:trt="http://www.onvif.org/ver10/media/wsdl"/>',
                $username,
                $password,
                3000,
            );

            $usingMedia2 = false;
            if (($profilesResponse['status'] < 200 || $profilesResponse['status'] >= 300 || $profilesResponse['body'] === '')
                && ! in_array($profilesResponse['status'], [0, 401, 403], true)) {
                // Fallback to ONVIF Profile T Media2 (ver20/media/wsdl) or discovered service path
                $services = $this->discoverServicePaths(
                    $endpoint['base_url'].$endpoint['device_path'],
                    $endpoint['ip'],
                    $username,
                    $password,
                );
                $media2Url = $endpoint['base_url'].($services['media2'] ?? $endpoint['media2_path']);
                $media2Response = $this->sendSoapWithDigestFallback(
                    $media2Url,
                    'http://www.onvif.org/ver20/media/wsdl/GetProfiles',
                    '<tr2:GetProfiles xmlns:tr2="http://www.onvif.org/ver20/media/wsdl"/>',
                    $username,
                    $password,
                    3000,
                );
                if ($media2Response['status'] >= 200 && $media2Response['status'] < 300 && $media2Response['body'] !== '') {
                    $profilesResponse = $media2Response;
                    $mediaUrl = $media2Url;
                    $usingMedia2 = true;
                }
            }

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
            $uriResponse = $usingMedia2
                ? $this->sendSoapWithDigestFallback(
                    $mediaUrl,
                    'http://www.onvif.org/ver20/media/wsdl/GetSnapshotUri',
                    '<tr2:GetSnapshotUri xmlns:tr2="http://www.onvif.org/ver20/media/wsdl"><tr2:ProfileToken>'.$escapedToken.'</tr2:ProfileToken></tr2:GetSnapshotUri>',
                    $username,
                    $password,
                    3000,
                )
                : $this->sendSoapWithDigestFallback(
                    $mediaUrl,
                    'http://www.onvif.org/ver10/media/wsdl/GetSnapshotUri',
                    '<trt:GetSnapshotUri xmlns:trt="http://www.onvif.org/ver10/media/wsdl"><trt:ProfileToken>'.$escapedToken.'</trt:ProfileToken></trt:GetSnapshotUri>',
                    $username,
                    $password,
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

            $imageResponse = $this->fetchSnapshotBytesWithDigestSupport($safeSnapshotUrl, $username, $password);

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

            $profilesResponse = $this->sendSoapWithDigestFallback(
                $mediaUrl,
                'http://www.onvif.org/ver10/media/wsdl/GetProfiles',
                '<trt:GetProfiles xmlns:trt="http://www.onvif.org/ver10/media/wsdl"/>',
                $username,
                $password,
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

            $ptzResponse = $this->sendSoapWithDigestFallback(
                $ptzUrl,
                $soapAction,
                $bodyXml,
                $username,
                $password,
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
     * Build an RFC 7616 / RFC 2617 HTTP Digest Authorization header value for a challenge.
     */
    public static function buildHttpDigestAuthorization(
        string $wwwAuthenticate,
        string $method,
        string $requestUri,
        string $username,
        string $password,
        string $cnonce = '',
        int $nonceCount = 1,
    ): ?string {
        if (! preg_match('/^\s*Digest\s+(.+)$/i', trim($wwwAuthenticate), $matches)) {
            return null;
        }

        $params = self::parseDigestParams($matches[1]);
        $realm = $params['realm'] ?? null;
        $nonce = $params['nonce'] ?? null;
        if ($realm === null || $nonce === null || $nonce === '') {
            return null;
        }

        $algorithmRaw = strtoupper(trim($params['algorithm'] ?? 'MD5'));
        $hashAlgo = match ($algorithmRaw) {
            'SHA-256', 'SHA-256-SESS' => 'sha256',
            'MD5', 'MD5-SESS', '' => 'md5',
            default => null,
        };
        if ($hashAlgo === null) {
            return null;
        }

        $isSession = str_ends_with($algorithmRaw, '-SESS');
        $cnonceVal = $cnonce !== '' ? $cnonce : bin2hex(random_bytes(8));
        $ncHex = sprintf('%08x', max(1, $nonceCount));

        $ha1 = hash($hashAlgo, $username.':'.$realm.':'.$password);
        if ($isSession) {
            $ha1 = hash($hashAlgo, $ha1.':'.$nonce.':'.$cnonceVal);
        }

        $qopOptions = isset($params['qop'])
            ? array_map('trim', explode(',', strtolower($params['qop'])))
            : [];
        $useQopAuth = in_array('auth', $qopOptions, true);

        $ha2 = hash($hashAlgo, strtoupper($method).':'.$requestUri);
        $responseHash = $useQopAuth
            ? hash($hashAlgo, $ha1.':'.$nonce.':'.$ncHex.':'.$cnonceVal.':auth:'.$ha2)
            : hash($hashAlgo, $ha1.':'.$nonce.':'.$ha2);

        $safeUser = str_replace(['\\', '"'], ['\\\\', '\\"'], $username);
        $safeRealm = str_replace(['\\', '"'], ['\\\\', '\\"'], $realm);
        $safeNonce = str_replace(['\\', '"'], ['\\\\', '\\"'], $nonce);
        $safeUri = str_replace(['\\', '"'], ['\\\\', '\\"'], $requestUri);

        $parts = [
            'username="'.$safeUser.'"',
            'realm="'.$safeRealm.'"',
            'nonce="'.$safeNonce.'"',
            'uri="'.$safeUri.'"',
            'algorithm='.($algorithmRaw !== '' ? $algorithmRaw : 'MD5'),
            'response="'.$responseHash.'"',
        ];

        if ($useQopAuth) {
            $parts[] = 'qop=auth';
            $parts[] = 'nc='.$ncHex;
            $parts[] = 'cnonce="'.$cnonceVal.'"';
        }

        if (isset($params['opaque']) && $params['opaque'] !== '') {
            $safeOpaque = str_replace(['\\', '"'], ['\\\\', '\\"'], $params['opaque']);
            $parts[] = 'opaque="'.$safeOpaque.'"';
        }

        return 'Digest '.implode(', ', $parts);
    }

    /**
     * @return array<string, string>
     */
    private static function parseDigestParams(string $headerParams): array
    {
        $parsed = [];
        if (preg_match_all('/([A-Za-z0-9_-]+)\s*=\s*(?:"((?:[^"\\\\]|\\\\.)*)"|([^\s,]+))/', $headerParams, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = strtolower($match[1]);
                $val = isset($match[2]) && $match[2] !== ''
                    ? stripcslashes($match[2])
                    : ($match[3] ?? '');
                $parsed[$key] = $val;
            }
        }

        return $parsed;
    }

    /**
     * @return array{media1: string|null, media2: string|null, ptz: string|null}
     */
    private function discoverServicePaths(string $deviceUrl, string $authorisedIp, ?string $username, ?string $password): array
    {
        $discovered = ['media1' => null, 'media2' => null, 'ptz' => null];
        $response = $this->sendSoapWithDigestFallback(
            $deviceUrl,
            'http://www.onvif.org/ver10/device/wsdl/GetServices',
            '<tds:GetServices xmlns:tds="http://www.onvif.org/ver10/device/wsdl"><tds:IncludeCapability>false</tds:IncludeCapability></tds:GetServices>',
            $username,
            $password,
            2500,
        );

        if ($response['status'] < 200 || $response['status'] >= 300 || $response['body'] === '') {
            return $discovered;
        }

        $doc = $this->parseSafeXml($response['body']);
        if (! $doc) {
            return $discovered;
        }

        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query('//*[local-name()="Service"]');
        if ($nodes === false) {
            return $discovered;
        }

        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $ns = trim((string) $xpath->evaluate('string(*[local-name()="Namespace"])', $node));
            $xaddr = trim((string) $xpath->evaluate('string(*[local-name()="XAddr"])', $node));
            $path = $this->extractSafeSameHostServicePath($xaddr, $authorisedIp);
            if ($path === null) {
                continue;
            }

            if ($ns === 'http://www.onvif.org/ver10/media/wsdl') {
                $discovered['media1'] = $path;
            } elseif ($ns === 'http://www.onvif.org/ver20/media/wsdl') {
                $discovered['media2'] = $path;
            } elseif ($ns === 'http://www.onvif.org/ver20/ptz/wsdl') {
                $discovered['ptz'] = $path;
            }
        }

        return $discovered;
    }

    /**
     * @return array{manufacturer: string|null, model: string|null, firmware_version: string|null, serial_number: string|null}
     */
    private function fetchDeviceInformation(string $deviceUrl, ?string $username, ?string $password): array
    {
        $empty = [
            'manufacturer' => null,
            'model' => null,
            'firmware_version' => null,
            'serial_number' => null,
        ];

        $response = $this->sendSoapWithDigestFallback(
            $deviceUrl,
            'http://www.onvif.org/ver10/device/wsdl/GetDeviceInformation',
            '<tds:GetDeviceInformation xmlns:tds="http://www.onvif.org/ver10/device/wsdl"/>',
            $username,
            $password,
            2500,
        );

        if ($response['status'] < 200 || $response['status'] >= 300 || $response['body'] === '') {
            return $empty;
        }

        $doc = $this->parseSafeXml($response['body']);
        if (! $doc) {
            return $empty;
        }

        $xpath = new DOMXPath($doc);
        $readField = static function (string $localName) use ($xpath): ?string {
            $val = trim((string) $xpath->evaluate('string(//*[local-name()="'.$localName.'"][1])'));

            return $val !== '' ? substr($val, 0, 120) : null;
        };

        return [
            'manufacturer' => $readField('Manufacturer'),
            'model' => $readField('Model'),
            'firmware_version' => $readField('FirmwareVersion'),
            'serial_number' => $readField('SerialNumber'),
        ];
    }

    /**
     * @return array{
     *     status: int,
     *     profiles: list<array{token: string, name: string, media_version: string, encoding: string|null, stream_uri: string|null, snapshot_uri: string|null}>
     * }
     */
    private function fetchMediaProfiles(
        string $mediaUrl,
        string $authorisedIp,
        string $version,
        ?string $username,
        ?string $password,
    ): array {
        $isMedia2 = $version === 'media2';
        $response = $isMedia2
            ? $this->sendSoapWithDigestFallback(
                $mediaUrl,
                'http://www.onvif.org/ver20/media/wsdl/GetProfiles',
                '<tr2:GetProfiles xmlns:tr2="http://www.onvif.org/ver20/media/wsdl"><tr2:Type>All</tr2:Type></tr2:GetProfiles>',
                $username,
                $password,
                3000,
            )
            : $this->sendSoapWithDigestFallback(
                $mediaUrl,
                'http://www.onvif.org/ver10/media/wsdl/GetProfiles',
                '<trt:GetProfiles xmlns:trt="http://www.onvif.org/ver10/media/wsdl"/>',
                $username,
                $password,
                3000,
            );

        if ($response['status'] < 200 || $response['status'] >= 300 || $response['body'] === '') {
            return ['status' => $response['status'], 'profiles' => []];
        }

        $doc = $this->parseSafeXml($response['body']);
        if (! $doc) {
            return ['status' => $response['status'], 'profiles' => []];
        }

        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query('//*[local-name()="Profiles"]');
        if ($nodes === false || $nodes->length === 0) {
            return ['status' => $response['status'], 'profiles' => []];
        }

        $profiles = [];
        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement || count($profiles) >= 4) {
                continue;
            }
            $token = trim($node->getAttribute('token'));
            if ($token === '' || strlen($token) > 120) {
                continue;
            }
            $name = trim((string) $xpath->evaluate('string(*[local-name()="Name"][1])', $node));
            $encoding = trim((string) $xpath->evaluate(
                'string(.//*[local-name()="VideoEncoderConfiguration"]/*[local-name()="Encoding"][1])',
                $node,
            ));

            $escapedToken = htmlspecialchars($token, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $streamResp = $isMedia2
                ? $this->sendSoapWithDigestFallback(
                    $mediaUrl,
                    'http://www.onvif.org/ver20/media/wsdl/GetStreamUri',
                    '<tr2:GetStreamUri xmlns:tr2="http://www.onvif.org/ver20/media/wsdl"><tr2:Protocol>RTSP</tr2:Protocol><tr2:ProfileToken>'.$escapedToken.'</tr2:ProfileToken></tr2:GetStreamUri>',
                    $username,
                    $password,
                    2500,
                )
                : $this->sendSoapWithDigestFallback(
                    $mediaUrl,
                    'http://www.onvif.org/ver10/media/wsdl/GetStreamUri',
                    '<trt:GetStreamUri xmlns:trt="http://www.onvif.org/ver10/media/wsdl" xmlns:tt="http://www.onvif.org/ver10/schema"><trt:StreamSetup><tt:Stream>RTP-Unicast</tt:Stream><tt:Transport><tt:Protocol>RTSP</tt:Protocol></tt:Transport></trt:StreamSetup><trt:ProfileToken>'.$escapedToken.'</trt:ProfileToken></trt:GetStreamUri>',
                    $username,
                    $password,
                    2500,
                );

            $rawStreamUri = $streamResp['status'] >= 200 && $streamResp['status'] < 300
                ? $this->extractSnapshotUri($streamResp['body'])
                : null;
            $safeStreamUri = $rawStreamUri !== null
                ? $this->validateSameHostStreamUrl($rawStreamUri, $authorisedIp)
                : null;

            $snapResp = $isMedia2
                ? $this->sendSoapWithDigestFallback(
                    $mediaUrl,
                    'http://www.onvif.org/ver20/media/wsdl/GetSnapshotUri',
                    '<tr2:GetSnapshotUri xmlns:tr2="http://www.onvif.org/ver20/media/wsdl"><tr2:ProfileToken>'.$escapedToken.'</tr2:ProfileToken></tr2:GetSnapshotUri>',
                    $username,
                    $password,
                    2500,
                )
                : $this->sendSoapWithDigestFallback(
                    $mediaUrl,
                    'http://www.onvif.org/ver10/media/wsdl/GetSnapshotUri',
                    '<trt:GetSnapshotUri xmlns:trt="http://www.onvif.org/ver10/media/wsdl"><trt:ProfileToken>'.$escapedToken.'</trt:ProfileToken></trt:GetSnapshotUri>',
                    $username,
                    $password,
                    2500,
                );

            $rawSnapUri = $snapResp['status'] >= 200 && $snapResp['status'] < 300
                ? $this->extractSnapshotUri($snapResp['body'])
                : null;
            $safeSnapUri = $rawSnapUri !== null
                ? $this->validateSameHostSnapshotUrl($rawSnapUri, $authorisedIp)
                : null;

            $profiles[] = [
                'token' => $token,
                'name' => $name !== '' ? substr($name, 0, 80) : $token,
                'media_version' => $version,
                'encoding' => $encoding !== '' ? strtoupper(substr($encoding, 0, 16)) : null,
                'stream_uri' => $safeStreamUri,
                'snapshot_uri' => $safeSnapUri,
            ];
        }

        return ['status' => $response['status'], 'profiles' => $profiles];
    }

    /**
     * @return array{
     *     ok: bool,
     *     reason_code: string,
     *     message: string,
     *     ip?: string,
     *     site_id?: int|null,
     *     base_url?: string,
     *     device_path?: string,
     *     media_path?: string,
     *     media2_path?: string,
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

        $port = (int) (data_get($device->config, 'onvif.port') ?? data_get($device->meta, 'onvif.port') ?? $this->defaultOnvifPortForDevice($device));
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

        $devicePath = $this->safeServicePath(
            data_get($device->config, 'onvif.device_path') ?? data_get($device->meta, 'onvif.device_path'),
            '/onvif/device_service',
        );
        $mediaPath = $this->safeServicePath(
            data_get($device->config, 'onvif.media_path') ?? data_get($device->meta, 'onvif.media_path'),
            '/onvif/media_service',
        );
        $media2Path = $this->safeServicePath(
            data_get($device->config, 'onvif.media2_path') ?? data_get($device->meta, 'onvif.media2_path'),
            '/onvif/media2_service',
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
            'device_path' => $devicePath,
            'media_path' => $mediaPath,
            'media2_path' => $media2Path,
            'ptz_path' => $ptzPath,
        ];
    }

    private function defaultOnvifPortForDevice(Device $device): int
    {
        $vendor = strtolower(trim($device->manufacturer.' '.$device->model.' '.$device->provider));
        if (str_contains($vendor, 'tapo') || str_contains($vendor, 'tp-link') || str_contains($vendor, 'tplink')) {
            return 2020;
        }

        return 80;
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

    private function extractSafeSameHostServicePath(string $xaddr, string $authorisedCanonicalIp): ?string
    {
        $parts = parse_url($xaddr);
        if (! is_array($parts)) {
            return null;
        }

        $host = trim((string) ($parts['host'] ?? ''), '[]');
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        try {
            $xaddrIp = $this->cidrMatcher->canonicalAddress($host);
        } catch (Throwable) {
            return null;
        }

        if (! hash_equals($authorisedCanonicalIp, $xaddrIp)) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');

        return $this->safeServicePath($path, '') !== '' ? $path : null;
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
            strtolower(trim((string) $device->manufacturer)),
            'onvif',
            'hikvision',
            'dahua',
            'uniview',
            'axis',
            'hanwha',
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

    /**
     * Send an ONVIF SOAP request with WS-UsernameToken, automatically answering
     * an HTTP 401 Digest challenge (`WWW-Authenticate: Digest`) when credentials exist.
     *
     * @return array{status: int, content_type: string, body: string, headers?: array<string, string>}
     */
    private function sendSoapWithDigestFallback(
        string $url,
        string $soapAction,
        string $bodyXml,
        ?string $username,
        ?string $password,
        int $timeoutMs,
    ): array {
        $headers = [
            'Content-Type' => 'application/soap+xml; charset=utf-8; action="'.$soapAction.'"',
        ];
        $response = $this->sendHttp(
            'POST',
            $url,
            $headers,
            $this->soapEnvelope($bodyXml, $username, $password),
            $timeoutMs,
        );

        if ($response['status'] === 401 && $username !== null && $password !== null) {
            $challenge = $this->extractWwwAuthenticate($response);
            if ($challenge !== '') {
                $digestAuth = self::buildHttpDigestAuthorization(
                    $challenge,
                    'POST',
                    $this->requestUriForDigest($url),
                    $username,
                    $password,
                );
                if ($digestAuth !== null) {
                    $headers['Authorization'] = $digestAuth;
                    $response = $this->sendHttp(
                        'POST',
                        $url,
                        $headers,
                        $this->soapEnvelope($bodyXml, $username, $password),
                        $timeoutMs,
                    );
                }
            }
        }

        return $response;
    }

    /**
     * Fetch snapshot image bytes using HTTP Basic or RFC 7616 HTTP Digest challenge-response.
     *
     * @return array{status: int, content_type: string, body: string, headers?: array<string, string>}
     */
    private function fetchSnapshotBytesWithDigestSupport(string $safeSnapshotUrl, ?string $username, ?string $password): array
    {
        $headers = ['Accept' => 'image/jpeg, image/png, image/webp'];
        if ($username !== null && $password !== null) {
            $headers['Authorization'] = 'Basic '.base64_encode($username.':'.$password);
        }

        $response = $this->sendHttp('GET', $safeSnapshotUrl, $headers, null, 4000);
        unset($headers['Authorization']);

        if ($response['status'] === 401 && $username !== null && $password !== null) {
            $challenge = $this->extractWwwAuthenticate($response);
            if ($challenge !== '') {
                $digestAuth = self::buildHttpDigestAuthorization(
                    $challenge,
                    'GET',
                    $this->requestUriForDigest($safeSnapshotUrl),
                    $username,
                    $password,
                );
                if ($digestAuth !== null) {
                    $headers['Authorization'] = $digestAuth;
                    $response = $this->sendHttp('GET', $safeSnapshotUrl, $headers, null, 4000);
                }
            }
        }

        return $response;
    }

    /**
     * @param  array{status: int, content_type: string, body: string, headers?: array<string, string>}  $response
     */
    private function extractWwwAuthenticate(array $response): string
    {
        $headers = $response['headers'] ?? [];
        foreach (['www-authenticate', 'WWW-Authenticate', 'Www-Authenticate'] as $key) {
            if (isset($headers[$key]) && is_string($headers[$key]) && trim($headers[$key]) !== '') {
                return trim($headers[$key]);
            }
        }

        return '';
    }

    private function requestUriForDigest(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return '/';
        }
        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        $query = isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== ''
            ? '?'.$parts['query']
            : '';

        return $path.$query;
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

    /**
     * Validate an RTSP/RTSPS stream URI against the camera's authorised IP and strip credentials.
     */
    private function validateSameHostStreamUrl(string $rawUrl, string $authorisedCanonicalIp): ?string
    {
        $parts = parse_url($rawUrl);
        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['rtsp', 'rtsps'], true)) {
            return null;
        }

        $host = trim((string) ($parts['host'] ?? ''), '[]');
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        try {
            $streamIp = $this->cidrMatcher->canonicalAddress($host);
        } catch (Throwable) {
            return null;
        }

        if (! hash_equals($authorisedCanonicalIp, $streamIp)) {
            return null;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'rtsps' ? 322 : 554);
        if (! in_array($port, self::ALLOWED_RTSP_PORTS, true) && $port !== 322) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '/');
        if ($path === '' || ! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        $safeQuery = '';
        if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
            $safePairs = [];
            foreach (explode('&', $parts['query']) as $pair) {
                if ($pair === '') {
                    continue;
                }
                [$rawKey] = explode('=', $pair, 2);
                $normKey = strtolower(trim(rawurldecode($rawKey)));
                if (in_array($normKey, [
                    'token', 'access_token', 'auth', 'password', 'pass', 'pwd',
                    'secret', 'key', 'api_key', 'apikey', 'session', 'sig', 'signature',
                ], true)) {
                    continue;
                }
                $safePairs[] = $pair;
            }
            if ($safePairs !== []) {
                $safeQuery = '?'.implode('&', $safePairs);
            }
        }

        $hostBracketed = str_contains($streamIp, ':') ? "[{$streamIp}]" : $streamIp;

        return $scheme.'://'.$hostBracketed.':'.$port.$path.$safeQuery;
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
     * @return array{status: int, content_type: string, body: string, headers: array<string, string>}
     */
    private function sendHttp(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeoutMs,
    ): array {
        if ($this->httpTransport !== null) {
            $result = ($this->httpTransport)($method, $url, $headers, $body, $timeoutMs);

            return [
                'status' => $result['status'],
                'content_type' => $result['content_type'],
                'body' => $result['body'],
                'headers' => $result['headers'] ?? [],
            ];
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
                'headers' => [
                    'www-authenticate' => (string) $response->header('WWW-Authenticate'),
                ],
            ];
        } catch (Throwable) {
            return [
                'status' => 0,
                'content_type' => '',
                'body' => '',
                'headers' => [],
            ];
        }
    }
}
