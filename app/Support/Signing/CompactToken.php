<?php

namespace App\Support\Signing;

/**
 * The Oblivion signer (plan item C2.4): compact signed tokens, header.payload.signature, each part
 * base64url without padding (RFC 4648 section 5), signed with HMAC-SHA256 (RFC 2104) over
 * "header.payload". Only HS256 is ever accepted. Spec: docs/chat-experience/clean-room/specs/oblivion-live.md.
 */
final class CompactToken
{
    public const ALGORITHM = 'HS256';

    private const MAX_LENGTH = 2048;

    /**
     * @param  array<string, mixed>  $claims
     */
    public static function issue(array $claims, string $keyId, string $secret, string $type = 'OL'): string
    {
        if ($keyId === '' || $secret === '') {
            throw new \InvalidArgumentException('A key ID and secret are required to sign a token.');
        }
        $header = self::encode(json_encode(['alg' => self::ALGORITHM, 'typ' => $type, 'kid' => $keyId], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $payload = self::encode(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $header.'.'.$payload.'.'.self::encode(hash_hmac('sha256', $header.'.'.$payload, $secret, true));
    }

    /**
     * Check a token and return its claims. `exp`, `aud` and the header's `kid` are required;
     * `iat`, when present, may be at most `$leewaySeconds` in the future.
     *
     * @param  array<string, string>  $keys  key ID => secret
     * @return array<string, mixed>
     *
     * @throws InvalidCompactToken
     */
    public static function verify(string $token, array $keys, string $audience, int $leewaySeconds = 5, ?int $now = null, string $type = 'OL'): array
    {
        $now ??= time();
        if ($token === '' || strlen($token) > self::MAX_LENGTH) {
            throw new InvalidCompactToken('malformed');
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new InvalidCompactToken('malformed');
        }
        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header = self::object(self::decode($encodedHeader));
        if (($header['alg'] ?? null) !== self::ALGORITHM || ($header['typ'] ?? null) !== $type) {
            throw new InvalidCompactToken('algorithm');
        }
        $keyId = $header['kid'] ?? null;
        if (! is_string($keyId) || ! array_key_exists($keyId, $keys) || ! is_string($keys[$keyId]) || $keys[$keyId] === '') {
            throw new InvalidCompactToken('key');
        }
        $signature = self::decode($encodedSignature);
        if (strlen($signature) !== 32 || ! hash_equals(hash_hmac('sha256', $encodedHeader.'.'.$encodedPayload, $keys[$keyId], true), $signature)) {
            throw new InvalidCompactToken('signature');
        }

        $claims = self::object(self::decode($encodedPayload));
        if (($claims['aud'] ?? null) !== $audience) {
            throw new InvalidCompactToken('audience');
        }
        if (! is_int($claims['exp'] ?? null) || $now > $claims['exp'] + $leewaySeconds) {
            throw new InvalidCompactToken('expired');
        }
        if (array_key_exists('iat', $claims) && (! is_int($claims['iat']) || $claims['iat'] > $now + $leewaySeconds)) {
            throw new InvalidCompactToken('not_yet_valid');
        }

        return $claims;
    }

    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Decode canonical unpadded base64url; anything else is refused.
     *
     * @throws InvalidCompactToken
     */
    public static function decode(string $text): string
    {
        if (preg_match('/\A[A-Za-z0-9_-]*\z/', $text) !== 1 || strlen($text) % 4 === 1) {
            throw new InvalidCompactToken('malformed');
        }
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);
        if ($bytes === false || self::encode($bytes) !== $text) {
            throw new InvalidCompactToken('malformed');
        }

        return $bytes;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidCompactToken
     */
    private static function object(string $json): array
    {
        try {
            $value = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidCompactToken('malformed');
        }
        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidCompactToken('malformed');
        }

        return $value;
    }
}
