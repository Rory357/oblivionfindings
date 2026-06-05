<?php

namespace App\Services\Sites\Calendar\Providers;

use App\Models\RespiteBooking;
use App\Services\Sites\Calendar\CalendarItem;
use Illuminate\Support\Carbon;

/**
 * Surfaces respite bookings onto the unified Site Calendar so a home's respite
 * occupancy appears alongside its other obligations. This reads the SAME
 * {@see RespiteBooking} records the Respite workspace Calendar tab renders, so
 * the two surfaces are one source of truth and can never drift apart.
 *
 * A booking's site is resolved as location_id ?? the client's home site_id,
 * because approve() doesn't yet populate location_id on every booking.
 */
class RespiteObligationProvider extends ObligationProvider
{
    public function sourceKey(): string
    {
        return 'respite';
    }

    public function obligations(array $siteIds, Carbon $start, Carbon $end): array
    {
        if ($siteIds === []) {
            return [];
        }

        return RespiteBooking::query()
            ->whereIn('status', ['pending', 'confirmed', 'in_progress', 'completed'])
            ->where('start_at', '<=', $end)
            ->where('end_at', '>=', $start)
            ->where(function ($q) use ($siteIds) {
                // Site is the booking's own location, else the client's home.
                $q->whereIn('location_id', $siteIds)
                    ->orWhere(function ($q2) use ($siteIds) {
                        $q2->whereNull('location_id')
                            ->whereHas('client', fn ($c) => $c->whereIn('site_id', $siteIds));
                    });
            })
            ->with([
                'location:id,name,type',
                'client:id,first_name,last_name,site_id',
                'client.site:id,name,type',
                'coordinator:id,name',
            ])
            ->get()
            ->map(function (RespiteBooking $booking) {
                $site = $booking->location ?: $booking->client?->site;
                $client = $booking->client;
                $name = trim(($client?->first_name ?? '').' '.($client?->last_name ?? ''));

                return new CalendarItem(
                    id: "respite-{$booking->id}",
                    source: 'respite',
                    group: 'auto',
                    title: $name !== '' ? "Respite — {$name}" : 'Respite booking',
                    start: $this->isoDate($booking->start_at),
                    end: $this->isoDate($booking->end_at),
                    allDay: true,
                    status: $this->bookingStatus($booking->status),
                    owner: $this->ownerArray($booking->coordinator),
                    ref: 'RESP-'.$booking->id,
                    site: $this->siteArray($site),
                    link: "/respite/bookings/{$booking->id}",
                );
            })
            ->all();
    }

    /**
     * Map a respite booking status onto the calendar's status vocabulary.
     */
    private function bookingStatus(string $status): string
    {
        return match ($status) {
            'pending' => 'pending',
            'confirmed', 'in_progress' => 'approved',
            'completed' => 'completed',
            'cancelled' => 'cancelled',
            default => 'scheduled',
        };
    }
}
