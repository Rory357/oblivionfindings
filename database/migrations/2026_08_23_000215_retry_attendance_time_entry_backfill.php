<?php

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Services\TimeTrackingService;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Retry the historical attendance projection after the application payroll
 * mutex exists. The June migration runs earlier in migration order and safely
 * defers populated upgrades rather than bypassing current payroll/Site policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('hr_attendance_sessions')
            || ! DB::getSchemaBuilder()->hasTable('hr_payroll_run_mutexes')) {
            return;
        }

        /** @var TimeTrackingService $service */
        $service = app(TimeTrackingService::class);
        $receipt = ['projected' => 0, 'skipped' => []];

        HrAttendanceSession::query()
            ->whereNotNull('clock_out_at')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('hr_time_entries')
                    ->whereColumn('hr_time_entries.attendance_session_id', 'hr_attendance_sessions.id');
            })
            ->with('shift')
            ->chunkById(200, function ($sessions) use ($service, &$receipt): void {
                $userIds = $sessions->pluck('closed_by')
                    ->merge($sessions->pluck('user_id'))
                    ->filter()
                    ->unique();
                $users = User::query()->whereIn('id', $userIds)->get()->keyBy('id');

                foreach ($sessions as $session) {
                    $actor = $users[$session->closed_by] ?? $users[$session->user_id] ?? null;
                    $reason = null;
                    if (! $actor) {
                        $reason = 'No resolvable actor.';
                    } elseif (! is_numeric($session->site_id) || (int) $session->site_id < 1) {
                        $reason = 'Canonical captured Site is missing; migration will not infer one from mutable current data.';
                    } elseif ($this->hasProtectedTimesheet($session)) {
                        $reason = 'Approved or payroll-linked Timesheet evidence is immutable.';
                    }

                    if ($reason !== null) {
                        $this->recordSkip($receipt, (int) $session->id, $reason);

                        continue;
                    }

                    try {
                        $service->syncEntryFromSession($session, $actor, ['notes' => $session->notes]);
                        $receipt['projected']++;
                    } catch (LogicException|HttpExceptionInterface $exception) {
                        $this->recordSkip(
                            $receipt,
                            (int) $session->id,
                            trim($exception->getMessage()) ?: $exception::class,
                        );
                    }
                }
            });

        ksort($receipt['skipped']);
        Log::notice('HR attendance time-entry retry backfill completed.', [
            'projected_count' => $receipt['projected'],
            'skipped_count' => count($receipt['skipped']),
            'skipped_sessions' => $receipt['skipped'],
        ]);
    }

    public function down(): void
    {
        // Evidence-preserving backfill; no safe automatic reversal.
    }

    private function hasProtectedTimesheet(HrAttendanceSession $session): bool
    {
        return DB::table('timesheets')
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
    }

    /** @param array{projected: int, skipped: array<int, string>} $receipt */
    private function recordSkip(array &$receipt, int $sessionId, string $reason): void
    {
        $receipt['skipped'][$sessionId] = $reason;
        Log::warning('HR attendance time-entry retry backfill skipped a legacy session.', [
            'attendance_session_id' => $sessionId,
            'reason' => $reason,
        ]);
    }
};
