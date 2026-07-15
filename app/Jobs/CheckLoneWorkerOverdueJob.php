<?php

namespace App\Jobs;

use App\Models\LoneWorkerSession;
use App\Services\HealthSafety\LoneWorkerSignalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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
        $overdueCount = 0;
        $overrunCount = 0;
        $sessionsChecked = 0;

        LoneWorkerSession::query()
            ->whereIn('status', ['active', 'overdue'])
            ->select('id')
            ->chunkById(100, function ($sessions) use (
                $signalService,
                &$overdueCount,
                &$overrunCount,
                &$sessionsChecked,
            ): void {
                foreach ($sessions as $candidate) {
                    $sessionsChecked++;

                    try {
                        $outcome = DB::transaction(function () use ($candidate, $signalService): array {
                            $session = LoneWorkerSession::query()
                                ->whereKey($candidate->id)
                                ->lockForUpdate()
                                ->first();
                            if (! $session || ! in_array($session->status, ['active', 'overdue'], true)) {
                                return ['overdue' => 0, 'overrun' => 0];
                            }

                            $overdue = 0;
                            $overrun = 0;
                            $lastCheckIn = ($session->last_check_in_at ?? $session->started_at)->copy();
                            $checkInDeadline = $lastCheckIn->addMinutes($session->check_in_interval_minutes);
                            if ($session->status === 'active' && $checkInDeadline->isPast()) {
                                $minutesOverdue = (int) $checkInDeadline->diffInMinutes(now());
                                $session->update(['status' => 'overdue']);
                                $signalService->emitOverdueCheckIn($session, $minutesOverdue);
                                $overdue = 1;
                            }

                            if ($session->expected_end_at && $session->expected_end_at->isPast()) {
                                $minutesOverrun = (int) $session->expected_end_at->diffInMinutes(now());
                                $signalService->emitSessionOverrun($session, $minutesOverrun);
                                $overrun = 1;
                            }

                            return ['overdue' => $overdue, 'overrun' => $overrun];
                        }, 3);

                        $overdueCount += $outcome['overdue'];
                        $overrunCount += $outcome['overrun'];
                    } catch (Throwable $exception) {
                        Log::error('CheckLoneWorkerOverdueJob: session failed', [
                            'session_id' => $candidate->id,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            });

        if ($overdueCount > 0 || $overrunCount > 0) {
            Log::info('CheckLoneWorkerOverdueJob: completed', [
                'sessions_checked' => $sessionsChecked,
                'overdue_check_ins' => $overdueCount,
                'session_overruns' => $overrunCount,
            ]);
        }
    }
}
