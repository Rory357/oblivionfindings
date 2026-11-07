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
use App\Services\HealthSafety\WorkerParticipationEvidenceReadService;
use App\Services\HealthSafety\WorkerParticipationMeetingDeliveryService;
use App\Services\HealthSafety\WorkerParticipationRegisterReadService;
use App\Services\HealthSafety\WorkerParticipationSchedulingNormalizer;
use App\Services\UserSiteAccessService;
use App\Support\Incidents\IncidentOccurrencePeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
 * meeting stages notices for attendees + the site workforce (#14, sent after the
 * save by WorkerParticipationMeetingDeliveryService), and recurring participation
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
        $this->assertCreatableSite($request, $request->validated('site_id'));
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
        $this->assertWritableSite($request, $representative->site_id);
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

        $this->assertCreatableSite($request, $validated['site_id']);
        $validated = $this->scheduledValues($validated, $request);

        [$committee, $meeting] = DB::transaction(function () use ($validated, $request): array {
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
            if (! $request->boolean('schedule_meeting')) { return [$committee, null]; }

            $meeting = $committee->meetings()->create([
                'scheduled_at' => $validated['scheduled_at'],
                'location' => $validated['location'] ?? null,
                'agenda_items' => $validated['agenda_items'] ?? [],
                'status' => 'scheduled',
                'created_by' => $request->user()->id,
            ]);
            $attendeeIds = $this->cleanIds($validated['attendees'] ?? $committee->members ?? []);
            $meeting->attendeeUsers()->sync($attendeeIds);
            // #14: the notices are staged in the same save, after the Site is checked again; nothing is sent inline.
            $this->stageMeetingNotices($request, $meeting, 'scheduled', $attendeeIds, workforce: true);

            return [$committee, $meeting];
        });

        if ($meeting !== null) {
            return back()->with('success', 'Committee created and first meeting scheduled.');
        }

        return back()->with('success', 'Committee created successfully.')->with('created_committee_id', $committee->id);
    }

    public function storeMeeting(StoreMeetingRequest $request, HsCommittee $committee): RedirectResponse
    {
        $this->assertWritableSite($request, $committee->site_id);
        $data = $this->scheduledValues($request->validated(), $request);

        DB::transaction(function () use ($data, $request, $committee): void {
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
            $this->stageMeetingNotices($request, $meeting, 'scheduled', $attendeeIds, workforce: true);
        });

        return back()->with('success', 'Meeting scheduled successfully.');
    }

    public function updateMeeting(Request $request, int|string $meeting): RedirectResponse
    {
        $meetingId = $this->writableMeetingId($request, $meeting);
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

        if (array_key_exists('action_items', $validated)) {
            $validated['actions_due_count'] = collect($validated['action_items'] ?? [])
                ->filter(fn ($i) => ($i['status'] ?? null) !== 'done')->count();
        }

        DB::transaction(function () use ($request, $meetingId, $validated): void {
            $meeting = $this->lockedMeeting($request, $meetingId);
            $rescheduled = isset($validated['scheduled_at'])
                && Carbon::parse($validated['scheduled_at'])->ne($meeting->scheduled_at);

            $this->updateExactly($meeting, $validated);

            // Reschedule re-notifies attendees; the calendar entry is source-linked
            // (derived live by the provider) so there are no stale rows to chase.
            if ($rescheduled) {
                $this->stageMeetingNotices($request, $meeting, 'rescheduled',
                    $meeting->attendeeUsers()->pluck('users.id')->all(), workforce: false);
            }
        });

        return back()->with('success', 'Meeting updated successfully.');
    }

    public function addMeetingAttendees(Request $request, int|string $meeting): RedirectResponse
    {
        $meetingId = $this->writableMeetingId($request, $meeting);
        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        DB::transaction(function () use ($request, $meetingId, $validated): void {
            $meeting = $this->lockedMeeting($request, $meetingId);
            $before = $meeting->attendeeUsers()->pluck('users.id')->all();
            $meeting->attendeeUsers()->syncWithoutDetaching($this->cleanIds($validated['user_ids']));
            $newIds = array_values(array_diff($this->cleanIds($validated['user_ids']), $before));

            if ($newIds) {
                $this->stageMeetingNotices($request, $meeting, 'attendees_added', $newIds, workforce: false);
            }
        });

        return back()->with('success', 'Attendees added successfully.');
    }

    public function completeMeeting(Request $request, int|string $meeting): RedirectResponse
    {
        $meetingId = $this->writableMeetingId($request, $meeting);
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

        DB::transaction(function () use ($request, $meetingId, $validated): void {
            $meeting = $this->lockedMeeting($request, $meetingId);

            // Mark who actually attended on the pivot (additive — keeps invitees).
            $attended = $this->cleanIds($validated['actual_attendee_ids'] ?? []);
            if ($attended) {
                $meeting->attendeeUsers()->syncWithoutDetaching(
                    collect($attended)->mapWithKeys(fn ($id) => [$id => ['attended' => true, 'response' => 'accepted']])->all()
                );
            }

            $items = $validated['action_items'] ?? $meeting->action_items ?? [];

            $this->updateExactly($meeting, [
                'minutes' => $validated['minutes'] ?? $meeting->minutes,
                'action_items' => $items,
                'actions_due_count' => collect($items)->filter(fn ($i) => ($i['status'] ?? null) !== 'done')->count(),
                'status' => 'completed',
                'ended_at' => now(),
            ]);
        });

        return back()->with('success', 'Meeting completed successfully.');
    }

    public function cancelMeeting(Request $request, int|string $meeting): RedirectResponse
    {
        $meetingId = $this->writableMeetingId($request, $meeting);
        // No more title-LIKE calendar cleanup — the provider derives entries from
        // the meeting row, so flipping status to cancelled is enough.
        DB::transaction(fn () => $this->updateExactly($this->lockedMeeting($request, $meetingId), ['status' => 'cancelled']));

        return back()->with('success', 'Meeting cancelled successfully.');
    }

    public function uploadMeetingMinutes(Request $request, int|string $meeting): RedirectResponse
    {
        $meetingId = $this->writableMeetingId($request, $meeting);
        $request->validate(['document' => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx']]);

        $path = $request->file('document')->store("health-safety/meetings/{$meetingId}", 'private');
        try {
            DB::transaction(fn () => $this->updateExactly($this->lockedMeeting($request, $meetingId), [
                'minutes_document_path' => $path,
                'minutes_document_name' => $request->file('document')->getClientOriginalName(),
            ]));
        } catch (\Throwable $error) {
            // The record still points at its earlier minutes: remove only the file this request stored.
            if (is_string($path) && $path !== '') { \Illuminate\Support\Facades\Storage::disk('private')->delete($path); }
            throw $error;
        }

        return back()->with('success', 'Meeting minutes uploaded successfully.');
    }

    public function downloadMeetingMinutes(Request $request, int|string $meeting): StreamedResponse
    {
        $values = $request->validate(['site_id' => ['nullable', 'integer', 'min:1']]);
        return app(WorkerParticipationEvidenceReadService::class)->download($request, 'meetings', $this->readRecordId($meeting), null, isset($values['site_id']) ? (int) $values['site_id'] : null);
    }

    /**
     * #14 (Stephan, 31 Oct: "Build it"): stage the meeting's notices in the meeting's own save, instead of sending
     * them inline. The delivery service records the meeting's current source, then stages one durable notice per
     * named new attendee (in the app and by email) and, on first scheduling, one in-app notice per current worker at
     * the committee's Site, as HSWA asks. Each notice is sent after the save commits and only while its recipient can
     * still be told; it carries only generic words and a link to the meeting.
     */
    private function stageMeetingNotices(Request $request, HsCommitteeMeeting $meeting, string $reason, array $newAttendeeIds, bool $workforce): void
    {
        $delivery = app(WorkerParticipationMeetingDeliveryService::class);
        $event = $delivery->recordCurrentEpoch($meeting, $request->user(), $reason);
        $delivery->stageToAudience($request->user(), $event['event_id'], $this->cleanIds($newAttendeeIds), $workforce);
    }

    /**
     * A meeting route's ID, checked against the manager's Sites before the meeting itself is read: only its id and
     * committee are loaded first, so another Site's meeting (and its minutes) is never hydrated.
     */
    private function writableMeetingId(Request $request, int|string $meeting): int
    {
        $id = $this->readRecordId($meeting);
        $identity = HsCommitteeMeeting::query()->whereKey($id)->first(['id', 'hs_committee_id']);
        abort_unless($identity !== null, 404);
        $this->assertWritableSite($request, HsCommittee::withTrashed()->whereKey($identity->hs_committee_id)->value('site_id'));

        return $id;
    }

    /** The full meeting, locked inside the write transaction, with its Site checked again on the locked row. */
    private function lockedMeeting(Request $request, int $id): HsCommitteeMeeting
    {
        $meeting = HsCommitteeMeeting::query()->whereKey($id)->lockForUpdate()->firstOrFail();
        $this->assertWritableMeeting($request, $meeting);

        return $meeting;
    }

    /**
     * Save only the requested change. After the update the row is read again: if anything else on it differs from
     * what this request read and wrote (an unrequested change made meanwhile), the whole save is refused with 409 and
     * rolls back, with no audit or notice.
     */
    private function updateExactly(HsCommitteeMeeting $meeting, array $values): void
    {
        $original = $meeting->getRawOriginal();
        $meeting->update($values);
        $written = $meeting->getAttributes();
        $actual = (array) DB::table('hs_committee_meetings')->where('id', $meeting->id)->lockForUpdate()->first();
        foreach ($actual as $column => $value) {
            if ($column === 'updated_at') { continue; }
            $expected = array_key_exists($column, $meeting->getChanges()) ? ($written[$column] ?? null) : ($original[$column] ?? null);
            abort_unless(self::sameStoredValue($expected, $value), 409, 'This meeting was changed by someone else. Reload it and try again.');
        }
    }

    /** Stored values compare as text, JSON by content (MySQL keeps its own key order). */
    private static function sameStoredValue(mixed $expected, mixed $actual): bool
    {
        if ($expected === null || $actual === null) { return $expected === $actual; }
        $expected = $expected instanceof \DateTimeInterface ? $expected->format('Y-m-d H:i:s') : (string) $expected;
        $actual = (string) $actual;
        if ($expected === $actual) { return true; }
        $left = json_decode($expected, true); $right = json_decode($actual, true);
        if (is_array($left) && is_array($right)) { return self::canonical($left) === self::canonical($right); }
        // Dates may come back with or without fractional seconds.
        $leftTime = strtotime($expected); $rightTime = strtotime($actual);

        return $leftTime !== false && $rightTime !== false && preg_match('/^\d{4}-\d{2}-\d{2}/', $expected) === 1 && $leftTime === $rightTime;
    }

    private static function canonical(array $value): array
    {
        if (! array_is_list($value)) { ksort($value); }

        return array_map(fn ($item) => is_array($item) ? self::canonical($item) : $item, $value);
    }

    /* ================================================================== */
    /*  Consultations                                                      */
    /* ================================================================== */

    public function storeConsultation(StoreConsultationRequest $request): RedirectResponse
    {
        $this->assertCreatableSite($request, $request->validated('site_id'));
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
        $this->assertWritableSite($request, $consultation->site_id);
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
        $this->assertWritableSite($request, $consultation->site_id);
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
        $this->assertWritableSite($request, $consultation->site_id);
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

    /**
     * Participation writes follow the register's Site rule: a manager writes only at their own H&S Sites,
     * healthSafety.viewAllSites covers every Site, and a record with no Site needs that all-Sites access.
     * Another Site's record answers 404, as it does in the register.
     */
    private function assertWritableSite(Request $request, int|string|null $siteId): void
    {
        $sites = app(UserSiteAccessService::class);
        if ($sites->canAccessAllHealthSafetySites($request->user())) {
            return;
        }
        abort_unless($siteId !== null && in_array((int) $siteId, $sites->accessibleHealthSafetySiteIds($request->user()), true), 404);
    }

    /** A new record must name one of the manager's H&S Sites; all-Sites access keeps any Site, as before. */
    private function assertCreatableSite(Request $request, int|string|null $siteId): void
    {
        $sites = app(UserSiteAccessService::class);
        if ($sites->canAccessAllHealthSafetySites($request->user())) {
            return;
        }
        $sites->assertCanAccessHealthSafetySiteId($request->user(), $siteId === null ? null : (int) $siteId);
    }

    /** A meeting belongs to its committee's Site. */
    private function assertWritableMeeting(Request $request, HsCommitteeMeeting $meeting): void
    {
        $this->assertWritableSite($request, HsCommittee::withTrashed()->whereKey($meeting->hs_committee_id)->value('site_id'));
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
