<?php

namespace App\Support\HealthSafety;

/** Navigation hints only. Every destination independently proves current source access. */
final class InjuryReturn
{
    public const REGISTER = '/health-safety/injuries';
    public const SECTIONS = ['overview', 'recovery', 'capacity', 'scheduling', 'treatment', 'connections', 'evidence', 'history'];
    public const COMMANDS = ['start_treatment', 'begin_rtw', 'mark_recovered', 'close', 'acc', 'add_rtw', 'add_capacity', 'edit',
        'assign', 'review', 'edit_rtw', 'add_duty', 'upload_evidence', 'remove_evidence'];

    public static function sanitize(mixed $value): ?string { return self::clean($value, true); }
    public static function leaf(mixed $value): ?string { return self::clean($value, false); }
    public static function fromRegister(array $query): string
    {
        return self::sanitize(self::REGISTER.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986)) ?? self::REGISTER;
    }

    public static function record(int $id, mixed $returnTo = null, ?string $section = null, array $selections = [], ?string $command = null): string
    {
        if (! self::positive((string) $id)) { return self::REGISTER; }
        $path = self::REGISTER.'/'.$id;
        $query = [];
        if ($section === 'rtw') { $section = 'recovery'; }
        if (in_array($section, self::SECTIONS, true)) { $query['section'] = $section; }
        if (in_array($command, self::COMMANDS, true)) { $query['action'] = $command; }
        foreach (['plan', 'assessment', 'duty', 'attachment'] as $key) {
            $value = $selections[$key] ?? null;
            if ((is_int($value) || is_string($value)) && self::positive((string) $value)) { $query[$key] = (string) $value; }
        }
        $origin = self::sanitize($returnTo);
        if ($origin !== null && ! self::containsPath($origin, $path)) { $query['return_to'] = $origin; }
        return $path.($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    private static function clean(mixed $value, bool $nested): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 1500 || ! str_starts_with($value, '/')
            || str_starts_with($value, '//') || str_contains($value, '\\') || str_contains($value, '#')
            || preg_match('/[\x00-\x20\x7f]/', $value)) { return null; }
        $parts = parse_url($value);
        if (! is_array($parts) || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) { return null; }
        $path = $parts['path'] ?? '';
        $query = self::query($parts['query'] ?? '');
        if (! $nested && array_key_exists('return_to', $query)) { return null; }
        $own = preg_match('~^/health-safety/injuries/([1-9]\d*)$~D', $path, $record) === 1;
        $profile = preg_match('~^/hr/people/([1-9]\d*)$~D', $path, $person) === 1;
        if (($own && ! self::positive($record[1])) || ($profile && ! self::positive($person[1]))) { return null; }
        if ($path === self::REGISTER) {
            $safe = [];
            foreach (['tab', 'q', 'site_id', 'severity', 'status', 'treatment', 'acc_open', 'worksafe', 'period', 'from', 'to', 'type', 'body_part', 'page'] as $key) {
                $item = $query[$key] ?? null;
                if (! is_string($item) || $item === '' || strlen($item) > 200 || preg_match('/[\x00-\x1f\x7f]/', $item)) { continue; }
                $allowed = match ($key) {
                    'tab' => ['all', 'reported', 'under_treatment', 'return_to_work', 'recovered', 'closed', 'worksafe', 'acc'],
                    'status' => ['reported', 'under_treatment', 'return_to_work', 'recovered', 'closed'],
                    'severity' => ['minor', 'moderate', 'serious', 'critical'],
                    'treatment' => ['none', 'first_aid', 'gp_visit', 'hospital', 'emergency_department', 'hospitalisation', 'specialist', 'ongoing'],
                    'period' => ['all', 'week', '30d', 'quarter', 'year'], 'acc_open', 'worksafe' => ['0', '1', 'false', 'true'],
                    default => null,
                };
                if (($allowed !== null && ! in_array($item, $allowed, true))
                    || (in_array($key, ['site_id', 'page'], true) && ! self::positive($item))
                    || (in_array($key, ['from', 'to'], true) && ! self::date($item))) { continue; }
                $safe[$key] = $item;
            }
            $base = self::url($path, $safe);
        } elseif ($own) {
            $safe = [];
            $section = $query['section'] ?? null;
            if ($section === 'rtw') { $section = 'recovery'; }
            if (in_array($section, self::SECTIONS, true)) { $safe['section'] = $section; }
            foreach (['plan', 'assessment', 'duty'] as $key) {
                if (is_string($query[$key] ?? null) && self::positive($query[$key])) { $safe[$key] = $query[$key]; }
            }
            $base = self::url($path, $safe);
        } elseif ($path === '/hr/wellbeing') {
            $base = $path;
        } elseif ($profile) {
            $base = $path.(($query['tab'] ?? null) === 'injuries' ? '?tab=injuries' : '');
        } else {
            $safe = array_filter(array_diff_key($query, ['return_to' => true]), 'is_string');
            $base = RestraintReturn::leaf(self::url($path, $safe));
        }
        if ($base === null) { return null; }
        $sourceRecord = $own || $profile || preg_match('~^/(?:incidents|control-room/alerts|operations/shifts|health-safety/(?:events|first-aid|corrective-actions))/[1-9]\d*$~D', $path) === 1;
        if ($nested && $sourceRecord) {
            $back = self::leaf($query['return_to'] ?? null);
            if ($back !== null && ! self::containsPath($back, $path)) { $base .= (str_contains($base, '?') ? '&' : '?').'return_to='.rawurlencode($back); }
        }
        return $base;
    }

    /** Duplicate values cannot become an ambiguous source identity or selected return. */
    private static function query(string $raw): array
    {
        $query = [];
        foreach (explode('&', $raw) as $pair) {
            if ($pair === '') { continue; }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($key);
            $query[$key] = array_key_exists($key, $query) ? null : urldecode($value);
        }
        return $query;
    }
    private static function url(string $path, array $query): string
    {
        return $path.($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }
    private static function date(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) === 1 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
    private static function positive(string $value): bool
    {
        return preg_match('/^[1-9]\d*$/D', $value) === 1 && strlen($value) <= 16 && (float) $value <= 9007199254740991;
    }
    private static function containsPath(string $value, string $path): bool
    {
        if (parse_url($value, PHP_URL_PATH) === $path) { return true; }
        $query = self::query(parse_url($value, PHP_URL_QUERY) ?? '');
        return is_string($query['return_to'] ?? null) && parse_url($query['return_to'], PHP_URL_PATH) === $path;
    }
}
