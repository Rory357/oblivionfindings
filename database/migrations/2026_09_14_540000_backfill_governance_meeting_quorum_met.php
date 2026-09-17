<?php

use App\Domain\Governance\Models\GovernanceMeeting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data repair: a meeting's stored `quorum_met` was never updated when
 * attendance was recorded, so every held meeting read "quorum not met" in
 * the Meetings register. Recording attendance now keeps it in step; this
 * sets it for meetings whose recorded attendance already met the quorum.
 * Only false → true, using the same calculation the meeting workspace shows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('governance_meetings') || ! Schema::hasTable('meeting_attendances')) {
            return;
        }

        GovernanceMeeting::query()
            ->where('quorum_met', false)
            ->whereHas('attendances')
            ->select(['id', 'board_committee_id', 'chair_id', 'secretary_id', 'quorum_required'])
            ->chunkById(100, function ($meetings): void {
                foreach ($meetings as $meeting) {
                    try {
                        if ($meeting->calculateQuorum()['met'] ?? false) {
                            // Query builder: no timestamps or change log for a data repair.
                            DB::table('governance_meetings')->where('id', $meeting->id)->update(['quorum_met' => true]);
                        }
                    } catch (\Throwable) {
                        // Leave the stored value if this meeting can't be recalculated.
                    }
                }
            });
    }

    public function down(): void
    {
        // A data repair: the earlier stale values can't be restored, and don't need to be.
    }
};
