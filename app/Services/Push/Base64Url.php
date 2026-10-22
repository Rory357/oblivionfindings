<?php

namespace App\Services\Push;

/**
 * URL-safe base64 without padding (RFC 4648 section 5, as RFC 7515 uses it).
 *
 * @internal
 */
final class Base64Url
{
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** Accepts padded or unpadded input, and the standard alphabet too; null when it isn't base64. */
    public static function decode(string $text): ?string
    {
        if (! preg_match('/^[A-Za-z0-9_\-+\/]*={0,2}$/D', $text)) {
            return null;
        }
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);

        return $bytes === false ? null : $bytes;
    }
}
