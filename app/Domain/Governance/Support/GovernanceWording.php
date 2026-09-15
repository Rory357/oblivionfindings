<?php

namespace App\Domain\Governance\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Small plain-language phrases shared by the server-written Governance work
 * items (My work, board priorities, meeting preparation, calendar entries).
 *
 * Wording source of truth:
 *   docs/audits/2026-09-14-governance-plain-language-ux/vocabulary.md
 *
 * Labels, money, NZ dates and references live in GovernanceLabels; this class
 * only adds the relative-day phrases ("in 3 days", "yesterday") and counted
 * nouns ("1 action", "3 actions") those work items need. Days are NZ calendar
 * days (app.worker_timezone), never UTC days.
 */
final class GovernanceWording
{
    /**
     * Whole NZ calendar days from today to the value — 0 today, 1 tomorrow,
     * -1 yesterday. Null when there is no readable date.
     */
    public static function daysFromToday(CarbonInterface|string|null $value): ?int
    {
        $date = self::nzDateString($value);
        if ($date === null) {
            return null;
        }

        $today = CarbonImmutable::now(self::timezone())->toDateString();

        return (int) round(
            (CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC')->getTimestamp()
                - CarbonImmutable::createFromFormat('!Y-m-d', $today, 'UTC')->getTimestamp()) / 86400
        );
    }

    /**
     * "today" / "tomorrow" / "yesterday" / "in 3 days" / "3 days ago".
     * Unknown dates read "on a date not set".
     */
    public static function relativeDay(CarbonInterface|string|null $value): string
    {
        $days = self::daysFromToday($value);

        return match (true) {
            $days === null => 'on a date not set',
            $days === 0 => 'today',
            $days === 1 => 'tomorrow',
            $days === -1 => 'yesterday',
            $days > 1 => "in {$days} days",
            default => abs($days).' days ago',
        };
    }

    /** "1 action" / "3 actions" (pass `$many` for irregular plurals). */
    public static function count(int $count, string $one, ?string $many = null): string
    {
        return $count.' '.($count === 1 ? $one : ($many ?? $one.'s'));
    }

    /** First letter upper-cased, the rest untouched ("in 3 days" → "In 3 days"). */
    public static function capitalise(string $text): string
    {
        return $text === '' ? '' : mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    /** The NZ calendar date (Y-m-d) a stored value falls on. */
    private static function nzDateString(CarbonInterface|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            if (is_string($value)) {
                $text = trim($value);
                if ($text === '') {
                    return null;
                }
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
                    return $text;
                }

                // Stored instants without an offset are UTC (app convention).
                return CarbonImmutable::parse($text, 'UTC')->setTimezone(self::timezone())->toDateString();
            }

            return CarbonImmutable::instance($value)->setTimezone(self::timezone())->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private static function timezone(): string
    {
        $timezone = config('app.worker_timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : GovernanceLabels::TIMEZONE;
    }
}
