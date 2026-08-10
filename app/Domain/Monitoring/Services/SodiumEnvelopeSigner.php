<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Contracts\EnvelopeSigner;
use Illuminate\Contracts\Config\Repository;
use RuntimeException;
use UnexpectedValueException;

final class SodiumEnvelopeSigner implements EnvelopeSigner
{
    public function __construct(private readonly Repository $config) {}

    public function activeKeyId(): string
    {
        $keyId = $this->config->get('monitoring.signing.active_key_id');

        if (! is_string($keyId) || $keyId === '') {
            throw new RuntimeException('Monitoring envelope signing key is not configured.');
        }

        return $keyId;
    }

    public function sign(string $keyId, string $message): string
    {
        return sodium_crypto_auth($message, $this->key($keyId));
    }

    public function verify(string $keyId, string $message, string $signature): bool
    {
        $key = $this->key($keyId);

        return strlen($signature) === SODIUM_CRYPTO_AUTH_BYTES
            && sodium_crypto_auth_verify($signature, $message, $key);
    }

    private function key(string $keyId): string
    {
        $keys = $this->config->get('monitoring.signing.keys', []);
        $encodedKey = is_array($keys) && array_key_exists($keyId, $keys)
            ? $keys[$keyId]
            : null;
        $key = is_string($encodedKey) ? base64_decode($encodedKey, true) : false;

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_AUTH_KEYBYTES) {
            throw new UnexpectedValueException('Monitoring envelope signing key is unknown.');
        }

        return $key;
    }
}
