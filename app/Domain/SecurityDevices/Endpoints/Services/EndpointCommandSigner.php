<?php

namespace App\Domain\SecurityDevices\Endpoints\Services;

use RuntimeException;

final class EndpointCommandSigner
{
    public function publicKey(): string
    {
        $secret = $this->secret();
        try {
            return base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret));
        } finally {
            sodium_memzero($secret);
        }
    }

    /** Sign exact bytes; agents verify before parsing or acting. */
    public function sign(array $payload): array
    {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > 131072) {
            throw new RuntimeException('The endpoint command exceeds the permitted size.');
        }
        $secret = $this->secret();
        try {
            return [
                'payload' => base64_encode($encoded),
                'signature' => base64_encode(sodium_crypto_sign_detached($encoded, $secret)),
            ];
        } finally {
            sodium_memzero($secret);
        }
    }

    private function secret(): string
    {
        $secret = base64_decode((string) config('endpoint_management.command_signing_secret'), true);
        if (! is_string($secret) || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Endpoint command signing is not configured.');
        }

        $pair = sodium_crypto_sign_seed_keypair(substr($secret, 0, SODIUM_CRYPTO_SIGN_SEEDBYTES));
        $expected = sodium_crypto_sign_secretkey($pair);
        $valid = hash_equals($expected, $secret);
        sodium_memzero($expected);
        sodium_memzero($pair);
        if (! $valid) {
            sodium_memzero($secret);
            throw new RuntimeException('Endpoint command signing key is invalid.');
        }

        return $secret;
    }
}
