<?php

namespace App\Services\Fleet;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaintenanceRestrictionService
{
    /** Call only while holding the canonical asset row in the same transaction. */
    public function assertBookable(int $assetId): void
    {
        if (DB::table('fleet_maintenance_restrictions')
            ->where('asset_id', $assetId)->where('state', 'active')
            ->lockForUpdate()->first(['id'])) {
            throw ValidationException::withMessages([
                'asset_id' => 'This asset has an active maintenance restriction. It must be released before booking.',
            ]);
        }

        // New failed or unassessed checks after the last authorised release
        // cannot silently become a Ready signal just because no hold row was
        // created yet. Old pre-migration rows have no submitted_at.
        $lastRelease = DB::table('fleet_maintenance_actions as action')
            ->join('fleet_work_orders as work', 'work.id', '=', 'action.work_order_id')
            ->where('work.asset_id', $assetId)->where('action.action_type', 'release')
            ->orderByDesc('action.id')->lockForUpdate()->first(['action.occurred_at'])?->occurred_at;
        $unresolved = DB::table('fleet_checklist_runs')->where('asset_id', $assetId)
            ->whereNotNull('submitted_at')
            ->where(fn ($query) => $query->whereNull('outcome')->orWhere('outcome', '!=', 'passed'));
        if ($lastRelease) {
            $unresolved->where('submitted_at', '>=', $lastRelease);
        }
        if ($unresolved->lockForUpdate()->get(['outcome', 'rule_version_id', 'rule_snapshot_json'])
            ->contains(fn ($run) => self::blocksAvailability($run))) {
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
