<?php

namespace App\Support\HealthSafety;

/** Read-only source context with at most one supported leaf; never a command. */
final class FirstAidReturn
{
    public const REGISTER = '/health-safety/first-aid';

    public const SECTIONS = ['overview', 'injury', 'incident', 'followups', 'evidence', 'history', 'connections'];

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

    public static function href(int $id, mixed $returnTo = null, ?string $section = null, ?int $followupId = null): string
    {
        $path = self::REGISTER.'/'.$id;
        $query = [];
        if (in_array($section, self::SECTIONS, true)) {
            $query['section'] = $section;
        }
        if ($followupId !== null && $followupId > 0) {
            $query['followup'] = $followupId;
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
        $injuryRecord = preg_match('~^/health-safety/injuries/([1-9]\d*)$~D', $path) === 1;
        if (in_array($path, ['/health-safety', '/dashboard'], true)) {
            return $path;
        }
        parse_str($parts['query'] ?? '', $query);
        if (! $nested && array_key_exists('return_to', $query)) {
            return null;
        }
        $origin = $query['return_to'] ?? null;
        unset($query['return_to']);
        $own = preg_match('~^/health-safety/first-aid/([1-9]\d*)$~D', $path, $match) === 1;
        if ($own && ! self::positive($match[1])) {
            return null;
        }
        $record = $own || preg_match('~^/(?:incidents|hazards|control-room/alerts|health-safety/(?:events|corrective-actions|risk-assessments|procedures|drills))/[1-9]\d*$~D', $path) === 1 || preg_match('~^/health-safety/lone-workers/sessions/[1-9]\d*$~D', $path) === 1;
        $safe = [];
        if ($own || $path === self::REGISTER) {
            $keys = $own ? ['section', 'followup'] : ['tab', 'site_id', 'treated_person_type', 'treatment_outcome', 'first_aider_id', 'period', 'q', 'page'];
            foreach ($keys as $key) {
                $item = $query[$key] ?? null;
                if (! is_string($item) || $item === '' || strlen($item) > 200 || preg_match('/[\x00-\x1f\x7f]/', $item)) {
                    continue;
                }
                $valid = match ($key) {
                    'section' => in_array($item, self::SECTIONS, true),
                    'followup', 'site_id', 'first_aider_id', 'page' => self::positive($item),
                    'tab' => in_array($item, ['all', 'ambulance', 'linked', 'reportable', 'followup'], true),
                    'period' => in_array($item, ['week', '30d', 'quarter', 'all'], true),
                    'treated_person_type' => in_array($item, ['staff', 'client', 'visitor', 'contractor'], true),
                    'treatment_outcome' => in_array($item, ['returned_to_activity', 'sent_home', 'medical_centre', 'sent_to_hospital', 'ongoing_monitoring', 'refused_treatment', 'other'], true),
                    default => true,
                };
                if ($valid) {
                    $safe[$key] = $item;
                }
            }
            $base = $path.($safe === [] ? '' : '?'.http_build_query($safe, '', '&', PHP_QUERY_RFC3986));
        } elseif ($path === InjuryReturn::REGISTER || $injuryRecord) {
            // Only these literal injury read paths delegate; InjuryReturn never delegates its own path back here.
            $injuryQuery = implode('&', array_filter(explode('&', $parts['query'] ?? ''),
                fn (string $pair): bool => urldecode(explode('=', $pair, 2)[0]) !== 'return_to'));
            $base = InjuryReturn::leaf($path.($injuryQuery === '' ? '' : '?'.$injuryQuery));
        } elseif (preg_match('~^/sites/[1-9]\d*$~D', $path) && ($query['tab'] ?? null) === 'first_aid') {
            $base = $path.'?tab=first_aid';
        } elseif (preg_match('~^/operations/clients/[1-9]\d*$~D', $path) && ($query['tab'] ?? null) === 'first_aid') {
            $base = $path.'?tab=first_aid';
        } else {
            $base = LoneWorkerSessionReturn::leaf($path.($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986)));
        }
        if ($base === null) {
            return null;
        }
        if ($nested && ($record || $injuryRecord) && $origin !== null) {
            $leaf = self::leaf($origin);
            if ($leaf !== null && ! self::containsPath($leaf, $path)) {
                $base .= (str_contains($base, '?') ? '&' : '?').'return_to='.rawurlencode($leaf);
            }
        }

        return $base;
    }

    private static function positive(string $value): bool
    {
        return preg_match('/^[1-9]\d*$/D', $value) === 1 && strlen($value) <= 16 && (float) $value <= 9007199254740991;
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
