<?php

namespace App\Exceptions;

use App\Support\HealthSafety\EmergencyDrillReturn;
use App\Support\HealthSafety\FirstAidReturn;
use App\Support\HealthSafety\LoneWorkerSessionReturn;
use App\Support\HealthSafety\RiskAssessmentReturn;
use App\Support\HealthSafety\SafeWorkProcedureReturn;
use InvalidArgumentException;
use RuntimeException;

final class RecoverableTaskAuthorizationException extends RuntimeException
{
    private const ALLOWED_QUERY_KEYS = [
        'q',
        'sources',
        'severity',
        'bucket',
        'assigned',
        'overdue',
        'due',
        'following',
        'done',
        'page',
    ];

    public readonly string $returnTo;

    public function __construct(string $returnTo, string $message)
    {
        $validatedReturnTo = self::validatedReturnTo($returnTo);
        if ($validatedReturnTo === null || $validatedReturnTo !== $returnTo) {
            throw new InvalidArgumentException(
                'Recoverable authorization requires a validated internal workspace return URL.',
            );
        }

        $this->returnTo = $validatedReturnTo;

        parent::__construct($message, 403);
    }

    public static function validatedReturnTo(mixed $returnTo): ?string
    {
        if (! is_string($returnTo) || $returnTo === '' || strlen($returnTo) > 2048) {
            return null;
        }

        $parts = parse_url($returnTo);
        $path = is_array($parts) ? ($parts['path'] ?? '') : '';
        $peopleLocations = preg_match('#^/operations/people-locations(?:/(?:map|people|analytics|alerts|history|settings))?$#D', $path) === 1;
        $correctiveAction = preg_match('#^/health-safety/corrective-actions/[1-9][0-9]*$#D', $path) === 1;
        $hazard = preg_match('#^/hazards/[1-9][0-9]*$#D', $path) === 1;
        $risk = preg_match('#^/health-safety/risk-assessments/[1-9][0-9]*$#D', $path) === 1;
        $procedure = preg_match('#^/health-safety/procedures/[1-9][0-9]*$#D', $path) === 1;
        $drill = preg_match('#^/health-safety/drills/[1-9][0-9]*$#D', $path) === 1;
        if ($path === FirstAidReturn::REGISTER) {
            return FirstAidReturn::leaf($returnTo);
        }
        if (preg_match('#^/health-safety/first-aid/[1-9][0-9]*$#D', $path) === 1) {
            return FirstAidReturn::sanitize($returnTo);
        }
        if ($path === LoneWorkerSessionReturn::REGISTER) {
            return LoneWorkerSessionReturn::leaf($returnTo);
        }
        if (preg_match('#^/health-safety/lone-workers/sessions/[1-9][0-9]*$#D', $path) === 1) {
            return LoneWorkerSessionReturn::sanitize($returnTo);
        }
        if ($drill) {
            return EmergencyDrillReturn::sanitize($returnTo);
        }
        if ($procedure) {
            return SafeWorkProcedureReturn::sanitize($returnTo);
        }
        if (! is_array($parts)
            || ($path !== '/tasks' && ! $peopleLocations && ! $correctiveAction && ! $hazard && ! $risk)
            || isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])) {
            return null;
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        $validated = [];
        $keys = ($hazard || $risk) ? ['section', 'return_to'] : ($correctiveAction ? ['section'] : ($peopleLocations ? ['q', 'population', 'site', 'selected', 'source', 'date', 'cohort', 'sort', 'peopleView', 'chartView', 'alertStatus'] : self::ALLOWED_QUERY_KEYS));

        foreach ($query as $key => $value) {
            if (! in_array($key, $keys, true)) {
                continue;
            }

            if (! is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);
            if ($value === '' || strlen($value) > 500) {
                continue;
            }

            if ($key === 'page' && (! ctype_digit($value) || (int) $value < 1)) {
                continue;
            }

            if ($risk && $key === 'section' && ! preg_match('/^[a-z][a-z0-9_-]{0,49}$/D', $value)) {
                continue;
            }
            if (($hazard || $risk) && $key === 'return_to') {
                $value = $risk ? RiskAssessmentReturn::sanitize($value) : self::hazardOriginReturn($value);
                if ($value === null) {
                    continue;
                }
            }

            $validated[$key] = $value;
        }

        $queryString = http_build_query($validated, '', '&', PHP_QUERY_RFC3986);

        return $path.($queryString !== '' ? '?'.$queryString : '');
    }

    /** One nested origin only; its query cannot carry another redirect or command. */
    private static function hazardOriginReturn(string $value): ?string
    {
        $parts = parse_url($value);
        $path = is_array($parts) ? ($parts['path'] ?? '') : '';
        if (! is_array($parts) || isset($parts['scheme']) || isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (! in_array($path, ['/compliance/hazards', '/my-day', '/tasks'], true)
                && preg_match('#^/sites/[1-9][0-9]*(?:/hazards)?$#D', $path) !== 1
                && preg_match('#^/operations/clients/[1-9][0-9]*$#D', $path) !== 1)) {
            return null;
        }
        $keys = ['q', 'tab', 'site_id', 'site_type', 'severity', 'risk_rating', 'assignee_id',
            'due_state', 'from', 'to', 'page', 'section'];
        if ($path === '/tasks') {
            $keys = [...$keys, ...self::ALLOWED_QUERY_KEYS];
        }
        parse_str($parts['query'] ?? '', $query);
        $validated = [];
        foreach ($query as $key => $entry) {
            if (! in_array($key, $keys, true) || ! is_scalar($entry)) {
                continue;
            }
            $entry = trim((string) $entry);
            if ($entry === '' || strlen($entry) > 500
                || ($key === 'page' && (! ctype_digit($entry) || (int) $entry < 1))) {
                continue;
            }
            $validated[$key] = $entry;
        }
        $queryString = http_build_query($validated, '', '&', PHP_QUERY_RFC3986);

        return $path.($queryString !== '' ? '?'.$queryString : '');
    }
}
