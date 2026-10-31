<?php

namespace App\Support\HealthSafety;

/** Navigation only; source read and command authority never follow from an origin. */
final class ChemicalExposureReturn
{
    public const BASE = '/health-safety/chemical-exposures';
    public const FILTERS = ['site_id', 'worker_id', 'substance_id', 'from', 'to', 'page'];
    public const SECTIONS = ['overview', 'reviews', 'followups', 'evidence', 'history', 'connections'];
    public const ACTIONS = ['correct', 'review', 'assign_followup', 'reassign_followup', 'complete_followup', 'upload_evidence', 'remove_evidence'];

    public static function record(int $id, mixed $origin = null, ?string $section = null, ?string $action = null): string
    {
        if (! self::positive((string) $id)) { return self::BASE; }
        $query = [];
        if (in_array($section, self::SECTIONS, true)) { $query['section'] = $section; }
        if (in_array($action, self::ACTIONS, true)) { $query['action'] = $action; }
        $back = self::sanitize($origin);
        if ($back !== null && parse_url($back, PHP_URL_PATH) !== self::BASE.'/'.$id) { $query['return_to'] = $back; }
        return self::url(self::BASE.'/'.$id, $query);
    }

    public static function capture(int $substanceId, mixed $origin = null): string
    {
        $query = ['substance_id' => $substanceId];
        $back = self::sanitize($origin);
        if ($back !== null) { $query['return_to'] = $back; }
        return self::url(self::BASE.'/capture', $query);
    }

    public static function sanitize(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 1500 || ! str_starts_with($value, '/')
            || str_starts_with($value, '//') || str_contains($value, '\\') || str_contains($value, '#')
            || preg_match('/[\x00-\x20\x7f]/', $value) || preg_match('/%(?![0-9a-f]{2})/i', $value)) { return null; }
        $parts = parse_url($value);
        if (! is_array($parts) || array_intersect(array_keys($parts), ['scheme', 'host', 'user', 'pass', 'fragment'])) { return null; }
        $query = [];
        foreach (explode('&', $parts['query'] ?? '') as $pair) {
            if ($pair === '') { continue; }
            [$key, $item] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($key); $item = urldecode($item);
            // No arrays, ambiguous duplicates, private UUIDs or nested navigation data.
            if (str_contains($key, '[') || str_contains($key, ']') || preg_match('/[\x00-\x1f\x7f\\\\]/', $key.$item)) { return null; }
            $query[$key] = array_key_exists($key, $query) ? null : $item;
        }
        $path = $parts['path'] ?? '';
        if ($path === self::BASE) {
            $safe = [];
            foreach (self::FILTERS as $key) {
                $item = $query[$key] ?? null;
                if (! is_string($item) || strlen($item) > 64) { continue; }
                if (in_array($key, ['from', 'to'], true)) {
                    if (! self::instant($item)) { continue; }
                } elseif (! self::positive($item)) { continue; }
                $safe[$key] = $item;
            }
            return self::url($path, $safe);
        }
        if (preg_match('~^'.preg_quote(self::BASE, '~').'/([1-9]\d*)$~D', $path, $match) && self::positive($match[1])) {
            $safe = in_array($query['section'] ?? null, self::SECTIONS, true) ? ['section' => $query['section']] : [];
            foreach (['history_page', 'reviews_page', 'followups_page', 'evidence_page'] as $key) {
                if (is_string($query[$key] ?? null) && self::positive($query[$key])) { $safe[$key] = $query[$key]; }
            }
            return self::url($path, $safe);
        }
        if ($path === '/health-safety/substances') {
            $safe = [];
            foreach (['tab', 'q', 'site_id', 'physical_form', 'status', 'controlled', 'sds_state', 'period', 'page'] as $key) {
                $item = $query[$key] ?? null;
                if (! is_string($item) || strlen($item) > 200) { continue; }
                $enums = match ($key) {
                    'tab' => ['all', 'active', 'controlled', 'sds_expiring', 'sds_missing', 'inactive'],
                    'physical_form' => ['solid', 'liquid', 'gas', 'powder', 'aerosol', 'paste', 'other'],
                    'status' => ['active', 'inactive', 'removed'], 'controlled' => ['controlled', 'standard'],
                    'sds_state' => ['current', 'expiring', 'expired', 'missing', 'unknown', 'ambiguous', 'unavailable', 'not_yet_effective', 'repair_required'],
                    default => null,
                };
                if ($enums !== null && ! in_array($item, $enums, true)) { continue; }
                if (in_array($key, ['site_id', 'page', 'period'], true) && ! self::positive($item)) { continue; }
                if ($key === 'period' && ((int) $item < 7 || (int) $item > 365)) { continue; }
                $safe[$key] = $item;
            }
            return self::url($path, $safe);
        }
        if (preg_match('~^/health-safety/substances/([1-9]\d*)$~D', $path, $match)) {
            if (! self::positive($match[1])) { return null; }
            return self::url($path, in_array($query['section'] ?? null, ['overview', 'controls', 'sds', 'storage', 'history', 'exposures'], true)
                ? ['section' => $query['section']] : []);
        }
        // Strip private/transient selectors before using the actual established source Back contract.
        $safe = array_filter(array_diff_key($query, array_flip(['receipt_key', 'idempotency_key', 'return_to', 'action',
            'command', 'upload', 'new', 'section', 'evidence', 'followup'])), 'is_string');
        return InjuryReturn::leaf(self::url($path, $safe));
    }

    private static function url(string $path, array $query): string
    {
        return $path.($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }
    private static function positive(string $value): bool
    {
        return preg_match('/^[1-9]\d*$/D', $value) === 1 && strlen($value) <= 10 && (float) $value <= 2147483647;
    }
    private static function instant(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?(Z|[+-](\d{2}):(\d{2}))$/D', $value, $m) !== 1
            || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59) { return false; }
        return $m[7] === 'Z' || ((int) $m[8] <= 14 && (int) $m[9] <= 59 && ((int) $m[8] !== 14 || (int) $m[9] === 0));
    }
}
