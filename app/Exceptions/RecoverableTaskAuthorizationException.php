<?php

namespace App\Exceptions;

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
        if (! is_array($parts)
            || ($path !== '/tasks' && ! $peopleLocations)
            || isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])) {
            return null;
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        $validated = [];
        $keys = $peopleLocations ? ['q', 'population', 'site', 'selected', 'source', 'date', 'cohort', 'sort', 'peopleView', 'chartView', 'alertStatus'] : self::ALLOWED_QUERY_KEYS;

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

            $validated[$key] = $value;
        }

        $queryString = http_build_query($validated, '', '&', PHP_QUERY_RFC3986);

        return $path.($queryString !== '' ? '?'.$queryString : '');
    }
}
