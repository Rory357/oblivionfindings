<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteInspectionSchedule;
use App\Models\SiteInspectionRecord;
use App\Models\SiteCalendarEvent;
use Illuminate\Http\Request;

class SiteInspectionController extends Controller
{
    public function index(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        $schedules = SiteInspectionSchedule::where('site_id', $site->id)
            ->with('assignedTo:id,name')
            ->orderBy('next_due_date')
            ->get();

        $records = SiteInspectionRecord::where('site_id', $site->id)
            ->with(['schedule', 'completedBy:id,name'])
            ->orderByDesc('due_date')
            ->paginate(20);

        return inertia('sites/inspections/index', [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'type' => $site->type,
            ],
            'schedules' => $schedules,
            'records' => $records,
        ]);
    }

    public function store(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'inspection_type' => 'required|string|max:50',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'frequency' => 'required|in:weekly,monthly,quarterly,bi_annual,annual,custom',
            'custom_rrule' => 'nullable|string',
            'first_due_date' => 'required|date',
            'assigned_to_user_id' => 'nullable|exists:users,id',
            'auto_create_calendar_event' => 'boolean',
        ]);

        $schedule = SiteInspectionSchedule::create([
            ...$validated,
            'site_id' => $site->id,
            'tenant_id' => $site->tenant_id,
            'next_due_date' => $validated['first_due_date'],
            'is_active' => true,
        ]);

        // Create calendar event if requested
        if ($validated['auto_create_calendar_event'] ?? true) {
            SiteCalendarEvent::create([
            'site_id' => $site->id,
            'tenant_id' => $site->tenant_id,
            'event_type' => 'inspection',
                'title' => $validated['title'],
                'description' => $validated['description'] ?? 'Scheduled inspection',
                'start_at' => $validated['first_due_date'] . ' 09:00:00',
                'end_at' => $validated['first_due_date'] . ' 10:00:00',
                'recurrence_rule' => $this->frequencyToRrule($validated['frequency'], $validated['custom_rrule'] ?? null),
                'created_by_user_id' => $request->user()->id,
                'owner_user_id' => $validated['assigned_to_user_id'] ?? null,
                'status' => 'draft',
            ]);
        }

        return redirect()
            ->route('sites.inspections.index', $site)
            ->with('success', 'Inspection schedule created.');
    }

    public function complete(Request $request, Site $site, SiteInspectionSchedule $schedule)
    {
        $this->authorize('update', $site);
        abort_unless($schedule->site_id === $site->id, 404);

        $validated = $request->validate([
            'result' => 'required|in:pass,fail,partial,na',
            'findings' => 'nullable|string',
            'corrective_actions' => 'nullable|string',
            'evidence_photos' => 'nullable|array',
            'linked_hazard_id' => 'nullable|exists:site_hazards,id',
        ]);

        $record = SiteInspectionRecord::create([
            'schedule_id' => $schedule->id,
            'site_id' => $site->id,
            'tenant_id' => $site->tenant_id,
            'due_date' => $schedule->next_due_date,
            'completed_at' => now(),
            'completed_by_user_id' => $request->user()->id,
            'result' => $validated['result'],
            'findings' => $validated['findings'] ?? null,
            'corrective_actions' => $validated['corrective_actions'] ?? null,
            'linked_hazard_id' => $validated['linked_hazard_id'] ?? null,
            'evidence_photos' => $validated['evidence_photos'] ?? null,
        ]);

        // Update next due date
        $schedule->update([
            'next_due_date' => $this->calculateNextDueDate($schedule),
        ]);

        return redirect()
            ->route('sites.inspections.index', $site)
            ->with('success', 'Inspection recorded successfully.');
    }

    public function destroy(Request $request, Site $site, SiteInspectionSchedule $schedule)
    {
        $this->authorize('update', $site);
        abort_unless($schedule->site_id === $site->id, 404);

        $schedule->delete();

        return redirect()
            ->route('sites.inspections.index', $site)
            ->with('success', 'Inspection schedule deleted.');
    }

    private function frequencyToRrule(string $frequency, ?string $custom): ?string
    {
        if ($custom) {
            return $custom;
        }

        return match ($frequency) {
            'weekly' => 'FREQ=WEEKLY;INTERVAL=1',
            'monthly' => 'FREQ=MONTHLY;INTERVAL=1',
            'quarterly' => 'FREQ=MONTHLY;INTERVAL=3',
            'bi_annual' => 'FREQ=MONTHLY;INTERVAL=6',
            'annual' => 'FREQ=YEARLY;INTERVAL=1',
            default => null,
        };
    }

    private function calculateNextDueDate(SiteInspectionSchedule $schedule): string
    {
        $current = \Carbon\Carbon::parse($schedule->next_due_date);

        return match ($schedule->frequency) {
            'weekly' => $current->addWeek()->toDateString(),
            'monthly' => $current->addMonth()->toDateString(),
            'quarterly' => $current->addMonths(3)->toDateString(),
            'bi_annual' => $current->addMonths(6)->toDateString(),
            'annual' => $current->addYear()->toDateString(),
            default => $current->addMonth()->toDateString(),
        };
    }
}
