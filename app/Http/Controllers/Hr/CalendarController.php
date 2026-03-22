<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Models\Site;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CalendarController extends Controller
{
    /**
     * Combined calendar view showing events and approved leave.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.calendar.view'), 403);

        $start = $request->query('start', now()->startOfMonth()->toDateString());
        $end = $request->query('end', now()->endOfMonth()->toDateString());

        $events = HrCalendarEvent::forTenant($user->tenant_id)
            ->inRange($start, $end)
            ->with(['creator:id,name', 'site:id,name'])
            ->orderBy('starts_at')
            ->get();

        // Merge approved leave as calendar events
        $leaveEvents = HrLeaveRequest::where('tenant_id', $user->tenant_id)
            ->where('status', 'approved')
            ->where('start_date', '<=', $end)
            ->where('end_date', '>=', $start)
            ->with('user:id,name')
            ->get()
            ->map(fn ($leave) => [
                'id' => 'leave-' . $leave->id,
                'title' => ($leave->user->name ?? 'Staff') . ' - ' . ucfirst($leave->leave_type ?? 'Leave'),
                'start' => $leave->start_date,
                'end' => $leave->end_date,
                'allDay' => true,
                'event_type' => 'leave',
                'color' => '#94a3b8',
            ]);

        $sites = Site::where('tenant_id', $user->tenant_id)->get(['id', 'name']);

        return Inertia::render('hr/calendar/index', [
            'events' => $events,
            'leaveEvents' => $leaveEvents,
            'sites' => $sites,
            'filters' => [
                'start' => $start,
                'end' => $end,
            ],
            'can' => [
                'manage' => $user->canDo('hr.calendar.manage'),
            ],
        ]);
    }

    /**
     * Store a new calendar event.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.calendar.manage'), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'event_type' => ['required', 'string', 'in:company,team,training,social,holiday'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'is_all_day' => ['sometimes', 'boolean'],
            'location' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
        ]);

        HrCalendarEvent::create([
            'tenant_id' => $user->tenant_id,
            'created_by' => $user->id,
            ...$data,
        ]);

        return redirect()->back()->with('success', 'Calendar event created.');
    }

    /**
     * Update an existing calendar event.
     */
    public function update(Request $request, HrCalendarEvent $event)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.calendar.manage'), 403);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'event_type' => ['sometimes', 'string', 'in:company,team,training,social,holiday'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date', 'after_or_equal:starts_at'],
            'is_all_day' => ['sometimes', 'boolean'],
            'location' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
        ]);

        $event->update($data);

        return redirect()->back()->with('success', 'Calendar event updated.');
    }

    /**
     * Delete a calendar event.
     */
    public function destroy(Request $request, HrCalendarEvent $event)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.calendar.manage'), 403);

        $event->delete();

        return redirect()->back()->with('success', 'Calendar event deleted.');
    }
}
