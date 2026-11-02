<?php

namespace Oblivion\Collector\Runtime;

use DateTimeImmutable;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\ScopeGuard;
use Throwable;

/**
 * S&D Phase E (R5): one still picture from a standard ONVIF camera, taken on the hub that covers the
 * Site. The same rules as the server's own ONVIF client:
 *
 * 1. https: read the camera's certificate first. Untrusted or changed: stop and report it; no login.
 * 2. Ask the camera's media service for its first profile (Media1, then Media2) and its picture
 *    address, signed with a WS-Security password digest; answer only an HTTP Digest challenge.
 * 3. The picture address must be the camera's own IP. https only on the trusted port.
 * 4. Fetch the picture with HTTP Digest only. A camera that asks for Basic gets no password.
 */
final readonly class OnvifSnapshotRunner
{
    private const int MAX_SOAP_BYTES = 262_144;

    private const array HTTP_PICTURE_PORTS = [80, 2020, 8000, 8080, 8899];

    private const array FAILURE_TEXT = [
        'camera_certificate_untrusted' => 'The camera’s certificate isn’t trusted yet, so no login was sent.',
        'camera_certificate_changed' => 'The camera’s certificate has changed, so no login was sent.',
        'camera_tls_unreachable' => 'The hub couldn’t open a secure connection to the camera.',
        'authentication_required' => 'The camera rejected the saved login, or none is saved.',
        'basic_auth_refused' => 'The camera asks for a plain (Basic) password, so the hub didn’t send it.',
        'not_supported' => 'The camera didn’t answer the ONVIF picture request.',
        'no_media_profile' => 'The camera answered but has no ONVIF media profile.',
        'snapshot_uri_unavailable' => 'The camera didn’t give a picture address.',
        'invalid_snapshot_uri' => 'The camera gave a picture address outside its own address.',
        'snapshot_fetch_failed' => 'The camera didn’t return a picture.',
        'unreachable' => 'The hub couldn’t reach the camera.',
        'snapshot_too_large' => 'The picture was larger than the hub’s 1 MB limit.',
        'invalid_image' => 'The camera didn’t return a valid picture.',
        'precheck_failed' => 'The hub couldn’t complete its safety and login checks.',
        'interrupted' => 'The hub restarted before the picture came back.',
    ];

    public function __construct(
        private ScopeGuard $scope,
        private CredentialLeaseDecryptor $credentials,
        private OnvifCameraNetwork $network = new NativeOnvifCameraNetwork,
    ) {}

    /** @return list<string> */
    public static function failureCodes(): array
    {
        return array_keys(self::FAILURE_TEXT);
    }

    /** @param array<string, mixed> $command @return array<string, mixed> */
    public function run(array $command, ?DateTimeImmutable $at = null): array
    {
        $at ??= new DateTimeImmutable('now');
        $this->scope->assertCommand($command, $at);
        $startedAt = new DateTimeImmutable('now');
        $endpoint = $command['endpoint'];
        $material = [];
        $username = null;
        $password = null;

        try {
            try {
                $material = $this->credentials->open($command, $at);
            } catch (Throwable) {
                return $this->failed($command, 'precheck_failed', $at, $startedAt);
            }
            [$username, $password] = self::login($material);
            if ($username === null || $password === null) {
                return $this->failed($command, 'authentication_required', $at, $startedAt);
            }

            $address = (string) $endpoint['address'];
            $port = (int) $endpoint['port'];
            $scheme = (string) $endpoint['scheme'];
            $connect = (int) $endpoint['connect_timeout_seconds'];
            $timeout = (int) $endpoint['response_timeout_seconds'];
            $pin = null;
            if ($scheme === 'https') {
                $certificate = $this->network->inspectCertificate($address, $port, $connect);
                if ($certificate === null) {
                    return $this->failed($command, 'camera_tls_unreachable', $at, $startedAt);
                }
                $trusted = $endpoint['trusted_pin'];
                if (is_string($trusted)) {
                    if (! hash_equals($trusted, $certificate['pin'])) {
                        return $this->failed($command, 'camera_certificate_changed', $at, $startedAt, $certificate);
                    }
                    $pin = $trusted;
                } elseif (! $certificate['publicly_trusted']) {
                    return $this->failed($command, 'camera_certificate_untrusted', $at, $startedAt, $certificate);
                }
            }
            $base = $scheme.'://'.self::host($address).(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443) ? '' : ':'.$port);
            $call = fn (string $path, string $action, string $body): array => $this->soap($base.$path, $action, $body, $username, $password, $pin, $connect, $timeout);

            $mediaPath = (string) $endpoint['media_path'];
            $media2 = false;
            $profiles = $call($mediaPath, 'http://www.onvif.org/ver10/media/wsdl/GetProfiles', '<trt:GetProfiles xmlns:trt="http://www.onvif.org/ver10/media/wsdl"/>');
            if (! self::ok($profiles) && ! in_array($profiles['status'], [0, 401, 403], true)) {
                $second = $call((string) $endpoint['media2_path'], 'http://www.onvif.org/ver20/media/wsdl/GetProfiles', '<tr2:GetProfiles xmlns:tr2="http://www.onvif.org/ver20/media/wsdl"/>');
                if (self::ok($second)) {
                    [$profiles, $mediaPath, $media2] = [$second, (string) $endpoint['media2_path'], true];
                }
            }
            if (in_array($profiles['status'], [401, 403], true)) {
                return $this->failed($command, 'authentication_required', $at, $startedAt);
            }
            if (! self::ok($profiles)) {
                return $this->failed($command, $profiles['status'] === 0 ? 'unreachable' : 'not_supported', $at, $startedAt);
            }
            $token = self::profileToken($profiles['body']);
            if ($token === null) {
                return $this->failed($command, 'no_media_profile', $at, $startedAt);
            }

            $escaped = htmlspecialchars($token, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $uriResponse = $media2
                ? $call($mediaPath, 'http://www.onvif.org/ver20/media/wsdl/GetSnapshotUri', '<tr2:GetSnapshotUri xmlns:tr2="http://www.onvif.org/ver20/media/wsdl"><tr2:ProfileToken>'.$escaped.'</tr2:ProfileToken></tr2:GetSnapshotUri>')
                : $call($mediaPath, 'http://www.onvif.org/ver10/media/wsdl/GetSnapshotUri', '<trt:GetSnapshotUri xmlns:trt="http://www.onvif.org/ver10/media/wsdl"><trt:ProfileToken>'.$escaped.'</trt:ProfileToken></trt:GetSnapshotUri>');
            if (! self::ok($uriResponse)) {
                return $this->failed($command, 'snapshot_uri_unavailable', $at, $startedAt);
            }
            $picture = self::sameCamera(self::snapshotUri($uriResponse['body']), $address, $scheme, $port);
            if ($picture === null) {
                return $this->failed($command, 'invalid_snapshot_uri', $at, $startedAt);
            }

            $image = $this->fetch($picture['url'], $picture['https'] ? $pin : null, $username, $password, $connect, $timeout, (int) $endpoint['max_image_bytes']);
            if ($image === 'basic_auth_refused') {
                return $this->failed($command, 'basic_auth_refused', $at, $startedAt);
            }
            if ($image['truncated']) {
                return $this->failed($command, 'snapshot_too_large', $at, $startedAt);
            }
            if (in_array($image['status'], [401, 403], true)) {
                return $this->failed($command, 'authentication_required', $at, $startedAt);
            }
            if ($image['status'] === 0) {
                return $this->failed($command, 'unreachable', $at, $startedAt);
            }
            if (! self::ok($image)) {
                return $this->failed($command, 'snapshot_fetch_failed', $at, $startedAt);
            }
            $mime = self::mime($image['body']);
            if ($mime === null) {
                return $this->failed($command, 'invalid_image', $at, $startedAt);
            }

            return $this->succeeded($command, $mime, $image['body'], $at, $startedAt);
        } catch (Throwable) {
            return $this->failed($command, 'unreachable', $at, $startedAt);
        } finally {
            if (is_string($password) && $password !== '') {
                sodium_memzero($password);
            }
            foreach ($material as &$value) {
                if (is_string($value) && $value !== '') {
                    sodium_memzero($value);
                }
                $value = null;
            }
            unset($value);
        }
    }

    /** @param array<string, mixed> $command @return array<string, mixed> */
    public function interrupted(array $command, ?DateTimeImmutable $at = null): array
    {
        $at ??= new DateTimeImmutable('now');
        $this->scope->assertCommand($command, $at);

        return $this->failed($command, 'interrupted', $at, $at);
    }

    /**
     * One SOAP call, signed with a WS-Security password digest; a 401 is answered only when it is
     * an HTTP Digest challenge.
     *
     * @return array{status: int, body: string, truncated: bool, challenge: string}
     */
    private function soap(string $url, string $action, string $bodyXml, string $username, string $password, ?string $pin, int $connect, int $timeout): array
    {
        $headers = ['Content-Type' => 'application/soap+xml; charset=utf-8; action="'.$action.'"'];
        $response = $this->network->request('POST', $url, $headers, self::envelope($bodyXml, $username, $password), $pin, $connect, $timeout, self::MAX_SOAP_BYTES);
        if ($response['status'] === 401) {
            $digest = self::digest($response['challenge'], 'POST', self::requestUri($url), $username, $password);
            if ($digest !== null) {
                $response = $this->network->request('POST', $url, $headers + ['Authorization' => $digest], self::envelope($bodyXml, $username, $password), $pin, $connect, $timeout, self::MAX_SOAP_BYTES);
            }
        }

        return $response;
    }

    /**
     * The picture, asked first with no password; only an HTTP Digest challenge is answered.
     *
     * @return array{status: int, body: string, truncated: bool, challenge: string}|'basic_auth_refused'
     */
    private function fetch(string $url, ?string $pin, string $username, string $password, int $connect, int $timeout, int $maxBytes): array|string
    {
        $headers = ['Accept' => 'image/jpeg, image/png, image/webp'];
        $response = $this->network->request('GET', $url, $headers, null, $pin, $connect, $timeout, $maxBytes);
        if ($response['status'] !== 401) {
            return $response;
        }
        $digest = self::digest($response['challenge'], 'GET', self::requestUri($url), $username, $password);
        if ($digest === null) {
            return preg_match('/\A\s*Digest\s/i', $response['challenge']) === 1 ? $response : 'basic_auth_refused';
        }

        return $this->network->request('GET', $url, $headers + ['Authorization' => $digest], null, $pin, $connect, $timeout, $maxBytes);
    }

    private static function envelope(string $bodyXml, string $username, string $password): string
    {
        $nonce = random_bytes(16);
        $created = gmdate('Y-m-d\TH:i:s\Z');
        $digest = base64_encode(sha1($nonce.$created.$password, true));

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"><s:Header>'
            .'<Security s:mustUnderstand="1" xmlns="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd"><UsernameToken>'
            .'<Username>'.htmlspecialchars($username, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</Username>'
            .'<Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordDigest">'.$digest.'</Password>'
            .'<Nonce EncodingType="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary">'.base64_encode($nonce).'</Nonce>'
            .'<Created xmlns="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd">'.$created.'</Created>'
            .'</UsernameToken></Security></s:Header>'
            .'<s:Body>'.$bodyXml.'</s:Body></s:Envelope>';
    }

    /** An RFC 7616 Digest answer, or null when the challenge isn't Digest (Basic is never answered). */
    private static function digest(string $challenge, string $method, string $uri, string $username, string $password): ?string
    {
        if (preg_match('/\A\s*Digest\s+(.+)\z/is', trim($challenge), $match) !== 1) {
            return null;
        }
        $params = [];
        if (preg_match_all('/([A-Za-z0-9_-]+)\s*=\s*(?:"((?:[^"\\\\]|\\\\.)*)"|([^\s,]+))/', $match[1], $pairs, PREG_SET_ORDER)) {
            foreach ($pairs as $pair) {
                $params[strtolower($pair[1])] = isset($pair[2]) && $pair[2] !== '' ? stripcslashes($pair[2]) : ($pair[3] ?? '');
            }
        }
        $realm = $params['realm'] ?? null;
        $nonce = $params['nonce'] ?? null;
        if ($realm === null || $nonce === null || $nonce === '') {
            return null;
        }
        $algorithm = strtoupper(trim($params['algorithm'] ?? 'MD5'));
        $hash = match ($algorithm) {
            'SHA-256', 'SHA-256-SESS' => 'sha256',
            'MD5', 'MD5-SESS', '' => 'md5',
            default => null,
        };
        if ($hash === null) {
            return null;
        }
        $cnonce = bin2hex(random_bytes(8));
        $count = '00000001';
        $ha1 = hash($hash, $username.':'.$realm.':'.$password);
        if (str_ends_with($algorithm, '-SESS')) {
            $ha1 = hash($hash, $ha1.':'.$nonce.':'.$cnonce);
        }
        $qopAuth = in_array('auth', array_map('trim', explode(',', strtolower($params['qop'] ?? ''))), true);
        $ha2 = hash($hash, $method.':'.$uri);
        $response = $qopAuth
            ? hash($hash, $ha1.':'.$nonce.':'.$count.':'.$cnonce.':auth:'.$ha2)
            : hash($hash, $ha1.':'.$nonce.':'.$ha2);
        $quote = static fn (string $value): string => str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
        $parts = [
            'username="'.$quote($username).'"', 'realm="'.$quote($realm).'"', 'nonce="'.$quote($nonce).'"',
            'uri="'.$quote($uri).'"', 'algorithm='.($algorithm !== '' ? $algorithm : 'MD5'), 'response="'.$response.'"',
        ];
        if ($qopAuth) {
            array_push($parts, 'qop=auth', 'nc='.$count, 'cnonce="'.$cnonce.'"');
        }
        if (($params['opaque'] ?? '') !== '') {
            $parts[] = 'opaque="'.$quote($params['opaque']).'"';
        }

        return 'Digest '.implode(', ', $parts);
    }

    /**
     * The picture address, only when it is the camera's own IP: http on a camera port, or https on
     * the same port as the trusted connection (so the trusted key still applies).
     *
     * @return array{url: string, https: bool}|null
     */
    private static function sameCamera(?string $raw, string $address, string $scheme, int $port): ?array
    {
        if ($raw === null || strlen($raw) > 512 || preg_match('/[\x00-\x20\x7f]/', $raw) === 1) {
            return null;
        }
        $parts = parse_url($raw);
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $pictureScheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        if (! in_array($pictureScheme, ['http', 'https'], true) || filter_var($host, FILTER_VALIDATE_IP) === false
            || inet_pton($host) !== inet_pton($address)) {
            return null;
        }
        $picturePort = isset($parts['port']) ? (int) $parts['port'] : ($pictureScheme === 'https' ? 443 : 80);
        if ($pictureScheme === 'https' ? ($scheme !== 'https' || $picturePort !== $port) : ! in_array($picturePort, self::HTTP_PICTURE_PORTS, true)) {
            return null;
        }
        $path = (string) ($parts['path'] ?? '/');
        $path = str_starts_with($path, '/') ? $path : '/'.$path;
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';
        $default = ($pictureScheme === 'http' && $picturePort === 80) || ($pictureScheme === 'https' && $picturePort === 443);

        return [
            'url' => $pictureScheme.'://'.self::host($address).($default ? '' : ':'.$picturePort).$path.$query,
            'https' => $pictureScheme === 'https',
        ];
    }

    private static function profileToken(string $xml): ?string
    {
        if (! self::safeXml($xml) || preg_match('/<(?:[A-Za-z0-9_-]+:)?Profiles\b[^>]*?\btoken\s*=\s*"([^"<>]{1,120})"/', $xml, $match) !== 1) {
            return null;
        }
        $token = trim(htmlspecialchars_decode($match[1], ENT_XML1 | ENT_QUOTES));

        return $token !== '' ? $token : null;
    }

    private static function snapshotUri(string $xml): ?string
    {
        if (! self::safeXml($xml) || preg_match('/<(?:[A-Za-z0-9_-]+:)?Uri>\s*([^<]{1,600})\s*<\/(?:[A-Za-z0-9_-]+:)?Uri>/', $xml, $match) !== 1) {
            return null;
        }
        $uri = trim(htmlspecialchars_decode($match[1], ENT_XML1 | ENT_QUOTES));

        return $uri !== '' ? $uri : null;
    }

    /** No document types or entities: the replies are read with plain patterns, never expanded. */
    private static function safeXml(string $xml): bool
    {
        return $xml !== '' && stripos($xml, '<!DOCTYPE') === false && stripos($xml, '<!ENTITY') === false;
    }

    /** @param array<string, scalar|null> $material @return array{0: ?string, 1: ?string} */
    private static function login(array $material): array
    {
        $username = null;
        foreach (['username', 'user', 'onvif_username'] as $key) {
            if (is_string($material[$key] ?? null) && trim($material[$key]) !== '' && strlen($material[$key]) <= 128) {
                $username = trim($material[$key]);
                break;
            }
        }
        $password = null;
        foreach (['password', 'secret', 'onvif_password'] as $key) {
            if (is_string($material[$key] ?? null) && $material[$key] !== '' && strlen($material[$key]) <= 1024) {
                $password = $material[$key];
                break;
            }
        }

        return [$username, $password];
    }

    private static function requestUri(string $url): string
    {
        $parts = parse_url($url);
        $path = is_array($parts) && ($parts['path'] ?? '') !== '' ? (string) $parts['path'] : '/';

        return $path.(is_array($parts) && ($parts['query'] ?? '') !== '' ? '?'.$parts['query'] : '');
    }

    private static function host(string $address): string
    {
        return str_contains($address, ':') ? '['.$address.']' : $address;
    }

    /** @param array{status: int, body: string, truncated: bool, challenge: string} $response */
    private static function ok(array $response): bool
    {
        return $response['status'] >= 200 && $response['status'] < 300 && ! $response['truncated'] && $response['body'] !== '';
    }

    private static function mime(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => 'image/png',
            strlen($bytes) >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
    }

    /** @param array<string, mixed> $command @return array<string, mixed> */
    private function succeeded(array $command, string $mime, string $image, DateTimeImmutable $acceptedAt, DateTimeImmutable $startedAt): array
    {
        $sha = hash('sha256', $image);
        $result = $this->result($command, 'succeeded', [
            'provider_state' => 'captured',
            'mime' => $mime,
            'image_bytes' => strlen($image),
            'image_sha256' => $sha,
        ], 'camera-snapshot:'.$command['command_uuid'], null, $acceptedAt, $startedAt);
        $result['attachment'] = [
            'kind' => 'image',
            'encoding' => 'gzip+base64',
            'bytes' => strlen($image),
            'sha256' => $sha,
            'data' => base64_encode((string) gzencode($image, 1)),
        ];

        return $result;
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array{pin: string, fingerprint: string, subject: string|null, valid_to: int|null, publicly_trusted: bool}|null  $certificate
     * @return array<string, mixed>
     */
    private function failed(array $command, string $code, DateTimeImmutable $acceptedAt, DateTimeImmutable $startedAt, ?array $certificate = null): array
    {
        $safe = ['reason_code' => $code];
        if ($certificate !== null) {
            $safe += [
                'offered_pin' => $certificate['pin'],
                'offered_fingerprint' => $certificate['fingerprint'],
                'offered_subject' => $certificate['subject'] === null ? null : substr($certificate['subject'], 0, 200),
                'offered_valid_to' => $certificate['valid_to'],
            ];
        }

        return $this->result($command, 'failed', $safe, null, self::FAILURE_TEXT[$code], $acceptedAt, $startedAt);
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, scalar|null>  $safeResult
     * @return array<string, mixed>
     */
    private function result(array $command, string $status, array $safeResult, ?string $reference, ?string $failure, DateTimeImmutable $acceptedAt, DateTimeImmutable $startedAt): array
    {
        $completedAt = new DateTimeImmutable('now');
        $startedAt = $startedAt < $acceptedAt ? $acceptedAt : $startedAt;
        $completedAt = $completedAt < $startedAt ? $startedAt : $completedAt;

        return [
            'item_type' => 'command_result',
            'command_uuid' => $command['command_uuid'],
            'attempt_uuid' => $command['attempt_uuid'],
            'attempt_number' => $command['attempt_number'],
            'site_id' => $command['site_id'],
            'device_id' => $command['device_id'],
            'capability' => $command['capability'],
            'contract_hash' => $command['contract_hash'],
            'execution_status' => $status,
            'safe_result' => $safeResult,
            'provider_request_reference' => $reference,
            'safe_failure_reason' => $failure,
            'accepted_at' => $acceptedAt->format(DATE_ATOM),
            'started_at' => $startedAt->format(DATE_ATOM),
            'completed_at' => $completedAt->format(DATE_ATOM),
            'reconciliation' => null,
        ];
    }
}
