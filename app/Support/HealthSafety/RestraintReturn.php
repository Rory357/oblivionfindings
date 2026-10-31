<?php

namespace App\Support\HealthSafety;

/** One authorised read destination and one bounded source leaf; never a command. */
final class RestraintReturn
{
    public const REGISTER = '/health-safety/restraints';

    public const EVENT_SECTIONS = ['overview', 'review', 'monitoring', 'debrief', 'followups', 'evidence', 'history', 'connections'];

    public const PLAN_SECTIONS = ['overview', 'content', 'review', 'approval', 'versions', 'history', 'connections'];

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

    public static function event(int $id, mixed $returnTo = null, ?string $section = null, ?int $followup = null): string
    {
        return self::href('events', $id, $returnTo, $section, $followup);
    }

    public static function plan(int $id, mixed $returnTo = null, ?string $section = null, ?int $version = null): string
    {
        return self::href('plans', $id, $returnTo, $section, $version);
    }

    private static function href(string $kind, int $id, mixed $returnTo, ?string $section, ?int $child): string
    {
        $path = self::REGISTER.'/'.$kind.'/'.$id;
        $query = [];
        if (in_array($section, $kind === 'events' ? self::EVENT_SECTIONS : self::PLAN_SECTIONS, true)) {
            $query['section'] = $section;
        }
        if ($child !== null && $child > 0) {
            $query[$kind === 'events' ? 'followup' : 'version'] = $child;
        }
        $origin = self::sanitize($returnTo);
        if ($origin !== null && ! self::containsPath($origin, $path)) {
            $query['return_to'] = $origin;
        }

        return $path.($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    private static function clean(mixed $value, bool $nested): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 1500 || str_contains($value, chr(92))
            || str_contains($value, '#') || preg_match('/[\x00-\x20\x7f]/', $value)) {
            return null;
        }
        $parts = parse_url($value);
        if (! is_array($parts) || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $path = $parts['path'] ?? '';
        $injuryRecord = preg_match('~^/health-safety/injuries/([1-9]\d*)$~D', $path) === 1;
        parse_str($parts['query'] ?? '', $query);
        if (! $nested && array_key_exists('return_to', $query)) {
            return null;
        }
        $origin = $query['return_to'] ?? null;
        unset($query['return_to']);
        $record = preg_match('~^/health-safety/restraints/(events|plans)/([1-9]\d*)(?:/(review|approval))?$~D', $path, $match) === 1;
        if ($record && $match[1] === 'events' && ($match[3] ?? null) === 'approval') {
            return null;
        }
        if ($record && ! self::positive($match[2])) {
            return null;
        }
        if (preg_match('~^/(?:respite/stays|operations/clients)/([1-9]\d*)$~D', $path, $identity) === 1
            && ! self::positive($identity[1])) {
            return null;
        }
        if ($path === InjuryReturn::REGISTER || $injuryRecord) {
            // Only these literal injury read paths delegate; InjuryReturn never delegates its own path back here.
            $injuryQuery = implode('&', array_filter(explode('&', $parts['query'] ?? ''),
                fn (string $pair): bool => urldecode(explode('=', $pair, 2)[0]) !== 'return_to'));
            $base = InjuryReturn::leaf($path.($injuryQuery === '' ? '' : '?'.$injuryQuery));
        } elseif (preg_match('~^/respite/stays/([1-9]\d*)$~D', $path, $stay) === 1 && self::positive($stay[1])) {
            $base = $path;
        } elseif (preg_match('~^/operations/clients/([1-9]\d*)$~D', $path, $client) === 1
            && self::positive($client[1]) && ($query['tab'] ?? null) === 'observations') {
            $base = $path.'?tab=observations';
        } elseif ($path === '/respite' && ($query['tab'] ?? null) === 'stays') {
            $base = '/respite?tab=stays';
        } elseif ($path === '/health-clinical/behaviour') {
            $safe = [];
            foreach (['client_id', 'site_id', 'date_from', 'date_to', 'view'] as $key) {
                $item = $query[$key] ?? null;
                if (! is_string($item)) {
                    continue;
                }
                if (match ($key) {
                    'client_id', 'site_id' => self::positive($item),
                    'date_from', 'date_to' => preg_match('/^\d{4}-\d{2}-\d{2}$/D', $item) === 1 && self::date($item),
                    'view' => $item === 'restraint',
                }) {
                    $safe[$key] = $item;
                }
            }
            $base = $path.($safe === [] ? '' : '?'.http_build_query($safe, '', '&', PHP_QUERY_RFC3986));
        } elseif ($record || $path === self::REGISTER) {
            $safe = [];
            $keys = $record ? ($match[1] === 'events' ? ['section', 'followup'] : ['section', 'version', 'approval'])
                : ['lens', 'tab', 'client_id', 'site_id', 'restraint_type', 'severity', 'within_plan', 'review_state', 'period', 'from', 'to', 'q', 'events_page', 'plans_page'];
            foreach ($keys as $key) {
                $item = $query[$key] ?? null;
                if (! is_string($item) || $item === '' || strlen($item) > 200 || preg_match('/[\x00-\x1f\x7f]/', $item)) {
                    continue;
                }
                $valid = match ($key) {
                    'section' => in_array($item, $match[1] === 'events' ? self::EVENT_SECTIONS : self::PLAN_SECTIONS, true),
                    'followup', 'version', 'approval', 'client_id', 'site_id', 'events_page', 'plans_page' => self::positive($item),
                    'lens' => in_array($item, ['events', 'plans'], true),
                    'tab' => in_array($item, ['all', 'unreviewed', 'out_of_plan', 'injury', 'critical', '30d', 'active', 'draft', 'under_review', 'archived', 'review_due'], true),
                    'restraint_type' => in_array($item, ['physical', 'chemical', 'mechanical', 'seclusion', 'environmental'], true),
                    'severity' => in_array($item, ['low', 'medium', 'high', 'critical'], true),
                    'within_plan' => in_array($item, ['yes', 'no'], true),
                    'review_state' => in_array($item, ['reviewed', 'unreviewed', 'due', 'overdue'], true),
                    'period' => in_array($item, ['week', '30d', 'quarter', 'all'], true),
                    'from', 'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/D', $item) === 1 && self::date($item),
                    default => true,
                };
                if ($valid) {
                    $safe[$key] = $item;
                }
            }
            $base = $path.($safe === [] ? '' : '?'.http_build_query($safe, '', '&', PHP_QUERY_RFC3986));
        } else {
            $base = FirstAidReturn::leaf($path.($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986)));
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

    private static function date(string $value): bool
    {
        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year);
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
