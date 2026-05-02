<?php

namespace App\Services\Respite;

use App\Models\RespiteBooking;
use App\Models\RespiteStay;
use App\Models\ServiceContext;
use App\Models\Shift;
use Illuminate\Support\Carbon;

class RespiteShiftSync
{
    public function ensureShiftForBooking(RespiteBooking $booking, ?int $actorId = null, ?int $serviceContextId = null): Shift
    {
        $booking->loadMissing('client');

        $serviceContextId ??= $booking->client?->service_context_id ?: ServiceContext::defaultId();

        return Shift::firstOrCreate(
            ['respite_booking_id' => $booking->id],
            [
                'client_id' => $booking->client_id,
                'service_context_id' => $serviceContextId,
                'user_id' => null,
                'starts_at' => $booking->start_at,
                'ends_at' => $booking->end_at,
                'status' => 'scheduled',
                'created_by' => $actorId,
            ]
        );
    }

    public function syncBooking(RespiteBooking $booking): void
    {
        $booking->loadMissing('shift');

        $shift = $booking->shift;
        if (! $shift || $shift->status === 'completed') {
            return;
        }

        if ($booking->status === 'cancelled') {
            $this->cancelShiftForBooking($booking, $shift);

            return;
        }

        $shift->update([
            'client_id' => $booking->client_id,
            'starts_at' => $booking->start_at,
            'ends_at' => $booking->end_at,
        ]);
    }

    public function checkInStay(RespiteStay $stay, ?Carbon $checkedInAt = null, ?int $actorId = null): void
    {
        $shift = $this->shiftForStay($stay);
        if (! $shift || in_array($shift->status, ['cancelled', 'completed'], true)) {
            return;
        }

        $shift->update([
            'actual_starts_at' => $shift->actual_starts_at ?: ($checkedInAt ?: now()),
            'started_by' => $shift->started_by ?: $actorId,
            'status' => 'in_progress',
        ]);
    }

    public function extendStay(RespiteStay $stay, Carbon $newEnd): void
    {
        $stay->loadMissing('booking.shift');

        $booking = $stay->booking;
        if ($booking) {
            $booking->update([
                'end_at' => $newEnd,
                'updated_by' => auth()->id(),
            ]);
        }

        $shift = $booking?->shift;
        if ($shift && ! in_array($shift->status, ['cancelled', 'completed'], true)) {
            $shift->update([
                'ends_at' => $newEnd,
            ]);
        }
    }

    public function dischargeStay(RespiteStay $stay, string $summary, ?Carbon $dischargedAt = null, ?int $actorId = null): void
    {
        $shift = $this->shiftForStay($stay);
        if (! $shift || $shift->status === 'cancelled') {
            return;
        }

        $shift->update([
            'actual_ends_at' => $shift->actual_ends_at ?: ($dischargedAt ?: now()),
            'completed_by' => $shift->completed_by ?: $actorId,
            'status' => 'completed',
            'notes' => $this->appendNote($shift->notes, 'Respite discharge summary: '.$summary),
        ]);
    }

    private function cancelShiftForBooking(RespiteBooking $booking, Shift $shift): void
    {
        $reason = trim((string) $booking->cancellation_reason);

        $shift->update([
            'status' => 'cancelled',
            'notes' => $reason !== ''
                ? $this->appendNote($shift->notes, 'Respite booking cancelled: '.$reason)
                : $shift->notes,
        ]);
    }

    private function shiftForStay(RespiteStay $stay): ?Shift
    {
        $stay->loadMissing('booking.shift');

        return $stay->booking?->shift;
    }

    private function appendNote(?string $notes, string $line): string
    {
        $notes = trim((string) $notes);

        return $notes === '' ? $line : $notes."\n\n".$line;
    }
}
