<?php

namespace App\Support;

/**
 * Stable hash → hue (0–360) for resident avatar tints.
 *
 * Mirrors `resources/js/pages/my-day/lib/resident-hue.ts` so server-rendered
 * payloads and client-side fallbacks agree on the colour a resident gets.
 * Do not change the algorithm without updating the TS side; otherwise
 * residents' avatars will flip colour mid-page.
 */
class ResidentHue
{
    /**
     * Compute a stable hue in [0, 360) from a client identifier.
     *
     * Uses FNV-1a 32-bit so the JS and PHP implementations match exactly.
     */
    public static function for(int|string $clientId): int
    {
        $s = (string) $clientId;
        $h = 2166136261;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $h ^= ord($s[$i]);
            // 32-bit FNV-1a prime, with overflow handled via & 0xFFFFFFFF.
            $h = ($h * 16777619) & 0xFFFFFFFF;
        }

        return $h % 360;
    }

    /** Compose two-letter initials from first/last name. */
    public static function initials(?string $firstName, ?string $lastName = null): string
    {
        $a = $firstName !== null && $firstName !== '' ? mb_substr($firstName, 0, 1) : '';
        $b = $lastName !== null && $lastName !== '' ? mb_substr($lastName, 0, 1) : '';

        return mb_strtoupper($a.$b);
    }
}
