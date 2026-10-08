<?php

namespace App\Support;

/** Local return locations are navigation hints, never an authorization grant. */
final class MedicationJourney
{
    public static function returnTo(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 2048 || ! str_starts_with($value, '/') || str_starts_with($value, '//')) {
            return null;
        }

        $decoded = rawurldecode($value);
        if (preg_match('/[\\\\\x00-\x1f\x7f]/', $decoded)) {
            return null;
        }

        $url = parse_url($value);
        if ($url === false || isset($url['scheme']) || isset($url['host']) || isset($url['user'])) {
            return null;
        }
        $path = rawurldecode($url['path'] ?? '');
        if (str_starts_with($path, '//') || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $path)) {
            return null;
        }

        foreach (['/my-day', '/meds/today', '/emar', '/operations/clients', '/clients', '/operations/shifts', '/operations/handovers', '/attendance', '/tasks', '/sites', '/calendar', '/my-calendar', '/health-clinical'] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                // A return location describes one source, never a nested chain.
                $query = array_values(array_filter(explode('&', $url['query'] ?? ''),
                    fn (string $part): bool => rawurldecode(explode('=', $part, 2)[0]) !== 'return_to'));

                return ($url['path'] ?? '').($query !== [''] && $query !== [] ? '?'.implode('&', $query) : '')
                    .(isset($url['fragment']) ? '#'.$url['fragment'] : '');
            }
        }

        return null;
    }
}
