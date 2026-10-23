<?php

namespace App\Support\HealthSafety;

/** One bounded record plus one supported leaf; never a command or authority grant. */
final class EmergencyDrillReturn
{
    public const REGISTER = '/health-safety/drills';

    public static function sanitize(mixed $value): ?string
    {
        return self::clean($value, true);
    }

    public static function leaf(mixed $value): ?string
    {
        return self::clean($value, false);
    }

    public static function fromRegister(array $query): string
    {
        return self::sanitize(self::REGISTER.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986)) ?? self::REGISTER;
    }

    public static function href(int $id, mixed $returnTo = null, ?string $section = null, ?int $findingId = null): string
    {
        $path = self::REGISTER.'/'.$id;
        $query = [];
        if (in_array($section, ['overview', 'run', 'participants', 'findings', 'evidence', 'history', 'connections'], true)) {
            $query['section'] = $section;
        }
        if ($findingId !== null && $findingId > 0) {
            $query['finding'] = $findingId;
        }
        $origin = self::sanitize($returnTo);
        if ($origin !== null && ! self::containsPath($origin, $path)) {
            $query['return_to'] = $origin;
        }

        return $path.($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    private static function clean(mixed $value, bool $nested): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 1500 || str_contains($value, chr(92)) || preg_match('/[\x00-\x20\x7f]/', $value) || str_contains($value, '#')) {
            return null;
        }
        $parts = parse_url($value);
        if (! is_array($parts) || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $path = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $query);
        if (! $nested && array_key_exists('return_to', $query)) {
            return null;
        }
        $own = preg_match('~^/health-safety/drills/[1-9]\d*$~D', $path) === 1;
        $site = preg_match('~^/sites/[1-9]\d*$~D', $path) === 1;
        $alert = preg_match('~^/control-room/alerts/[1-9]\d*$~D', $path) === 1;
        $alertList = $path === '/control-room/alerts';
        $otherRecord = $alert || preg_match('~^/(?:incidents|hazards|health-safety/(?:events|corrective-actions|risk-assessments|procedures))/[1-9]\d*$~D', $path) === 1;
        $nestedOrigin = $query['return_to'] ?? null;
        $safe = [];
        $calendarProfile = $site && ($query['tab'] ?? null) === 'calendar';
        if ($path === '/calendar' || preg_match('~^/sites/[1-9]\d*/calendar$~D', $path) === 1 || $calendarProfile) {
            return self::calendar($path, $query, $calendarProfile);
        }
        if ($path === self::REGISTER || $own || $alert || $alertList || ($site && ($query['tab'] ?? null) === 'drills')) {
            $keys = $alert ? ['section'] : ($alertList ? ['lens', 'search', 'severity', 'source', 'queue_id', 'assigned_to', 'status', 'date_from', 'date_to', 'sort', 'dir', 'snoozed', 'page'] : ($own ? ['section', 'finding'] : ($site ? ['tab'] : ['tab', 'q', 'period', 'drill_type', 'outcome', 'site_id', 'page'])));
            foreach ($keys as $key) {
                $item = $query[$key] ?? null;
                if (! is_string($item) || $item === '' || strlen($item) > 200 || preg_match('/[\x00-\x1f\x7f]/', $item)) {
                    continue;
                }
                $valid = match ($key) {
                    'section' => $alert ? in_array($item, ['overview', 'tasks'], true) : in_array($item, ['overview', 'run', 'participants', 'findings', 'evidence', 'history', 'connections'], true),
                    'finding', 'site_id', 'page', 'queue_id' => preg_match('/^[1-9]\d*$/D', $item) === 1,
                    'lens' => in_array($item, ['active', 'history', 'snoozed', 'my_queue', 'safety_handover'], true),
                    'status' => in_array($item, ['all', 'open', 'ack', 'triaging', 'confirmed', 'dismissed', 'resolved', 'closed'], true),
                    'severity' => in_array($item, ['all', 'critical', 'high', 'medium', 'low', 'info'], true),
                    'assigned_to' => in_array($item, ['me', 'unassigned'], true) || preg_match('/^[1-9]\d*$/D', $item) === 1,
                    'sort' => in_array($item, ['priority', 'severity', 'triggered_at', 'status', 'alert_type'], true),
                    'dir' => in_array($item, ['asc', 'desc'], true),
                    'snoozed' => $item === '1',
                    'date_from', 'date_to' => self::validDate($item),
                    'tab' => $site ? $item === 'drills' : in_array($item, ['all', 'scheduled', 'overdue', 'in_progress', 'completed', 'findings'], true),
                    'period' => in_array($item, ['30d', 'quarter', '6mo', 'all'], true),
                    'drill_type' => in_array($item, ['fire', 'fire_evacuation', 'earthquake', 'lockdown', 'tsunami', 'chemical_spill', 'medical_emergency', 'other'], true),
                    'outcome' => in_array($item, ['passed', 'passed_actions', 'failed'], true),
                    default => true,
                };
                if ($valid) {
                    $safe[$key] = $item;
                }
            }
            $base = $path.($safe === [] ? '' : '?'.http_build_query($safe, '', '&', PHP_QUERY_RFC3986));
        } else {
            unset($query['return_to']);
            $base = SafeWorkProcedureReturn::sanitize($path.($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986)));
            if ($base === null) {
                return null;
            }
        }
        if ($nested && ($own || $otherRecord) && $nestedOrigin !== null) {
            $origin = self::leaf($nestedOrigin);
            if ($origin !== null && ! self::containsPath($origin, $path)) {
                $base .= (str_contains($base, '?') ? '&' : '?').'return_to='.rawurlencode($origin);
            }
        }

        return $base;
    }

    /** Read-only Calendar view state; unknown layer keys never grant visibility. */
    private static function calendar(string $path, array $query, bool $profile): string
    {
        $safe = $profile ? ['tab' => 'calendar'] : [];
        foreach (['date', 'view', 'sources', 'site_id', 'q', 'color', 'density'] as $key) {
            $value = $query[$key] ?? null;
            if (! is_string($value) || $value === '' || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                continue;
            }
            if ($key === 'sources') {
                $keys = array_values(array_unique(explode(',', $value)));
                if ($value === 'none' || (count($keys) <= 20 && array_filter($keys, fn ($item) => preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $item) !== 1) === [])) {
                    $safe[$key] = $value === 'none' ? 'none' : implode(',', $keys);
                }

                continue;
            }
            $valid = match ($key) {
                'date' => self::validDate($value),
                'view' => in_array($value, ['day', 'week', 'month', 'agenda', 'timeline'], true),
                'site_id' => preg_match('/^[1-9]\d*$/D', $value) === 1 && strlen($value) <= 16 && (int) $value <= 9007199254740991,
                'q' => strlen($value) <= 200,
                'color' => in_array($value, ['source', 'status', 'owner'], true),
                'density' => in_array($value, ['comfortable', 'compact'], true),
                default => false,
            };
            if ($valid) {
                $safe[$key] = $value;
            }
        }

        return $path.($safe === [] ? '' : '?'.http_build_query($safe, '', '&', PHP_QUERY_RFC3986));
    }

    private static function validDate(string $value): bool
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts)) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    private static function containsPath(string $url, string $path): bool
    {
        if (parse_url($url, PHP_URL_PATH) === $path) {
            return true;
        }
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

        return is_string($query['return_to'] ?? null) && parse_url($query['return_to'], PHP_URL_PATH) === $path;
    }
}
