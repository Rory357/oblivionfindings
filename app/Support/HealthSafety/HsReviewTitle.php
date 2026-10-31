<?php

namespace App\Support\HealthSafety;

/**
 * The one title builder for a safety review (H&S UI/UX plan B1.1/B7, rule "Text from a record shows only to people who
 * can read that record"). Every surface (record, Overview, /tasks) shows this server-made title; the client has no
 * second builder.
 *
 *  - The source's own words ("Fall in the shower") only for a viewer who can read the source.
 *  - Otherwise the kind and the person ("Near miss — Tane Wineera"): Stephan's 25 Oct rule, "event yes, incident no".
 *  - Chemical exposures and safeguarding concerns, and first aid, show kind and site only to a viewer who can't read
 *    the source ("Chemical exposure — Kauri House"); a first-aid review never names the person to them.
 *
 * Restraint, injury and first-aid reviews stay out of lists for non-readers before any title is made
 * (HsEventAccessService); this class only words what the viewer may already see.
 */
final class HsReviewTitle
{
    /** Kinds whose person stays hidden from a viewer who can't read the source. */
    private const SITE_ONLY_FOR_NON_READERS = ['exposure', 'safeguarding'];

    /** Kinds where the person is the worker, not the client. */
    private const WORKER_KINDS = ['injury', 'exposure'];

    public static function make(
        string $category,
        bool $sourceReadable,
        ?string $sourceWords,
        ?string $client,
        ?string $staff,
        ?string $site,
        bool $firstAid = false,
    ): string {
        $kind = HsLabels::label('review_category', $category);
        $client = self::clean($client);
        $staff = self::clean($staff);
        $site = self::clean($site);
        $person = in_array($category, self::WORKER_KINDS, true) ? ($staff ?? $client) : ($client ?? $staff);

        $words = $sourceReadable ? self::clean($sourceWords) : null;
        if ($words !== null) {
            return $person !== null ? "{$words} — {$person}" : $words;
        }
        if (! $sourceReadable && ($firstAid || in_array($category, self::SITE_ONLY_FOR_NON_READERS, true))) {
            return $site !== null ? "{$kind} — {$site}" : $kind;
        }
        $who = $person ?? $site;

        return $who !== null ? "{$kind} — {$who}" : $kind;
    }

    private static function clean(?string $value): ?string
    {
        $value = $value === null ? '' : trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' ? null : $value;
    }
}
