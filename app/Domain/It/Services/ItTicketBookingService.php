<?php

namespace App\Domain\It\Services;

use App\Domain\Hr\Models\HrLeaveRequest;
use App\Models\ItTicket;
use App\Models\ItTicketBooking;
use App\Models\PersonalCalendarEntry;
use App\Models\Shift;
use App\Models\SiteCalendarEvent;
use App\Models\StaffTimeOff;
use App\Models\User;
use App\Services\Sites\SiteCalendarService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ItTicketBookingService
{
    public function __construct(private readonly ItTicketWorkService $work, private readonly ItTicketWorkTime $time) {}

    public function create(ItTicket $ticket, User $actor, array $input): void
    {
        $safe = Validator::make($input, ['technician_user_ids' => ['required', 'array', 'min:1', 'max:20'],
            'technician_user_ids.*' => ['integer', 'min:1', 'distinct'], 'brief' => ['required', 'string', 'max:2000'],
            'location' => ['nullable', 'string', 'max:1000']])->validate();
        $period = $this->time->period($input, 'booking', false);
        if ($period['starts_at']->lessThan(CarbonImmutable::now())) {
            $this->invalid('Schedule a future period. Record past work using a work note.');
        }
        $ids = $safe['technician_user_ids'];
        sort($ids);
        $group = (string) Str::uuid();
        foreach ($ids as $id) {
            $tech = $this->work->technician($id, $ticket);
            if ($this->busy($tech, $period['starts_at'], $period['ends_at'])) {
                $this->invalid('A selected technician is unavailable in this period. Check availability again.');
            }
            $booking = ItTicketBooking::create(['ticket_id' => $ticket->id, 'technician_user_id' => $id, 'recorded_by' => $actor->id,
                'group_uuid' => $group, 'starts_at' => $period['starts_at'], 'ends_at' => $period['ends_at'], 'status' => 'requested',
                'details' => ['brief' => $safe['brief'], 'location' => $safe['location'] ?? null]]);
            $this->work->record($ticket, $actor, 'booking', $booking->id, 'requested', null, $booking->toArray(), 'Additional technician requested');
        }
    }

    public function change(ItTicket $ticket, User $actor, array $input): void
    {
        Validator::make($input, ['id' => ['required', 'integer'], 'action' => ['required', Rule::in(['accepted', 'declined', 'cancelled', 'reschedule', 'restore'])]])->validate();
        $booking = ItTicketBooking::where('ticket_id', $ticket->id)->findOrFail($input['id']);
        $action = $input['action'];
        $reason = $this->work->reason($input);
        $before = $booking->toArray();
        // A dispatcher must still be able to cancel work after the booked person's access ends.
        $tech = $action === 'cancelled' ? null : $this->work->technician((int) $booking->technician_user_id, $ticket);
        if (in_array($action, ['accepted', 'declined'], true)) {
            // Responding is the assigned person's statement, never impersonation by a dispatcher.
            abort_unless((int) $actor->id === (int) $tech->id, 403);
            if ($booking->status !== 'requested') {
                $this->invalid('This request already has a response.');
            }
            if ($action === 'accepted' && $this->busy($tech, $booking->starts_at, $booking->ends_at, $booking->id)) {
                $this->invalid('This period is no longer available. Decline or reschedule the request.');
            }
            $booking->status = $action;
        } elseif ($action === 'cancelled') {
            if (! in_array($booking->status, ['requested', 'accepted'], true)) {
                $this->invalid('Only an open booking can be cancelled.');
            }
            $booking->status = 'cancelled';
        } else {
            if ($action === 'restore' ? $booking->status !== 'cancelled' : ! in_array($booking->status, ['requested', 'accepted'], true)) {
                $this->invalid('This booking cannot be rescheduled.');
            }
            $period = $this->time->period($input, 'booking', false);
            if ($period['starts_at']->lessThan(CarbonImmutable::now())) {
                $this->invalid('Choose a future period for this booking.');
            }
            if ($this->busy($tech, $period['starts_at'], $period['ends_at'], $booking->id)) {
                $this->invalid('This technician is unavailable in the new period.');
            }
            $booking->fill(['starts_at' => $period['starts_at'], 'ends_at' => $period['ends_at'], 'status' => 'requested']);
        }
        $booking->save();
        $this->work->record($ticket, $actor, 'booking', $booking->id, $action, $before, $booking->toArray(), $reason);
    }

    /** Return only busy/free, never another person's private appointment or leave details. */
    public function busy(User $tech, CarbonImmutable $start, CarbonImmutable $end, ?int $except = null): bool
    {
        if (ItTicketBooking::where('technician_user_id', $tech->id)->whereIn('status', ['requested', 'accepted'])
            ->when($except, fn ($q) => $q->where('id', '!=', $except))->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists()) {
            return true;
        }
        foreach ([StaffTimeOff::class, HrLeaveRequest::class, Shift::class] as $model) {
            $query = $model::where('user_id', $tech->id)->where('starts_at', '<', $end)->where('ends_at', '>', $start);
            if ($model === HrLeaveRequest::class) {
                $query->where('status', 'approved');
            }
            if ($model === Shift::class) {
                $query->visibleToFrontline()->whereNotIn('status', ['cancelled', 'draft']);
            }
            if ($query->exists()) {
                return true;
            }
        }
        if (Schema::hasTable('personal_calendar_entries') && PersonalCalendarEntry::where('user_id', $tech->id)->whereNotIn('status', ['cancelled', 'completed'])
            ->where('start_at', '<', $end)->where(fn ($q) => $q->where('end_at', '>', $start)->orWhere(fn ($q) => $q->whereNull('end_at')->where('start_at', '>=', $start)))->exists()) {
            return true;
        }
        // The canonical service expands recurring events and exceptions. Supplement its
        // start-in-range query for a nonrecurring event that began before the slot.
        $events = app(SiteCalendarService::class)->getEventsForRange(null, null, Carbon::instance($start), Carbon::instance($end), $tech->id);
        foreach ($events as $event) {
            if (in_array($event['status'] ?? null, ['cancelled', 'completed'], true)) {
                continue;
            }
            $zone = ($event['all_day'] ?? false) ? config('app.worker_timezone', 'Pacific/Auckland') : 'UTC';
            $from = CarbonImmutable::parse($event['start'] ?? $event['start_at'], $zone);
            $to = CarbonImmutable::parse($event['end'] ?? $event['end_at'] ?? $from->addMinute(), $zone);
            if ($from->lessThan($end) && $to->greaterThan($start)) {
                return true;
            }
        }

        return SiteCalendarEvent::whereNull('recurrence_rule')->whereNotIn('status', ['cancelled', 'completed'])
            ->where(fn ($q) => $q->where('owner_user_id', $tech->id)->orWhereJsonContains('attendee_user_ids', $tech->id)->orWhereJsonContains('attendee_user_ids', (string) $tech->id))
            ->where('start_at', '<', $end)->where('end_at', '>', $start)->exists();
    }

    public function calendar(User $actor, Carbon $start, Carbon $end): array
    {
        if (! $this->work->ready()) {
            return [];
        }

        return ItTicketBooking::with('ticket')->where('technician_user_id', $actor->id)->whereIn('status', ['requested', 'accepted', 'completed'])
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)->get()
            ->filter(fn ($booking) => $booking->ticket && app(ItWorkAccessService::class)->canWork($actor, $booking->ticket))
            ->map(fn ($booking) => ['id' => 'it-booking-'.$booking->id, 'title' => $booking->ticket->reference.' · '.$booking->ticket->title,
                'start' => $booking->starts_at->toIso8601String(), 'end' => $booking->ends_at->toIso8601String(), 'allDay' => false,
                'extendedProps' => ['type' => 'it_booking', 'status' => $booking->status, 'location' => $booking->details['location'] ?? null,
                    'link' => '/it/tickets/'.$booking->ticket_id.'?tab=schedule']])->values()->all();
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['booking' => $message]);
    }
}
