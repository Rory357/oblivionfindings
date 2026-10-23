<?php

namespace App\Support\HealthSafety;

/** Navigation only: one supported record and one leaf origin, never a command. */
final class SafeWorkProcedureReturn
{
    public const REGISTER = '/health-safety/procedures';

    private const LIST_KEYS = [
        '/incidents' => ['tab', 'q', 'site_id', 'client_id', 'severity', 'source', 'kind', 'from', 'to', 'page'],
        '/health-safety/events' => ['tab', 'q', 'site_id', 'severity', 'category', 'source', 'from', 'to', 'priority', 'unassigned', 'page'],
        '/health-safety/corrective-actions' => ['tab', 'q', 'site_id', 'status', 'priority', 'assigned_to', 'overdue', 'awaiting_verification', 'from', 'to', 'page'],
        '/compliance/hazards' => ['tab', 'q', 'site_id', 'site_type', 'severity', 'risk_rating', 'assignee_id', 'due_state', 'from', 'to', 'page'],
        '/health-safety/risk-assessments' => ['tab', 'status', 'risk_level', 'due_for_review', 'risk_acceptable', 'site_id', 'client_id', 'hs_event_id', 'search', 'page'],
        self::REGISTER => ['tab', 'q', 'category', 'status', 'site_id', 'review_state', 'page'],
        '/tasks' => ['q', 'sources', 'severity', 'bucket', 'assigned', 'overdue', 'due', 'following', 'done', 'site_id', 'page'],
        '/my-day' => [],
        '/hr/my' => [],
    ];

    public static function sanitize(mixed $value): ?string
    {
        return self::clean($value, true);
    }

    /** A leaf cannot carry another return target, even one that would be stripped. */
    public static function leaf(mixed $value): ?string
    {
        return self::clean($value, false);
    }

    public static function fromRegister(array $query): string
    {
        return self::sanitize(self::REGISTER.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986)) ?? self::REGISTER;
    }

    public static function href(int $id, mixed $returnTo = null, ?string $section = null, ?int $version = null, bool $working = false): string
    {
        $path = self::REGISTER.'/'.$id;
        $query = [];
        if ($section !== null && self::validSection($path, $section)) {
            $query['section'] = $section;
        }
        if ($version !== null && $version > 0) {
            $query['version'] = $version;
        }
        if ($working && $version === null) {
            $query['revision'] = 'working';
        }
        $origin = self::sanitize($returnTo);
        if ($origin !== null && ! self::containsPath($origin, $path)) {
            $query['return_to'] = $origin;
        }

        return $path.($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    private static function clean(mixed $value, bool $nested): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 1500
            || preg_match('/[\\\\\x00-\x20\x7f]/', $value) || str_contains($value, '#')) {
            return null;
        }
        $parts = parse_url($value);
        if (! is_array($parts) || isset($parts['scheme']) || isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $path = $parts['path'] ?? '';
        $record = preg_match('~^/(?:incidents|hazards|health-safety/(?:events|corrective-actions|risk-assessments|procedures))/[1-9]\d*$~D', $path) === 1;
        $site = preg_match('~^/sites/[1-9]\d*$~D', $path) === 1;
        $client = preg_match('~^/operations/clients/[1-9]\d*$~D', $path) === 1;
        $staff = preg_match('~^/hr/people/[1-9]\d*$~D', $path) === 1;
        if (! isset(self::LIST_KEYS[$path]) && ! $record && ! $site && ! $client && ! $staff) {
            return null;
        }
        parse_str($parts['query'] ?? '', $query);
        if (! $nested && array_key_exists('return_to', $query)) {
            return null;
        }
        $keys = self::LIST_KEYS[$path] ?? ($record ? ['section'] : ['tab']);
        if (str_starts_with($path, self::REGISTER.'/')) {
            $keys = ['section', 'version', 'revision', 'request'];
        }
        $safe = [];
        foreach ($keys as $key) {
            $item = $query[$key] ?? null;
            if (! is_string($item) || $item === '' || strlen($item) > 200 || preg_match('/[\x00-\x1f\x7f]/', $item)) {
                continue;
            }
            if (in_array($key, ['site_id', 'client_id', 'hs_event_id', 'assignee_id', 'page', 'version', 'request'], true)
                && ! preg_match('/^[1-9]\d*$/D', $item)) {
                continue;
            }
            if (in_array($key, ['from', 'to'], true) && ! self::validDate($item)) {
                continue;
            }
            if ($key === 'revision' && ($item !== 'working' || isset($query['version']))) {
                continue;
            }
            if ($key === 'section' && ! self::validSection($path, $item)) {
                continue;
            }
            if ($key === 'tab' && (($site && ! in_array($item, ['hazards', 'risk_assessments'], true))
                || ($client && $item !== 'risk_management') || ($staff && $item !== 'training'))) {
                continue;
            }
            if ($key === 'tab' && $path === self::REGISTER && ! in_array($item, ['all', 'draft', 'under_review', 'approved', 'review_due', 'archived'], true)) {
                continue;
            }
            if ($key === 'status' && $path === self::REGISTER && ! in_array($item, ['draft', 'under_review', 'approved', 'archived'], true)) {
                continue;
            }
            if ($key === 'kind' && ! in_array($item, ['all', 'incidents', 'near_misses'], true)) {
                continue;
            }
            if ($key === 'review_state' && ! in_array($item, ['overdue', 'today', 'due_soon', 'current', 'none'], true)) {
                continue;
            }
            if (! in_array($key, ['q', 'search'], true) && ! preg_match('/^[a-zA-Z0-9_,.-]+$/D', $item)) {
                continue;
            }
            $safe[$key] = $item;
        }
        if ($nested && $record && array_key_exists('return_to', $query)) {
            $leaf = self::leaf($query['return_to']);
            if ($leaf !== null && ! self::containsPath($leaf, $path)) {
                $safe['return_to'] = $leaf;
            }
        }

        return $path.($safe === [] ? '' : '?'.http_build_query($safe, '', '&', PHP_QUERY_RFC3986));
    }

    private static function validSection(string $path, string $value): bool
    {
        $sections = match (true) {
            str_starts_with($path, self::REGISTER.'/') => ['overview', 'steps', 'ppe', 'applies', 'documents', 'history', 'connections'],
            str_starts_with($path, '/hazards/') => ['overview', 'risk', 'actions', 'connections', 'evidence', 'history'],
            str_starts_with($path, '/health-safety/risk-assessments/') => ['overview', 'risk', 'connections', 'evidence', 'history'],
            str_starts_with($path, '/health-safety/corrective-actions/') => ['overview', 'evidence', 'history'],
            str_starts_with($path, '/incidents/') => ['overview', 'followups', 'evidence', 'investigation', 'linked', 'history'],
            str_starts_with($path, '/health-safety/events/') => ['overview', 'handover', 'investigation', 'actions', 'risk', 'timeline', 'evidence'],
            default => [],
        };

        return in_array($value, $sections, true);
    }

    private static function validDate(string $value): bool
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts)) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    private static function containsPath(string $value, string $path): bool
    {
        $parts = parse_url($value);
        if (($parts['path'] ?? '') === $path) {
            return true;
        }
        parse_str($parts['query'] ?? '', $query);

        return is_string($query['return_to'] ?? null) && (parse_url($query['return_to'], PHP_URL_PATH) === $path);
    }
}
