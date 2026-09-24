<?php

namespace App\Services\Fleet;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class MaintenanceRestrictionService
{
    /** A Maintenance assessment of one check: no issue found, released for use. */
    public const NO_ISSUE_RELEASE = 'no_issue_release';

    /** Whether the assessment table exists yet (deploys run code before migrations). */
    private ?bool $assessmentsReady = null;

    /** @return array{restriction_ids:list<int>,check_run_ids:list<int>} */
    public function blockers(int $assetId, bool $lock = false, array $ignoreRestrictionIds = [], array $ignoreRunIds = []): array
    {
        $restrictions = DB::table('fleet_maintenance_restrictions')->where('asset_id', $assetId)
            ->where('state', 'active')->when($ignoreRestrictionIds !== [], fn ($q) => $q->whereNotIn('id', $ignoreRestrictionIds))
            ->orderBy('id');
        $restrictionIds = ($lock ? $restrictions->lockForUpdate() : $restrictions)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $releaseQuery = DB::table('fleet_maintenance_actions as action')->join('fleet_work_orders as work', 'work.id', '=', 'action.work_order_id')
            ->where('work.asset_id', $assetId)->where('action.action_type', 'release')->orderByDesc('action.id');
        $lastRelease = ($lock ? $releaseQuery->lockForUpdate() : $releaseQuery)->value('action.occurred_at');
        $runs = DB::table('fleet_checklist_runs')->where('asset_id', $assetId)->whereNotNull('submitted_at')
            ->where(fn ($q) => $q->whereNull('outcome')->orWhere('outcome', '!=', 'passed'))
            ->when($lastRelease, fn ($q) => $q->where('submitted_at', '>=', $lastRelease))
            ->when($ignoreRunIds !== [], fn ($q) => $q->whereNotIn('id', $ignoreRunIds))->orderBy('id');
        $blockingRuns = ($lock ? $runs->lockForUpdate() : $runs)->get(['id', 'outcome', 'rule_version_id', 'rule_snapshot_json'])
            ->filter(fn ($run) => self::blocksAvailability($run))->pluck('id')->map(fn ($id) => (int) $id)->all();
        // A Maintenance "no issue found" assessment resolves exactly that check.
        $blockingRuns = array_values(array_diff($blockingRuns, $this->assessedRunIds($blockingRuns, $lock)));

        return ['restriction_ids' => $restrictionIds, 'check_run_ids' => $blockingRuns];
    }

    /**
     * Maintenance blockers for several vehicles in a fixed number of queries,
     * with the same semantics as blockers() (projection only, no locks).
     *
     * @param  list<int>  $assetIds
     * @return array<int, array{restriction_ids: list<int>, check_run_ids: list<int>}>
     */
    public function blockersMany(array $assetIds): array
    {
        $result = array_fill_keys($assetIds, ['restriction_ids' => [], 'check_run_ids' => []]);
        if ($assetIds === []) {
            return $result;
        }
        DB::table('fleet_maintenance_restrictions')->whereIn('asset_id', $assetIds)->where('state', 'active')
            ->orderBy('id')->get(['id', 'asset_id'])
            ->each(function (object $row) use (&$result): void {
                $result[(int) $row->asset_id]['restriction_ids'][] = (int) $row->id;
            });
        $latestRelease = DB::table('fleet_maintenance_actions as action')
            ->join('fleet_work_orders as work', 'work.id', '=', 'action.work_order_id')
            ->whereIn('work.asset_id', $assetIds)->where('action.action_type', 'release')
            ->groupBy('work.asset_id')->selectRaw('work.asset_id, MAX(action.id) as action_id');
        $releasedAt = DB::table('fleet_maintenance_actions as released')
            ->joinSub($latestRelease, 'latest', 'latest.action_id', '=', 'released.id')
            ->pluck('released.occurred_at', 'latest.asset_id');
        $candidates = [];
        DB::table('fleet_checklist_runs')->whereIn('asset_id', $assetIds)->whereNotNull('submitted_at')
            ->where(fn ($q) => $q->whereNull('outcome')->orWhere('outcome', '!=', 'passed'))
            ->orderBy('id')->get(['id', 'asset_id', 'submitted_at', 'outcome', 'rule_version_id', 'rule_snapshot_json'])
            ->each(function (object $run) use (&$candidates, $releasedAt): void {
                $release = $releasedAt[$run->asset_id] ?? null;
                if (($release === null || $run->submitted_at >= $release) && self::blocksAvailability($run)) {
                    $candidates[(int) $run->id] = (int) $run->asset_id;
                }
            });
        $assessed = array_flip($this->assessedRunIds(array_keys($candidates)));
        foreach ($candidates as $runId => $assetId) {
            if (! isset($assessed[$runId])) {
                $result[$assetId]['check_run_ids'][] = $runId;
            }
        }

        return $result;
    }

    /**
     * Checks Maintenance has assessed as "no issue found — release for use".
     * Under a lock this is a current read, so a decision committed while the
     * caller waited for the vehicle lock is seen.
     *
     * @param  list<int>  $runIds
     * @return list<int>
     */
    public function assessedRunIds(array $runIds, bool $lock = false): array
    {
        if ($runIds === [] || ! ($this->assessmentsReady ??= Schema::hasTable('fleet_maintenance_check_assessments'))) {
            return [];
        }
        $query = DB::table('fleet_maintenance_check_assessments')->whereIn('check_run_id', $runIds)
            ->where('decision', self::NO_ISSUE_RELEASE)->orderBy('check_run_id');

        return ($lock ? $query->lockForUpdate() : $query)->pluck('check_run_id')->map(fn ($id): int => (int) $id)->all();
    }

    /** Call only while holding the canonical asset row in the same transaction. */
    public function assertBookable(int $assetId): void
    {
        $blockers = $this->blockers($assetId, true);
        if ($blockers['restriction_ids'] !== []) {
            throw ValidationException::withMessages([
                'asset_id' => 'This asset has an active maintenance restriction. It must be released before booking.',
            ]);
        }

        // New failed or unassessed checks after the last authorised release
        // cannot silently become a Ready signal just because no hold row was
        // created yet. Old pre-migration rows have no submitted_at.
        if ($blockers['check_run_ids'] !== []) {
            throw ValidationException::withMessages([
                'asset_id' => 'A maintenance check needs assessment or repair before this asset can be booked.',
            ]);
        }
    }

    /** Advisory impact is effective only when it came from an approved, stored rule version. */
    public static function blocksAvailability(object $run): bool
    {
        if ($run->outcome === 'passed') {
            return false;
        }
        if (! $run->rule_version_id) {
            return true; // Unknown provenance needs assessment.
        }
        $snapshot = json_decode((string) $run->rule_snapshot_json, true);

        return ! is_array($snapshot) || ($snapshot['availability_impact'] ?? null) !== 'advisory';
    }
}
