<?php

namespace App\Domain\Monitoring\Protocols\Snmp;

use InvalidArgumentException;

final readonly class SnmpTransportResult
{
    private const array STATUSES = [
        'ok',
        'authentication_failed',
        'privacy_failed',
        'timeout',
        'transport_unavailable',
        'walk_limit_exceeded',
    ];

    /**
     * @param  array<string, int|float|string|bool|null>  $varbinds
     * @param  list<string>  $completedOptionalWalkRoots
     */
    private function __construct(
        public string $status,
        public array $varbinds,
        public ?int $latencyMs,
        public bool $partial,
        public array $completedOptionalWalkRoots,
    ) {
        if (! in_array($status, self::STATUSES, true)
            || ($latencyMs !== null && ($latencyMs < 0 || $latencyMs > 120_000))) {
            throw new InvalidArgumentException('SNMP transport result is invalid.');
        }

        foreach ($varbinds as $oid => $value) {
            if (! is_string($oid) || preg_match('/^\d+(?:\.\d+)+$/', $oid) !== 1
                || (! is_scalar($value) && $value !== null)
                || (is_string($value) && strlen($value) > 2048)) {
                throw new InvalidArgumentException('SNMP varbind is invalid or unbounded.');
            }
        }
        if (! array_is_list($completedOptionalWalkRoots)
            || count($completedOptionalWalkRoots) > SnmpQuery::MAX_OIDS
            || collect($completedOptionalWalkRoots)->unique()->count() !== count($completedOptionalWalkRoots)) {
            throw new InvalidArgumentException('SNMP optional walk evidence is invalid.');
        }
        foreach ($completedOptionalWalkRoots as $root) {
            if (! is_string($root) || preg_match('/^\d+(?:\.\d+)+$/', $root) !== 1) {
                throw new InvalidArgumentException('SNMP optional walk evidence is invalid.');
            }
        }
    }

    /**
     * @param  array<string, int|float|string|bool|null>  $varbinds
     * @param  list<string>  $completedOptionalWalkRoots
     */
    public static function success(
        array $varbinds,
        int $latencyMs,
        bool $partial = false,
        array $completedOptionalWalkRoots = [],
    ): self {
        return new self('ok', $varbinds, $latencyMs, $partial, $completedOptionalWalkRoots);
    }

    public static function failure(string $status, ?string $detail = null): self
    {
        unset($detail);

        return new self($status, [], null, false, []);
    }
}
