<?php

namespace App\Jobs;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\ShiftEligibilityWarningNotification;
use App\Services\ShiftSignalService;
use App\Services\ShiftStaffEligibilityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Nightly job that scans future scheduled shifts with assigned staff
 * and re-evaluates eligibility. When a shift is now blocked (e.g. cert
 * expired, leave approved after assignment), it emits a control-room
 * signal and notifies the relevant manager.
 *
 * Does NOT auto-unassign staff — surfaces the problem for human review.
 */
class RecalculateFutureShiftEligibility implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const SIGNAL_TYPE = 'shift_eligibility_changed';

    public int $tries = 1;

    public int $timeout = 300;

    public function handle(
        ShiftStaffEligibilityService $eligibility,
        ShiftSignalService $signals,
    ): void {
        $flagged = 0;
        $scanned = 0;

        Shift::query()
            ->whereIn('status', ['scheduled'])
            ->whereNotNull('user_id')
            ->where('starts_at', '>', now())
            ->where('starts_at', '<', now()->addDays(14))
            ->with(['staff:id,name,email', 'site:id,name'])
            ->chunkById(50, function ($shifts) use ($eligibility, $signals, &$flagged, &$scanned) {
                foreach ($shifts as $shift) {
                    $scanned++;

                    if (! $shift->staff) {
                        continue;
                    }

                    try {
                        $result = $eligibility->evaluate($shift, $shift->staff);
                    } catch (\Throwable $e) {
                        Log::warning('Future eligibility check failed', [
                            'shift_id' => $shift->id,
                            'error' => $e->getMessage(),
                        ]);
                        continue;
                    }

                    if (! $result->hasBlocks()) {
                        continue;
                    }

                    $flagged++;

                    // Emit control-room signal (idempotent — won't duplicate for same shift+type+date).
                    $signals->emitForShift(
                        shift: $shift,
                        signalType: self::SIGNAL_TYPE,
                        severity: 'high',
                        occurredAt: now(),
                        payload: [
                            'staff_name' => $shift->staff->name,
                            'blocking_reasons' => $result->blocking_reasons,
                            'checked_at' => now()->toIso8601String(),
                        ],
                        windowKey: now()->toDateString(),
                    );

                    // Notify the staff member's manager, or fall back to provider managers.
                    $this->notifyManager($shift, $result->blocking_reasons);
                }
            });

        Log::info("Future shift eligibility scan complete: {$scanned} scanned, {$flagged} flagged.");
    }

    protected function notifyManager(Shift $shift, array $blockingReasons): void
    {
        $staffProfile = HrEmployeeProfile::where('user_id', $shift->user_id)
            ->where('is_active', true)
            ->first(['manager_user_id']);

        $manager = $staffProfile?->manager_user_id
            ? User::find($staffProfile->manager_user_id)
            : null;

        // Fall back to any provider_manager if no direct manager.
        if (! $manager) {
            $manager = User::whereHas('roles', fn ($q) => $q->where('name', 'provider_manager'))
                ->first();
        }

        if (! $manager) {
            return;
        }

        $notification = new ShiftEligibilityWarningNotification(
            shiftId: $shift->id,
            staffName: $shift->staff?->name ?? 'Unknown',
            shiftDate: $shift->starts_at?->format('D j M, g:i A') ?? 'Unknown',
            siteName: $shift->site?->name ?? 'Unknown site',
            blockingReasons: $blockingReasons,
        );

        try {
            $manager->notify($notification);
        } catch (\Throwable $e) {
            Log::warning('Failed to send eligibility warning notification', [
                'shift_id' => $shift->id,
                'manager_id' => $manager->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
