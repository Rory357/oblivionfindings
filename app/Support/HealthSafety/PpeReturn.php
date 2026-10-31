<?php

namespace App\Support\HealthSafety;

/** Read hints only; destinations independently enforce current source/Site authority. */
final class PpeReturn
{
    public const REGISTER = '/health-safety/ppe';
    public const TABS = ['inv_all', 'inv_available', 'inv_allocated', 'inv_inspection', 'inv_expiring', 'inv_condemned', 'alloc_active', 'alloc_unack', 'types'];
    public const SECTIONS = ['overview', 'allocations', 'inspections', 'fit-training', 'evidence', 'history'];
    public const COMMANDS = ['edit', 'allocate', 'inspect', 'condemn', 'dispose', 'acknowledge', 'return', 'hold', 'release', 'upload_evidence', 'remove_evidence', 'relocate_evidence'];
    public const PAGES = ['allocations_page', 'inspections_page', 'attachments_page', 'history_page'];

    public static function sanitize(mixed $value): ?string { return self::clean($value, true); }
    public static function fromRegister(array $query): string { return self::sanitize(self::url(self::REGISTER, $query)) ?? self::REGISTER; }
    public static function record(string $kind, int $id, mixed $returnTo = null, ?string $section = null, array $selections = [], ?string $command = null): string
    {
        if (! in_array($kind, ['inventory', 'allocations', 'inspections'], true) || ! self::positive((string) $id)) { return self::REGISTER; }
        $path = self::REGISTER.'/'.$kind.'/'.$id; $query = [];
        if (in_array($section, self::SECTIONS, true)) { $query['section'] = self::section($section, $kind); }
        if (in_array($command, self::COMMANDS, true)) { $query['action'] = $command; }
        foreach (['attachment', ...self::PAGES] as $key) {
            if ((is_int($selections[$key] ?? null) || is_string($selections[$key] ?? null)) && self::positive((string) $selections[$key])) { $query[$key] = (string) $selections[$key]; }
        }
        $origin = self::sanitize($returnTo);
        if ($origin !== null && ! self::containsPath($origin, $path)) { $query['return_to'] = $origin; }
        return self::url($path, $query);
    }
    private static function clean(mixed $value, bool $nested): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 1500 || ! str_starts_with($value, '/') || str_starts_with($value, '//')
            || str_contains($value, '\\') || str_contains($value, '#') || preg_match('/[\x00-\x20\x7f]/', $value)) { return null; }
        $parts = parse_url($value);
        if (! is_array($parts) || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) { return null; }
        $path = $parts['path'] ?? ''; $query = self::query($parts['query'] ?? '');
        if (! $nested && array_key_exists('return_to', $query)) { return null; }
        $own = preg_match('~^/health-safety/ppe/(inventory|allocations|inspections)/([1-9]\d*)$~D', $path, $record) === 1;
        $site = preg_match('~^/sites/([1-9]\d*)$~D', $path, $siteMatch) === 1;
        if (($own && ! self::positive($record[2])) || ($site && ! self::positive($siteMatch[1]))) { return null; }
        $safe = [];
        if ($path === self::REGISTER) {
            foreach (['tab', 'site_id', 'category', 'status', 'ppe_type_id', 'search', 'page', 'allocations_page', 'view'] as $key) {
                $item = $query[$key] ?? null;
                if (! is_string($item) || $item === '' || strlen($item) > 200 || preg_match('/[\x00-\x1f\x7f]/', $item)) { continue; }
                $values = match ($key) { 'tab' => self::TABS, 'category' => ['head', 'eye', 'ear', 'respiratory', 'hand', 'foot', 'high_visibility', 'body', 'fall_protection', 'other'],
                    'status' => ['available', 'allocated', 'maintenance', 'condemned', 'disposed'], 'view' => ['cards', 'table'], default => null };
                if (($values !== null && ! in_array($item, $values, true)) || (in_array($key, ['site_id', 'ppe_type_id', 'page', 'allocations_page'], true) && ! self::positive($item))) { continue; }
                $safe[$key] = $item;
            }
            $base = self::url($path, $safe);
        } elseif ($own) {
            if (in_array($query['section'] ?? null, self::SECTIONS, true)) { $safe['section'] = self::section($query['section'], $record[1]); }
            foreach (self::PAGES as $key) { if (is_string($query[$key] ?? null) && self::positive($query[$key])) { $safe[$key] = $query[$key]; } }
            $base = self::url($path, $safe);
        } elseif ($site && ($query['tab'] ?? null) === 'ppe') {
            $base = $path.'?tab=ppe';
        } else {
            $base = InjuryReturn::leaf(self::url($path, array_filter(array_diff_key($query, ['return_to' => true]), 'is_string')));
        }
        if ($base === null) { return null; }
        if ($nested && ($own || $site)) {
            $back = self::clean($query['return_to'] ?? null, false);
            if ($back !== null && ! self::containsPath($back, $path)) { $base .= (str_contains($base, '?') ? '&' : '?').'return_to='.rawurlencode($back); }
        }
        return $base;
    }
    private static function section(string $value, string $kind): string
    {
        if ($kind === 'inventory' && $value === 'fit-training') { return 'allocations'; }
        if (($kind === 'allocations' && in_array($value, ['allocations', 'inspections'], true)) || ($kind === 'inspections' && in_array($value, ['allocations', 'inspections', 'fit-training'], true))) { return 'overview'; }
        return $value;
    }
    private static function positive(string $value): bool { return preg_match('/^[1-9]\d*$/D', $value) === 1 && strlen($value) <= 16 && (float) $value <= 9007199254740991; }
    private static function url(string $path, array $query): string { return $path.($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986)); }
    private static function containsPath(string $value, string $path): bool
    {
        $parts = parse_url($value); $query = self::query($parts['query'] ?? '');
        return ($parts['path'] ?? '') === $path || (is_string($query['return_to'] ?? null) && parse_url($query['return_to'], PHP_URL_PATH) === $path);
    }
    private static function query(string $raw): array
    {
        $query = [];
        foreach (explode('&', $raw) as $pair) {
            if ($pair === '') { continue; }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, ''); $key = urldecode($key);
            $query[$key] = array_key_exists($key, $query) ? null : urldecode($value);
        }
        return $query;
    }
}
