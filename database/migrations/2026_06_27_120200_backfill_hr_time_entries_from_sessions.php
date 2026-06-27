<?php

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Services\TimeTrackingService;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backend handoff §5 (historical) — backfill an HrTimeEntry for every closed
 * attendance session that predates the unified clock paths and never got one
 * (legacy /my-day clock-outs created a session + Operations timesheet but no
 * HrTimeEntry). Routes each through the same tested, idempotent
 * TimeTrackingService::syncEntryFromSession used by the live clock paths, so
 * historical entries surface in the Time Entries tab and HrTimeEntry-based KPIs.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Skip on a fresh/empty schema (e.g. RefreshDatabase in tests) — nothing
        // to backfill, and avoids resolving services before they're needed.
        if (! DB::getSchemaBuilder()->hasTable('hr_attendance_sessions')) {
            return;
        }

        /** @var TimeTrackingService $service */
        $service = app(TimeTrackingService::class);

        HrAttendanceSession::query()
            ->whereNotNull('clock_out_at')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('hr_time_entries')
                    ->whereColumn('hr_time_entries.attendance_session_id', 'hr_attendance_sessions.id');
            })
            ->with('shift')
            ->chunkById(200, function ($sessions) use ($service) {
                $userIds = $sessions->pluck('closed_by')
                    ->merge($sessions->pluck('user_id'))
                    ->filter()
                    ->unique();
                $users = User::query()->whereIn('id', $userIds)->get()->keyBy('id');

                foreach ($sessions as $session) {
                    $actor = $users[$session->closed_by] ?? $users[$session->user_id] ?? null;
                    if (! $actor) {
                        continue; // orphaned session with no resolvable actor — skip
                    }

                    // Idempotent: creates the missing entry (and closes it from the
                    // session's clock-out); a no-op if one already exists.
                    $service->syncEntryFromSession($session, $actor, ['notes' => $session->notes]);
                }
            });
    }

    public function down(): void
    {
        // Backfill only — backfilled rows are indistinguishable from organically
        // created ones, so there is no safe automatic reversal. No-op.
    }
};
