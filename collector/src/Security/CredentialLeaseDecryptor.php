<?php

namespace Oblivion\Collector\Security;

use DateTimeImmutable;
use Oblivion\Collector\Exceptions\ScopeViolation;
use RuntimeException;
use Throwable;

final readonly class CredentialLeaseDecryptor
{
    private string $keyPair;

    public function __construct(string $requestSigningSecretKey)
    {
        if (strlen($requestSigningSecretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Collector credential decryption identity is invalid.');
        }

        try {
            $signingPublicKey = sodium_crypto_sign_publickey_from_secretkey($requestSigningSecretKey);
            $encryptionPublicKey = sodium_crypto_sign_ed25519_pk_to_curve25519($signingPublicKey);
            $encryptionSecretKey = sodium_crypto_sign_ed25519_sk_to_curve25519($requestSigningSecretKey);
            $this->keyPair = sodium_crypto_box_keypair_from_secretkey_and_publickey(
                $encryptionSecretKey,
                $encryptionPublicKey,
            );
            sodium_memzero($encryptionSecretKey);
        } catch (Throwable $exception) {
            throw new RuntimeException('Collector credential decryption identity is invalid.', previous: $exception);
        }
    }

    /** @param array<string, mixed> $check @return array<string, scalar|null> */
    public function open(array $check, DateTimeImmutable $at): array
    {
        $lease = $check['credential_lease'] ?? null;
        if (! is_array($lease)) {
            throw new ScopeViolation('Credential lease is unavailable.');
        }
        $encoded = $lease['sealed_material'] ?? null;
        $ciphertext = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (! is_string($ciphertext)
            || strlen($ciphertext) <= SODIUM_CRYPTO_BOX_SEALBYTES
            || strlen($ciphertext) > 1_100_000) {
            throw new ScopeViolation('Credential lease ciphertext is invalid.');
        }

        $plaintext = sodium_crypto_box_seal_open($ciphertext, $this->keyPair);
        if (! is_string($plaintext)) {
            throw new ScopeViolation('Credential lease is not bound to this collector.');
        }

        try {
            $payload = json_decode($plaintext, true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($payload) || ($payload['version'] ?? null) !== 1) {
                throw new ScopeViolation('Credential lease payload is invalid.');
            }
            foreach (['collector_id', 'site_id', 'device_id', 'protocol', 'target', 'expires_at'] as $field) {
                if (($payload[$field] ?? null) !== ($lease[$field] ?? null)) {
                    throw new ScopeViolation('Credential lease payload scope does not match the signed check.');
                }
            }
            $expiry = $this->expiry($payload['expires_at'] ?? null);
            if ($expiry <= $at) {
                throw new ScopeViolation('Credential lease is expired or invalid.');
            }
            $material = $payload['material'] ?? null;
            if (! is_array($material) || $material === [] || count($material) > 64) {
                throw new ScopeViolation('Credential lease material is invalid.');
            }
            $safe = [];
            $bytes = 0;
            foreach ($material as $key => $value) {
                if (! is_string($key)
                    || preg_match('/^[A-Za-z][A-Za-z0-9._-]{0,63}$/', $key) !== 1
                    || (! is_scalar($value) && $value !== null)
                    || in_array(strtolower($key), ['command', 'shell', 'script', 'executable', 'argv', 'powershell'], true)) {
                    throw new ScopeViolation('Credential lease material is invalid.');
                }
                $bytes += is_string($value) ? strlen($value) : 16;
                if ($bytes > 1_048_576) {
                    throw new ScopeViolation('Credential lease material is too large.');
                }
                $safe[$key] = $value;
            }

            return $safe;
        } catch (ScopeViolation $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ScopeViolation('Credential lease payload is invalid.', previous: $exception);
        } finally {
            sodium_memzero($plaintext);
        }
    }

    private function expiry(mixed $value): DateTimeImmutable
    {
        try {
            if (! is_string($value)) {
                throw new RuntimeException;
            }

            return new DateTimeImmutable($value);
        } catch (Throwable $exception) {
            throw new ScopeViolation('Credential lease expiry is invalid.', previous: $exception);
        }
    }
}
