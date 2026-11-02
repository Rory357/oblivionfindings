<?php

namespace App\Domain\SecurityDevices\Hubs\Services;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointBootstrap;
use RuntimeException;

/**
 * The server details a new hub needs: where to reach Oblivion, the server's signing key and
 * its TLS pin. Same rules as the hub wizard's profile; throws when Setup isn't finished.
 */
final class HubInstallationSettings
{
    /**
     * @return array{deployment_url: string, server_url: string, organisation: string, server_public_key: string, tls_public_key_pin: string}
     */
    public function resolve(): array
    {
        $deployment = EndpointBootstrap::serverOrigin((string) config('endpoint_management.server_url'));
        $origin = EndpointBootstrap::serverOrigin((string) (config('monitoring.collector.installation.server_url') ?: $deployment));
        $organisation = (string) config('endpoint_management.organisation_label', config('app.name'));
        self::assertLabel($organisation);

        $pin = config('monitoring.collector.installation.tls_public_key_pin');
        $decodedPin = is_string($pin) && str_starts_with($pin, 'sha256//') ? base64_decode(substr($pin, 8), true) : false;
        if (! is_string($decodedPin) || strlen($decodedPin) !== 32 || base64_encode($decodedPin) !== substr($pin, 8)) {
            throw new RuntimeException('Configure the collector server TLS public-key pin.');
        }

        $encoded = config('monitoring.collector.signing_secret_key');
        $secret = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (! is_string($secret) || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Configure the collector signing authority.');
        }
        $pair = sodium_crypto_sign_seed_keypair(substr($secret, 0, SODIUM_CRYPTO_SIGN_SEEDBYTES));
        try {
            if (! hash_equals(sodium_crypto_sign_secretkey($pair), $secret)) {
                throw new RuntimeException('Collector signing authority is invalid.');
            }
            $public = sodium_crypto_sign_publickey_from_secretkey($secret);
        } finally {
            sodium_memzero($pair);
            sodium_memzero($secret);
        }

        return [
            'deployment_url' => $deployment,
            'server_url' => $origin,
            'organisation' => $organisation,
            'server_public_key' => base64_encode($public),
            'tls_public_key_pin' => $pin,
        ];
    }

    /** The host name the installer's file name carries, e.g. "oblivionfindings.com". */
    public function serverHost(): string
    {
        $host = parse_url((string) config('endpoint_management.server_url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : 'oblivion';
    }

    public static function assertLabel(mixed $value): void
    {
        if (! is_string($value) || $value === '' || trim($value) !== $value || mb_strlen($value) > 300 || preg_match('/[\p{Cc}\p{Cf}]/u', $value)) {
            throw new RuntimeException('Configure a valid installation identity label.');
        }
    }
}
