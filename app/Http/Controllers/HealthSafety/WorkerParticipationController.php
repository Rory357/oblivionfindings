<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class WorkerParticipationController extends Controller
{
    /**
     * List H&S reps, committees, upcoming meetings, and consultations.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.view'), 403);

        $tenantId = $user->tenant_id;
        $siteFilter = $request->input('site_id');

        // H&S Representatives
        $repsQuery = \DB::table('hs_representatives')
            ->where('tenant_id', $tenantId)
            ->when($siteFilter, fn ($q) => $q->where('site_id', $siteFilter));

        $activeReps = (clone $repsQuery)->where('status', 'active')->count();

        $reps = (clone $repsQuery)
            ->leftJoin('users', 'hs_representatives.user_id', '=', 'users.id')
            ->leftJoin('sites', 'hs_representatives.site_id', '=', 'sites.id')
            ->select(
                'hs_representatives.*',
                'users.name as user_name',
                'sites.name as site_name'
            )
            ->orderByDesc('hs_representatives.created_at')
            ->get();

        // Committees
        $committeesQuery = \DB::table('hs_committees')
            ->where('tenant_id', $tenantId)
            ->when($siteFilter, fn ($q) => $q->where('site_id', $siteFilter));

        $activeCommittees = (clone $committeesQuery)->where('status', 'active')->count();

        $committees = (clone $committeesQuery)
            ->leftJoin('sites', 'hs_committees.site_id', '=', 'sites.id')
            ->select('hs_committees.*', 'sites.name as site_name')
            ->orderByDesc('hs_committees.created_at')
            ->get();

        // Committee Meetings this month
        $meetingsThisMonth = \DB::table('hs_committee_meetings')
            ->join('hs_committees', 'hs_committee_meetings.committee_id', '=', 'hs_committees.id')
            ->where('hs_committees.tenant_id', $tenantId)
            ->whereBetween('hs_committee_meetings.scheduled_at', [
                now()->startOfMonth(),
                now()->endOfMonth(),
            ])
            ->count();

        $upcomingMeetings = \DB::table('hs_committee_meetings')
            ->join('hs_committees', 'hs_committee_meetings.committee_id', '=', 'hs_committees.id')
            ->where('hs_committees.tenant_id', $tenantId)
            ->where('hs_committee_meetings.scheduled_at', '>=', now())
            ->orderBy('hs_committee_meetings.scheduled_at')
            ->select('hs_committee_meetings.*', 'hs_committees.name as committee_name')
            ->limit(20)
            ->get();

        // Consultations
        $consultationsQuery = \DB::table('hs_consultations')
            ->where('tenant_id', $tenantId)
            ->when($siteFilter, fn ($q) => $q->where('site_id', $siteFilter));

        $openConsultations = (clone $consultationsQuery)->where('status', 'open')->count();

        $consultations = (clone $consultationsQuery)
            ->leftJoin('sites', 'hs_consultations.site_id', '=', 'sites.id')
            ->select('hs_consultations.*', 'sites.name as site_name')
            ->orderByDesc('hs_consultations.created_at')
            ->limit(50)
            ->get();

        $sites = \DB::table('sites')
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'name']);

        $staff = \DB::table('users')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('health-safety/worker-participation/index', [
            'reps' => $reps,
            'committees' => $committees,
            'upcomingMeetings' => $upcomingMeetings,
            'consultations' => $consultations,
            'stats' => [
                'active_reps' => $activeReps,
                'active_committees' => $activeCommittees,
                'meetings_this_month' => $meetingsThisMonth,
                'open_consultations' => $openConsultations,
            ],
            'sites' => $sites,
            'staff' => $staff,
            'filters' => $request->only(['site_id']),
        ]);
    }

    /**
     * Create a new H&S representative.
     */
    public function storeRepresentative(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'site_id' => ['required', 'exists:sites,id'],
            'election_method' => ['required', 'string', 'in:elected,appointed,volunteered'],
            'elected_at' => ['required', 'date'],
            'term_expires_at' => ['nullable', 'date', 'after:elected_at'],
            'training_days_used' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        \DB::table('hs_representatives')->insert(array_merge($validated, [
            'tenant_id' => $user->tenant_id,
            'status' => 'active',
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return redirect()->back()->with('success', 'H&S representative added successfully.');
    }

    /**
     * Update an H&S representative.
     */
    public function updateRepresentative(Request $request, int $representative)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $rep = \DB::table('hs_representatives')
            ->where('id', $representative)
            ->where('tenant_id', $user->tenant_id)
            ->firstOrFail();

        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:active,inactive,resigned'],
            'training_days_used' => ['sometimes', 'integer', 'min:0'],
            'term_expires_at' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        \DB::table('hs_representatives')
            ->where('id', $representative)
            ->update(array_merge($validated, [
                'updated_at' => now(),
            ]));

        return redirect()->back()->with('success', 'Representative updated successfully.');
    }

    /**
     * Create a new H&S committee.
     */
    public function storeCommittee(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'site_id' => ['required', 'exists:sites,id'],
            'meeting_frequency' => ['required', 'string', 'in:weekly,fortnightly,monthly,quarterly'],
            'established_at' => ['required', 'date'],
            'terms_of_reference' => ['nullable', 'string', 'max:5000'],
            'members' => ['required', 'array', 'min:1'],
            'members.*' => ['exists:users,id'],
        ]);

        $members = $validated['members'];
        unset($validated['members']);

        $committeeId = \DB::table('hs_committees')->insertGetId(array_merge($validated, [
            'tenant_id' => $user->tenant_id,
            'status' => 'active',
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        // Attach members
        foreach ($members as $memberId) {
            \DB::table('hs_committee_members')->insert([
                'committee_id' => $committeeId,
                'user_id' => $memberId,
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return redirect()->back()->with('success', 'Committee created successfully.');
    }

    /**
     * Create a meeting for a committee.
     */
    public function storeMeeting(Request $request, int $committee)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $committeeRecord = \DB::table('hs_committees')
            ->where('id', $committee)
            ->where('tenant_id', $user->tenant_id)
            ->firstOrFail();

        $validated = $request->validate([
            'scheduled_at' => ['required', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
            'agenda_items' => ['nullable', 'array'],
            'agenda_items.*' => ['string', 'max:500'],
            'attendees' => ['nullable', 'array'],
            'attendees.*' => ['exists:users,id'],
        ]);

        $attendees = $validated['attendees'] ?? [];
        unset($validated['attendees']);

        $validated['agenda_items'] = json_encode($validated['agenda_items'] ?? []);

        $meetingId = \DB::table('hs_committee_meetings')->insertGetId(array_merge($validated, [
            'committee_id' => $committee,
            'status' => 'scheduled',
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        foreach ($attendees as $attendeeId) {
            \DB::table('hs_committee_meeting_attendees')->insert([
                'meeting_id' => $meetingId,
                'user_id' => $attendeeId,
                'created_at' => now(),
            ]);
        }

        return redirect()->back()->with('success', 'Meeting scheduled successfully.');
    }

    /**
     * Update a committee meeting (minutes, action items, status).
     */
    public function updateMeeting(Request $request, int $meeting)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $meetingRecord = \DB::table('hs_committee_meetings')
            ->join('hs_committees', 'hs_committee_meetings.committee_id', '=', 'hs_committees.id')
            ->where('hs_committee_meetings.id', $meeting)
            ->where('hs_committees.tenant_id', $user->tenant_id)
            ->select('hs_committee_meetings.*')
            ->firstOrFail();

        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:scheduled,in_progress,completed,cancelled'],
            'minutes' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'action_items' => ['sometimes', 'nullable', 'array'],
            'action_items.*.description' => ['required_with:action_items', 'string', 'max:500'],
            'action_items.*.assigned_to' => ['required_with:action_items', 'exists:users,id'],
            'action_items.*.due_date' => ['required_with:action_items', 'date'],
        ]);

        if (isset($validated['action_items'])) {
            $validated['action_items'] = json_encode($validated['action_items']);
        }

        \DB::table('hs_committee_meetings')
            ->where('id', $meeting)
            ->update(array_merge($validated, [
                'updated_at' => now(),
            ]));

        return redirect()->back()->with('success', 'Meeting updated successfully.');
    }

    /**
     * Create a worker consultation record.
     */
    public function storeConsultation(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:change_notification,hazard_review,policy_review,risk_assessment,general'],
            'description' => ['required', 'string', 'max:5000'],
            'site_id' => ['required', 'exists:sites,id'],
            'consultation_date' => ['required', 'date'],
        ]);

        \DB::table('hs_consultations')->insert(array_merge($validated, [
            'tenant_id' => $user->tenant_id,
            'status' => 'open',
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return redirect()->back()->with('success', 'Consultation created successfully.');
    }

    /**
     * Update a consultation (feedback, outcome, status).
     */
    public function updateConsultation(Request $request, int $consultation)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $record = \DB::table('hs_consultations')
            ->where('id', $consultation)
            ->where('tenant_id', $user->tenant_id)
            ->firstOrFail();

        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:open,in_progress,closed'],
            'feedback' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'outcome' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        \DB::table('hs_consultations')
            ->where('id', $consultation)
            ->update(array_merge($validated, [
                'updated_at' => now(),
            ]));

        return redirect()->back()->with('success', 'Consultation updated successfully.');
    }
}
