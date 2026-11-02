<?php

namespace App\Domain\Obli\Fixes;

/**
 * A plan from the AI is checked before anyone sees it (D16): exact shape, text lengths, fix keys
 * only from this computer's list, and no unsafe step. A plan that fails a check is never shown;
 * a plan with an unsafe step is refused in the server's own words (O).
 */
final class ObliFixPlan
{
    public const REFUSAL = 'I can’t do that; it would weaken this computer’s protection or change who is an administrator. I can find out what’s wrong another way, or ask IT.';

    /**
     * @param  array<string, mixed>  $json  the AI's plan (ObliFixInstructions::planSchema)
     * @param  list<string>  $fixKeys  the fixes on for this computer now
     * @return array<string, mixed>|null the stored plan (state planned | refused | needs_it), or null when it fails a check
     */
    public static function validate(array $json, array $fixKeys): ?array
    {
        if (array_diff(array_keys($json), ['title', 'found', 'plan', 'minutes', 'refuse', 'needs_it']) !== []) {
            return null;
        }
        $title = self::text($json['title'] ?? null, 80);
        if ($title === null || ! is_array($json['found'] ?? null) || ! array_is_list($json['found']) || count($json['found']) > 8) {
            return null;
        }
        $checks = [];
        foreach ($json['found'] as $found) {
            $text = is_array($found) ? self::text($found['text'] ?? null, 200) : null;
            if ($text === null || ! in_array($found['tone'] ?? null, ['ok', 'warning', 'critical'], true)) {
                return null;
            }
            $checks[] = ['tone' => $found['tone'], 'text' => $text];
        }
        $checks = array_slice($checks, 0, 6);
        $base = ['title' => $title, 'checks' => $checks, 'plan' => [], 'minutes' => 0];

        if (($json['refuse'] ?? null) !== null) {
            $text = is_array($json['refuse']) ? self::text($json['refuse']['text'] ?? null, 300) : null;
            if ($text === null) {
                return null;
            }
            $alternative = is_array($json['refuse']) ? self::text($json['refuse']['alternative'] ?? null, 40) : null;

            return $base + ['state' => 'refused', 'refusal' => ['text' => $text, 'offers' => self::offers($alternative)]];
        }
        if (($json['needs_it'] ?? null) !== null) {
            $text = is_array($json['needs_it']) ? self::text($json['needs_it']['text'] ?? null, 300) : null;
            if ($text === null) {
                return null;
            }

            return $base + ['state' => 'needs_it', 'refusal' => ['text' => $text, 'offers' => [['key' => 'ask_it', 'label' => 'Ask IT']]]];
        }

        $steps = $json['plan'] ?? null;
        if (! is_array($steps) || ! array_is_list($steps) || $steps === [] || count($steps) > 8) {
            return null;
        }
        $plan = [];
        foreach ($steps as $step) {
            if (! is_array($step) || array_diff(array_keys($step), ['text', 'kind', 'fix_key']) !== []) {
                return null;
            }
            $text = self::text($step['text'] ?? null, 200);
            $kind = $step['kind'] ?? null;
            if ($text === null || ! in_array($kind, ['screen', 'fix', 'ask_it'], true)) {
                return null;
            }
            $key = $kind === 'fix' ? ($step['fix_key'] ?? null) : null;
            if ($kind === 'fix' && (! is_string($key) || ! in_array($key, $fixKeys, true))) {
                return null;
            }
            if (ObliFixSafety::unsafeText($text)) {
                return $base + ['state' => 'refused', 'refusal' => ['text' => self::REFUSAL, 'offers' => self::offers(null)]];
            }
            $plan[] = ['text' => $text, 'kind' => $kind, 'fix_key' => $key];
        }
        if (ObliFixSafety::unsafeText($title)) {
            return $base + ['state' => 'refused', 'refusal' => ['text' => self::REFUSAL, 'offers' => self::offers(null)]];
        }
        $minutes = is_int($json['minutes'] ?? null) ? $json['minutes'] : 5;

        return ['state' => 'planned', 'title' => $title, 'checks' => $checks, 'plan' => $plan, 'minutes' => max(1, min(15, $minutes)), 'refusal' => null];
    }

    /**
     * The plan as the tray shows it: a fix on IT's list is "fix" with its key and it_allows;
     * anything else needing admin is "fix" with no key (a one-off request to IT).
     *
     * @param  list<array{text: string, kind: string, fix_key: ?string}>  $plan
     * @return list<array{text: string, kind: string, fix_key: ?string, it_allows: bool}>
     */
    public static function forTray(array $plan): array
    {
        return array_values(array_map(fn (array $step): array => [
            'text' => (string) $step['text'],
            'kind' => $step['kind'] === 'screen' ? 'screen' : 'fix',
            'fix_key' => $step['kind'] === 'fix' ? $step['fix_key'] : null,
            'it_allows' => $step['kind'] === 'fix',
        ], $plan));
    }

    /** @return list<array{key: string, label: string}> */
    private static function offers(?string $alternative): array
    {
        $offers = [['key' => 'ask_it', 'label' => 'Ask IT']];
        // The tray asks Obli again with the label as the problem, which needs 10 characters or more.
        if ($alternative !== null && mb_strlen($alternative) >= 10 && ! ObliFixSafety::unsafeText($alternative)) {
            $offers[] = ['key' => 'safe_alternative', 'label' => $alternative];
        }

        return $offers;
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || preg_match('/[\p{Cc}\p{Cf}]/u', $value)) {
            return null;
        }
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' || mb_strlen($value) > $max ? null : $value;
    }
}
