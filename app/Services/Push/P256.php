<?php

namespace App\Services\Push;

use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * The P-256 key and signature encodings PHP's OpenSSL functions need.
 *
 * Web push stores keys as raw bytes (a 65-byte uncompressed point and a 32-byte scalar);
 * OpenSSL reads DER. Public keys are wrapped as SubjectPublicKeyInfo (RFC 5480), private
 * keys as ECPrivateKey (RFC 5915), and signatures convert between DER and the 64-byte
 * R ‖ S form JWS uses (RFC 7518 section 3.4).
 *
 * @internal
 */
final class P256
{
    /** OBJECT IDENTIFIER prime256v1 (1.2.840.10045.3.1.7). */
    private const CURVE = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";

    /** OBJECT IDENTIFIER id-ecPublicKey (1.2.840.10045.2.1). */
    private const EC_PUBLIC_KEY = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01";

    /** True for a 65-byte uncompressed point (X9.62: 0x04 ‖ X ‖ Y). Curve membership is checked by OpenSSL on load. */
    public static function isPoint(string $bytes): bool
    {
        return strlen($bytes) === 65 && $bytes[0] === "\x04";
    }

    /** @throws InvalidArgumentException when the bytes are not a point on P-256 */
    public static function publicKey(string $point): OpenSSLAsymmetricKey
    {
        if (! self::isPoint($point)) {
            throw new InvalidArgumentException('The public key is not an uncompressed P-256 point.');
        }
        // SEQUENCE { SEQUENCE { id-ecPublicKey, prime256v1 }, BIT STRING (no unused bits) point }
        $der = "\x30\x59\x30\x13".self::EC_PUBLIC_KEY.self::CURVE."\x03\x42\x00".$point;
        $key = openssl_pkey_get_public(self::pem('PUBLIC KEY', $der));
        self::clearErrors();
        if ($key === false) {
            throw new InvalidArgumentException('The public key is not a point on P-256.');
        }

        return $key;
    }

    /** @throws InvalidArgumentException when the pair can't be read as a P-256 private key */
    public static function privateKey(#[\SensitiveParameter] string $scalar, string $point): OpenSSLAsymmetricKey
    {
        if (strlen($scalar) !== 32 || ! self::isPoint($point)) {
            throw new InvalidArgumentException('The private key is not a 32-byte P-256 scalar with its public point.');
        }
        // ECPrivateKey: SEQUENCE { INTEGER 1, OCTET STRING scalar, [0] prime256v1, [1] BIT STRING point }
        $der = "\x30\x77\x02\x01\x01\x04\x20".$scalar."\xa0\x0a".self::CURVE."\xa1\x44\x03\x42\x00".$point;
        $key = openssl_pkey_get_private(self::pem('EC PRIVATE KEY', $der));
        self::clearErrors();
        if ($key === false) {
            throw new InvalidArgumentException('The private key is not a P-256 key.');
        }

        return $key;
    }

    /**
     * A fresh key pair from OpenSSL.
     *
     * @return array{0: OpenSSLAsymmetricKey, 1: string, 2: string} the key, its 32-byte scalar, its 65-byte point
     *
     * @throws RuntimeException when OpenSSL can't create a key (on Windows, usually a missing OPENSSL_CONF)
     */
    public static function generate(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false) {
            throw new RuntimeException('Could not create the key pair. OpenSSL said: '.self::errors());
        }
        $ec = openssl_pkey_get_details($key)['ec'] ?? null;
        if (! is_array($ec) || ! isset($ec['d'], $ec['x'], $ec['y'])) {
            throw new RuntimeException('Could not create the key pair: OpenSSL returned no P-256 key details.');
        }

        // OpenSSL drops leading zero bytes from big numbers; the stored forms are fixed-width.
        return [
            $key,
            self::fixedWidth($ec['d']),
            "\x04".self::fixedWidth($ec['x']).self::fixedWidth($ec['y']),
        ];
    }

    /**
     * Converts OpenSSL's DER ECDSA signature, SEQUENCE { INTEGER r, INTEGER s }, to R ‖ S.
     *
     * @throws InvalidArgumentException when the DER isn't a P-256 ECDSA signature
     */
    public static function signatureToRaw(string $der): string
    {
        $offset = 0;
        if (self::byte($der, $offset++) !== 0x30) {
            throw new InvalidArgumentException('The signature is not a DER sequence.');
        }
        $length = self::length($der, $offset);
        if ($offset + $length !== strlen($der)) {
            throw new InvalidArgumentException('The signature length does not match its contents.');
        }
        $raw = '';
        for ($part = 0; $part < 2; $part++) { // r, then s
            if (self::byte($der, $offset++) !== 0x02) {
                throw new InvalidArgumentException('The signature value is not a DER integer.');
            }
            $size = self::length($der, $offset);
            $value = ltrim(substr($der, $offset, $size), "\x00");
            $offset += $size;
            if ($value === '' || strlen($value) > 32) {
                throw new InvalidArgumentException('The signature value is out of range for P-256.');
            }
            $raw .= self::fixedWidth($value);
        }
        if ($offset !== strlen($der)) {
            throw new InvalidArgumentException('The signature has trailing bytes.');
        }

        return $raw;
    }

    /** Left-pads a big-endian number to 32 bytes. */
    public static function fixedWidth(string $bytes): string
    {
        if (strlen($bytes) > 32) {
            throw new InvalidArgumentException('The value is longer than 32 bytes.');
        }

        return str_pad($bytes, 32, "\x00", STR_PAD_LEFT);
    }

    /** OpenSSL keeps a queue of error strings; read it so a later call doesn't report a stale one. */
    public static function errors(): string
    {
        $errors = [];
        while (($error = openssl_error_string()) !== false) {
            $errors[] = $error;
        }

        return $errors === [] ? 'no detail' : implode('; ', $errors);
    }

    private static function clearErrors(): void
    {
        while (openssl_error_string() !== false) {
            // Discard: failures here are reported by the caller without OpenSSL's wording.
        }
    }

    private static function pem(string $label, string $der): string
    {
        return "-----BEGIN {$label}-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END {$label}-----\n";
    }

    private static function byte(string $der, int $offset): int
    {
        if ($offset >= strlen($der)) {
            throw new InvalidArgumentException('The signature ends early.');
        }

        return ord($der[$offset]);
    }

    /** Reads a DER length (short form, or long form up to two bytes) and moves past it. */
    private static function length(string $der, int &$offset): int
    {
        $first = self::byte($der, $offset++);
        if ($first < 0x80) {
            $length = $first;
        } elseif ($first === 0x81) {
            $length = self::byte($der, $offset++);
        } elseif ($first === 0x82) {
            $length = (self::byte($der, $offset) << 8) | self::byte($der, $offset + 1);
            $offset += 2;
        } else {
            throw new InvalidArgumentException('The signature uses an unsupported DER length.');
        }
        if ($offset + $length > strlen($der)) {
            throw new InvalidArgumentException('The signature ends early.');
        }

        return $length;
    }
}
