<?php

namespace App\Services\Respite;

use App\Models\Client;
use App\Models\ClientRespiteAllocation;
use App\Models\RespiteBooking;
use App\Support\WorkerClock;
use Carbon\CarbonImmutable;

class ClientRespiteAllocationSummary
{
    /**
     * @return array{allocated:int,used:int,booked:int,remaining:int,period_label:string,period_start:string,period_end:string,funding_source:?string,notes:?string}|null
     */
    public function forClient(Client $client): ?array
    {
        $today = WorkerClock::today()->toDateString();

        $allocation = ClientRespiteAllocation::query()
            ->where('client_id', $client->id)
            ->whereDate('period_start', '<=', $today)
            ->whereDate('period_end', '>=', $today)
            ->orderByDesc('period_start')
            ->first();

        if (! $allocation) {
            return null;
        }

        // The period is NZ calendar dates; booking times are UTC instants.
        $bookings = RespiteBooking::query()
            ->where('client_id', $client->id)
            ->whereIn('status', ['confirmed', 'in_progress', 'completed'])
            ->where('end_at', '>=', WorkerClock::dayStartUtc($allocation->period_start->toDateString()))
            ->where('start_at', '<=', WorkerClock::dayEndUtc($allocation->period_end->toDateString()))
            ->get(['id', 'start_at', 'end_at', 'status']);

        $used = 0;
        $booked = 0;

        foreach ($bookings as $booking) {
            $nights = $this->nightsWithinPeriod(
                $this->calendarDay(WorkerClock::localDate($booking->start_at)),
                $this->calendarDay(WorkerClock::localDate($booking->end_at)),
                $this->calendarDay($allocation->period_start->toDateString()),
                $this->calendarDay($allocation->period_end->toDateString()),
            );

            if ($booking->status === 'completed' || $booking->end_at?->isPast()) {
                $used += $nights;
                continue;
            }

            $booked += $nights;
        }

        $allocated = (int) $allocation->nights_allocated;

        return [
            'allocated' => $allocated,
            'used' => $used,
            'booked' => $booked,
            'remaining' => max(0, $allocated - $used - $booked),
            'period_label' => $allocation->period_start->format('j M Y').' - '.$allocation->period_end->format('j M Y'),
            'period_start' => $allocation->period_start->toDateString(),
            'period_end' => $allocation->period_end->toDateString(),
            'funding_source' => $allocation->funding_source,
            'notes' => $allocation->notes,
        ];
    }

    /** A Y-m-d calendar date as UTC midnight, so day sums ignore daylight saving. */
    private function calendarDay(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'UTC');
    }

    /** Each argument is a calendar day; booking days are their NZ dates. */
    private function nightsWithinPeriod(
        CarbonImmutable $start,
        CarbonImmutable $end,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
    ): int {
        $windowStart = $start->greaterThan($periodStart) ? $start : $periodStart;
        $windowEnd = $end->lessThan($periodEnd->addDay()) ? $end : $periodEnd->addDay();

        return max(0, (int) $windowStart->diffInDays($windowEnd));
    }
}
