<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Explicit, callback-bounded current reads. Never changes connection isolation. */
final class CurrentAuthorizationReads
{
    private bool $active = true;

    private function __construct(private Connection $connection) {}

    public static function within(callable $decision): mixed
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Current authorization requires a transaction.');
        }
        $reads = new self(DB::connection());
        try {
            return $decision($reads);
        } finally {
            $reads->active = false;
        }
    }

    /** @template T of EloquentBuilder|Builder
     * @param  T  $query
     * @return T
     */
    public function query(EloquentBuilder|Builder $query): EloquentBuilder|Builder
    {
        $this->assertActive();
        $base = $query instanceof EloquentBuilder ? $query->getQuery() : $query;
        $this->assertConnection($base);
        $base->beforeQuery(fn (Builder $compiled) => $this->lockTree($compiled));

        return $query;
    }

    public function assertActive(): void
    {
        if (! $this->active || $this->connection !== DB::connection() || $this->connection->transactionLevel() < 1) {
            throw new LogicException('Current authorization evidence has left its transaction scope.');
        }
    }

    private function lockTree(Builder $query): void
    {
        $this->assertActive();
        $this->assertConnection($query);
        // An outer locking SELECT does not lock an EXISTS subquery. Apply an
        // explicit current read to each nested query block, including negatives.
        $query->lock('for share nowait');
        foreach ($query->wheres ?? [] as $where) {
            if (($where['query'] ?? null) instanceof Builder) {
                $this->lockTree($where['query']);
            }
        }
        foreach ($query->unions ?? [] as $union) {
            if (($union['query'] ?? null) instanceof Builder) {
                $this->lockTree($union['query']);
            }
        }
    }

    private function assertConnection(Builder $query): void
    {
        if ($query->getConnection() !== $this->connection || $query->getConnection()->transactionLevel() < 1) {
            throw new LogicException('Current authorization query uses a different transaction connection.');
        }
    }
}
