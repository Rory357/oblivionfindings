<?php

namespace App\Http\Controllers\Respite;

use App\Http\Controllers\Controller;
use App\Models\RespiteCalendarEvent;
use App\Models\RespiteBooking;
use App\Services\Respite\RespiteCalendarProjector;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RespiteCalendarController extends Controller
{
    public function index(Request $request): Response
    {
        // Backfill events for confirmed/active bookings that predate the projector.
        $confirmed = RespiteBooking::whereIn('status', ['confirmed', 'in_progress', 'completed'])->get();
        $projector = app(RespiteCalendarProjector::class);
        foreach ($confirmed as $booking) {
            $exists = RespiteCalendarEvent::query()
                ->where('booking_id', $booking->id)
                ->where('event_type', 'booking_confirmed')
                ->exists();
            if (!$exists) {
                $projector->projectBooking($booking, auth()->id());
            }
        }

        $events = RespiteCalendarEvent::query()
            ->orderBy('start_at')
            ->limit(500)
            ->get();

        return Inertia::render('respite/calendar/index', [
            'events' => $events,
            'bookings' => RespiteBooking::whereIn('status', ['confirmed', 'in_progress', 'completed'])
                ->orderBy('start_at')
                ->limit(200)
                ->get(),
        ]);
    }
}
