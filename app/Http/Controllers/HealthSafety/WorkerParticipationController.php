<?php

namespace App\Http\Controllers\HealthSafety;

use App\Domain\Governance\Models\ComplianceObligation;
use App\Domain\Governance\Services\ComplianceEngineService;
use App\Http\Controllers\Controller;
use App\Http\Requests\HealthSafety\StoreConsultationRequest;
use App\Http\Requests\HealthSafety\StoreMeetingRequest;
use App\Http\Requests\HealthSafety\StoreRepresentativeRequest;
use App\Models\HsCommittee;
use App\Models\HsCommitteeMeeting;
use App\Models\HsConsultation;
use App\Models\HsRepresentative;
use App\Models\User;
use App\Notifications\HealthSafety\CommitteeMeetingScheduled;
use App\Services\HealthSafety\WorkerParticipationEvidenceReadService;
use App\Services\HealthSafety\WorkerParticipationRegisterReadService;
use App\Services\HealthSafety\WorkerParticipationSchedulingNormalizer;
use App\Support\Incidents\IncidentOccurrencePeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
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
    /** Canonical consultation lifecycle, in order. */
    private const CONSULT_STAGES = ['open', 'feedback_received', 'actioned', 'closed'];

    public function __construct(
        private readonly ComplianceEngineService $compliance,
    ) {}

    /* ================================================================== */
    /*  Index                                                              */
    /* ================================================================== */

    public function index(Request $request): \Inertia\Response
    {
        $props = app(WorkerParticipationRegisterReadService::class)->index($request);
        // Never bind a new account to private props retained from an earlier partial response.
        return Inertia::render('health-safety/worker-participation/index', array_map(fn ($value) => Inertia::always($value), $props));
    }

    /** Only labelled wall-time inputs opt into the worker scheduling contract. */
    private function scheduledValues(array $values, Request $request): array
    {
        $normalized = WorkerParticipationSchedulingNormalizer::normalize($values['scheduled_at'] ?? null,
            $request->input('scheduled_timezone'), $request->input('scheduled_offset'), IncidentOccurrencePeriod::timezone());
        if ($normalized['errors'] !== []) { throw ValidationException::withMessages($normalized['errors']); }
        if (array_key_exists('scheduled_at', $values)) { $values['scheduled_at'] = $normalized['value']; }
        unset($values['scheduled_timezone'], $values['scheduled_offset']);

        return $values;
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

        return back()->with('success', 'H&S representative added successfully.');
    }

    public function updateRepresentative(Request $request, HsRepresentative $representative): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:active,inactive,resigned'],
            'work_group' => ['sometimes', 'nullable', 'string', 'max:120'],
            'training_days_completed' => ['sometimes', 'integer', 'min:0', 'max:30'],
            'initial_training_completed_at' => ['sometimes', 'nullable', 'date', 'before_or_equal:'.now(IncidentOccurrencePeriod::timezone())->toDateString()],
            'term_expires_at' => ['sometimes', 'nullable', 'date', 'before_or_equal:'.now(IncidentOccurrencePeriod::timezone())->addYears(3)->toDateString()],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $representative->update($validated);
        $fresh = $representative->fresh('user');
        $this->syncRepresentativeObligations($fresh, $request->user());

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
            'scheduled_timezone' => ['nullable', 'string', 'max:80'],
            'scheduled_offset' => ['nullable', 'string', 'max:6'],
            'location' => ['nullable', 'string', 'max:255'],
            'agenda_items' => ['nullable', 'array'],
            'agenda_items.*' => ['string', 'max:255'],
            'attendees' => ['nullable', 'array'],
            'attendees.*' => ['integer', 'exists:users,id'],
        ]);

        $validated = $this->scheduledValues($validated, $request);

        [$committee, $meeting, $attendeeIds] = DB::transaction(function () use ($validated, $request): array {
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
            if (! $request->boolean('schedule_meeting')) { return [$committee, null, []]; }

            $meeting = $committee->meetings()->create([
                'scheduled_at' => $validated['scheduled_at'],
                'location' => $validated['location'] ?? null,
                'agenda_items' => $validated['agenda_items'] ?? [],
                'status' => 'scheduled',
                'created_by' => $request->user()->id,
            ]);
            $attendeeIds = $this->cleanIds($validated['attendees'] ?? $committee->members ?? []);
            $meeting->attendeeUsers()->sync($attendeeIds);
            return [$committee, $meeting, $attendeeIds];
        });

        if ($meeting !== null) {
            $this->notifyMeeting($meeting, $attendeeIds, $committee, notifyWorkers: true);

            return back()->with('success', 'Committee created and first meeting scheduled.');
        }

        return back()->with('success', 'Committee created successfully.')->with('created_committee_id', $committee->id);
    }

    public function storeMeeting(StoreMeetingRequest $request, HsCommittee $committee): RedirectResponse
    {
        $data = $this->scheduledValues($request->validated(), $request);

        [$meeting, $attendeeIds] = DB::transaction(function () use ($data, $request, $committee): array {
            $meeting = $committee->meetings()->create([
                'scheduled_at' => $data['scheduled_at'],
                'location' => $data['location'] ?? null,
                'agenda_items' => $data['agenda_items'] ?? [],
                'status' => 'scheduled',
                'created_by' => $request->user()->id,
            ]);
            // Attendance and its meeting commit together; calendar occurrences are derived live.
            $attendeeIds = $this->cleanIds($data['attendees'] ?? $committee->members ?? []);
            $meeting->attendeeUsers()->sync($attendeeIds);
            return [$meeting, $attendeeIds];
        });

        $this->notifyMeeting($meeting, $attendeeIds, $committee, notifyWorkers: true);

        return back()->with('success', 'Meeting scheduled successfully.');
    }

    public function updateMeeting(Request $request, HsCommitteeMeeting $meeting): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:scheduled,in_progress,completed,cancelled'],
            'scheduled_at' => ['sometimes', 'date'],
            'scheduled_timezone' => ['nullable', 'string', 'max:80'],
            'scheduled_offset' => ['nullable', 'string', 'max:6'],
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

        $validated = $this->scheduledValues($validated, $request);

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

    public function downloadMeetingMinutes(Request $request, int|string $meeting): StreamedResponse
    {
        $values = $request->validate(['site_id' => ['nullable', 'integer', 'min:1']]);
        return app(WorkerParticipationEvidenceReadService::class)->download($request, 'meetings', $this->readRecordId($meeting), null, isset($values['site_id']) ? (int) $values['site_id'] : null);
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

    public function downloadConsultationDocument(Request $request, int|string $consultation, string $type): StreamedResponse
    {
        abort_unless(in_array($type, ['document', 'outcome'], true), 404);
        $values = $request->validate(['site_id' => ['nullable', 'integer', 'min:1']]);
        return app(WorkerParticipationEvidenceReadService::class)->download($request, 'consultations', $this->readRecordId($consultation), $type, isset($values['site_id']) ? (int) $values['site_id'] : null);
    }

    /* ================================================================== */
    /*  Export (board report CSV)                                          */
    /* ================================================================== */

    public function export(Request $request): StreamedResponse
    {
        $values = $request->validate(['site_id' => ['nullable', 'integer', 'min:1']]);
        return app(WorkerParticipationEvidenceReadService::class)->export($request, isset($values['site_id']) ? (int) $values['site_id'] : null);
    }

    /** Parse native read IDs before hydration, preserving decimal URLs without integer overflow. */
    private function readRecordId(int|string $value): int
    {
        $digits = ltrim((string) $value, '0');
        $maximum = (string) PHP_INT_MAX;
        abort_unless($digits !== '' && ctype_digit($digits)
            && (strlen($digits) < strlen($maximum) || strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) <= 0), 404);
        return (int) $digits;
    }

    /** @param array<int, mixed> $ids
     * @return int[]
     */
    private function cleanIds(array $ids): array
    {
        return array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));
    }
}
