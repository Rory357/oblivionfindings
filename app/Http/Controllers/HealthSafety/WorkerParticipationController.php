<?php

namespace App\Http\Controllers\HealthSafety;

use App\Domain\Hr\Models\HrCalendarEvent;
use App\Http\Controllers\Controller;
use App\Models\HsCommittee;
use App\Models\HsCommitteeMeeting;
use App\Models\HsConsultation;
use App\Models\HsRepresentative;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkerParticipationController extends Controller
{
    /**
     * List H&S reps, committees, upcoming meetings, and consultations.
     */
    public function index(Request $request): \Inertia\Response
    {
        $siteFilter = $request->input('site_id');

        // H&S Representatives
        $reps = HsRepresentative::with(['user:id,name', 'site:id,name'])
            ->when($siteFilter, fn ($q) => $q->where('site_id', $siteFilter))
            ->orderByDesc('created_at')
            ->get();

        $activeReps = HsRepresentative::where('status', 'active')
            ->when($siteFilter, fn ($q) => $q->where('site_id', $siteFilter))
            ->count();

        // Committees
        $committees = HsCommittee::with('site:id,name')
            ->when($siteFilter, fn ($q) => $q->where('site_id', $siteFilter))
            ->orderByDesc('created_at')
            ->get();

        $activeCommittees = HsCommittee::where('status', 'active')
            ->when($siteFilter, fn ($q) => $q->where('site_id', $siteFilter))
            ->count();

        // Committee Meetings this month
        $meetingsThisMonth = HsCommitteeMeeting::whereBetween('scheduled_at', [
            now()->startOfMonth(),
            now()->endOfMonth(),
        ])->count();

        $upcomingMeetings = HsCommitteeMeeting::with('committee:id,name')
            ->where('scheduled_at', '>=', now())
            ->orderBy('scheduled_at')
            ->limit(20)
            ->get();

        // Consultations
        $consultations = HsConsultation::with('site:id,name')
            ->when($siteFilter, fn ($q) => $q->where('site_id', $siteFilter))
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $openConsultations = HsConsultation::where('status', 'open')
            ->when($siteFilter, fn ($q) => $q->where('site_id', $siteFilter))
            ->count();

        return Inertia::render('health-safety/worker-participation/index', [
            'representatives' => $reps,
            'committees' => $committees,
            'meetings' => $upcomingMeetings,
            'consultations' => $consultations,
            'stats' => [
                'active_reps' => $activeReps,
                'active_committees' => $activeCommittees,
                'meetings_this_month' => $meetingsThisMonth,
                'open_consultations' => $openConsultations,
            ],
            'sites' => Site::select('id', 'name')->where('is_active', true)->orderBy('name')->get(),
            'staff' => User::select('id', 'name')->orderBy('name')->get(),
            'filters' => $request->only(['site_id']),
        ]);
    }

    /**
     * Create a new H&S representative.
     */
    public function storeRepresentative(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'site_id' => ['required', 'exists:sites,id'],
            'election_method' => ['required', 'string', 'in:elected,appointed,volunteered'],
            'elected_at' => ['required', 'date'],
            'term_expires_at' => ['nullable', 'date', 'after:elected_at'],
            'training_days_completed' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        HsRepresentative::create(array_merge($validated, [
            'status' => 'active',
            'created_by' => $request->user()->id,
        ]));

        return redirect()->back()->with('success', 'H&S representative added successfully.');
    }

    /**
     * Update an H&S representative.
     */
    public function updateRepresentative(Request $request, HsRepresentative $representative): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:active,inactive,resigned'],
            'training_days_completed' => ['sometimes', 'integer', 'min:0'],
            'term_expires_at' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $representative->update($validated);

        return redirect()->back()->with('success', 'Representative updated successfully.');
    }

    /**
     * Create a new H&S committee.
     */
    public function storeCommittee(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'site_id' => ['required', 'exists:sites,id'],
            'meeting_frequency' => ['required', 'string', 'in:weekly,fortnightly,monthly,quarterly'],
            'established_at' => ['required', 'date'],
            'terms_of_reference' => ['nullable', 'string', 'max:5000'],
            'members' => ['required', 'array', 'min:1'],
            'members.*' => ['exists:users,id'],
        ]);

        HsCommittee::create(array_merge($validated, [
            'status' => 'active',
            'created_by' => $request->user()->id,
        ]));

        return redirect()->back()->with('success', 'Committee created successfully.');
    }

    /**
     * Create a meeting for a committee.
     */
    public function storeMeeting(Request $request, HsCommittee $committee): RedirectResponse
    {
        $validated = $request->validate([
            'scheduled_at' => ['required', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
            'agenda_items' => ['nullable', 'array'],
            'attendees' => ['nullable', 'array'],
            'attendees.*' => ['exists:users,id'],
        ]);

        $meeting = $committee->meetings()->create(array_merge($validated, [
            'status' => 'scheduled',
            'created_by' => $request->user()->id,
        ]));

        // Create calendar events for attendees
        $attendeeIds = $validated['attendees'] ?? $committee->members ?? [];
        if (is_array($attendeeIds)) {
            $tenantId = $request->user()->tenant_id ?? 1;
            $scheduledAt = \Carbon\Carbon::parse($validated['scheduled_at']);
            foreach ($attendeeIds as $userId) {
                HrCalendarEvent::create([
                    'tenant_id' => $tenantId,
                    'title' => 'H&S Committee Meeting: ' . $committee->name,
                    'description' => 'Committee meeting at ' . ($validated['location'] ?? 'TBC'),
                    'event_type' => 'hs_meeting',
                    'starts_at' => $scheduledAt,
                    'ends_at' => $scheduledAt->copy()->addHour(),
                    'is_all_day' => false,
                    'location' => $validated['location'] ?? null,
                    'created_by' => $userId,
                ]);
            }
        }

        return redirect()->back()->with('success', 'Meeting scheduled successfully.');
    }

    /**
     * Update a committee meeting (minutes, action items, status).
     */
    public function updateMeeting(Request $request, HsCommitteeMeeting $meeting): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:scheduled,in_progress,completed,cancelled'],
            'minutes' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'action_items' => ['sometimes', 'nullable', 'array'],
            'action_items.*.description' => ['required_with:action_items', 'string', 'max:500'],
            'action_items.*.assigned_to' => ['required_with:action_items', 'exists:users,id'],
            'action_items.*.due_date' => ['required_with:action_items', 'date'],
        ]);

        $meeting->update($validated);

        return redirect()->back()->with('success', 'Meeting updated successfully.');
    }

    /**
     * Create a worker consultation record.
     */
    public function storeConsultation(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'consultation_type' => ['required', 'string', 'in:change_notification,hazard_review,policy_review,risk_assessment,general'],
            'description' => ['required', 'string', 'max:5000'],
            'site_id' => ['required', 'exists:sites,id'],
            'consultation_date' => ['required', 'date'],
        ]);

        HsConsultation::create(array_merge($validated, [
            'status' => 'open',
            'initiated_by' => $request->user()->id,
            'created_by' => $request->user()->id,
        ]));

        return redirect()->back()->with('success', 'Consultation created successfully.');
    }

    /**
     * Update a consultation (feedback, outcome, status).
     */
    public function updateConsultation(Request $request, HsConsultation $consultation): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:open,in_progress,closed'],
            'worker_feedback_summary' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'outcome' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        $consultation->update($validated);

        return redirect()->back()->with('success', 'Consultation updated successfully.');
    }

    /* ================================================================== */
    /*  Consultation Workflow                                              */
    /* ================================================================== */

    /**
     * Update a consultation's status with optional feedback/outcome fields.
     */
    public function updateConsultationStatus(Request $request, HsConsultation $consultation): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:open,feedback_received,actioned,closed'],
            'worker_feedback_summary' => ['nullable', 'string', 'max:5000'],
            'outcome' => ['nullable', 'string', 'max:5000'],
            'changes_made' => ['nullable', 'string', 'max:5000'],
            'workers_consulted' => ['nullable', 'array'],
        ]);

        $consultation->update($validated);

        return redirect()->back()->with('success', 'Consultation status updated successfully.');
    }

    /**
     * Upload a document to a consultation (supporting document or outcome document).
     */
    public function uploadConsultationDocument(Request $request, HsConsultation $consultation): RedirectResponse
    {
        $request->validate([
            'document' => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx,xlsx,jpg,png'],
            'type' => ['required', 'string', 'in:document,outcome'],
        ]);

        $file = $request->file('document');
        $storagePath = "health-safety/consultations/{$consultation->id}";
        $path = $file->store($storagePath, 'private');

        if ($request->input('type') === 'document') {
            $consultation->update([
                'document_path' => $path,
                'document_name' => $file->getClientOriginalName(),
            ]);
        } else {
            $consultation->update([
                'outcome_document_path' => $path,
                'outcome_document_name' => $file->getClientOriginalName(),
            ]);
        }

        return redirect()->back()->with('success', 'Document uploaded successfully.');
    }

    /**
     * Download a consultation document.
     */
    public function downloadConsultationDocument(HsConsultation $consultation, string $type): StreamedResponse
    {
        if ($type === 'document') {
            $path = $consultation->document_path;
            $name = $consultation->document_name ?? basename($path);
        } else {
            $path = $consultation->outcome_document_path;
            $name = $consultation->outcome_document_name ?? basename($path);
        }

        abort_unless($path && Storage::disk('private')->exists($path), 404, 'Document not found.');

        return Storage::disk('private')->download($path, $name);
    }

    /* ================================================================== */
    /*  Meeting Workflow                                                   */
    /* ================================================================== */

    /**
     * Add attendees to a meeting and create calendar events for new ones.
     */
    public function addMeetingAttendees(Request $request, HsCommitteeMeeting $meeting): RedirectResponse
    {
        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['exists:users,id'],
        ]);

        $existingAttendees = $meeting->attendees ?? [];
        $newUserIds = array_diff($validated['user_ids'], $existingAttendees);

        $meeting->update([
            'attendees' => array_values(array_unique(array_merge($existingAttendees, $validated['user_ids']))),
        ]);

        // Create calendar events for newly added attendees
        $committee = $meeting->committee;
        $tenantId = $request->user()->tenant_id ?? 1;
        foreach ($newUserIds as $userId) {
            HrCalendarEvent::create([
                'tenant_id' => $tenantId,
                'title' => 'H&S Committee Meeting: ' . ($committee->name ?? 'Meeting'),
                'description' => 'Committee meeting at ' . ($meeting->location ?? 'TBC'),
                'event_type' => 'hs_meeting',
                'starts_at' => $meeting->scheduled_at,
                'ends_at' => $meeting->scheduled_at->copy()->addHour(),
                'is_all_day' => false,
                'location' => $meeting->location,
                'created_by' => $userId,
            ]);
        }

        return redirect()->back()->with('success', 'Attendees added successfully.');
    }

    /**
     * Complete a meeting with minutes, action items, and confirmed attendees.
     */
    public function completeMeeting(Request $request, HsCommitteeMeeting $meeting): RedirectResponse
    {
        $validated = $request->validate([
            'minutes' => ['nullable', 'string', 'max:10000'],
            'action_items' => ['nullable', 'array'],
            'confirmed_attendees' => ['nullable', 'array'],
            'confirmed_attendees.*' => ['exists:users,id'],
            'actual_attendee_ids' => ['nullable', 'array'],
            'actual_attendee_ids.*' => ['exists:users,id'],
        ]);

        // Normalise: frontend may send actual_attendee_ids instead of confirmed_attendees
        if (! empty($validated['actual_attendee_ids']) && empty($validated['confirmed_attendees'])) {
            $validated['confirmed_attendees'] = $validated['actual_attendee_ids'];
        }
        unset($validated['actual_attendee_ids']);

        $meeting->update(array_merge($validated, [
            'status' => 'completed',
            'ended_at' => now(),
        ]));

        return redirect()->back()->with('success', 'Meeting completed successfully.');
    }

    /**
     * Cancel a meeting and remove associated calendar events.
     */
    public function cancelMeeting(Request $request, HsCommitteeMeeting $meeting): RedirectResponse
    {
        $meeting->update(['status' => 'cancelled']);

        // Delete associated calendar events
        $committee = $meeting->committee;
        if ($committee && $meeting->scheduled_at) {
            HrCalendarEvent::where('event_type', 'hs_meeting')
                ->where('starts_at', $meeting->scheduled_at)
                ->where('title', 'like', '%' . $committee->name . '%')
                ->delete();
        }

        return redirect()->back()->with('success', 'Meeting cancelled successfully.');
    }

    /**
     * Upload meeting minutes document.
     */
    public function uploadMeetingMinutes(Request $request, HsCommitteeMeeting $meeting): RedirectResponse
    {
        $request->validate([
            'document' => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx'],
        ]);

        $file = $request->file('document');
        $storagePath = "health-safety/meetings/{$meeting->id}";
        $path = $file->store($storagePath, 'private');

        $meeting->update([
            'minutes_document_path' => $path,
            'minutes_document_name' => $file->getClientOriginalName(),
        ]);

        return redirect()->back()->with('success', 'Meeting minutes uploaded successfully.');
    }

    /**
     * Download meeting minutes document.
     */
    public function downloadMeetingMinutes(HsCommitteeMeeting $meeting): StreamedResponse
    {
        $path = $meeting->minutes_document_path;
        $name = $meeting->minutes_document_name ?? basename($path ?? '');

        abort_unless($path && Storage::disk('private')->exists($path), 404, 'Minutes document not found.');

        return Storage::disk('private')->download($path, $name);
    }
}
