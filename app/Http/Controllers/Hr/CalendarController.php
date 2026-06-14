<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Models\Site;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CalendarController extends Controller
{
    use ResolvesHrTenant;

    /**
     * Combined calendar view showing events and approved leave.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $start = $request->query('start', now()->startOfMonth()->toDateString());
        $end = $request->query('end', now()->endOfMonth()->toDateString());

        $events = HrCalendarEvent::forTenant($tenantId)
            ->inRange($start, $end)
            ->with(['creator:id,name', 'site:id,name'])
            ->orderBy('starts_at')
            ->get();

        // Merge approved leave as calendar events
        $leaveEvents = HrLeaveRequest::where('tenant_id', $tenantId)
            ->where('status', 'approved')
            ->where('starts_at', '<=', $end)
            ->where('ends_at', '>=', $start)
            ->with('user:id,name')
            ->get()
            ->map(fn ($leave) => [
                'id' => 'leave-' . $leave->id,
                'title' => ($leave->user->name ?? 'Staff') . ' - ' . ucfirst($leave->leave_type ?? 'Leave'),
                'start' => $leave->starts_at,
                'end' => $leave->ends_at,
                'allDay' => true,
                'event_type' => 'leave',
                'color' => '#94a3b8',
            ]);

        $sites = Site::where('tenant_id', $tenantId)->get(['id', 'name']);

        return Inertia::render('hr/calendar/index', [
            'events' => $events,
            'leaveEvents' => $leaveEvents,
            'sites' => $sites,
            'filters' => [
                'start' => $start,
                'end' => $end,
            ],
            'can' => [
                'manage' => $this->canManage($user),
            ],
        ]);
    }

    /**
     * Store a new calendar event.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

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
            'tenant_id' => $tenantId,
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
        abort_unless($this->canManage($user), 403);
        $this->assertHrTenantAccess($this->resolveHrTenantIdForUser($user), $event->tenant_id);

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
        abort_unless($this->canManage($user), 403);
        $this->assertHrTenantAccess($this->resolveHrTenantIdForUser($user), $event->tenant_id);

        $event->delete();

        return redirect()->back()->with('success', 'Calendar event deleted.');
    }

    private function canView($user): bool
    {
        return (bool) $user && (
            $user->canDo('hr.calendar.view')
            || $user->canDo('hr.calendar.manage')
            || $user->canDo('calendar.view')
            || $user->canDo('calendar.viewAny')
            || $user->canDo('calendar.manage_recurring')
        );
    }

    private function canManage($user): bool
    {
        return (bool) $user && (
            $user->canDo('hr.calendar.manage')
            || $user->canDo('calendar.create')
            || $user->canDo('calendar.manage_recurring')
        );
    }
}
