<?php

namespace App\Observers;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\PositionService;

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
    public function __construct(private readonly PositionService $positions) {}

    public function saved(HrEmployeeProfile $profile): void
    {
        $this->sync($profile->position_id);

        // A transfer changes the count of both the old and the new position.
        if ($profile->wasChanged('position_id')) {
            $this->sync($profile->getOriginal('position_id'));
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
