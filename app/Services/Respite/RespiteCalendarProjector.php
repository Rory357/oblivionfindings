<?php

namespace App\Services\Respite;

use App\Models\RespiteBooking;
use App\Models\RespiteCalendarEvent;

class RespiteCalendarProjector
{
    public function projectBooking(RespiteBooking $booking, ?int $actorId = null): RespiteCalendarEvent
    {
        return RespiteCalendarEvent::create([
            'booking_id' => $booking->id,
            'event_type' => 'booking_confirmed',
            'start_at' => $booking->start_at,
            'end_at' => $booking->end_at,
            'visibility' => 'limited',
            'projection_status' => 'pending',
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);
    }
}
