<?php

namespace App\Domain\Hr\Jobs;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrWellbeingIndicator;
use App\Domain\Hr\Services\WellbeingIndicatorService;
use App\Models\User;
use App\Notifications\StaffFatigueAlertNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class CalculateWellbeingIndicatorsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ?int $tenantId = null
    ) {}

    public function handle(): void
    {
        $tenantIds = $this->tenantId
            ? collect([$this->tenantId])
            : (
                Schema::hasColumn('users', 'tenant_id')
                    ? User::select('tenant_id')
                        ->whereNotNull('tenant_id')
                        ->distinct()
                        ->pluck('tenant_id')
                    : collect([null])
            );

        foreach ($tenantIds as $tenantId) {
            $this->calculateForTenant($tenantId !== null ? (int) $tenantId : null);
        }
    }

    private function calculateForTenant(?int $tenantId): void
    {
        $periodEnd = now();
        $periodStart = now()->subWeeks(4)->startOfDay();

        // Snapshot current flag levels before recalculation.
        $previousFlags = HrWellbeingIndicator::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('period_end', '>=', $periodStart->toDateString())
            ->pluck('flag_level', 'user_id')
            ->all();

        $service = app(WellbeingIndicatorService::class);

        $processed = $service->calculateAllIndicators(
            tenantId: $tenantId,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
        );

        Log::info("Wellbeing indicators calculated for tenant " . ($tenantId ?? 'global') . ": {$processed} employees processed.");

        // Notify managers for staff who have escalated to red.
        $this->notifyEscalations($tenantId, $previousFlags, $service);
    }

    private function notifyEscalations(?int $tenantId, array $previousFlags, WellbeingIndicatorService $service): void
    {
        $newRedFlags = $service->getFlaggedStaff($tenantId, 'red');

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
            return User::find($profile->manager_user_id);
        }

        return User::whereHas('roles', fn ($q) => $q->where('name', 'provider_manager'))
            ->first();
    }
}
