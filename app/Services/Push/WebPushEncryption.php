<?php

namespace App\Services\Push;

use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Encrypts one push message for one browser subscription: RFC 8291 key agreement
 * with the RFC 8188 "aes128gcm" content coding, as a single record.
 */
final class WebPushEncryption
{
    /** 4096-byte record, less the 86-byte header, the 0x02 delimiter and the 16-byte tag (RFC 8291 section 4). */
    public const MAX_PAYLOAD_BYTES = 3993;

    private const RECORD_SIZE = 4096;

    private ?string $fixedSalt = null;

    /** @var array{0: string, 1: string}|null */
    private ?array $fixedKey = null;

    /**
     * Uses the given salt and server key instead of fresh random ones.
     *
     * For reproducing the RFC 8291 test vector only. Reusing a salt and key pair
     * across messages breaks the encryption.
     *
     * @internal
     */
    public static function withFixedInputs(string $salt, #[\SensitiveParameter] string $privateScalar, string $publicPoint): self
    {
        $encryption = new self;
        $encryption->fixedSalt = $salt;
        $encryption->fixedKey = [$privateScalar, $publicPoint];

        return $encryption;
    }

    /**
     * @param  string  $uaPublic  the browser's 65-byte p256dh point
     * @param  string  $authSecret  the browser's 16-byte auth secret
     * @return string the request body: salt ‖ record size ‖ key-ID length ‖ server public key ‖ ciphertext and tag
     *
     * @throws InvalidArgumentException when a browser key or the payload can't be used
     * @throws RuntimeException when OpenSSL can't do its part
     */
    public function encrypt(string $uaPublic, #[\SensitiveParameter] string $authSecret, #[\SensitiveParameter] string $payload): string
    {
        if (strlen($authSecret) !== 16) {
            throw new InvalidArgumentException('The subscription auth secret is not 16 bytes.');
        }
        if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException('The payload does not fit in one push record.');
        }
        $browserKey = P256::publicKey($uaPublic);
        [$serverKey, $asPublic] = $this->serverKey();
        $salt = $this->fixedSalt ?? random_bytes(16);

        $ecdhSecret = openssl_pkey_derive($browserKey, $serverKey, 32);
        if ($ecdhSecret === false || strlen($ecdhSecret) !== 32) {
            throw new RuntimeException('P-256 key agreement failed. OpenSSL said: '.P256::errors());
        }

        // RFC 8291 section 3.4, HKDF written out as HMAC-SHA-256 steps.
        $prkKey = hash_hmac('sha256', $ecdhSecret, $authSecret, true);
        $keyInfo = "WebPush: info\x00".$uaPublic.$asPublic;
        $ikm = hash_hmac('sha256', $keyInfo."\x01", $prkKey, true);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\x00\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\x00\x01", $prk, true), 0, 12);

        // One record, so the nonce needs no sequence number; 0x02 marks the last record.
        $tag = '';
        $ciphertext = openssl_encrypt($payload."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new RuntimeException('AES-128-GCM encryption failed. OpenSSL said: '.P256::errors());
        }

        return $salt.pack('N', self::RECORD_SIZE).chr(strlen($asPublic)).$asPublic.$ciphertext.$tag;
    }

    /** @return array{0: OpenSSLAsymmetricKey, 1: string} the private key and its 65-byte public point */
    private function serverKey(): array
    {
        if ($this->fixedKey !== null) {
            return [P256::privateKey($this->fixedKey[0], $this->fixedKey[1]), $this->fixedKey[1]];
        }
        [$key, , $point] = P256::generate();

        return [$key, $point];
    }
}
