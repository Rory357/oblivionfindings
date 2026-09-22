<?php

namespace App\Services\Fleet;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaintenanceRestrictionService
{
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
        return ['restriction_ids' => $restrictionIds, 'check_run_ids' => $blockingRuns];
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
