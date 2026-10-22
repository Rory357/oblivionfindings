<?php

namespace App\Services\Chat;

/**
 * A reaction is exactly one emoji: one user-perceived character, which may carry a skin tone,
 * a variation selector or joined parts (👍🏽, ❤️, 👨‍👩‍👧, 🇳🇿, #️⃣). Written from Unicode TR29 and TR51.
 */
final class ChatReactionEmoji
{
    public const MAX_BYTES = 32;

    public static function valid(mixed $value): bool
    {
        if (! is_string($value) || $value === '' || strlen($value) > self::MAX_BYTES || ! mb_check_encoding($value, 'UTF-8')) {
            return false;
        }

        // One extended grapheme cluster, with no spaces or control characters in it.
        if (preg_match('/^\X$/u', $value) !== 1 || preg_match('/[\s\p{Cc}]/u', $value) === 1) {
            return false;
        }

        return preg_match('/\p{Extended_Pictographic}/u', $value) === 1
            || preg_match('/^\p{Regional_Indicator}{2}$/u', $value) === 1
            || preg_match('/^[0-9#*]\x{FE0F}?\x{20E3}$/u', $value) === 1;
    }
}
