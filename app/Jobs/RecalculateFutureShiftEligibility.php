<?php

namespace App\Jobs;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\ShiftEligibilityWarningNotification;
use App\Services\Eligibility\WorkforceEligibilityRefresh;
use App\Services\UserSiteAccessService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Nightly job that stages a bounded durable scan of assigned employee duties
 * and current evidence. When a shift is now blocked (e.g. cert
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

    public function handle(WorkforceEligibilityRefresh $refresh): void
    {
        $scan = $refresh->requestNightlyScan()->fresh();
        Log::info('Nightly workforce eligibility refresh staged', [
            'recheck_id' => $scan->id, 'staged_count' => $scan->scanned_count,
            'complete' => $scan->status === 'completed',
        ]);
    }

    public function notifyEligibilityReview(Shift $shift, array $reasons): void
    {
        $this->notifyManager($shift, $reasons);
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
                'exception_class' => $e::class,
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
