<?php

namespace App\Services;

use App\Models\Shift;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class ShiftConflictService
{
    public const TIGHT_TURNAROUND_MINUTES = 30;

    /**
     * @param  Collection<int, Shift>|null  $preloadedShifts  Optional batch-loaded
     *   set of the user's active shifts (non-cancelled/non-completed, with client).
     *   When provided, the overlap predicate is applied in PHP instead of issuing
     *   a fresh query — used by the eligibility batch loader to avoid a per-pair
     *   query. When null, the original query path is used unchanged.
     */
    public function findBlockingStaffConflicts(
        ?int $userId,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?int $ignoreShiftId = null,
        ?Collection $preloadedShifts = null,
    ): Collection {
        if (! $userId) {
            return collect();
        }

        if ($preloadedShifts !== null) {
            return $preloadedShifts
                ->filter(fn (Shift $shift) => (! $ignoreShiftId || (int) $shift->id !== (int) $ignoreShiftId)
                    && $shift->starts_at
                    && $shift->ends_at
                    && $shift->starts_at->lt($endsAt)
                    && $shift->ends_at->gt($startsAt))
                ->sortBy('starts_at')
                ->values();
        }

        return Shift::query()
            ->when($ignoreShiftId, fn ($query) => $query->where('id', '!=', $ignoreShiftId))
            ->where('user_id', $userId)
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->with(['client:id,first_name,last_name'])
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * @param  Collection<int, Shift>|null  $preloadedShifts  See
     *   findBlockingStaffConflicts(); same opt-in batch path. When null the
     *   original query path is used unchanged.
     */
    public function findTightTurnaroundWarnings(
        ?int $userId,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?int $ignoreShiftId = null,
        int $bufferMinutes = self::TIGHT_TURNAROUND_MINUTES,
        ?Collection $preloadedShifts = null,
    ): Collection {
        if (! $userId) {
            return collect();
        }

        $bufferStart = $startsAt->copy()->subMinutes($bufferMinutes);
        $bufferEnd = $endsAt->copy()->addMinutes($bufferMinutes);

        if ($preloadedShifts !== null) {
            return $preloadedShifts
                ->filter(function (Shift $shift) use ($ignoreShiftId, $bufferStart, $startsAt, $endsAt, $bufferEnd) {
                    if ($ignoreShiftId && (int) $shift->id === (int) $ignoreShiftId) {
                        return false;
                    }

                    // Mirror the SQL: ends_at BETWEEN bufferStart AND startsAt
                    // OR starts_at BETWEEN endsAt AND bufferEnd (inclusive bounds).
                    $endsInBefore = $shift->ends_at
                        && $shift->ends_at->gte($bufferStart)
                        && $shift->ends_at->lte($startsAt);
                    $startsInAfter = $shift->starts_at
                        && $shift->starts_at->gte($endsAt)
                        && $shift->starts_at->lte($bufferEnd);

                    return $endsInBefore || $startsInAfter;
                })
                ->sortBy('starts_at')
                ->values();
        }

        return Shift::query()
            ->when($ignoreShiftId, fn ($query) => $query->where('id', '!=', $ignoreShiftId))
            ->where('user_id', $userId)
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->where(function ($query) use ($bufferStart, $startsAt, $endsAt, $bufferEnd) {
                $query
                    ->whereBetween('ends_at', [$bufferStart, $startsAt])
                    ->orWhereBetween('starts_at', [$endsAt, $bufferEnd]);
            })
            ->with(['client:id,first_name,last_name'])
            ->orderBy('starts_at')
            ->get();
    }

    public function findClientOverlapWarnings(
        ?int $clientId,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?int $ignoreShiftId = null,
    ): Collection {
        if (! $clientId) {
            return collect();
        }

        return Shift::query()
            ->when($ignoreShiftId, fn ($query) => $query->where('id', '!=', $ignoreShiftId))
            ->where('client_id', $clientId)
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->with(['staff:id,name'])
            ->orderBy('starts_at')
            ->get();
    }

    public function blockingMessage(Collection $conflicts): string
    {
        $first = $conflicts->first();
        if (! $first instanceof Shift) {
            return 'This staff member already has another shift during that time.';
        }

        $clientName = trim(($first->client?->first_name ?? '').' '.($first->client?->last_name ?? ''));
        $window = $this->formatWindow($first);

        return collect([
            'This staff member already has another shift during that time.',
            $clientName !== '' ? 'Client: '.$clientName : null,
            $window !== '' ? 'Existing shift: '.$window : null,
        ])->filter()->implode(' ');
    }

    public function overlapWarningsMessage(Collection $warnings): ?string
    {
        if ($warnings->isEmpty()) {
            return null;
        }

        $count = $warnings->count();
        $first = $warnings->first();

        $detail = $first instanceof Shift && $first->staff?->name
            ? ' Existing overlap includes '.$first->staff->name.'.'
            : '';

        return $count === 1
            ? 'This client already has another overlapping shift scheduled.'.$detail
            : "This client already has {$count} overlapping shifts scheduled.".$detail;
    }

    public function tightTurnaroundMessage(Collection $warnings): string
    {
        $first = $warnings->first();

        if (! $first instanceof Shift) {
            return 'This staff member has another shift too close to this one, leaving very little handover or travel time.';
        }

        $clientName = trim(($first->client?->first_name ?? '').' '.($first->client?->last_name ?? ''));
        $window = $this->formatWindow($first);

        return collect([
            'This staff member has another shift within '.self::TIGHT_TURNAROUND_MINUTES.' minutes of this one.',
            $clientName !== '' ? 'Nearby shift client: '.$clientName : null,
            $window !== '' ? 'Nearby shift: '.$window : null,
        ])->filter()->implode(' ');
    }

    protected function formatWindow(Shift $shift): string
    {
        if (! $shift->starts_at || ! $shift->ends_at) {
            return '';
        }

        $sameDay = $shift->starts_at->toDateString() === $shift->ends_at->toDateString();

        if ($sameDay) {
            return $shift->starts_at->format('D j M g:i A').' - '.$shift->ends_at->format('g:i A');
        }

        return $shift->starts_at->format('D j M g:i A').' - '.$shift->ends_at->format('D j M g:i A');
    }
}
