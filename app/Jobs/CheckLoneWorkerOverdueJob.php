<?php

namespace App\Jobs;

use App\Models\LoneWorkerSession;
use App\Services\HealthSafety\LoneWorkerSignalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled job to detect lone worker overdue check-ins and session overruns.
 *
 * Runs every 5 minutes. For each active session:
 * - If check-in is overdue → emits lone_worker_overdue_checkin signal
 * - If session has overrun expected end time → emits lone_worker_session_overrun signal
 *
 * Also updates session status to 'overdue' when check-in is overdue,
 * maintaining consistency with the LoneWorkerSession state machine.
 */
class CheckLoneWorkerOverdueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(LoneWorkerSignalService $signalService): void
    {
        // Only check active and overdue sessions.
        // Emergency sessions are excluded — they already have a higher-severity
        // canonical alert and should not generate overdue noise on top.
        // Completed sessions are excluded — they have ended normally.
        $activeSessions = LoneWorkerSession::whereIn('status', ['active', 'overdue'])
            ->with(['user:id,name', 'site:id,name', 'client:id,first_name,last_name'])
            ->get();

        $overdueCount = 0;
        $overrunCount = 0;

        foreach ($activeSessions as $session) {
            // Check for overdue check-in
            if ($session->isCheckInOverdue()) {
                $lastCheckIn = $session->last_check_in_at ?? $session->started_at;
                $minutesOverdue = (int) $lastCheckIn
                    ->addMinutes($session->check_in_interval_minutes)
                    ->diffInMinutes(now());

                // Update session status to overdue if still active
                if ($session->status === 'active') {
                    $session->update(['status' => 'overdue']);
                }

                // Emit signal → Control Room
                $signalService->emitOverdueCheckIn($session, $minutesOverdue);
                $overdueCount++;
            }

            // Check for session overrun (past expected end time, still active)
            if ($session->expected_end_at && $session->expected_end_at->isPast()) {
                $minutesOverrun = (int) $session->expected_end_at->diffInMinutes(now());

                $signalService->emitSessionOverrun($session, $minutesOverrun);
                $overrunCount++;
            }
        }

        if ($overdueCount > 0 || $overrunCount > 0) {
            Log::info('CheckLoneWorkerOverdueJob: completed', [
                'sessions_checked' => $activeSessions->count(),
                'overdue_check_ins' => $overdueCount,
                'session_overruns' => $overrunCount,
            ]);
        }
    }
}
