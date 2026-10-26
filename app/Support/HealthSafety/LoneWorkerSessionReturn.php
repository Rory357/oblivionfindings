<?php

namespace App\Support\HealthSafety;

use App\Exceptions\RecoverableTaskAuthorizationException;

/** Read context only: one canonical record and one supported originating view. */
final class LoneWorkerSessionReturn
{
    public const REGISTER = '/health-safety/lone-workers';

    public const SECTIONS = ['overview', 'check-ins', 'response', 'location', 'history', 'connections'];

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

    public static function href(int $id, mixed $returnTo = null, ?string $section = null): string
    {
        $path = self::REGISTER.'/sessions/'.$id;
        $query = [];
        if (in_array($section, self::SECTIONS, true)) {
            $query['section'] = $section;
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
        $own = preg_match('~^/health-safety/lone-workers/sessions/[1-9]\d*$~D', $path) === 1;
        $shift = preg_match('~^/operations/shifts/[1-9]\d*$~D', $path) === 1;
        $record = $own || $shift || preg_match('~^/(?:incidents|hazards|control-room/alerts|health-safety/(?:events|corrective-actions|risk-assessments|procedures|drills))/[1-9]\d*$~D', $path) === 1;
        $origin = $query['return_to'] ?? null;
        unset($query['return_to']);
        $safe = [];
        if ($own || $path === self::REGISTER || $path === '/security-devices/tracking') {
            $keys = $own ? ['section'] : ($path === self::REGISTER ? ['tab', 'q', 'site_id', 'user_id', 'status', 'period', 'page'] : ['tab', 'attention']);
            foreach ($keys as $key) {
                $item = $query[$key] ?? null;
                if (! is_string($item) || $item === '' || strlen($item) > 200 || preg_match('/[\x00-\x1f\x7f]/', $item)) {
                    continue;
                }
                $valid = match ($key) {
                    'section' => in_array($item, self::SECTIONS, true),
                    'site_id', 'user_id', 'page' => preg_match('/^[1-9]\d*$/D', $item) === 1,
                    'tab' => $path === self::REGISTER ? in_array($item, ['sessions', 'alerts'], true) : in_array($item, ['overview', 'personal-safety', 'fleet', 'assets', 'geofences', 'history'], true),
                    'attention' => in_array($item, ['offline', 'low-battery', 'consent-blocked', 'unassigned', 'stale'], true),
                    'status' => in_array($item, ['active', 'overdue', 'emergency', 'completed', 'open', 'ack', 'triaging', 'confirmed', 'resolved', 'closed', 'dismissed'], true),
                    'period' => in_array($item, ['today', 'week', '30d', 'all'], true),
                    default => true,
                };
                if ($valid) {
                    $safe[$key] = $item;
                }
            }
            $base = $path.($safe === [] ? '' : '?'.http_build_query($safe, '', '&', PHP_QUERY_RFC3986));
        } elseif ($shift) {
            // Shift owns its record view; no unverified section or command keys.
            $base = $path;
        } elseif (preg_match('~^/operations/people-locations(?:/(?:map|people|analytics|alerts|history|settings))?$~D', $path)) {
            $base = RecoverableTaskAuthorizationException::validatedReturnTo($path.($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986)));
        } else {
            $base = EmergencyDrillReturn::leaf($path.($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986)));
        }
        if ($base === null) {
            return null;
        }
        if ($nested && $record && $origin !== null) {
            $leaf = self::leaf($origin);
            if ($leaf !== null && ! self::containsPath($leaf, $path)) {
                $base .= (str_contains($base, '?') ? '&' : '?').'return_to='.rawurlencode($leaf);
            }
        }

        return $base;
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
