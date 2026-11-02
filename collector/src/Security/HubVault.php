<?php

namespace Oblivion\Collector\Security;

use RuntimeException;

/**
 * The hub's login store (S&D Phase B, "Hub security"). Anything that holds saved logins (the
 * last signed work list, with its sealed logins) is kept on disk encrypted with a key that
 * only Oblivion hands out, and only to an approved hub that hasn't been removed. The key lives
 * in memory; a removed hub can't fetch it again, so it can't read what it stored.
 */
final class HubVault
{
    private const string PREFIX = 'OFV1:';

    private string $key;

    private function __construct(string $key)
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('Hub login-store key is invalid.');
        }
        $this->key = $key;
    }

    /** Open the key Oblivion sealed to this hub's own signing identity. */
    public static function fromSealed(string $sealedBase64, string $signingSecretKey): self
    {
        $sealed = base64_decode($sealedBase64, true);
        if (! is_string($sealed) || strlen($signingSecretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Hub login-store key is invalid.');
        }
        $boxSecret = sodium_crypto_sign_ed25519_sk_to_curve25519($signingSecretKey);
        $boxPublic = sodium_crypto_sign_ed25519_pk_to_curve25519(substr($signingSecretKey, 32));
        $pair = sodium_crypto_box_keypair_from_secretkey_and_publickey($boxSecret, $boxPublic);
        try {
            $key = sodium_crypto_box_seal_open($sealed, $pair);
        } finally {
            sodium_memzero($boxSecret);
            sodium_memzero($pair);
        }
        if (! is_string($key)) {
            throw new RuntimeException('Hub login-store key could not be opened.');
        }

        return new self($key);
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX.base64_encode($nonce.sodium_crypto_secretbox($plaintext, $nonce, $this->key));
    }

    public function decrypt(string $stored): string
    {
        if (! self::isSealed($stored)) {
            throw new RuntimeException('Stored hub state isn’t in the encrypted login store.');
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if (! is_string($raw) || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Stored hub state is damaged.');
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key,
        );
        if (! is_string($plain)) {
            throw new RuntimeException('Stored hub state couldn’t be opened with this key.');
        }

        return $plain;
    }

    public static function isSealed(string $stored): bool
    {
        return str_starts_with($stored, self::PREFIX);
    }

    public function __destruct()
    {
        sodium_memzero($this->key);
    }
}
