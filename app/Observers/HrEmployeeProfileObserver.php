<?php

namespace App\Observers;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\AssetService;
use App\Domain\Hr\Services\HrNotificationService;
use App\Domain\Hr\Services\PositionService;
use Illuminate\Support\Facades\Log;

/**
 * Keeps `HrPosition.current_headcount` in step with reality as employees are
 * hired, deactivated, transferred between positions, or removed — the link that
 * was previously missing (PositionService::syncHeadcount had no callers).
 *
 * NOTE: model events do not fire on mass updates (the People bulk bar uses
 * `->update()`), so the scheduled vacancy check (`hr:check-vacancies`) is the
 * reconciling backstop for those paths.
 */
class HrEmployeeProfileObserver
{
    public function __construct(
        private readonly PositionService $positions,
        private readonly AssetService $assets,
        private readonly HrNotificationService $notifications,
    ) {}

    public function saved(HrEmployeeProfile $profile): void
    {
        $this->sync($profile->position_id);

        // A transfer changes the count of both the old and the new position.
        if ($profile->wasChanged('position_id')) {
            $this->sync($profile->getOriginal('position_id'));
        }

        // Offboarding loop: the moment an employee is deactivated, flag any
        // equipment they still hold so HR can recover it before they walk.
        if ($profile->wasChanged('is_active') && $profile->is_active === false) {
            $this->flagLeaverHeldAssets($profile);
        }
    }

    private function flagLeaverHeldAssets(HrEmployeeProfile $profile): void
    {
        try {
            $alerts = $this->assets->leaverHeldAlerts($profile);
            if ($alerts !== []) {
                $this->notifications->sendAssetAlerts($alerts);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to flag leaver-held assets on offboarding', [
                'profile_id' => $profile->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function deleted(HrEmployeeProfile $profile): void
    {
        $this->sync($profile->position_id);
    }

    public function restored(HrEmployeeProfile $profile): void
    {
        $this->sync($profile->position_id);
    }

    private function sync(int|string|null $positionId): void
    {
        if ($positionId) {
            $this->positions->syncHeadcount((int) $positionId);
        }
    }
}
