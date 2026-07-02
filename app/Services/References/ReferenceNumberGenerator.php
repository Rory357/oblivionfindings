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
     * first use. The SELECT ... FOR UPDATE serialises concurrent allocations.
     */
    private function allocate(string $scope): int
    {
        return DB::transaction(function () use ($scope): int {
            DB::table('reference_sequences')->insertOrIgnore([
                'scope' => $scope,
                'next_value' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $current = (int) DB::table('reference_sequences')
                ->where('scope', $scope)
                ->lockForUpdate()
                ->value('next_value');

            DB::table('reference_sequences')
                ->where('scope', $scope)
                ->update(['next_value' => $current + 1, 'updated_at' => now()]);

            return $current;
        });
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
