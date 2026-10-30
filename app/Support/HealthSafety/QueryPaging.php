<?php

namespace App\Support\HealthSafety;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Drops paging from a cloned query so it can be reused as an unpaged subquery or proof read.
 *
 * `->limit(null)->offset(0)` no longer does this: Laravel 13 compiles any set offset, so it emits
 * `offset 0` with no `limit`, which MySQL rejects. Use `->when(true, QueryPaging::clear(...))`.
 */
final class QueryPaging
{
    public static function clear(EloquentBuilder|QueryBuilder $query): void
    {
        $base = $query instanceof EloquentBuilder ? $query->getQuery() : $query;
        $base->limit = null;
        $base->offset = null;
        $base->unionLimit = null;
        $base->unionOffset = null;
    }
}
