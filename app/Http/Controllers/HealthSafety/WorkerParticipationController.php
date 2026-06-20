<?php

namespace App\Http\Controllers\HealthSafety;

use App\Domain\Governance\Models\ComplianceObligation;
use App\Domain\Governance\Services\ComplianceEngineService;
use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Controller;
use App\Http\Requests\HealthSafety\StoreConsultationRequest;
use App\Http\Requests\HealthSafety\StoreMeetingRequest;
use App\Http\Requests\HealthSafety\StoreRepresentativeRequest;
use App\Models\HsCommittee;
use App\Models\HsCommitteeMeeting;
use App\Models\HsConsultation;
use App\Models\HsRepresentative;
use App\Models\Site;
use App\Models\StaffCredential;
use App\Models\User;
use App\Notifications\HealthSafety\CommitteeMeetingScheduled;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Worker Participation — HSWA 2015 participation register.
 *
 * Rebuilt to the Health & Safety gold standard (mirrors IncidentsController@index):
 * server-side pagination keyed off the active ?tab=, tabCounts, a hero block, a
 * detail prop loaded only when an entity param is present, and a can block.
 *
 * Calendar / cross-module integration (docs/worker-participation-redesign/) is
 * handled by WorkerParticipationObligationProvider (registered in
 * SiteCalendarAggregator) — this controller no longer writes orphaned,
 * per-attendee HrCalendarEvent rows. Committee-meeting attendance lives in the
 * hs_meeting_attendees pivot (HsCommitteeMeeting::attendeeUsers), scheduling a
 * meeting notifies attendees + the site workforce, and recurring participation
 * duties (HSR <=3yr term re-election, 2-day/yr training) are created as
 * ComplianceObligations via ComplianceEngineService (with reminders scheduled).
 */
class WorkerParticipationController extends Controller
{
    use ServesPrivateAttachments;

    private const TABS = ['representatives', 'meetings', 'consultations'];

    /** Canonical consultation lifecycle, in order. */
    private const CONSULT_STAGES = ['open', 'feedback_received', 'actioned', 'closed'];

    /** StaffCredential.type used to track a trained HSR (NZQA US 29315). */
    private const HSR_CREDENTIAL_TYPE = 'HSR Initial Training (NZQA US 29315)';

    public function __construct(
        private readonly ComplianceEngineService $compliance,
    ) {}

    /* ================================================================== */
    /*  Index                                                              */
    /* ================================================================== */

    public function index(Request $request): \Inertia\Response
    {
        $tab = in_array($request->input('tab'), self::TABS, true)
            ? $request->input('tab')
            : 'representatives';

        $filters = [
            'tab' => $tab,
            'site_id' => $request->integer('site_id') ?: null,
            'status' => $request->input('status') ?: null,
            'period' => $request->input('period', 'quarter'),
            'q' => $request->input('q') ?: null,
        ];

        $rows = match ($tab) {
            'meetings' => $this->meetingRows($filters),
            'consultations' => $this->consultationRows($filters),
            default => $this->representativeRows($filters),
        };

        return Inertia::render('health-safety/worker-participation/index', [
            'filters' => $filters,
            'tab' => $tab,
            'tabCounts' => $this->tabCounts($filters),
            'rows' => $rows,
            'hero' => $this->hero($filters),
            'detail' => $this->detail($request),
            'can' => ['manage' => $request->user()?->canDo('hazards.manage') ?? false],
            'sites' => Site::select('id', 'name')->where('is_active', true)->orderBy('name')->get(),
            'staff' => User::select('id', 'name')->orderBy('name')->get(),
            'committees' => HsCommittee::select('id', 'name', 'site_id', 'meeting_frequency')
                ->withCount('meetings')->orderBy('name')->get(),
        ]);
    }

    /* ---- per-tab paginated lists (server-side filters) --------------- */

    private function representativeRows(array $f)
    {
        return HsRepresentative::query()
            ->with(['user:id,name', 'site:id,name'])
            ->when($f['site_id'], fn ($q, $id) => $q->where('site_id', $id))
            ->when($f['status'], fn ($q, $s) => $q->where('status', $s))
            ->when($f['q'], fn ($q, $term) => $q->where(fn ($w) => $w
                ->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$term}%"))
                ->orWhere('work_group', 'like', "%{$term}%")))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    private function meetingRows(array $f)
    {
        return HsCommitteeMeeting::query()
            ->with(['committee:id,name,site_id'])
            ->withCount(['attendeeUsers as attendees_count'])
            ->when($f['site_id'], fn ($q, $id) => $q->whereHas('committee', fn ($c) => $c->where('site_id', $id)))
            ->when($f['status'], fn ($q, $s) => $q->where('status', $s))
            ->when($this->periodStart($f['period']), fn ($q, $from) => $q->where('scheduled_at', '>=', $from))
            ->when($f['q'], fn ($q, $term) => $q->whereHas('committee', fn ($c) => $c->where('name', 'like', "%{$term}%")))
            ->orderByDesc('scheduled_at')
            ->paginate(20)
            ->withQueryString();
    }

    private function consultationRows(array $f)
    {
        return HsConsultation::query()
            ->with(['site:id,name'])
            ->when($f['site_id'], fn ($q, $id) => $q->where('site_id', $id))
            ->when($f['status'], fn ($q, $s) => $q->where('status', $s))
            ->when($this->periodStart($f['period']), fn ($q, $from) => $q->where('consultation_date', '>=', $from))
            ->when($f['q'], fn ($q, $term) => $q->where('title', 'like', "%{$term}%"))
            ->orderByDesc('consultation_date')
            ->paginate(20)
            ->withQueryString();
    }

    private function tabCounts(array $f): array
    {
        return [
            'representatives' => HsRepresentative::when($f['site_id'], fn ($q, $id) => $q->where('site_id', $id))->count(),
            'meetings' => HsCommitteeMeeting::when($f['site_id'], fn ($q, $id) => $q->whereHas('committee', fn ($c) => $c->where('site_id', $id)))->count(),
            'consultations' => HsConsultation::when($f['site_id'], fn ($q, $id) => $q->where('site_id', $id))->count(),
        ];
    }

    /**
     * Trailing window start for the period pill (recent + all upcoming). Null
     * means no date filter. Representatives are not date-bound, so the period
     * only scopes the meetings + consultations lists.
     */
    private function periodStart(?string $period): ?Carbon
    {
        return match ($period) {
            'week' => now()->startOfWeek(),
            '30d' => now()->subDays(30)->startOfDay(),
            'year' => now()->startOfYear(),
            'quarter' => now()->startOfQuarter(),
            default => null,
        };
    }

    /* ---- hero block (cluster + NZ badge counts/booleans) ------------- */

    private function hero(array $f): array
    {
        $site = $f['site_id'];
        $activeReps = HsRepresentative::where('status', 'active')->when($site, fn ($q, $id) => $q->where('site_id', $id))->count();
        $totalSites = Site::where('is_active', true)->when($site, fn ($q, $id) => $q->where('id', $id))->count();
        $coveredSites = HsRepresentative::where('status', 'active')
            ->when($site, fn ($q, $id) => $q->where('site_id', $id))
            ->distinct('site_id')->count('site_id');
        $minutesOut = HsCommitteeMeeting::where('status', 'completed')->whereNull('minutes_document_path')
            ->when($site, fn ($q, $id) => $q->whereHas('committee', fn ($c) => $c->where('site_id', $id)))
            ->count();
        $awaiting = HsConsultation::whereIn('status', ['open', 'feedback_received'])
            ->when($site, fn ($q, $id) => $q->where('site_id', $id))->count();

        return [
            'clusters' => [
                'participation' => [
                    'active_reps' => $activeReps,
                    'sites_without_rep' => max(0, $totalSites - $coveredSites),
                    'committees' => HsCommittee::where('status', 'active')->when($site, fn ($q, $id) => $q->where('site_id', $id))->count(),
                    'meetings_quarter' => HsCommitteeMeeting::whereBetween('scheduled_at', [now()->startOfQuarter(), now()->endOfQuarter()])
                        ->when($site, fn ($q, $id) => $q->whereHas('committee', fn ($c) => $c->where('site_id', $id)))->count(),
                ],
                'consultation' => [
                    'open' => HsConsultation::where('status', 'open')->when($site, fn ($q, $id) => $q->where('site_id', $id))->count(),
                    'awaiting_feedback' => $awaiting,
                    'overdue_actions' => (int) HsCommitteeMeeting::where('status', '!=', 'cancelled')->where('actions_due_count', '>', 0)
                        ->when($site, fn ($q, $id) => $q->whereHas('committee', fn ($c) => $c->where('site_id', $id)))
                        ->sum('actions_due_count'),
                    'minutes_outstanding' => $minutesOut,
                ],
            ],
            // counts / booleans only — the front-end formats the NZ compliance chips
            'badges' => [
                'reps_coverage_pct' => $totalSites > 0 ? (int) round($coveredSites / $totalSites * 100) : 0,
                'sites_total' => $totalSites,
                'sites_covered' => $coveredSites,
                'minutes_overdue' => $minutesOut,
                'consultations_awaiting' => $awaiting,
                'training_below_minimum' => HsRepresentative::where('status', 'active')->where('training_days_completed', '<', 2)
                    ->when($site, fn ($q, $id) => $q->where('site_id', $id))->count(),
            ],
        ];
    }

    /* ---- detail-over-list (only when an entity param is present) ----- */

    private function detail(Request $request): ?array
    {
        if ($id = $request->integer('consultation')) {
            $c = HsConsultation::with('site:id,name')->find($id);
            if (! $c) {
                return null;
            }
            $c->setAttribute('initiated_by_name', User::whereKey($c->initiated_by)->value('name'));
            $c->setAttribute('stage_index', array_search($c->status, self::CONSULT_STAGES, true) ?: 0);

            return ['kind' => 'consultation', 'data' => $c];
        }

        if ($id = $request->integer('meeting')) {
            $m = HsCommitteeMeeting::with([
                'committee:id,name,site_id',
                'attendeeUsers:id,name',
                'creator:id,name',
            ])->find($id);

            return $m ? ['kind' => 'meeting', 'data' => $m] : null;
        }

        if ($id = $request->integer('representative')) {
            $r = HsRepresentative::with(['user:id,name', 'site:id,name', 'creator:id,name'])->find($id);

            return $r ? ['kind' => 'representative', 'data' => $r] : null;
        }

        return null;
    }

    /* ================================================================== */
    /*  Representatives                                                    */
    /* ================================================================== */

    public function storeRepresentative(StoreRepresentativeRequest $request): RedirectResponse
    {
        $rep = HsRepresentative::create(array_merge($request->validated(), [
            'status' => 'active',
            'created_by' => $request->user()->id,
        ]));

        $fresh = $rep->fresh('user');
        $this->syncRepresentativeObligations($fresh, $request->user());
        $this->syncTrainedHsrCredential($fresh);

        return back()->with('success', 'H&S representative added successfully.');
    }

    public function updateRepresentative(Request $request, HsRepresentative $representative): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:active,inactive,resigned'],
            'work_group' => ['sometimes', 'nullable', 'string', 'max:120'],
            'training_days_completed' => ['sometimes', 'integer', 'min:0', 'max:30'],
            'initial_training_completed_at' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'term_expires_at' => ['sometimes', 'nullable', 'date', 'before_or_equal:'.now()->addYears(3)->toDateString()],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $representative->update($validated);
        $fresh = $representative->fresh('user');
        $this->syncRepresentativeObligations($fresh, $request->user());
        $this->syncTrainedHsrCredential($fresh);

        return back()->with('success', 'Representative updated successfully.');
    }

    /**
     * Create/refresh the recurring HSWA obligations a representative implies:
     * term re-election (HSR term is max 3 years) and the 2-day/yr training
     * entitlement. Deduped by obligation_code (firstOrNew on the live row) and
     * reminders are scheduled so they actually fire.
     */
    private function syncRepresentativeObligations(HsRepresentative $rep, User $actor): void
    {
        if ($rep->status !== 'active') {
            return;
        }

        if ($rep->term_expires_at && ! $this->obligationExists("HSR-TERM-{$rep->id}")) {
            $ob = $this->compliance->createObligation(
                framework: 'hswa',
                title: "HSR term re-election: {$rep->user?->name}",
                description: 'Health & Safety Representative term expires — initiate re-election (HSR term is capped at 3 years).',
                frequency: 'event_driven',
                owner: $actor,
                dueDate: Carbon::parse($rep->term_expires_at),
                obligationCode: "HSR-TERM-{$rep->id}",
                reminderDays: [90, 30, 7],
            );
            $this->compliance->scheduleReminders($ob);
        }

        if ((int) $rep->training_days_completed < 2 && ! $this->obligationExists("HSR-TRAINING-{$rep->id}")) {
            $ob = $this->compliance->createObligation(
                framework: 'hswa',
                title: "HSR training due: {$rep->user?->name}",
                description: 'HSR is below the 2-day/yr paid training entitlement (NZQA US 29315 required before issuing PINs / cease-work).',
                frequency: 'annual',
                owner: $actor,
                obligationCode: "HSR-TRAINING-{$rep->id}",
                reminderDays: [60, 14],
            );
            $this->compliance->scheduleReminders($ob);
        }
    }

    /**
     * Surface a trained HSR (completed NZQA Unit Standard 29315) as a tracked HR
     * credential on the rep's staff record — visible on /staff/{id}/credentials and
     * read by the HR compliance evaluator's `credential` check. Reuses the existing
     * staff_credentials table (no parallel credential system) and is idempotent on
     * [user_id, type], so re-saving the rep never duplicates. Closes the
     * cross-module gap where "trained HSR" (a precondition for issuing PINs /
     * directing cease-unsafe-work) was not a tracked credential.
     */
    private function syncTrainedHsrCredential(HsRepresentative $rep): void
    {
        if (! $rep->initial_training_completed_at || ! $rep->user_id) {
            return;
        }

        StaffCredential::updateOrCreate(
            ['user_id' => $rep->user_id, 'type' => self::HSR_CREDENTIAL_TYPE],
            [
                'issuer' => 'NZQA',
                'reference' => 'US 29315',
                'issued_at' => $rep->initial_training_completed_at,
                'notes' => 'Auto-recorded from the H&S Representative register (initial HSR training).',
            ]
        );
    }

    private function obligationExists(string $code): bool
    {
        return ComplianceObligation::where('obligation_code', $code)
            ->where('status', '!=', 'complete')
            ->exists();
    }

    /* ================================================================== */
    /*  Committees & Meetings                                              */
    /* ================================================================== */

    public function storeCommittee(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'site_id' => ['required', 'exists:sites,id'],
            'meeting_frequency' => ['required', 'string', 'in:weekly,fortnightly,monthly,quarterly'],
            'established_at' => ['required', 'date'],
            'terms_of_reference' => ['nullable', 'string', 'max:5000'],
            'members' => ['required', 'array', 'min:1'],
            'members.*' => ['integer', 'exists:users,id'],
            // Optional inline first meeting — lets the schedule-meeting wizard
            // stand up a brand-new committee AND its first meeting in ONE atomic
            // request, instead of a fragile two-POST chain across an Inertia
            // redirect (which could orphan the committee).
            'schedule_meeting' => ['sometimes', 'boolean'],
            'scheduled_at' => ['required_if:schedule_meeting,true', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
            'agenda_items' => ['nullable', 'array'],
            'agenda_items.*' => ['string', 'max:255'],
            'attendees' => ['nullable', 'array'],
            'attendees.*' => ['integer', 'exists:users,id'],
        ]);

        $committee = HsCommittee::create([
            'name' => $validated['name'],
            'site_id' => $validated['site_id'],
            'meeting_frequency' => $validated['meeting_frequency'],
            'established_at' => $validated['established_at'],
            'terms_of_reference' => $validated['terms_of_reference'] ?? null,
            'members' => $validated['members'],
            'status' => 'active',
            'created_by' => $request->user()->id,
        ]);

        if ($request->boolean('schedule_meeting')) {
            $meeting = $committee->meetings()->create([
                'scheduled_at' => $validated['scheduled_at'],
                'location' => $validated['location'] ?? null,
                'agenda_items' => $validated['agenda_items'] ?? [],
                'status' => 'scheduled',
                'created_by' => $request->user()->id,
            ]);

            $attendeeIds = $this->cleanIds($validated['attendees'] ?? $committee->members ?? []);
            $meeting->attendeeUsers()->sync($attendeeIds);
            $this->notifyMeeting($meeting, $attendeeIds, $committee, notifyWorkers: true);

            return back()->with('success', 'Committee created and first meeting scheduled.');
        }

        return back()->with('success', 'Committee created successfully.')->with('created_committee_id', $committee->id);
    }

    public function storeMeeting(StoreMeetingRequest $request, HsCommittee $committee): RedirectResponse
    {
        $data = $request->validated();

        $meeting = $committee->meetings()->create([
            'scheduled_at' => $data['scheduled_at'],
            'location' => $data['location'] ?? null,
            'agenda_items' => $data['agenda_items'] ?? [],
            'status' => 'scheduled',
            'created_by' => $request->user()->id,
        ]);

        // Real attendee rows (the pivot replaces the old per-attendee
        // HrCalendarEvent-with-created_by hack). Calendar visibility now comes
        // from WorkerParticipationObligationProvider reading scheduled_at.
        $attendeeIds = $this->cleanIds($data['attendees'] ?? $committee->members ?? []);
        $meeting->attendeeUsers()->sync($attendeeIds);

        $this->notifyMeeting($meeting, $attendeeIds, $committee, notifyWorkers: true);

        return back()->with('success', 'Meeting scheduled successfully.');
    }

    public function updateMeeting(Request $request, HsCommitteeMeeting $meeting): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:scheduled,in_progress,completed,cancelled'],
            'scheduled_at' => ['sometimes', 'date'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'agenda_items' => ['sometimes', 'nullable', 'array'],
            'agenda_items.*' => ['string', 'max:255'],
            'minutes' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'action_items' => ['sometimes', 'nullable', 'array'],
            'action_items.*.description' => ['required_with:action_items', 'string', 'max:500'],
            'action_items.*.assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'action_items.*.due_date' => ['nullable', 'date'],
            'action_items.*.status' => ['nullable', 'string', 'in:open,in_progress,done'],
        ]);

        $rescheduled = isset($validated['scheduled_at'])
            && Carbon::parse($validated['scheduled_at'])->ne($meeting->scheduled_at);

        if (array_key_exists('action_items', $validated)) {
            $validated['actions_due_count'] = collect($validated['action_items'] ?? [])
                ->filter(fn ($i) => ($i['status'] ?? null) !== 'done')->count();
        }

        $meeting->update($validated);

        // Reschedule re-notifies attendees; the calendar entry is source-linked
        // (derived live by the provider) so there are no stale rows to chase.
        if ($rescheduled) {
            $this->notifyMeeting($meeting->fresh(), $meeting->attendeeUsers()->pluck('users.id')->all(), $meeting->committee, notifyWorkers: false);
        }

        return back()->with('success', 'Meeting updated successfully.');
    }

    public function addMeetingAttendees(Request $request, HsCommitteeMeeting $meeting): RedirectResponse
    {
        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $before = $meeting->attendeeUsers()->pluck('users.id')->all();
        $meeting->attendeeUsers()->syncWithoutDetaching($this->cleanIds($validated['user_ids']));
        $newIds = array_values(array_diff($this->cleanIds($validated['user_ids']), $before));

        if ($newIds) {
            $this->notifyMeeting($meeting, $newIds, $meeting->committee, notifyWorkers: false);
        }

        return back()->with('success', 'Attendees added successfully.');
    }

    public function completeMeeting(Request $request, HsCommitteeMeeting $meeting): RedirectResponse
    {
        $validated = $request->validate([
            'minutes' => ['nullable', 'string', 'max:10000'],
            'action_items' => ['nullable', 'array'],
            'action_items.*.description' => ['required_with:action_items', 'string', 'max:500'],
            'action_items.*.assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'action_items.*.due_date' => ['nullable', 'date'],
            'action_items.*.status' => ['nullable', 'string', 'in:open,in_progress,done'],
            'actual_attendee_ids' => ['nullable', 'array'],
            'actual_attendee_ids.*' => ['integer', 'exists:users,id'],
        ]);

        // Mark who actually attended on the pivot (additive — keeps invitees).
        $attended = $this->cleanIds($validated['actual_attendee_ids'] ?? []);
        if ($attended) {
            $meeting->attendeeUsers()->syncWithoutDetaching(
                collect($attended)->mapWithKeys(fn ($id) => [$id => ['attended' => true, 'response' => 'accepted']])->all()
            );
        }

        $items = $validated['action_items'] ?? $meeting->action_items ?? [];

        $meeting->update([
            'minutes' => $validated['minutes'] ?? $meeting->minutes,
            'action_items' => $items,
            'actions_due_count' => collect($items)->filter(fn ($i) => ($i['status'] ?? null) !== 'done')->count(),
            'status' => 'completed',
            'ended_at' => now(),
        ]);

        return back()->with('success', 'Meeting completed successfully.');
    }

    public function cancelMeeting(Request $request, HsCommitteeMeeting $meeting): RedirectResponse
    {
        // No more title-LIKE calendar cleanup — the provider derives entries from
        // the meeting row, so flipping status to cancelled is enough.
        $meeting->update(['status' => 'cancelled']);

        return back()->with('success', 'Meeting cancelled successfully.');
    }

    public function uploadMeetingMinutes(Request $request, HsCommitteeMeeting $meeting): RedirectResponse
    {
        $request->validate(['document' => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx']]);

        $path = $request->file('document')->store("health-safety/meetings/{$meeting->id}", 'private');
        $meeting->update([
            'minutes_document_path' => $path,
            'minutes_document_name' => $request->file('document')->getClientOriginalName(),
        ]);

        return back()->with('success', 'Meeting minutes uploaded successfully.');
    }

    public function downloadMeetingMinutes(HsCommitteeMeeting $meeting): StreamedResponse
    {
        $path = $meeting->minutes_document_path;
        abort_unless((bool) $path, 404, 'Minutes document not found.');

        return $this->streamPrivateAttachment(
            'private',
            $path,
            $meeting->minutes_document_name ?? basename($path),
        );
    }

    /**
     * Notify the named attendees (database + email) and, on initial scheduling,
     * post an in-app notice to all other workers rostered at the committee's site
     * — HSWA requires all workers be notified of upcoming committee meetings and
     * given a reasonable opportunity to provide input.
     */
    private function notifyMeeting(HsCommitteeMeeting $meeting, array $attendeeIds, ?HsCommittee $committee, bool $notifyWorkers): void
    {
        $attendeeIds = $this->cleanIds($attendeeIds);

        if ($attendeeIds) {
            $users = User::whereIn('id', $attendeeIds)->get();
            Notification::send($users, new CommitteeMeetingScheduled($meeting, $committee, forAttendee: true));
        }

        if ($notifyWorkers && $committee?->site_id) {
            $workers = User::whereHas('shifts', fn ($q) => $q->where('site_id', $committee->site_id))
                ->whereNotIn('id', $attendeeIds)
                ->get();
            if ($workers->isNotEmpty()) {
                Notification::send($workers, new CommitteeMeetingScheduled($meeting, $committee, forAttendee: false));
            }
        }
    }

    /* ================================================================== */
    /*  Consultations                                                      */
    /* ================================================================== */

    public function storeConsultation(StoreConsultationRequest $request): RedirectResponse
    {
        $data = collect($request->validated())->except('document')->all();

        $consultation = HsConsultation::create(array_merge($data, [
            'status' => 'open',
            'initiated_by' => $request->user()->id,
            'created_by' => $request->user()->id,
        ]));

        // Optional supporting document attached at create time (premium upload in
        // the wizard's Documents step) — stored inline so there's no second hop.
        if ($request->hasFile('document')) {
            $file = $request->file('document');
            $path = $file->store("health-safety/consultations/{$consultation->id}", 'private');
            $consultation->update([
                'document_path' => $path,
                'document_name' => $file->getClientOriginalName(),
            ]);
        }

        return back()->with('success', 'Consultation created successfully.');
    }

    public function updateConsultation(Request $request, HsConsultation $consultation): RedirectResponse
    {
        // One canonical lifecycle everywhere (reconciles the old narrow
        // open/in_progress/closed drift): open -> feedback_received -> actioned -> closed.
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'consultation_type' => ['sometimes', 'string', 'in:hazard_review,risk_assessment,procedure_change,policy_review,equipment_change,change_notification,general'],
            'description' => ['sometimes', 'string', 'max:5000'],
            'consultation_date' => ['sometimes', 'date'],
            'status' => ['sometimes', 'string', 'in:open,feedback_received,actioned,closed'],
            'worker_feedback_summary' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'outcome' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'changes_made' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        $consultation->update($validated);

        return back()->with('success', 'Consultation updated successfully.');
    }

    public function updateConsultationStatus(Request $request, HsConsultation $consultation): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:open,feedback_received,actioned,closed'],
            'worker_feedback_summary' => ['nullable', 'string', 'max:5000'],
            'outcome' => ['nullable', 'string', 'max:5000'],
            'changes_made' => ['nullable', 'string', 'max:5000'],
            'workers_consulted' => ['nullable', 'array'],
            'workers_consulted.*' => ['integer', 'exists:users,id'],
        ]);

        // The lifecycle is monotonic — never regress the stage (e.g. recording
        // late feedback on an already-actioned consultation keeps the later
        // stage); content fields still save. Defense-in-depth — the front-end
        // already computes a non-regressing target.
        $current = array_search($consultation->status, self::CONSULT_STAGES, true);
        $target = array_search($validated['status'], self::CONSULT_STAGES, true);
        if ($current !== false && $target !== false && $target < $current) {
            $validated['status'] = $consultation->status;
        }

        $consultation->update($validated);

        return back()->with('success', 'Consultation status updated successfully.');
    }

    public function uploadConsultationDocument(Request $request, HsConsultation $consultation): RedirectResponse
    {
        $request->validate([
            'document' => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx,xlsx,jpg,png'],
            'type' => ['required', 'string', 'in:document,outcome'],
        ]);

        $path = $request->file('document')->store("health-safety/consultations/{$consultation->id}", 'private');
        $name = $request->file('document')->getClientOriginalName();

        $consultation->update($request->input('type') === 'document'
            ? ['document_path' => $path, 'document_name' => $name]
            : ['outcome_document_path' => $path, 'outcome_document_name' => $name]);

        return back()->with('success', 'Document uploaded successfully.');
    }

    public function downloadConsultationDocument(HsConsultation $consultation, string $type): StreamedResponse
    {
        abort_unless(in_array($type, ['document', 'outcome'], true), 404);

        [$path, $name] = $type === 'document'
            ? [$consultation->document_path, $consultation->document_name]
            : [$consultation->outcome_document_path, $consultation->outcome_document_name];

        abort_unless((bool) $path, 404, 'Document not found.');

        return $this->streamPrivateAttachment(
            'private',
            $path,
            $name ?? basename($path),
        );
    }

    /* ================================================================== */
    /*  Export (board report CSV)                                          */
    /* ================================================================== */

    public function export(Request $request): StreamedResponse
    {
        $site = $request->integer('site_id') ?: null;
        $filename = 'worker_participation_'.now()->format('Ymd_His').'.csv';

        $reps = HsRepresentative::with(['user:id,name', 'site:id,name'])
            ->when($site, fn ($q, $id) => $q->where('site_id', $id))->orderBy('status')->get();
        $meetings = HsCommitteeMeeting::with('committee:id,name')
            ->when($site, fn ($q, $id) => $q->whereHas('committee', fn ($c) => $c->where('site_id', $id)))
            ->orderByDesc('scheduled_at')->get();
        $consultations = HsConsultation::with('site:id,name')
            ->when($site, fn ($q, $id) => $q->where('site_id', $id))->orderByDesc('consultation_date')->get();

        return response()->streamDownload(function () use ($reps, $meetings, $consultations) {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['H&S Representatives']);
            fputcsv($out, ['Name', 'Site', 'Work group', 'Election method', 'Elected', 'Term expires', 'Training days', 'Status']);
            foreach ($reps as $r) {
                fputcsv($out, [
                    $r->user?->name, $r->site?->name, $r->work_group, $r->election_method,
                    optional($r->elected_at)->toDateString(), optional($r->term_expires_at)->toDateString(),
                    $r->training_days_completed, $r->status,
                ]);
            }

            fputcsv($out, []);
            fputcsv($out, ['Committee Meetings']);
            fputcsv($out, ['Committee', 'Scheduled', 'Status', 'Minutes filed', 'Actions due']);
            foreach ($meetings as $m) {
                fputcsv($out, [
                    $m->committee?->name, optional($m->scheduled_at)->format('Y-m-d H:i'), $m->status,
                    $m->minutes_document_path ? 'Yes' : 'No', $m->actions_due_count,
                ]);
            }

            fputcsv($out, []);
            fputcsv($out, ['Worker Consultations']);
            fputcsv($out, ['Title', 'Type', 'Site', 'Date', 'Status', 'Supporting doc', 'Outcome doc']);
            foreach ($consultations as $c) {
                fputcsv($out, [
                    $c->title, $c->consultation_type, $c->site?->name, optional($c->consultation_date)->toDateString(),
                    $c->status, $c->document_path ? 'Yes' : 'No', $c->outcome_document_path ? 'Yes' : 'No',
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return int[]
     */
    private function cleanIds(array $ids): array
    {
        return array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));
    }
}
