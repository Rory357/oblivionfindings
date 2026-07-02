<?php

namespace App\Services\References;

use Illuminate\Support\Facades\DB;

/**
 * Central, race-safe reference (ticket) number allocator.
 *
 * Numbers are allocated from the `reference_sequences` table under a
 * row-level lock, so two concurrent creates can never mint the same
 * reference — unlike the legacy per-model MAX()+1 / COUNT()+1 scans
 * this service replaces.
 *
 * Formats:
 *  - next('INC')          → INC-2026-0001  (year-scoped, resets each year)
 *  - nextGlobal('HR', 5)  → HR-00001       (single global sequence)
 */
class ReferenceNumberGenerator
{
    public function next(string $prefix, ?int $year = null, int $pad = 4): string
    {
        $year ??= now()->year;
        $value = $this->allocate("{$prefix}-{$year}");

        return sprintf('%s-%d-%0'.$pad.'d', $prefix, $year, $value);
    }

    public function nextGlobal(string $prefix, int $pad = 5): string
    {
        return sprintf('%s-%0'.$pad.'d', $prefix, $this->allocate($prefix));
    }

    /**
     * Allocate the next integer for a scope, creating the sequence row on
     * first use.
     *
     * Uses the single-statement MySQL sequence idiom
     * (UPDATE ... SET x = LAST_INSERT_ID(x) + 1) which takes only an
     * exclusive record lock — unlike insertOrIgnore + SELECT ... FOR UPDATE,
     * whose shared-then-exclusive lock upgrade deadlocks two concurrent
     * allocators of the same scope. LAST_INSERT_ID() is connection-scoped,
     * and no wrapping transaction is needed (inside an outer transaction a
     * rollback simply reverts the increment — gaps are fine, duplicates
     * are impossible because the row lock is held to the outer commit).
     */
    private function allocate(string $scope): int
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $claimed = DB::update(
                'update reference_sequences set next_value = last_insert_id(next_value) + 1, updated_at = ? where scope = ?',
                [now(), $scope],
            );

            if ($claimed > 0) {
                return (int) DB::scalar('select last_insert_id()');
            }

            // First use of this scope: claim 1 by inserting the row with the
            // sequence already advanced past it.
            $inserted = DB::table('reference_sequences')->insertOrIgnore([
                'scope' => $scope,
                'next_value' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted > 0) {
                return 1;
            }

            // Lost the first-use race to a concurrent insert — retry the UPDATE.
        }

        throw new \RuntimeException("Unable to allocate a reference number for scope [{$scope}].");
    }

    /**
     * Raise a scope's floor so future allocations start above numbers that
     * already exist in the wild (backfills, imports). Never lowers it.
     */
    public function ensureAtLeast(string $scope, int $nextValue): void
    {
        DB::transaction(function () use ($scope, $nextValue): void {
            DB::table('reference_sequences')->insertOrIgnore([
                'scope' => $scope,
                'next_value' => $nextValue,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('reference_sequences')
                ->where('scope', $scope)
                ->where('next_value', '<', $nextValue)
                ->update(['next_value' => $nextValue, 'updated_at' => now()]);
        });
    }
}
