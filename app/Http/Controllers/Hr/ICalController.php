<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Models\HrICalToken;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrPublicHoliday;
use App\Domain\Hr\Services\HrCalendarAccessService;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ICalController extends Controller
{
    public function __construct(
        private readonly HrCalendarAccessService $calendarAccess,
        private readonly HrCurrentStaffService $currentStaff,
    ) {}

    public function feed(Request $request, string $token)
    {
        $icalToken = HrICalToken::where('token', $token)->firstOrFail();
        $user = $icalToken->user;
        abort_unless($user && $this->currentStaff->isCurrent($user), 404);

        $events = [];

        // Approved leave
        $leaves = HrLeaveRequest::where('user_id', $user->id)
            ->where('status', 'approved')
            ->where('ends_at', '>=', now()->subMonths(3))
            ->get();

        foreach ($leaves as $leave) {
            $events[] = $this->formatEvent(
                'Leave: '.ucfirst(str_replace('_', ' ', $leave->leave_type)),
                $leave->starts_at,
                $leave->ends_at,
                'leave-'.$leave->id,
            );
        }

        // Personal subscriptions include application-wide rows plus events whose
        // explicit people/Site/department/team audience contains the token user.
        // Filter before the 500-row cap so hidden rows never consume visible slots.
        $calEvents = HrCalendarEvent::query()
            ->active()
            ->where('starts_at', '>=', now()->subMonths(3))
            ->with('attendees')
            ->orderBy('starts_at')
            ->lazy(100)
            ->filter(fn (HrCalendarEvent $event) => $this->eventIsVisibleTo($event, $user))
            ->take(500)
            ->collect();

        foreach ($calEvents as $event) {
            $events[] = $this->formatEvent(
                $event->title,
                $event->starts_at,
                $event->ends_at,
                'event-'.$event->id,
                $event->description,
                $event->location,
            );
        }

        // The token user's OWN rostered shifts (audit fix round 2). Shifts
        // carry user_id directly, so this is a cheap single-table query and —
        // being strictly the subscriber's own roster — safe under roster perm
        // semantics (staff always see their own shifts on /hr/my). Nobody
        // else's shifts are ever emitted on this feed.
        $shifts = Shift::query()
            ->where('user_id', $user->id)
            ->whereNotIn('status', ['cancelled'])
            ->where('starts_at', '>=', now()->subMonths(3))
            ->orderBy('starts_at')
            ->limit(500)
            ->with('site:id,name')
            ->get();

        foreach ($shifts as $shift) {
            if (! $shift->starts_at || ! $shift->ends_at) {
                continue;
            }
            $events[] = $this->formatEvent(
                'Shift'.($shift->site?->name ? ': '.$shift->site->name : ''),
                $shift->starts_at,
                $shift->ends_at,
                'shift-'.$shift->id,
                null,
                $shift->site?->name ?? $shift->location,
            );
        }

        // Application public holidays — all-day rows.
        $holidays = HrPublicHoliday::query()
            ->where('date', '>=', now()->subMonths(3))
            ->orderBy('date')
            ->limit(100)
            ->get();

        foreach ($holidays as $holiday) {
            $events[] = $this->formatEvent(
                'Public holiday: '.$holiday->name,
                $holiday->date->copy()->startOfDay(),
                $holiday->date->copy()->addDay()->startOfDay(),
                'holiday-'.$holiday->id,
            );
        }

        $ical = "BEGIN:VCALENDAR\r\n";
        $ical .= "VERSION:2.0\r\n";
        $ical .= "PRODID:-//Oblivion Findings//HR Calendar//EN\r\n";
        $ical .= "CALSCALE:GREGORIAN\r\n";
        $ical .= "X-WR-CALNAME:HR Calendar\r\n";
        $ical .= implode('', $events);
        $ical .= "END:VCALENDAR\r\n";

        return response($ical, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="hr-calendar.ics"',
        ]);
    }

    public function generateToken(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $this->currentStaff->isCurrent($user), 403);

        $token = HrICalToken::updateOrCreate(
            ['user_id' => $user->id],
            ['token' => Str::random(64), 'created_at' => now()]
        );

        return redirect()->back()->with('success', 'Calendar feed URL generated.');
    }

    private function formatEvent(string $summary, $start, $end, string $uid, ?string $description = null, ?string $location = null): string
    {
        $event = "BEGIN:VEVENT\r\n";
        $event .= "UID:{$uid}@oblivionfindings\r\n";
        $event .= 'DTSTART:'.$start->format('Ymd\THis')."\r\n";
        $event .= 'DTEND:'.$end->format('Ymd\THis')."\r\n";
        $event .= 'SUMMARY:'.$this->escapeIcal($summary)."\r\n";
        if ($description) {
            $event .= 'DESCRIPTION:'.$this->escapeIcal($description)."\r\n";
        }
        if ($location) {
            $event .= 'LOCATION:'.$this->escapeIcal($location)."\r\n";
        }
        $event .= "END:VEVENT\r\n";

        return $event;
    }

    private function eventIsVisibleTo(HrCalendarEvent $event, User $user): bool
    {
        return $this->calendarAccess->canViewEvent($user, $event);
    }

    private function escapeIcal(string $text): string
    {
        return str_replace(["\n", ',', ';'], ['\\n', '\\,', '\\;'], $text);
    }
}
