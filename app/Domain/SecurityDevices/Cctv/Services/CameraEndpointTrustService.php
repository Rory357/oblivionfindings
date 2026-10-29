<?php

namespace App\Domain\SecurityDevices\Cctv\Services;

use App\Domain\SecurityDevices\Cctv\Models\CameraEndpointTrust;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Trust this camera?" (Phase A, item A5, docs/security-devices/GOAL.md).
 *
 * Camera and Protect clients used to switch certificate checks off, so anything answering at a camera's
 * address could collect its password or API key. Now, for an https camera address:
 *
 * - a certificate that a public authority vouches for is checked the normal way;
 * - a self-signed certificate (most cameras and consoles) gets no password or key until someone has
 *   trusted its public key. The address's key is pinned, and every later request accepts only that key;
 * - a changed key is refused until an IT admin replaces the trusted one. Trusts and replacements are
 *   audit-logged.
 *
 * Trust is kept per Site, address and port, so a Protect console is trusted once for all its cameras.
 */
class CameraEndpointTrustService
{
    public const string PIN_PATTERN = '/\Asha256\/\/[A-Za-z0-9+\/]{43}=\z/';

    /**
     * Whether credentials may be sent to this https address now.
     *
     * @return array{ok: bool, reason_code: string, message: string, pin: string|null, trust: array<string, mixed>|null}
     */
    public function gate(int $siteId, string $host, int $port): array
    {
        $record = $this->record($siteId, $host, $port);
        $certificate = $this->inspect($host, $port);
        if ($certificate === null) {
            return $this->refusal('camera_tls_unreachable', 'Oblivion couldn’t open a secure connection to this camera, so nothing was sent.', null);
        }

        if ($record?->trusted_pin !== null && hash_equals($record->trusted_pin, $certificate['pin'])) {
            return ['ok' => true, 'reason_code' => 'camera_tls_pinned', 'message' => '', 'pin' => $record->trusted_pin, 'trust' => null];
        }

        if ($record?->trusted_pin === null && $certificate['publicly_trusted']) {
            return ['ok' => true, 'reason_code' => 'camera_tls_verified', 'message' => '', 'pin' => null, 'trust' => null];
        }

        $record = $this->recordOffered($siteId, $host, $port, $certificate);
        $changed = $record->trusted_pin !== null;

        return $this->refusal(
            $changed ? 'camera_certificate_changed' : 'camera_certificate_untrusted',
            $changed
                ? 'This camera’s certificate has changed since it was trusted, so no password was sent. If the change was planned, an IT admin can replace the trusted certificate.'
                : 'This camera’s certificate isn’t trusted yet, so no password was sent. Check it and choose Trust this camera.',
            $record,
        );
    }

    /**
     * Laravel HTTP options for a request to an https camera address that passed gate(). A pinned address
     * accepts only the pinned public key; anything else uses normal certificate checks.
     *
     * @return array<string, mixed>
     */
    public static function httpOptions(?string $pin): array
    {
        if ($pin === null) {
            return ['verify' => true];
        }

        // The pin is the check: cURL refuses any other public key before sending a byte. Normal chain
        // checks are off only because the pinned certificate is self-signed.
        return ['verify' => false, 'curl' => [CURLOPT_PINNEDPUBLICKEY => $pin]];
    }

    public function canTrust(User $user, CameraEndpointTrust $record): bool
    {
        if ($record->offered_pin === null) {
            return false;
        }

        return $record->trusted_pin !== null
            ? $user->canDo('securityDevices.commands.admin')
            : $user->canDo('securityDevices.commands.manage');
    }

    /** Trust the key this address offered; the fingerprint must match the one recorded. */
    public function trust(User $user, int $siteId, string $host, int $port, string $fingerprint): CameraEndpointTrust
    {
        return DB::transaction(function () use ($user, $siteId, $host, $port, $fingerprint): CameraEndpointTrust {
            $record = CameraEndpointTrust::query()
                ->where('site_id', $siteId)->where('host', $host)->where('port', $port)
                ->lockForUpdate()->first();

            if ($record === null || $record->offered_fingerprint === null || ! hash_equals($record->offered_fingerprint, strtoupper(trim($fingerprint)))) {
                throw ValidationException::withMessages([
                    'fingerprint' => 'This isn’t the certificate the camera offered. Try the camera again to see its current certificate.',
                ]);
            }

            abort_unless($this->canTrust($user, $record), 403, $record->trusted_pin !== null
                ? 'Only an IT admin can replace a trusted camera certificate.'
                : 'You can’t trust camera certificates.');

            $previous = $record->trusted_fingerprint;
            $record->forceFill([
                'trusted_pin' => $record->offered_pin,
                'trusted_fingerprint' => $record->offered_fingerprint,
                'trusted_at' => CarbonImmutable::now('UTC'),
                'trusted_by_user_id' => (int) $user->id,
                'offered_pin' => null,
                'offered_fingerprint' => null,
                'offered_subject' => null,
                'offered_valid_to' => null,
                'offered_at' => null,
            ])->save();

            AuditLogger::logOrFail($previous === null ? 'security_devices.camera_certificate.trusted' : 'security_devices.camera_certificate.replaced', $record, [
                'site_id' => $siteId,
                'host' => $host,
                'port' => $port,
                'fingerprint' => $record->trusted_fingerprint,
                'previous_fingerprint' => $previous,
                'actor_id' => (int) $user->id,
            ]);

            return $record;
        });
    }

    /**
     * What a page shows about this address's certificate.
     *
     * @return array{trusted_fingerprint: string|null, offered: array{fingerprint: string, subject: string|null, valid_to: string|null, seen_at: string|null, changed: bool}|null, can_trust: bool}|null
     */
    public function present(User $user, ?CameraEndpointTrust $record): ?array
    {
        if ($record === null) {
            return null;
        }

        return [
            'trusted_fingerprint' => $record->trusted_fingerprint,
            'offered' => $record->offered_fingerprint === null ? null : [
                'fingerprint' => $record->offered_fingerprint,
                'subject' => $record->offered_subject,
                'valid_to' => $record->offered_valid_to?->toIso8601String(),
                'seen_at' => $record->offered_at?->toIso8601String(),
                'changed' => $record->trusted_pin !== null,
            ],
            'can_trust' => $this->canTrust($user, $record),
        ];
    }

    public function record(int $siteId, string $host, int $port): ?CameraEndpointTrust
    {
        return CameraEndpointTrust::query()->where('site_id', $siteId)->where('host', $host)->where('port', $port)->first();
    }

    /**
     * Read the certificate an address presents, without trusting it and without sending anything else.
     *
     * @return array{pin: string, fingerprint: string, subject: string|null, valid_to: int|null, publicly_trusted: bool}|null
     */
    public function inspect(string $host, int $port, float $timeoutSeconds = 4.0): ?array
    {
        $target = (str_contains($host, ':') ? "[{$host}]" : $host).':'.$port;
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'SNI_enabled' => filter_var($host, FILTER_VALIDATE_IP) === false,
        ]]);

        set_error_handler(static fn (): bool => true);
        try {
            $socket = stream_socket_client('ssl://'.$target, $errorNumber, $errorText, $timeoutSeconds, STREAM_CLIENT_CONNECT, $context);
        } finally {
            restore_error_handler();
        }
        if (! is_resource($socket)) {
            return null;
        }

        $certificate = stream_context_get_params($socket)['options']['ssl']['peer_certificate'] ?? null;
        fclose($socket);
        if ($certificate === null) {
            return null;
        }

        $pin = self::publicKeyPin($certificate);
        $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');
        if ($pin === null || ! is_string($fingerprint)) {
            return null;
        }

        $parsed = openssl_x509_parse($certificate) ?: [];
        $subject = $parsed['subject']['CN'] ?? ($parsed['name'] ?? null);

        return [
            'pin' => $pin,
            'fingerprint' => self::formatFingerprint($fingerprint),
            'subject' => is_array($subject) ? implode(', ', $subject) : (is_string($subject) ? substr($subject, 0, 255) : null),
            'valid_to' => isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : null,
            'publicly_trusted' => $this->publiclyTrusted($host, $port, $timeoutSeconds),
        ];
    }

    /** The cURL pin for a certificate: sha256// plus base64 of the SHA-256 of its public key (SPKI). */
    public static function publicKeyPin(mixed $certificate): ?string
    {
        $key = openssl_pkey_get_public($certificate);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if (! is_array($details) || ! is_string($details['key'] ?? null)) {
            return null;
        }

        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $details['key']) ?? '', true);

        return is_string($der) && $der !== '' ? 'sha256//'.base64_encode(hash('sha256', $der, true)) : null;
    }

    /** "AB:CD:…", the way browsers show a certificate's SHA-256 fingerprint. */
    public static function formatFingerprint(string $hex): string
    {
        return strtoupper(implode(':', str_split(strtolower($hex), 2)));
    }

    private function publiclyTrusted(string $host, int $port, float $timeoutSeconds): bool
    {
        $target = (str_contains($host, ':') ? "[{$host}]" : $host).':'.$port;
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
        ]]);

        set_error_handler(static fn (): bool => true);
        try {
            $socket = stream_socket_client('ssl://'.$target, $errorNumber, $errorText, $timeoutSeconds, STREAM_CLIENT_CONNECT, $context);
        } finally {
            restore_error_handler();
        }
        if (! is_resource($socket)) {
            return false;
        }
        fclose($socket);

        return true;
    }

    /** @param array{pin: string, fingerprint: string, subject: string|null, valid_to: int|null} $certificate */
    private function recordOffered(int $siteId, string $host, int $port, array $certificate): CameraEndpointTrust
    {
        $record = CameraEndpointTrust::query()->firstOrNew(['site_id' => $siteId, 'host' => $host, 'port' => $port]);
        $record->forceFill([
            'offered_pin' => $certificate['pin'],
            'offered_fingerprint' => $certificate['fingerprint'],
            'offered_subject' => $certificate['subject'],
            'offered_valid_to' => $certificate['valid_to'] !== null ? CarbonImmutable::createFromTimestampUTC($certificate['valid_to']) : null,
            'offered_at' => CarbonImmutable::now('UTC'),
        ])->save();

        return $record;
    }

    /** @return array{ok: false, reason_code: string, message: string, pin: null, trust: array<string, mixed>|null} */
    private function refusal(string $reasonCode, string $message, ?CameraEndpointTrust $record): array
    {
        return [
            'ok' => false,
            'reason_code' => $reasonCode,
            'message' => $message,
            'pin' => null,
            'trust' => $record === null ? null : [
                'site_id' => $record->site_id,
                'host' => $record->host,
                'port' => $record->port,
            ],
        ];
    }
}
