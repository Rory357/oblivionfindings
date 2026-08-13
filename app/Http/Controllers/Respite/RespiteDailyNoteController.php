<?php

namespace App\Http\Controllers\Respite;

use App\Events\Respite\RespiteEvent;
use App\Http\Controllers\Controller;
use App\Models\ClientIncident;
use App\Models\RespiteAuditLog;
use App\Models\RespiteDailyNote;
use App\Models\RespiteStay;
use App\Services\Incidents\IncidentJourneyService;
use App\Services\Respite\RespiteStayScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class RespiteDailyNoteController extends Controller
{
    public function __construct(
        private readonly RespiteStayScope $stayScope,
    ) {}

    public function index(Request $request): Response
    {
        $notes = RespiteDailyNote::query()
            ->with(['stay.client', 'linkedIncident'])
            ->whereHas('stay', fn ($stays) => $this->stayScope->applyAccessibleStayScope($stays, $request))
            ->when($request->stay_id, fn ($q, $stayId) => $q->where('stay_id', $stayId))
            ->when($request->client_id, fn ($q, $clientId) => $q->where('client_id', $clientId))
            ->when($request->date, fn ($q, $date) => $q->forDate($date))
            ->when($request->date_from && $request->date_to, fn ($q) => $q->forDateRange($request->date_from, $request->date_to))
            ->when($request->shift_period, fn ($q, $shift) => $q->where('shift_period', $shift))
            ->when($request->with_concerns, fn ($q) => $q->withConcerns())
            ->when($request->with_incidents, fn ($q) => $q->withIncidents())
            ->when($request->sensitive, fn ($q) => $q->sensitive())
            ->orderByDesc('note_date')
            ->orderByDesc('created_at')
            ->paginate(20);

        $notes->getCollection()->each(function (RespiteDailyNote $note) use ($request): void {
            $stay = $this->stayScope->resolveAuthorizedStay($request, (int) $note->stay_id);
            $this->stayScope->dailyNote($stay, (int) $note->id, null);
        });

        return Inertia::render('respite/daily-notes/index', [
            'notes' => $notes,
            'filters' => $request->only(['stay_id', 'client_id', 'date', 'date_from', 'date_to', 'shift_period', 'with_concerns', 'with_incidents', 'sensitive']),
            'shiftPeriods' => $this->getShiftPeriods(),
            'wellbeingLevels' => $this->getWellbeingLevels(),
        ]);
    }

    public function create(Request $request): Response
    {
        $stays = RespiteStay::query()
            ->with('client')
            ->tap(fn ($query) => $this->stayScope->applyAccessibleStayScope($query, $request))
            ->whereIn('status', ['admitted', 'active', 'extended'])
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('respite/daily-notes/create', [
            'stays' => $stays,
            'stayId' => $request->stay_id,
            'clientId' => $request->client_id,
            'shiftPeriods' => $this->getShiftPeriods(),
            'wellbeingLevels' => $this->getWellbeingLevels(),
            'mobilityLevels' => $this->getMobilityLevels(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'stay_id' => 'required|integer',
            'client_id' => 'required|integer',
            'note_date' => 'required|date',
            'shift_period' => 'required|in:morning,afternoon,evening,night,all_day',
            'mood' => 'nullable|in:very_low,low,neutral,good,excellent',
            'appetite' => 'nullable|in:none,minimal,fair,good,excellent',
            'sleep_quality' => 'nullable|in:very_poor,poor,fair,good,excellent',
            'engagement' => 'nullable|in:none,minimal,moderate,good,excellent',
            'taha_wairua' => 'nullable|in:very_low,low,neutral,good,excellent',
            'taha_whanau' => 'nullable|in:very_low,low,neutral,good,excellent',
            'whanau_contact' => 'nullable|string|max:2000',
            'cultural_support_provided' => 'nullable|string|max:2000',
            'mobility' => 'nullable|in:bedbound,limited,assisted,independent',
            'activities' => 'nullable|string|max:2000',
            'observations' => 'nullable|string|max:5000',
            'concerns' => 'nullable|string|max:2000',
            'goals_progress' => 'nullable|string|max:2000',
            'medication_notes' => 'nullable|string|max:2000',
            'personal_care_notes' => 'nullable|string|max:2000',
            'nutrition_notes' => 'nullable|string|max:2000',
            'incident_occurred' => 'boolean',
            'linked_incident_id' => 'nullable|integer',
            'sensitive_flag' => 'boolean',
        ]);

        $note = DB::transaction(function () use ($request, $validated): RespiteDailyNote {
            $stay = $this->stayScope->resolveAuthorizedStay($request, (int) $validated['stay_id'], true);
            $this->stayScope->assertSubmittedClient($stay, $validated['client_id']);

            if (! empty($validated['linked_incident_id'])) {
                $this->stayScope->incident($stay, (int) $validated['linked_incident_id'], 'linked_incident_id', true);
            }

            $attributes = $validated;
            $attributes['stay_id'] = $stay->id;
            $attributes['client_id'] = $stay->client_id;
            $attributes['created_by'] = $request->user()?->id;

            $note = RespiteDailyNote::create($attributes);
            $this->createIncidentFromNoteWhenNeeded($note, $stay);

            RespiteAuditLog::log(
                $note,
                RespiteAuditLog::ACTION_CREATED,
                $request->user()?->id,
                null,
                array_diff_key($attributes, ['observations' => null, 'concerns' => null]),
                null,
                RespiteAuditLog::CATEGORY_STAY
            );

            return $note->fresh();
        }, 3);

        event(new RespiteEvent('respite.daily_note.created', [
            'id' => $note->id,
            'stay_id' => $note->stay_id,
            'client_id' => $note->client_id,
            'has_concerns' => ! empty($note->concerns),
            'has_incident' => $note->incident_occurred,
        ]));

        return redirect()
            ->route('respite.daily-notes.show', $note)
            ->with('success', 'Daily note created.');
    }

    public function show(RespiteDailyNote $dailyNote): Response
    {
        $stay = $this->stayScope->resolveAuthorizedStay(request(), (int) $dailyNote->stay_id);
        $dailyNote = $this->stayScope->dailyNote($stay, (int) $dailyNote->id, null);
        $dailyNote->load(['stay.client', 'linkedIncident', 'creator']);

        RespiteAuditLog::log(
            $dailyNote,
            RespiteAuditLog::ACTION_VIEWED,
            auth()->id(),
            null,
            null,
            null,
            RespiteAuditLog::CATEGORY_STAY
        );

        return Inertia::render('respite/daily-notes/show', [
            'note' => $dailyNote,
            'wellbeingSummary' => $dailyNote->getWellbeingSummary(),
            'wellbeingScore' => $dailyNote->getWellbeingScore(),
        ]);
    }

    public function update(Request $request, RespiteDailyNote $dailyNote): RedirectResponse
    {
        $validated = $request->validate([
            'mood' => 'nullable|in:very_low,low,neutral,good,excellent',
            'appetite' => 'nullable|in:none,minimal,fair,good,excellent',
            'sleep_quality' => 'nullable|in:very_poor,poor,fair,good,excellent',
            'engagement' => 'nullable|in:none,minimal,moderate,good,excellent',
            'taha_wairua' => 'nullable|in:very_low,low,neutral,good,excellent',
            'taha_whanau' => 'nullable|in:very_low,low,neutral,good,excellent',
            'whanau_contact' => 'nullable|string|max:2000',
            'cultural_support_provided' => 'nullable|string|max:2000',
            'mobility' => 'nullable|in:bedbound,limited,assisted,independent',
            'activities' => 'nullable|string|max:2000',
            'observations' => 'nullable|string|max:5000',
            'concerns' => 'nullable|string|max:2000',
            'goals_progress' => 'nullable|string|max:2000',
            'medication_notes' => 'nullable|string|max:2000',
            'personal_care_notes' => 'nullable|string|max:2000',
            'nutrition_notes' => 'nullable|string|max:2000',
            'incident_occurred' => 'sometimes|boolean',
            'linked_incident_id' => 'nullable|integer',
            'sensitive_flag' => 'sometimes|boolean',
        ]);

        $dailyNote = DB::transaction(function () use ($request, $dailyNote, $validated): RespiteDailyNote {
            $stay = $this->stayScope->resolveAuthorizedStay($request, (int) $dailyNote->stay_id, true);
            $lockedNote = $this->stayScope->dailyNote($stay, (int) $dailyNote->id, null, true);
            $oldValues = $lockedNote->only([
                'mood', 'appetite', 'sleep_quality', 'engagement', 'mobility',
                'concerns', 'incident_occurred', 'sensitive_flag',
            ]);

            if (! empty($validated['linked_incident_id'])) {
                $this->stayScope->incident($stay, (int) $validated['linked_incident_id'], 'linked_incident_id', true);
            }

            $attributes = $validated;
            $attributes['updated_by'] = $request->user()?->id;
            $lockedNote->update($attributes);
            $this->createIncidentFromNoteWhenNeeded($lockedNote, $stay);

            RespiteAuditLog::log(
                $lockedNote,
                RespiteAuditLog::ACTION_UPDATED,
                $request->user()?->id,
                $oldValues,
                array_intersect_key($attributes, $oldValues),
                null,
                RespiteAuditLog::CATEGORY_STAY
            );

            return $lockedNote->fresh();
        }, 3);

        event(new RespiteEvent('respite.daily_note.updated', [
            'id' => $dailyNote->id,
            'stay_id' => $dailyNote->stay_id,
        ]));

        return back()->with('success', 'Daily note updated.');
    }

    public function forStay(RespiteStay $stay): Response
    {
        $stay = $this->stayScope->resolveAuthorizedStay(request(), (int) $stay->id);
        $notes = RespiteDailyNote::query()
            ->where('stay_id', $stay->id)
            ->where('client_id', $stay->client_id)
            ->with('linkedIncident')
            ->orderByDesc('note_date')
            ->orderByDesc('created_at')
            ->get();

        $notes->each(fn (RespiteDailyNote $note) => $this->stayScope->dailyNote($stay, (int) $note->id, null));

        $wellbeingTrend = $notes->map(fn ($note) => [
            'date' => $note->note_date->format('Y-m-d'),
            'shift' => $note->shift_period,
            'score' => $note->getWellbeingScore(),
            'mood' => $note->mood,
            'concerns' => $note->hasConcerns(),
        ]);

        return Inertia::render('respite/daily-notes/for-stay', [
            'stay' => $stay->load('client'),
            'notes' => $notes,
            'wellbeingTrend' => $wellbeingTrend,
        ]);
    }

    public function withConcerns(Request $request): Response
    {
        $notes = RespiteDailyNote::query()
            ->withConcerns()
            ->whereHas('stay', fn ($stays) => $this->stayScope->applyAccessibleStayScope($stays, $request))
            ->with(['stay.client'])
            ->orderByDesc('note_date')
            ->paginate(20);

        $notes->getCollection()->each(function (RespiteDailyNote $note) use ($request): void {
            $stay = $this->stayScope->resolveAuthorizedStay($request, (int) $note->stay_id);
            $this->stayScope->dailyNote($stay, (int) $note->id, null);
        });

        return Inertia::render('respite/daily-notes/with-concerns', [
            'notes' => $notes,
        ]);
    }

    public function withIncidents(Request $request): Response
    {
        $notes = RespiteDailyNote::query()
            ->withIncidents()
            ->whereHas('stay', fn ($stays) => $this->stayScope->applyAccessibleStayScope($stays, $request))
            ->with(['stay.client', 'linkedIncident'])
            ->orderByDesc('note_date')
            ->paginate(20);

        $notes->getCollection()->each(function (RespiteDailyNote $note) use ($request): void {
            $stay = $this->stayScope->resolveAuthorizedStay($request, (int) $note->stay_id);
            $this->stayScope->dailyNote($stay, (int) $note->id, null);
        });

        return Inertia::render('respite/daily-notes/with-incidents', [
            'notes' => $notes,
        ]);
    }

    protected function getShiftPeriods(): array
    {
        return [
            'morning' => 'Morning (6am-12pm)',
            'afternoon' => 'Afternoon (12pm-6pm)',
            'evening' => 'Evening (6pm-10pm)',
            'night' => 'Night (10pm-6am)',
            'all_day' => 'All Day Summary',
        ];
    }

    protected function getWellbeingLevels(): array
    {
        return [
            'mood' => [
                'very_low' => 'Very Low',
                'low' => 'Low',
                'neutral' => 'Neutral',
                'good' => 'Good',
                'excellent' => 'Excellent',
            ],
            'appetite' => [
                'none' => 'None',
                'minimal' => 'Minimal',
                'fair' => 'Fair',
                'good' => 'Good',
                'excellent' => 'Excellent',
            ],
            'sleep_quality' => [
                'very_poor' => 'Very Poor',
                'poor' => 'Poor',
                'fair' => 'Fair',
                'good' => 'Good',
                'excellent' => 'Excellent',
            ],
            'engagement' => [
                'none' => 'None',
                'minimal' => 'Minimal',
                'moderate' => 'Moderate',
                'good' => 'Good',
                'excellent' => 'Excellent',
            ],
        ];
    }

    protected function getMobilityLevels(): array
    {
        return [
            'bedbound' => 'Bedbound',
            'limited' => 'Limited Mobility',
            'assisted' => 'Assisted Mobility',
            'independent' => 'Independent',
        ];
    }

    private function createIncidentFromNoteWhenNeeded(RespiteDailyNote $note, RespiteStay $stay): void
    {
        if (! $note->incident_occurred || $note->linked_incident_id) {
            return;
        }

        $actor = auth()->user();
        $incident = ClientIncident::create([
            'client_id' => $stay->client_id,
            'site_id' => $this->stayScope->siteId($stay),
            'reported_by' => $actor?->id,
            'respite_stay_id' => $stay->id,
            'type' => 'daily_note',
            'severity' => $note->sensitive_flag ? 'medium' : 'low',
            'status' => 'submitted',
            'submitted_at' => now(),
            'occurred_at' => $note->note_date,
            'title' => 'Daily note incident flag',
            'description' => $note->concerns ?: $note->observations ?: 'Incident was flagged from a respite daily note.',
            'requires_followup' => (bool) $note->sensitive_flag,
            'metadata' => [
                'source' => 'respite_daily_note',
                'daily_note_id' => $note->id,
                'stay_id' => $stay->id,
            ],
        ]);

        $journey = app(IncidentJourneyService::class)
            ->ensureForSubmittedIncident($incident, $actor);

        $note->forceFill([
            'linked_incident_id' => $journey->incident->id,
            'updated_by' => $actor?->id,
        ])->save();
    }
}
