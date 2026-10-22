<?php

namespace Tests\Unit\Push;

use OpenSSLAsymmetricKey;

/**
 * The receiving side of web push, written independently of App\Services\Push so the tests
 * check the sender against a second route: keys come from PHP's own EC parameters (not
 * hand-built DER), HKDF is hash_hkdf (not HMAC steps), and signatures are re-encoded to DER here.
 */
trait BrowserSide
{
    private static function b64u(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function unb64u(string $text): string
    {
        $bytes = base64_decode(strtr(preg_replace('/\s+/', '', $text), '-_', '+/'), true);
        self::assertIsString($bytes);

        return $bytes;
    }

    /** @return array{0: OpenSSLAsymmetricKey, 1: string} a browser key pair: the private key and its 65-byte point */
    private static function browserKeyPair(?string $scalar = null): array
    {
        $key = $scalar === null
            ? openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'])
            : openssl_pkey_new(['ec' => ['curve_name' => 'prime256v1', 'd' => $scalar]]);
        self::assertNotFalse($key, 'OpenSSL could not make a key; on Windows set OPENSSL_CONF to Herd\'s openssl.cnf.');
        $ec = openssl_pkey_get_details($key)['ec'];

        return [$key, "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)];
    }

    private static function publicKeyFromPoint(string $point): OpenSSLAsymmetricKey
    {
        self::assertSame(65, strlen($point));
        $key = openssl_pkey_new(['ec' => ['curve_name' => 'prime256v1', 'x' => substr($point, 1, 32), 'y' => substr($point, 33, 32)]]);
        self::assertNotFalse($key);

        return $key;
    }

    /** RFC 8291 section 3.4 from the user agent's side, then RFC 8188 record decryption. */
    private static function decrypt(string $body, OpenSSLAsymmetricKey $uaPrivate, string $uaPublic, string $authSecret): string
    {
        $salt = substr($body, 0, 16);
        self::assertSame(4096, unpack('N', substr($body, 16, 4))[1], 'record size');
        $idLength = ord($body[20]);
        self::assertSame(65, $idLength, 'the key ID is the 65-byte server key');
        $asPublic = substr($body, 21, $idLength);
        $record = substr($body, 21 + $idLength);

        $ecdhSecret = openssl_pkey_derive(self::publicKeyFromPoint($asPublic), $uaPrivate, 32);
        self::assertIsString($ecdhSecret);
        $ikm = hash_hkdf('sha256', $ecdhSecret, 32, "WebPush: info\x00".$uaPublic.$asPublic, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);
        $plain = openssl_decrypt(substr($record, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($record, -16));
        self::assertIsString($plain, 'the record must authenticate');
        self::assertSame("\x02", substr($plain, -1), 'last-record delimiter');

        return substr($plain, 0, -1);
    }

    /** JWS R ‖ S back to the DER form openssl_verify reads. */
    private static function rawSignatureToDer(string $raw): string
    {
        self::assertSame(64, strlen($raw));
        $integer = function (string $value): string {
            $value = ltrim($value, "\x00");
            if ($value === '' || ord($value[0]) >= 0x80) {
                $value = "\x00".$value;
            }

            return "\x02".chr(strlen($value)).$value;
        };
        $sequence = $integer(substr($raw, 0, 32)).$integer(substr($raw, 32));

        return "\x30".chr(strlen($sequence)).$sequence;
    }

    /**
     * Splits and checks a "vapid t=…, k=…" header.
     *
     * @return array{header: array<string, mixed>, claims: array<string, mixed>, signature: string, key: string}
     */
    private static function verifyVapid(string $authorization): array
    {
        self::assertMatchesRegularExpression('/^vapid t=([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+), k=([A-Za-z0-9_-]+)$/', $authorization);
        preg_match('/^vapid t=([^.]+)\.([^.]+)\.([^,]+), k=(.+)$/', $authorization, $parts);
        $signature = self::unb64u($parts[3]);
        $key = self::unb64u($parts[4]);
        self::assertSame(64, strlen($signature), 'ES256 signatures are 64 bytes (RFC 7518 section 3.4)');
        self::assertSame(1, openssl_verify($parts[1].'.'.$parts[2], self::rawSignatureToDer($signature), self::publicKeyFromPoint($key), OPENSSL_ALGO_SHA256));

        return [
            'header' => json_decode(self::unb64u($parts[1]), true, flags: JSON_THROW_ON_ERROR),
            'claims' => json_decode(self::unb64u($parts[2]), true, flags: JSON_THROW_ON_ERROR),
            'signature' => $signature,
            'key' => $key,
        ];
    }
}
