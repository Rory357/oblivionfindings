<?php

namespace App\Services;

use App\Models\Shift;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class ShiftConflictService
{
    public const TIGHT_TURNAROUND_MINUTES = 30;

    public function findBlockingStaffConflicts(
        ?int $userId,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?int $ignoreShiftId = null,
    ): Collection {
        if (! $userId) {
            return collect();
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

    public function findTightTurnaroundWarnings(
        ?int $userId,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?int $ignoreShiftId = null,
        int $bufferMinutes = self::TIGHT_TURNAROUND_MINUTES,
    ): Collection {
        if (! $userId) {
            return collect();
        }

        $bufferStart = $startsAt->copy()->subMinutes($bufferMinutes);
        $bufferEnd = $endsAt->copy()->addMinutes($bufferMinutes);

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
