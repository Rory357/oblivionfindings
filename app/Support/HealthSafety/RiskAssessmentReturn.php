<?php

namespace App\Support\HealthSafety;

/** Bounded navigation context, never an authorization decision or command. */
final class RiskAssessmentReturn
{
    public const REGISTER = '/health-safety/risk-assessments';

    public static function sanitize(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 500
            || preg_match('/[\\\\\x00-\x20\x7f]/', $value) || str_contains($value, '#')) {
            return null;
        }
        $parts = parse_url($value);
        if ($parts === false || isset($parts['scheme'], $parts['host']) || isset($parts['scheme'])
            || isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $path = $parts['path'] ?? '';
        if ($path !== self::REGISTER && $path !== '/tasks' && $path !== '/my-day'
            && ! preg_match('~^/(?:sites/[1-9]\d*|operations/clients/[1-9]\d*|health-safety/events/[1-9]\d*)$~D', $path)) {
            return null;
        }
        parse_str($parts['query'] ?? '', $query);
        $safe = [];
        $keys = match ($path) {
            self::REGISTER => ['tab', 'status', 'risk_level', 'due_for_review', 'risk_acceptable', 'site_id', 'client_id', 'hs_event_id', 'search', 'page'],
            '/tasks' => ['sources', 'status', 'done', 'site_id', 'search', 'q', 'page'],
            '/my-day' => [],
            default => ['section', 'tab'],
        };
        foreach ($keys as $key) {
            $item = $query[$key] ?? null;
            if (! is_string($item) || $item === '' || strlen($item) > 200 || preg_match('/[\x00-\x1f\x7f]/', $item)) {
                continue;
            }
            if (in_array($key, ['site_id', 'client_id', 'hs_event_id', 'page'], true) && ! preg_match('/^[1-9]\d*$/D', $item)) {
                continue;
            }
            if ($key === 'tab' && $path === self::REGISTER && ! in_array($item, ['all', 'active', 'drafts', 'due', 'high', 'closed'], true)) {
                continue;
            }
            if ($key === 'status' && $path === self::REGISTER && ! in_array($item, ['draft', 'active', 'under_review', 'superseded', 'archived'], true)) {
                continue;
            }
            if ($key === 'risk_level' && ! in_array($item, ['low', 'medium', 'high', 'extreme'], true)) {
                continue;
            }
            if ($key === 'risk_acceptable' && ! in_array($item, ['0', '1'], true)) {
                continue;
            }
            if (in_array($key, ['due_for_review', 'done'], true) && ! in_array($item, ['true', 'false'], true)) {
                continue;
            }
            if (in_array($key, ['section', 'tab'], true) && ! preg_match('/^[a-z][a-z0-9_-]{0,49}$/D', $item)) {
                continue;
            }
            $safe[$key] = $item;
        }

        return $path.($safe === [] ? '' : '?'.http_build_query($safe, '', '&', PHP_QUERY_RFC3986));
    }

    public static function fromRegister(array $query): string
    {
        return self::sanitize(self::REGISTER.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986)) ?? self::REGISTER;
    }

    public static function href(int $id, mixed $returnTo = null): string
    {
        $origin = self::sanitize($returnTo);

        return self::REGISTER.'/'.$id.($origin ? '?return_to='.rawurlencode($origin) : '');
    }
}
