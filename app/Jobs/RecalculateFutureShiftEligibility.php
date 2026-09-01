<?php

namespace App\Jobs;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\ShiftEligibilityWarningNotification;
use App\Services\ShiftSignalService;
use App\Services\ShiftStaffEligibilityService;
use App\Services\UserSiteAccessService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
        $shift = $this->currentShift($shift);
        if (! $shift) {
            return;
        }

        $staffProfile = $this->currentProfileForUserId((int) $shift->user_id);
        $manager = $this->eligibleRecipientForShift(
            $staffProfile?->manager_user_id,
            $shift,
        );

        if (! $manager) {
            $fallbackIds = User::query()
                ->whereHas('roles', fn ($query) => $query->where('name', 'provider_manager'))
                ->orderBy('id')
                ->pluck('id');

            foreach ($fallbackIds as $fallbackId) {
                $manager = $this->eligibleRecipientForShift((int) $fallbackId, $shift);
                if ($manager) {
                    break;
                }
            }
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

    private function currentShift(Shift $shift): ?Shift
    {
        return Shift::query()->with([
            'staff:id,name,email',
            'site:id,name',
            'client:id,site_id',
        ])->find($shift->getKey());
    }

    private function currentProfileForUserId(int $userId): ?HrEmployeeProfile
    {
        $today = now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();

        return HrEmployeeProfile::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->where(function ($query) use ($today): void {
                $query->whereNull('start_date')->orWhereDate('start_date', '<=', $today);
            })
            ->where(function ($query) use ($today): void {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', $today);
            })
            ->first();
    }

    private function eligibleRecipientForShift(?int $userId, Shift $shift): ?User
    {
        if (! $userId) {
            return null;
        }

        $recipient = User::query()
            ->whereKey($userId)
            ->whereNotNull('approved_at')
            ->whereNotIn('role', ['client', 'next_of_kin'])
            ->whereDoesntHave('roles', fn ($query) => $query->whereIn('name', ['client', 'next_of_kin']))
            ->first();
        if (! $recipient) {
            return null;
        }

        try {
            (new UserSiteAccessService)->assertCanAccessShift($recipient, $shift);
        } catch (HttpExceptionInterface) {
            return null;
        }

        return $recipient;
    }
}
