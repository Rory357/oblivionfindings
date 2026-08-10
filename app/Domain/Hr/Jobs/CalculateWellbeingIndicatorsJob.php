<?php

namespace App\Domain\Hr\Jobs;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrWellbeingIndicator;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Domain\Hr\Services\HrPerformanceAccessService;
use App\Domain\Hr\Services\WellbeingIndicatorService;
use App\Models\User;
use App\Notifications\StaffFatigueAlertNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CalculateWellbeingIndicatorsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $periodEnd = now();
        $periodStart = now()->subWeeks(4)->startOfDay();

        // Snapshot current flag levels before recalculation.
        $previousFlags = HrWellbeingIndicator::query()
            ->where('period_end', '>=', $periodStart->toDateString())
            ->whereIn('id', function ($query) use ($periodStart) {
                $query->selectRaw('MAX(id)')
                    ->from('hr_wellbeing_indicators')
                    ->where('period_end', '>=', $periodStart->toDateString())
                    ->groupBy('user_id');
            })
            ->pluck('flag_level', 'user_id')
            ->all();

        $service = app(WellbeingIndicatorService::class);

        $processed = $service->calculateAllIndicators(
            periodStart: $periodStart,
            periodEnd: $periodEnd,
        );

        Log::info("Wellbeing indicators calculated: {$processed} current employees processed.");

        // Notify managers for staff who have escalated to red.
        $this->notifyEscalations($previousFlags, $service);
    }

    private function notifyEscalations(array $previousFlags, WellbeingIndicatorService $service): void
    {
        $newRedFlags = $service->getApplicationFlaggedStaff('red');

        foreach ($newRedFlags as $flagged) {
            $userId = $flagged['user_id'];
            $previousLevel = $previousFlags[$userId] ?? 'none';

            // Only notify on escalation to red (not if already red).
            if ($previousLevel === 'red') {
                continue;
            }

            $staff = User::find($userId);
            if (! $staff) {
                continue;
            }

            $manager = $this->resolveManager($userId);
            if (! $manager) {
                continue;
            }

            try {
                $manager->notify(new StaffFatigueAlertNotification(
                    staffName: $flagged['name'] ?? $staff->name,
                    flagLevel: 'red',
                    triggeredRules: $flagged['triggered_rules'] ?? [],
                    userId: $userId,
                ));
            } catch (\Throwable $e) {
                Log::warning('Failed to send fatigue alert', [
                    'user_id' => $userId,
                    'manager_id' => $manager->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function resolveManager(int $userId): ?User
    {
        $profile = HrEmployeeProfile::where('user_id', $userId)
            ->where('is_active', true)
            ->first(['manager_user_id']);

        if ($profile?->manager_user_id) {
            $manager = User::find($profile->manager_user_id);

            return $manager && $this->canManageStaff($manager, $userId) ? $manager : null;
        }

        return app(HrCurrentStaffService::class)->currentUsersQuery()
            ->whereHas('roles', fn ($q) => $q->where('name', 'provider_manager'))
            ->get()
            ->first(fn (User $manager) => $this->canManageStaff($manager, $userId));
    }

    private function canManageStaff(User $manager, int $staffUserId): bool
    {
        if (! app(HrCurrentStaffService::class)->isCurrent($manager)) {
            return false;
        }

        try {
            app(HrPerformanceAccessService::class)->currentStaff($manager, $staffUserId);
        } catch (ModelNotFoundException) {
            return false;
        }

        return true;
    }
}
