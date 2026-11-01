<?php

namespace App\Support\HealthSafety;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use LogicException;

/**
 * Test-only parity check for the per-request proven id sets (speed option B, Stephan 30 Oct 2026). When enabled (the
 * test base class turns it on), every scope that uses a proven set also runs the original embedded scope on the same
 * query and fails loudly if the two admit different rows. Off in production: no extra queries there.
 */
final class ProofParity
{
    public static bool $enabled = false;

    /** Compare the rows two scoped queries admit; a query that can't stand alone (a correlated subquery) is skipped. */
    public static function assertSameRows(Builder $proven, Builder $embedded, string $column, string $label): void
    {
        $left = self::ids($proven, $column);
        $right = self::ids($embedded, $column);
        if ($left === null || $right === null) {
            return;
        }
        if ($left !== $right) {
            throw new LogicException($label.': proven-set scope admits ['.implode(',', $left).'] but the embedded scope admits ['.implode(',', $right).'].');
        }
    }

    /** @return list<int>|null */
    private static function ids(Builder $query, string $column): ?array
    {
        $base = (clone $query)->setEagerLoads([])->getQuery()
            ->cloneWithout(['columns', 'groups', 'havings', 'orders', 'limit', 'offset', 'aggregate'])
            ->cloneWithoutBindings(['select', 'having', 'order']);
        try {
            $ids = $base->select($column)->pluck(last(explode('.', $column)))->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
        } catch (QueryException) {
            return null;
        }

        return $ids;
    }
}
