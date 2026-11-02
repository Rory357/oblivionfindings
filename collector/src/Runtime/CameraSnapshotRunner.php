<?php

namespace Oblivion\Collector\Runtime;

use DateTimeImmutable;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\ScopeGuard;
use Throwable;

/**
 * S&D Phase B: one still picture from a UniFi Protect camera, taken on the hub that covers the Site.
 *
 * 1. Read the Protect console's certificate. Nothing else is sent.
 * 2. No trusted key (and not publicly valid), or a different key: stop and report the certificate.
 * 3. Only then send the API key, over a connection that accepts nothing but the trusted key, and
 *    fetch the picture (the standard size, which stays under the 1 MB limit).
 */
final readonly class CameraSnapshotRunner
{
    private const string API = '/proxy/protect/integration/v1';

    private const array FAILURE_TEXT = [
        'camera_certificate_untrusted' => 'The Protect console’s certificate isn’t trusted yet, so no API key was sent.',
        'camera_certificate_changed' => 'The Protect console’s certificate has changed, so no API key was sent.',
        'camera_tls_unreachable' => 'The hub couldn’t open a secure connection to the Protect console.',
        'authentication_required' => 'The Protect console rejected the API key, or none is saved.',
        'camera_not_mapped' => 'The camera wasn’t found on the Protect console.',
        'snapshot_fetch_failed' => 'The Protect console didn’t return a picture.',
        'unreachable' => 'The hub couldn’t reach the Protect console.',
        'snapshot_too_large' => 'The picture was larger than the hub’s 1 MB limit.',
        'invalid_image' => 'The Protect console didn’t return a valid picture.',
        'precheck_failed' => 'The hub couldn’t complete its safety and login checks.',
        'interrupted' => 'The hub restarted before the picture came back.',
    ];

    public function __construct(
        private ScopeGuard $scope,
        private CredentialLeaseDecryptor $credentials,
        private CameraHubNetwork $network = new NativeCameraHubNetwork,
    ) {}

    /** @param array<string, mixed> $command @return array<string, mixed> */
    public function run(array $command, ?DateTimeImmutable $at = null): array
    {
        $at ??= new DateTimeImmutable('now');
        $this->scope->assertCommand($command, $at);
        $startedAt = new DateTimeImmutable('now');
        $endpoint = $command['endpoint'];
        $material = [];
        $apiKey = null;

        try {
            try {
                $material = $this->credentials->open($command, $at);
            } catch (Throwable) {
                return $this->failed($command, 'precheck_failed', $at, $startedAt);
            }
            foreach (['protect_api_key', 'api_key', 'token'] as $key) {
                if (is_string($material[$key] ?? null) && trim($material[$key]) !== '') {
                    $apiKey = trim($material[$key]);
                    break;
                }
            }
            if ($apiKey === null) {
                return $this->failed($command, 'authentication_required', $at, $startedAt);
            }

            $address = (string) $endpoint['address'];
            $port = (int) $endpoint['port'];
            $certificate = $this->network->inspectCertificate($address, $port, (int) $endpoint['connect_timeout_seconds']);
            if ($certificate === null) {
                return $this->failed($command, 'camera_tls_unreachable', $at, $startedAt);
            }
            $trusted = $endpoint['trusted_pin'];
            if (is_string($trusted)) {
                if (! hash_equals($trusted, $certificate['pin'])) {
                    return $this->failed($command, 'camera_certificate_changed', $at, $startedAt, $certificate);
                }
                $pin = $trusted;
            } elseif ($certificate['publicly_trusted']) {
                $pin = null;
            } else {
                return $this->failed($command, 'camera_certificate_untrusted', $at, $startedAt, $certificate);
            }

            $base = 'https://'.(str_contains($address, ':') ? "[{$address}]" : $address).($port === 443 ? '' : ':'.$port).self::API;
            $headers = ['X-API-KEY' => $apiKey];
            $connect = (int) $endpoint['connect_timeout_seconds'];
            $response = (int) $endpoint['response_timeout_seconds'];

            $cameraId = $endpoint['camera_id'];
            if (! is_string($cameraId)) {
                $cameraId = $this->findCamera($base, $headers + ['Accept' => 'application/json'], $pin, $connect, $response, $endpoint['mac']);
                if ($cameraId === null) {
                    return $this->failed($command, 'camera_not_mapped', $at, $startedAt);
                }
            }

            $picture = $this->network->get(
                $base.'/cameras/'.rawurlencode($cameraId).'/snapshot',
                $headers + ['Accept' => 'image/jpeg, image/png, image/webp'],
                $pin,
                $connect,
                $response,
                (int) $endpoint['max_image_bytes'],
            );
            if ($picture['truncated']) {
                return $this->failed($command, 'snapshot_too_large', $at, $startedAt);
            }
            if (in_array($picture['status'], [401, 403], true)) {
                return $this->failed($command, 'authentication_required', $at, $startedAt);
            }
            if ($picture['status'] === 0) {
                return $this->failed($command, 'unreachable', $at, $startedAt);
            }
            if ($picture['status'] < 200 || $picture['status'] >= 300 || $picture['body'] === '') {
                return $this->failed($command, 'snapshot_fetch_failed', $at, $startedAt);
            }
            $mime = self::mime($picture['body']);
            if ($mime === null) {
                return $this->failed($command, 'invalid_image', $at, $startedAt);
            }

            return $this->succeeded($command, $mime, $picture['body'], $at, $startedAt);
        } catch (Throwable) {
            return $this->failed($command, 'unreachable', $at, $startedAt);
        } finally {
            if (is_string($apiKey)) {
                sodium_memzero($apiKey);
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

    /** @param array<string, string> $headers */
    private function findCamera(string $base, array $headers, ?string $pin, int $connect, int $response, mixed $mac): ?string
    {
        if (! is_string($mac) || preg_match('/\A[0-9A-F]{12}\z/', $mac) !== 1) {
            return null;
        }
        $list = $this->network->get($base.'/cameras', $headers, $pin, $connect, $response, 1_048_576);
        $decoded = $list['status'] >= 200 && $list['status'] < 300 ? json_decode($list['body'], true) : null;
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
            if ($rowMac === $mac && preg_match('/\A[A-Za-z0-9_-]{4,64}\z/', $id) === 1) {
                return $id;
            }
        }

        return null;
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
