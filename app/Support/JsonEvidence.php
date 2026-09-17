<?php

namespace App\Support;

/**
 * Comparing a stored JSON evidence snapshot with a freshly built one.
 *
 * MySQL's native `json` column type does not preserve object key order: it
 * normalises keys by length, then lexicographically. So an array written to a
 * json column comes back with its keys in a different order, and PHP's `===`
 * — which requires arrays to have the same keys in the same order — reports a
 * mismatch even when every key and value is identical.
 *
 * That silently broke integrity checks that round-trip evidence through a json
 * column and then compare it strictly: they could never match, so the records
 * they guard became permanently unusable rather than merely well-guarded.
 *
 * {@see matches()} compares the two as sets of key/value pairs, recursively,
 * while keeping `===`'s strictness about types and about lists' order. Order
 * carries no meaning in these snapshots; types and values do.
 */
final class JsonEvidence
{
    /**
     * Whether two decoded-JSON structures hold exactly the same data,
     * disregarding object key order (but not list order).
     */
    public static function matches(mixed $stored, mixed $current): bool
    {
        if (is_array($stored) && is_array($current)) {
            return self::canonicalise($stored) === self::canonicalise($current);
        }

        return $stored === $current;
    }

    /**
     * Recursively sort associative array keys so two structurally equal
     * snapshots compare identical. Lists keep their order — a reordered list
     * is different data, not a different spelling of the same data.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    public static function canonicalise(array $value): array
    {
        $isList = array_is_list($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonicalise($item);
            }
        }

        if (! $isList) {
            ksort($value);
        }

        return $value;
    }
}
