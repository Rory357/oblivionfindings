<?php

namespace App\Domain\It\Services;

use App\Models\ItAutomationRun;
use Illuminate\Database\Eloquent\Builder;

/** Distinguish successful bounded execution from completion of the owning workflow. */
final class ItAutomationRunOutcome
{
    public static function state(ItAutomationRun $run): string
    {
        if ($run->status === 'running') {
            return $run->finished_at === null ? 'running' : 'unknown';
        }
        if ($run->status === 'failed' || $run->status === 'skipped') {
            return $run->status;
        }
        if ($run->status !== 'succeeded' || $run->finished_at === null) {
            return 'unknown';
        }
        if ($run->automation_key !== 'it.poll-mailbox') {
            return 'succeeded';
        }
        $counts = self::mailboxCounts($run);
        if ($counts === null) {
            return 'unknown';
        }

        return match (true) {
            $counts['failed'] > 0 => 'failed',
            $counts['pending'] > 0 => 'pending',
            $counts['skipped'] > 0 => 'skipped',
            $counts['connections'] === 0 => 'no_work',
            default => 'succeeded',
        };
    }

    public static function mailboxCounts(ItAutomationRun $run): ?array
    {
        if ($run->automation_key !== 'it.poll-mailbox' || ! is_array($run->result_summary)) {
            return null;
        }
        $counts = array_intersect_key($run->result_summary, array_flip(['connections', 'failed', 'skipped', 'pending']));
        foreach (['connections', 'failed', 'skipped', 'pending'] as $key) {
            if (! is_int($counts[$key] ?? null) || $counts[$key] < 0) {
                return null;
            }
        }
        if ($counts['connections'] < $counts['failed'] + $counts['skipped'] + $counts['pending']) {
            return null;
        }

        return $counts;
    }

    /** Match the same strict historical evidence as state(), without scanning an unbounded run log. */
    public static function verifiedSuccess(Builder $query, string $key): Builder
    {
        $query->where('status', 'succeeded')->whereNotNull('finished_at');
        if ($key === 'it.poll-mailbox') {
            return self::whereState($query, ['succeeded']);
        }

        return $query;
    }

    /** Search only the public outcome, not arbitrary retained JSON or exception text. */
    public static function whereState(Builder $query, array $states): Builder
    {
        if ($states === []) {
            return $query->whereRaw('1 = 0');
        }
        $valid = [];
        foreach (['connections', 'failed', 'skipped', 'pending'] as $field) {
            $value = "JSON_EXTRACT(result_summary, '$.{$field}')";
            $valid[] = "(JSON_TYPE({$value}) = 'INTEGER' AND {$value} >= 0 AND {$value} <= ".PHP_INT_MAX.')';
        }
        $valid[] = "JSON_EXTRACT(result_summary, '$.failed') + JSON_EXTRACT(result_summary, '$.skipped') + JSON_EXTRACT(result_summary, '$.pending') <= JSON_EXTRACT(result_summary, '$.connections')";
        $validCounts = implode(' AND ', $valid);
        $outcome = "CASE
            WHEN status = 'running' THEN CASE WHEN finished_at IS NULL THEN 'running' ELSE 'unknown' END
            WHEN status IN ('failed', 'skipped') THEN status
            WHEN status = 'succeeded' AND finished_at IS NOT NULL THEN CASE
                WHEN automation_key <> 'it.poll-mailbox' THEN 'succeeded'
                WHEN NOT COALESCE(({$validCounts}), 0) THEN 'unknown'
                WHEN JSON_EXTRACT(result_summary, '$.failed') > 0 THEN 'failed'
                WHEN JSON_EXTRACT(result_summary, '$.pending') > 0 THEN 'pending'
                WHEN JSON_EXTRACT(result_summary, '$.skipped') > 0 THEN 'skipped'
                WHEN JSON_EXTRACT(result_summary, '$.connections') = 0 THEN 'no_work'
                ELSE 'succeeded' END
            ELSE 'unknown' END";

        return $query->whereRaw('('.$outcome.') IN ('.implode(',', array_fill(0, count($states), '?')).')', array_values($states));
    }
}
