<?php

namespace App\Services\Push;

use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Oblivion's VAPID signing key pair (RFC 8292), in the forms stored in .env:
 * VAPID_PUBLIC_KEY is the base64url 65-byte uncompressed P-256 point and
 * VAPID_PRIVATE_KEY the base64url 32-byte private scalar.
 */
final class VapidKeys
{
    private ?OpenSSLAsymmetricKey $signingKey = null;

    private function __construct(
        public readonly string $publicKey,
        #[\SensitiveParameter] public readonly string $privateKey,
    ) {}

    /**
     * A new pair for `php artisan webpush:vapid`.
     *
     * @throws RuntimeException when OpenSSL can't create the key (on Windows, usually a missing OPENSSL_CONF)
     */
    public static function generate(): self
    {
        [, $scalar, $point] = P256::generate();

        return new self(Base64Url::encode($point), Base64Url::encode($scalar));
    }

    /**
     * Reads a stored pair. Padded base64 is accepted, and a private key stored without its
     * leading zero bytes is widened back to 32 bytes.
     *
     * @throws InvalidArgumentException when either key is not in the stored form
     */
    public static function fromStored(string $publicKey, #[\SensitiveParameter] string $privateKey): self
    {
        $point = Base64Url::decode(trim($publicKey));
        $scalar = Base64Url::decode(trim($privateKey));
        if ($point === null || ! P256::isPoint($point)) {
            throw new InvalidArgumentException('VAPID_PUBLIC_KEY is not a base64url uncompressed P-256 point.');
        }
        if ($scalar === null || $scalar === '' || strlen($scalar) > 32 || trim($scalar, "\x00") === '') {
            throw new InvalidArgumentException('VAPID_PRIVATE_KEY is not a base64url 32-byte P-256 private key.');
        }

        return new self(Base64Url::encode($point), Base64Url::encode(P256::fixedWidth($scalar)));
    }

    /** The 65-byte public point, as the "k" parameter and browsers' applicationServerKey carry it. */
    public function publicKeyBytes(): string
    {
        return (string) Base64Url::decode($this->publicKey);
    }

    /**
     * The private key as OpenSSL reads it, after proving it belongs to the public key:
     * push services refuse tokens whose "k" doesn't match the signer (RFC 8292 section 4.2).
     *
     * @throws InvalidArgumentException when OpenSSL won't accept the pair, or the halves don't match
     */
    public function signingKey(): OpenSSLAsymmetricKey
    {
        if ($this->signingKey !== null) {
            return $this->signingKey;
        }
        $point = $this->publicKeyBytes();
        $key = P256::privateKey((string) Base64Url::decode($this->privateKey), $point);
        $probe = 'Oblivion VAPID key check';
        if (! openssl_sign($probe, $signature, $key, OPENSSL_ALGO_SHA256)
            || openssl_verify($probe, $signature, P256::publicKey($point), OPENSSL_ALGO_SHA256) !== 1) {
            P256::errors();
            throw new InvalidArgumentException('VAPID_PUBLIC_KEY does not belong to VAPID_PRIVATE_KEY.');
        }

        return $this->signingKey = $key;
    }

    /** Keeps the private key out of dumps and logs. */
    public function __debugInfo(): array
    {
        return ['publicKey' => $this->publicKey];
    }
}
