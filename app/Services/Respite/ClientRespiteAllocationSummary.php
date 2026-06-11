<?php

namespace App\Services\Respite;

use App\Models\Client;
use App\Models\ClientRespiteAllocation;
use App\Models\RespiteBooking;
use Carbon\CarbonImmutable;

class ClientRespiteAllocationSummary
{
    /**
     * @return array{allocated:int,used:int,booked:int,remaining:int,period_label:string,period_start:string,period_end:string,funding_source:?string,notes:?string}|null
     */
    public function forClient(Client $client): ?array
    {
        $today = CarbonImmutable::today();

        $allocation = ClientRespiteAllocation::query()
            ->where('client_id', $client->id)
            ->whereDate('period_start', '<=', $today)
            ->whereDate('period_end', '>=', $today)
            ->orderByDesc('period_start')
            ->first();

        if (! $allocation) {
            return null;
        }

        $bookings = RespiteBooking::query()
            ->where('client_id', $client->id)
            ->whereIn('status', ['confirmed', 'in_progress', 'completed'])
            ->where('end_at', '>=', $allocation->period_start)
            ->where('start_at', '<=', $allocation->period_end)
            ->get(['id', 'start_at', 'end_at', 'status']);

        $used = 0;
        $booked = 0;

        foreach ($bookings as $booking) {
            $nights = $this->nightsWithinPeriod(
                CarbonImmutable::instance($booking->start_at),
                CarbonImmutable::instance($booking->end_at),
                CarbonImmutable::instance($allocation->period_start),
                CarbonImmutable::instance($allocation->period_end),
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

    private function nightsWithinPeriod(
        CarbonImmutable $start,
        CarbonImmutable $end,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
    ): int {
        $windowStart = $start->greaterThan($periodStart) ? $start : $periodStart;
        $windowEnd = $end->lessThan($periodEnd->addDay()) ? $end : $periodEnd->addDay();

        return max(0, $windowStart->startOfDay()->diffInDays($windowEnd->startOfDay()));
    }
}
