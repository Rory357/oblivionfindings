<?php

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Services\TimeTrackingService;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Backend handoff §5 (historical) — backfill an HrTimeEntry for every closed
 * attendance session that predates the unified clock paths and never got one
 * (legacy /my-day clock-outs created a session + Operations timesheet but no
 * HrTimeEntry). Routes each through the same canonical compatibility delegate;
 * strict current policy may safely defer a row to the post-mutex replay rather
 * than weakening or fabricating historical payroll/Site evidence.
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
        $receipt = [
            'projected' => 0,
            'skipped' => [],
        ];

        HrAttendanceSession::query()
            ->whereNotNull('clock_out_at')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('hr_time_entries')
                    ->whereColumn('hr_time_entries.attendance_session_id', 'hr_attendance_sessions.id');
            })
            ->with('shift')
            ->chunkById(200, function ($sessions) use ($service, &$receipt) {
                $userIds = $sessions->pluck('closed_by')
                    ->merge($sessions->pluck('user_id'))
                    ->filter()
                    ->unique();
                $users = User::query()->whereIn('id', $userIds)->get()->keyBy('id');

                foreach ($sessions as $session) {
                    $actor = $users[$session->closed_by] ?? $users[$session->user_id] ?? null;
                    if (! $actor) {
                        $receipt['skipped'][(int) $session->id] = 'No resolvable actor.';
                        Log::warning('HR attendance time-entry backfill skipped a legacy session.', [
                            'attendance_session_id' => (int) $session->id,
                            'reason' => 'No resolvable actor.',
                        ]);

                        continue;
                    }

                    if (! is_numeric($session->site_id) || (int) $session->site_id < 1) {
                        $reason = 'Canonical captured Site is missing; migration will not infer one from mutable current data.';
                        $receipt['skipped'][(int) $session->id] = $reason;
                        Log::warning('HR attendance time-entry backfill skipped a legacy session.', [
                            'attendance_session_id' => (int) $session->id,
                            'reason' => $reason,
                        ]);

                        continue;
                    }

                    $protectedTimesheet = DB::table('timesheets')
                        ->where(function ($query) use ($session): void {
                            $query->where('attendance_session_id', $session->id);
                            if ($session->shift_id) {
                                $query->orWhere(function ($fallback) use ($session): void {
                                    $fallback
                                        ->where('shift_id', $session->shift_id)
                                        ->where('user_id', $session->user_id);
                                });
                            }
                        })
                        ->where(function ($query): void {
                            $query->where('status', 'approved')
                                ->orWhereNotNull('payroll_reference')
                                ->orWhereNotNull('exported_to_payroll_at');
                        })
                        ->exists();
                    if ($protectedTimesheet) {
                        $reason = 'Approved or payroll-linked Timesheet evidence is immutable.';
                        $receipt['skipped'][(int) $session->id] = $reason;
                        Log::warning('HR attendance time-entry backfill skipped a legacy session.', [
                            'attendance_session_id' => (int) $session->id,
                            'reason' => $reason,
                        ]);

                        continue;
                    }

                    // Idempotent: creates the missing entry (and closes it from the
                    // session's clock-out); a no-op if one already exists.
                    try {
                        $service->syncEntryFromSession($session, $actor, ['notes' => $session->notes]);
                        $receipt['projected']++;
                    } catch (QueryException $exception) {
                        if (! str_contains($exception->getMessage(), 'hr_payroll_run_mutexes')) {
                            throw $exception;
                        }

                        $reason = 'Application payroll mutex is not available at this historical migration point.';
                        $receipt['skipped'][(int) $session->id] = $reason;
                        Log::warning('HR attendance time-entry backfill skipped a legacy session.', [
                            'attendance_session_id' => (int) $session->id,
                            'reason' => $reason,
                        ]);
                    } catch (LogicException|HttpExceptionInterface $exception) {
                        // Current live policy is deliberately stricter than old
                        // data. Never weaken it or mutate/fabricate payroll/Site
                        // evidence during an upgrade; record a deterministic
                        // migration receipt and continue with the next row.
                        $reason = trim($exception->getMessage()) ?: $exception::class;
                        $receipt['skipped'][(int) $session->id] = $reason;
                        Log::warning('HR attendance time-entry backfill skipped a legacy session.', [
                            'attendance_session_id' => (int) $session->id,
                            'reason' => $reason,
                        ]);
                    }
                }
            });

        ksort($receipt['skipped']);
        Log::notice('HR attendance time-entry backfill completed.', [
            'projected_count' => $receipt['projected'],
            'skipped_count' => count($receipt['skipped']),
            'skipped_sessions' => $receipt['skipped'],
        ]);
    }

    public function down(): void
    {
        // Backfill only — backfilled rows are indistinguishable from organically
        // created ones, so there is no safe automatic reversal. No-op.
    }
};
