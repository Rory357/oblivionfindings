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
                'Recoverable task authorization requires a validated internal /tasks return URL.',
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
        if (! is_array($parts)
            || ($parts['path'] ?? null) !== '/tasks'
            || isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])) {
            return null;
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        $validated = [];

        foreach ($query as $key => $value) {
            if (! in_array($key, self::ALLOWED_QUERY_KEYS, true)) {
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

        return '/tasks'.($queryString !== '' ? '?'.$queryString : '');
    }
}
