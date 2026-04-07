<?php

namespace App\Services;

use App\Models\Shift;
use Illuminate\Validation\ValidationException;

class ShiftStateGuardService
{
    /**
     * Only planning states are allowed from create/edit/calendar flows.
     *
     * @return array<int, string>
     */
    public function planningStatuses(): array
    {
        return ['draft', 'scheduled'];
    }

    public function normalizePlanningStatus(?string $requestedStatus, bool $hasAssignee): string
    {
        $status = $requestedStatus ?: ($hasAssignee ? 'scheduled' : 'draft');

        if (! in_array($status, $this->planningStatuses(), true)) {
            throw ValidationException::withMessages([
                'status' => 'Planning screens can only create or edit shifts in draft or scheduled state. Use the lifecycle actions to start, complete, or cancel a shift.',
            ]);
        }

        if (! $hasAssignee && $status === 'scheduled') {
            return 'draft';
        }

        return $status;
    }

    public function assertEditableFromPlanning(Shift $shift, ?string $requestedStatus): void
    {
        if (! in_array($shift->status, $this->planningStatuses(), true)) {
            throw ValidationException::withMessages([
                'status' => 'This shift is already in a live or locked lifecycle state. Use the dedicated lifecycle actions instead of a planning edit.',
            ]);
        }

        if ($requestedStatus === null || $requestedStatus === $shift->status) {
            return;
        }

        if (! in_array($requestedStatus, $this->planningStatuses(), true)) {
            throw ValidationException::withMessages([
                'status' => 'Shift status must be changed using the dedicated lifecycle actions. Planning edits cannot directly start, complete, or cancel a shift.',
            ]);
        }
    }
}
