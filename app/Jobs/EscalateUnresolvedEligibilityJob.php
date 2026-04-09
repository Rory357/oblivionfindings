<?php

namespace App\Jobs;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Shift;
use App\Models\ShiftSignal;
use App\Models\User;
use App\Notifications\EligibilityEscalationNotification;
use App\Services\ShiftSignalService;
use App\Services\ShiftStaffEligibilityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Escalation companion to RecalculateFutureShiftEligibility.
 *
 * Finds future shifts that were flagged as ineligible and remain unresolved
 * (still assigned, still blocked) past a configurable threshold. Sends an
 * escalation notification up the management chain.
 *
 * Idempotency: uses a dedicated escalation signal type with a per-shift
 * window key so each shift is escalated at most once per threshold period.
 *
 * Does NOT auto-unassign staff — surfaces urgency for human action.
 */
class EscalateUnresolvedEligibilityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const ESCALATION_SIGNAL_TYPE = 'shift_eligibility_escalation';

    /**
     * How many hours a flagged shift must remain unresolved before escalation.
     */
    protected int $thresholdHours;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(int $thresholdHours = 24)
    {
        $this->thresholdHours = $thresholdHours;
    }

    public function handle(
        ShiftStaffEligibilityService $eligibility,
        ShiftSignalService $signals,
    ): void {
        $cutoff = now()->subHours($this->thresholdHours);
        $escalated = 0;

        // Find eligibility-changed signals older than threshold for shifts
        // that are still scheduled and assigned.
        $candidateSignals = ShiftSignal::query()
            ->where('signal_type', RecalculateFutureShiftEligibility::SIGNAL_TYPE)
            ->where('occurred_at', '<=', $cutoff)
            ->whereHas('shift', function ($q) {
                $q->where('status', 'scheduled')
                    ->whereNotNull('user_id')
                    ->where('starts_at', '>', now());
            })
            ->with(['shift.staff:id,name,email', 'shift.site:id,name'])
            ->get();

        foreach ($candidateSignals as $signal) {
            try {
                $shift = $signal->shift;

                if (! $shift || ! $shift->staff) {
                    continue;
                }

                // Re-validate: the issue may have been resolved without clearing the signal.
                $result = $eligibility->evaluate($shift, $shift->staff);

                if (! $result->hasBlocks()) {
                    // Resolved — no escalation needed.
                    continue;
                }

                // Emit escalation signal — emitForShift uses firstOrCreate
                // on idempotency_key, so repeated runs won't duplicate.
                $windowKey = 'escalation-' . $signal->occurred_at->toDateString();
                $escalationSignal = $signals->emitForShift(
                    shift: $shift,
                    signalType: self::ESCALATION_SIGNAL_TYPE,
                    severity: 'critical',
                    occurredAt: now(),
                    payload: [
                        'staff_name' => $shift->staff->name,
                        'blocking_reasons' => $result->blocking_reasons,
                        'original_signal_at' => $signal->occurred_at->toIso8601String(),
                        'hours_unresolved' => (int) $signal->occurred_at->diffInHours(now()),
                        'escalated_at' => now()->toIso8601String(),
                    ],
                    windowKey: $windowKey,
                );

                // Only notify if this is a new escalation (not a duplicate from a prior run).
                if (! $escalationSignal->wasRecentlyCreated) {
                    continue;
                }

                // Notify up the chain.
                $this->notifyEscalation($shift, $signal, $result->blocking_reasons);
                $escalated++;
            } catch (\Throwable $e) {
                Log::warning('EscalateUnresolvedEligibilityJob: failed to process signal', [
                    'signal_id' => $signal->id,
                    'shift_id' => $signal->shift_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($escalated > 0) {
            Log::info("EscalateUnresolvedEligibilityJob: escalated {$escalated} unresolved shift(s).");
        }
    }

    protected function notifyEscalation(Shift $shift, ShiftSignal $originalSignal, array $blockingReasons): void
    {
        $recipient = $this->resolveEscalationRecipient($shift);

        if (! $recipient) {
            return;
        }

        $hoursUnresolved = (int) $originalSignal->occurred_at->diffInHours(now());

        try {
            $recipient->notify(new EligibilityEscalationNotification(
                shiftId: $shift->id,
                staffName: $shift->staff?->name ?? 'Unknown',
                shiftDate: $shift->starts_at?->format('D j M, g:i A') ?? 'Unknown',
                siteName: $shift->site?->name ?? 'Unknown site',
                blockingReason: $blockingReasons[0] ?? 'Eligibility requirement not met',
                unresolvedSince: $originalSignal->occurred_at->format('D j M, g:i A'),
                hoursUnresolved: $hoursUnresolved,
            ));
        } catch (\Throwable $e) {
            Log::warning('Failed to send eligibility escalation notification', [
                'shift_id' => $shift->id,
                'recipient_id' => $recipient->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolve the escalation recipient:
     *   1. Manager's manager (if available)
     *   2. Direct manager (re-notify)
     *   3. Any provider_manager / admin (fallback)
     */
    protected function resolveEscalationRecipient(Shift $shift): ?User
    {
        $staffProfile = HrEmployeeProfile::where('user_id', $shift->user_id)
            ->where('is_active', true)
            ->first(['manager_user_id']);

        $directManager = $staffProfile?->manager_user_id
            ? User::find($staffProfile->manager_user_id)
            : null;

        // Try manager's manager first.
        if ($directManager) {
            $managerProfile = HrEmployeeProfile::where('user_id', $directManager->id)
                ->where('is_active', true)
                ->first(['manager_user_id']);

            $seniorManager = $managerProfile?->manager_user_id
                ? User::find($managerProfile->manager_user_id)
                : null;

            if ($seniorManager) {
                return $seniorManager;
            }

            // No senior manager — re-notify the direct manager as escalation.
            return $directManager;
        }

        // No hierarchy — fall back to provider_manager or admin.
        return User::whereHas('roles', fn ($q) => $q->whereIn('name', ['provider_manager', 'admin']))
            ->first();
    }

}
