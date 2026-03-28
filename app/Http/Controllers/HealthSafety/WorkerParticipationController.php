<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Models\HsCommittee;
use App\Models\HsCommitteeMeeting;
use App\Models\HsConsultation;
use App\Models\HsRepresentative;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

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
            'agenda_items.*' => ['string', 'max:500'],
            'attendees' => ['nullable', 'array'],
            'attendees.*' => ['exists:users,id'],
        ]);

        $committee->meetings()->create(array_merge($validated, [
            'status' => 'scheduled',
            'created_by' => $request->user()->id,
        ]));

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
}
