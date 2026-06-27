<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Models\HrICalToken;
use App\Domain\Hr\Models\HrLeaveRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ICalController extends Controller
{
    use ResolvesHrTenant;

    public function feed(Request $request, string $token)
    {
        $icalToken = HrICalToken::where('token', $token)->firstOrFail();
        $user = $icalToken->user;
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $events = [];

        // Approved leave
        $leaves = HrLeaveRequest::where('user_id', $user->id)
            ->where('status', 'approved')
            ->where('ends_at', '>=', now()->subMonths(3))
            ->get();

        foreach ($leaves as $leave) {
            $events[] = $this->formatEvent(
                'Leave: ' . ucfirst(str_replace('_', ' ', $leave->leave_type)),
                $leave->starts_at,
                $leave->ends_at,
                'leave-' . $leave->id,
            );
        }

        // Calendar events — tenant-scoped (previously leaked across tenants).
        $calEvents = HrCalendarEvent::forTenant($tenantId)
            ->where('starts_at', '>=', now()->subMonths(3))
            ->orderBy('starts_at')
            ->limit(100)
            ->get();

        foreach ($calEvents as $event) {
            $events[] = $this->formatEvent(
                $event->title,
                $event->starts_at,
                $event->ends_at,
                'event-' . $event->id,
                $event->description,
                $event->location,
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
        abort_unless($user, 403);

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
        $event .= "DTSTART:" . $start->format('Ymd\THis') . "\r\n";
        $event .= "DTEND:" . $end->format('Ymd\THis') . "\r\n";
        $event .= "SUMMARY:" . $this->escapeIcal($summary) . "\r\n";
        if ($description) {
            $event .= "DESCRIPTION:" . $this->escapeIcal($description) . "\r\n";
        }
        if ($location) {
            $event .= "LOCATION:" . $this->escapeIcal($location) . "\r\n";
        }
        $event .= "END:VEVENT\r\n";
        return $event;
    }

    private function escapeIcal(string $text): string
    {
        return str_replace(["\n", ",", ";"], ["\\n", "\\,", "\\;"], $text);
    }
}
