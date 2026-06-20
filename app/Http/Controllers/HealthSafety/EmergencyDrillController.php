<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Models\EmergencyDrill;
use App\Models\EmergencyDrillAttachment;
use App\Models\EmergencyDrillFinding;
use App\Models\HsEvent;
use App\Models\Site;
use App\Models\SiteEmergencyPlan;
use App\Models\User;
use App\Services\HealthSafety\DrillComplianceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class EmergencyDrillController extends Controller
{
    private const FIRE_DRILL_TYPES = ['fire', 'fire_evacuation'];

    private const DRILL_TYPES = ['fire_evacuation', 'earthquake', 'lockdown', 'tsunami', 'chemical_spill', 'medical_emergency', 'other'];

    private const OUTCOMES = ['passed', 'passed_actions', 'failed'];

    private const FINDING_TYPES = ['observation', 'non_conformance', 'improvement', 'positive'];

    private const SEVERITIES = ['critical', 'high', 'medium', 'low'];

    private const OPEN_FINDING_STATUSES = ['resolved', 'closed'];

    public function __construct(private readonly DrillComplianceService $compliance) {}

    /**
     * Emergency Drills register — the readiness view. Mirrors the H&S Events register:
     * hero clusters + NZ compliance badges, live tab counts, standardised rows with
     * lifecycle flags and, on ?drill=, the detail payload for the over-the-list modal.
     */
    public function index(Request $request): Response
    {
        $tab = (string) $request->input('tab', 'all');

        $query = EmergencyDrill::query()
            ->with(['site:id,name,region,city'])
            ->withCount([
                'participants',
                'findings',
                'findings as open_findings_count' => fn (Builder $q) => $q->whereNotIn('status', self::OPEN_FINDING_STATUSES),
                'findings as overdue_findings_count' => fn (Builder $q) => $q
                    ->whereNotNull('due_date')
                    ->where('due_date', '<', now())
                    ->whereNotIn('status', self::OPEN_FINDING_STATUSES),
            ])
            ->orderByDesc('scheduled_at');

        $this->applyScope($query, $request);
        $this->applyTab($query, $tab);

        if ($request->filled('drill_type')) {
            $this->applyDrillTypeFilter($query, (string) $request->input('drill_type'));
        }
        if ($request->filled('outcome')) {
            $query->where('outcome', $request->input('outcome'));
        }
        if ($request->filled('q')) {
            $term = trim((string) $request->input('q'));
            $query->where(function (Builder $q) use ($term) {
                $q->where('title', 'like', "%{$term}%")
                    ->orWhere('drill_type', 'like', "%{$term}%");
            });
        }

        $paginator = $query->paginate(25)->withQueryString();
        $drills = $paginator->through(fn (EmergencyDrill $d) => $this->buildDrillRow($d));

        // ── Hero + tab counts (respect scope only — not tab/refinements) ──
        $statusCounts = $this->scopedBase($request)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $count = fn (string $s): int => (int) ($statusCounts[$s] ?? 0);
        $upcoming = (int) $this->scopedBase($request)->where('status', 'scheduled')->where('scheduled_at', '>=', now())->count();
        $overdue = (int) $this->scopedBase($request)->where('status', 'scheduled')->where('scheduled_at', '<', now())->count();
        $withOpenFindings = (int) $this->scopedBase($request)
            ->whereHas('findings', fn (Builder $q) => $q->whereNotIn('status', self::OPEN_FINDING_STATUSES))
            ->count();

        $openFindings = (int) EmergencyDrillFinding::query()
            ->whereNotIn('status', self::OPEN_FINDING_STATUSES)
            ->whereHas('emergencyDrill', fn (Builder $q) => $this->applyScope($q, $request))
            ->count();
        $overdueFindings = (int) EmergencyDrillFinding::query()
            ->whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->whereNotIn('status', self::OPEN_FINDING_STATUSES)
            ->whereHas('emergencyDrill', fn (Builder $q) => $this->applyScope($q, $request))
            ->count();

        $tabCounts = [
            'all' => (int) $statusCounts->sum(),
            'scheduled' => $upcoming,
            'overdue' => $overdue,
            'in_progress' => $count('in_progress'),
            'completed' => $count('completed'),
            'findings' => $withOpenFindings,
        ];

        $complianceSummary = $this->compliance->summary();
        $fenzReviewsDue = $this->fenzReviewsDue();

        $hero = [
            'live' => [
                'scheduled' => $upcoming,
                'overdue' => $overdue,
                'in_progress' => $count('in_progress'),
                'completed' => $count('completed'),
            ],
            'attention' => [
                'sites_overdue' => $complianceSummary['overdue'],
                'findings_open' => $openFindings,
                'findings_overdue' => $overdueFindings,
                'awaiting_writeup' => $count('in_progress'),
            ],
            'badges' => [
                'sites_drilled_pct' => $complianceSummary['pct'],
                'drills_overdue' => $overdue,
                'sites_overdue' => $complianceSummary['overdue'],
                'fenz_reviews_due' => $fenzReviewsDue,
                'nga_paerewa_certified' => $complianceSummary['overdue'] === 0 && $fenzReviewsDue === 0,
            ],
        ];

        // ── Detail-over-list (?drill=) ──
        $detail = null;
        if ($request->filled('drill')) {
            $target = EmergencyDrill::find($request->integer('drill'));
            $detail = $target ? $this->buildDrillDetail($target) : null;
        }

        $canManage = (bool) ($request->user()?->canDo('hazards.manage') ?? false);

        return Inertia::render('health-safety/drills/index', [
            'drills' => $drills,
            'tab' => $tab,
            'tabCounts' => $tabCounts,
            'hero' => $hero,
            'filters' => [
                'q' => $request->input('q'),
                'tab' => $tab,
                'period' => $request->input('period', '6mo'),
                'drill_type' => $request->input('drill_type'),
                'outcome' => $request->input('outcome'),
                'site_id' => $request->filled('site_id') ? (int) $request->input('site_id') : null,
            ],
            'sites' => Site::where('is_active', true)->orderBy('name')->get(['id', 'name', 'region']),
            'staff' => $canManage ? $this->assignableStaff() : [],
            'detail' => $detail,
            'can' => ['manage' => $canManage],
        ]);
    }

    /**
     * Retire the standalone create page — the Schedule wizard lives on the register.
     */
    public function create(): RedirectResponse
    {
        return redirect()->route('health-safety.drills.index', ['schedule' => 1]);
    }

    /**
     * Schedule a drill (Schedule wizard). Persists the drill + seeds the coordinator
     * and warden participant rows so the roll-call is pre-populated.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'site_id' => ['required', 'exists:sites,id'],
            'drill_type' => ['required', 'string', 'in:'.implode(',', [...self::DRILL_TYPES, 'fire'])],
            'title' => ['required', 'string', 'max:255'],
            'scheduled_at' => ['required', 'date'],
            'scenario_description' => ['nullable', 'string', 'max:5000'],
            'assembly_point' => ['nullable', 'string', 'max:255'],
            'evacuation_scheme' => ['nullable', 'string', 'max:255'],
            'conducted_by' => ['nullable', 'exists:users,id'],
            'total_participants' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'warden_ids' => ['nullable', 'array'],
            'warden_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $validated['drill_type'] = $this->normalizeDrillType($validated['drill_type']);
        $wardenIds = collect($validated['warden_ids'] ?? [])->map(fn ($id) => (int) $id);
        unset($validated['warden_ids']);

        $drill = EmergencyDrill::create(array_merge($validated, [
            'status' => 'scheduled',
            'is_unannounced' => $request->boolean('is_unannounced'),
            'created_by' => $request->user()->id,
        ]));

        // Seed the roll-call: coordinator + wardens (deduped, unique per drill+user).
        $seed = collect();
        if (! empty($validated['conducted_by'])) {
            $seed->put((int) $validated['conducted_by'], 'coordinator');
        }
        foreach ($wardenIds as $id) {
            if (! $seed->has($id)) {
                $seed->put($id, 'warden');
            }
        }
        foreach ($seed as $userId => $role) {
            $drill->participants()->create(['user_id' => $userId, 'role' => $role, 'attended' => false]);
        }

        if ($request->boolean('stay')) {
            return back()->with('success', 'Emergency drill scheduled.');
        }

        return redirect()->route('health-safety.drills.index')->with('success', 'Emergency drill scheduled.');
    }

    /**
     * Deep-link / share fallback — renders the same detail modal on a thin shell.
     */
    public function show(EmergencyDrill $drill): Response
    {
        return Inertia::render('health-safety/drills/show', [
            'detail' => $this->buildDrillDetail($drill),
        ]);
    }

    /**
     * Edit / reschedule a drill (no lifecycle transition).
     */
    public function update(Request $request, EmergencyDrill $drill): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'drill_type' => ['sometimes', 'required', 'string', 'in:'.implode(',', [...self::DRILL_TYPES, 'fire'])],
            'scheduled_at' => ['sometimes', 'required', 'date'],
            'scenario_description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'assembly_point' => ['sometimes', 'nullable', 'string', 'max:255'],
            'evacuation_scheme' => ['sometimes', 'nullable', 'string', 'max:255'],
            'conducted_by' => ['sometimes', 'nullable', 'exists:users,id'],
        ]);

        if (isset($validated['drill_type'])) {
            $validated['drill_type'] = $this->normalizeDrillType($validated['drill_type']);
        }
        if ($request->has('is_unannounced')) {
            $validated['is_unannounced'] = $request->boolean('is_unannounced');
        }
        $validated['updated_by'] = $request->user()->id;

        $drill->update($validated);

        return back()->with('success', 'Drill updated.');
    }

    /**
     * Lifecycle: scheduled → in_progress.
     */
    public function start(Request $request, EmergencyDrill $drill): RedirectResponse
    {
        if (! in_array($drill->status, ['scheduled'], true)) {
            return back()->with('error', 'Only a scheduled drill can be started.');
        }

        $drill->update([
            'status' => 'in_progress',
            'started_at' => $drill->started_at ?? now(),
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Drill started — now in progress.');
    }

    /**
     * Lifecycle: in_progress → completed (Complete wizard). Recording the write-up
     * fires EmergencyDrillObserver — a non-passing outcome raises a drill_failure
     * safety event + Control Room signal.
     */
    public function complete(Request $request, EmergencyDrill $drill): RedirectResponse
    {
        if (in_array($drill->status, ['completed', 'cancelled'], true)) {
            return back()->with('error', 'This drill has already been closed out.');
        }

        $validated = $request->validate([
            'completed_at' => ['required', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'evacuation_time_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'weather_conditions' => ['nullable', 'string', 'max:255'],
            'total_participants' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'residents_evacuated' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'outcome' => ['required', 'string', 'in:'.implode(',', self::OUTCOMES)],
            'observer_notes' => ['nullable', 'string', 'max:5000'],
            'improvements_identified' => ['nullable', 'string', 'max:5000'],
            'conducted_by' => ['nullable', 'exists:users,id'],
        ]);

        $drill->update(array_merge($validated, [
            'status' => 'completed',
            'started_at' => $drill->started_at ?? now(),
            'all_areas_checked' => $request->boolean('all_areas_checked'),
            'assembly_point_reached' => $request->boolean('assembly_point_reached'),
            'roll_call_completed' => $request->boolean('roll_call_completed'),
            'updated_by' => $request->user()->id,
        ]));

        return back()->with('success', 'Completion recorded.');
    }

    /**
     * Lifecycle: → cancelled.
     */
    public function cancel(Request $request, EmergencyDrill $drill): RedirectResponse
    {
        if (in_array($drill->status, ['completed', 'cancelled'], true)) {
            return back()->with('error', 'This drill cannot be cancelled.');
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $notes = $drill->observer_notes;
        if (! empty($validated['reason'])) {
            $stamp = 'Cancelled: '.$validated['reason'];
            $notes = $notes ? $notes."\n".$stamp : $stamp;
        }

        $drill->update([
            'status' => 'cancelled',
            'observer_notes' => $notes,
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Drill cancelled.');
    }

    /**
     * Add a participant to the roll-call.
     */
    public function addParticipant(Request $request, EmergencyDrill $drill): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'role' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $drill->participants()->updateOrCreate(
            ['user_id' => $validated['user_id']],
            [
                'role' => $validated['role'] ?? 'participant',
                'attended' => $request->boolean('attended'),
                'notes' => $validated['notes'] ?? null,
            ],
        );

        return back()->with('success', 'Participant added.');
    }

    /**
     * Add a drill finding (observation / non-conformance / improvement / positive).
     */
    public function addFinding(Request $request, EmergencyDrill $drill): RedirectResponse
    {
        $validated = $request->validate([
            'finding_type' => ['required', 'string', 'in:'.implode(',', self::FINDING_TYPES)],
            'description' => ['required', 'string', 'max:2000'],
            'severity' => ['required', 'string', 'in:'.implode(',', self::SEVERITIES)],
            'corrective_action' => ['nullable', 'string', 'max:2000'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'due_date' => ['nullable', 'date'],
        ]);

        $drill->findings()->create(array_merge($validated, [
            'status' => 'open',
            'created_by' => $request->user()->id,
        ]));

        return back()->with('success', 'Finding recorded.');
    }

    /**
     * Update a finding (edit/reassign from the detail modal).
     */
    public function updateFinding(Request $request, EmergencyDrillFinding $finding): RedirectResponse
    {
        $validated = $request->validate([
            'finding_type' => ['sometimes', 'string', 'in:'.implode(',', self::FINDING_TYPES)],
            'description' => ['sometimes', 'required', 'string', 'max:2000'],
            'severity' => ['sometimes', 'string', 'in:'.implode(',', self::SEVERITIES)],
            'status' => ['sometimes', 'string', 'in:open,in_progress,resolved,closed'],
            'corrective_action' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'assigned_to' => ['sometimes', 'nullable', 'exists:users,id'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'resolution_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $validated['updated_by'] = $request->user()->id;
        $finding->update($validated);

        return back()->with('success', 'Finding updated.');
    }

    /**
     * Resolve a finding (open/in_progress → resolved).
     */
    public function resolveFinding(Request $request, EmergencyDrill $drill, EmergencyDrillFinding $finding): RedirectResponse
    {
        abort_unless((int) $finding->emergency_drill_id === (int) $drill->id, 404);

        $validated = $request->validate([
            'resolution_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $finding->update([
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolution_notes' => $validated['resolution_notes'] ?? $finding->resolution_notes,
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Finding resolved.');
    }

    /* ------------------------------------------------------------------ */
    /*  Evidence (premium document upload) */
    /* ------------------------------------------------------------------ */

    public function uploadAttachment(Request $request, EmergencyDrill $drill): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480'], // 20 MB
            'kind' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'alt_text' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $request->file('file');
        $disk = 'public';
        $path = $file->store('emergency_drill_attachments', $disk);

        $drill->attachments()->create([
            'uploaded_by' => $request->user()?->id,
            'disk' => $disk,
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'kind' => $data['kind'] ?? null,
            'notes' => $data['notes'] ?? null,
            'alt_text' => $data['alt_text'] ?? null,
        ]);

        return back()->with('success', 'Evidence uploaded.');
    }

    public function downloadAttachment(Request $request, EmergencyDrill $drill, EmergencyDrillAttachment $attachment)
    {
        abort_unless((int) $attachment->emergency_drill_id === (int) $drill->id, 404);

        $disk = $attachment->disk ?: 'public';
        abort_unless(Storage::disk($disk)->exists($attachment->path), 404);

        return Storage::disk($disk)->download($attachment->path, $attachment->original_name);
    }

    public function destroyAttachment(Request $request, EmergencyDrill $drill, EmergencyDrillAttachment $attachment): RedirectResponse
    {
        abort_unless((int) $attachment->emergency_drill_id === (int) $drill->id, 404);

        $disk = $attachment->disk ?: 'public';
        if ($attachment->path && Storage::disk($disk)->exists($attachment->path)) {
            Storage::disk($disk)->delete($attachment->path);
        }
        $attachment->delete();

        return back()->with('success', 'Evidence removed.');
    }

    /* ------------------------------------------------------------------ */
    /*  Payload builders */
    /* ------------------------------------------------------------------ */

    private function buildDrillRow(EmergencyDrill $d): array
    {
        $isOverdue = $d->status === 'scheduled' && $d->scheduled_at !== null && $d->scheduled_at->isPast();
        $openFindings = (int) ($d->open_findings_count ?? 0);

        return [
            'id' => $d->id,
            'reference' => 'DR-'.$d->id,
            'drill_type' => $d->drill_type,
            'type_label' => $this->typeLabel($d->drill_type),
            'title' => $d->title,
            'scheduled_at' => $d->scheduled_at?->toIso8601String(),
            'started_at' => $d->started_at?->toIso8601String(),
            'completed_at' => $d->completed_at?->toIso8601String(),
            'status' => $isOverdue ? 'overdue' : $d->status,
            'raw_status' => $d->status,
            'outcome' => $d->outcome,
            'site' => $d->site ? [
                'id' => $d->site->id,
                'name' => $d->site->name,
                'region' => $d->site->region ?: $d->site->city,
            ] : null,
            'participants_count' => (int) ($d->participants_count ?? 0),
            'total_participants' => $d->total_participants,
            'people_label' => $this->peopleLabel($d),
            'findings_open' => $openFindings,
            'findings_count' => (int) ($d->findings_count ?? 0),
            'flags' => [
                'overdue' => $isOverdue,
                'running' => $d->status === 'in_progress',
                'finding_overdue' => (int) ($d->overdue_findings_count ?? 0) > 0,
                'open_findings' => $openFindings,
            ],
        ];
    }

    private function buildDrillDetail(EmergencyDrill $drill): array
    {
        $drill->loadMissing(['site:id,name,region,city', 'conductor:id,name', 'creator:id,name']);

        $participants = $drill->participants()
            ->with('user:id,name')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'user_id' => $p->user_id,
                'name' => $p->user?->name ?? 'Unknown',
                'role' => $p->role,
                'attended' => (bool) $p->attended,
                'notes' => $p->notes,
            ]);

        $findings = $drill->findings()
            ->with('assignee:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (EmergencyDrillFinding $f) => [
                'id' => $f->id,
                'finding_type' => $f->finding_type,
                'description' => $f->description,
                'severity' => $f->severity,
                'status' => $f->status,
                'corrective_action' => $f->corrective_action,
                'assigned_to' => $f->assigned_to,
                'assignee_name' => $f->assignee?->name,
                'due_date' => $f->due_date?->toDateString(),
                'resolved_at' => $f->resolved_at?->toDateString(),
                'resolution_notes' => $f->resolution_notes,
                'is_overdue' => $f->due_date !== null
                    && $f->due_date->isPast()
                    && ! in_array($f->status, self::OPEN_FINDING_STATUSES, true),
            ]);

        $attachments = $drill->attachments()
            ->with('uploader:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (EmergencyDrillAttachment $a) => [
                'id' => $a->id,
                'original_name' => $a->original_name,
                'mime' => $a->mime,
                'size' => $a->size,
                'kind' => $a->kind,
                'notes' => $a->notes,
                'alt_text' => $a->alt_text,
                'is_image' => $a->isImage(),
                'uploaded_by_name' => $a->uploader?->name,
                'created_at' => $a->created_at?->toIso8601String(),
                'url' => "/health-safety/drills/{$drill->id}/attachments/{$a->id}/download",
            ]);

        $canManage = (bool) (auth()->user()?->canDo('hazards.manage') ?? false);

        // Two-way convergence: the drill_failure HsEvent (if any) the observer raised.
        $hsEvent = HsEvent::query()
            ->where('source_type', EmergencyDrill::class)
            ->where('source_id', $drill->id)
            ->latest('id')
            ->first(['id', 'reference_number', 'status', 'severity']);

        return [
            'id' => $drill->id,
            'reference' => 'DR-'.$drill->id,
            'drill_type' => $drill->drill_type,
            'type_label' => $this->typeLabel($drill->drill_type),
            'title' => $drill->title,
            'status' => $drill->status,
            'outcome' => $drill->outcome,
            'scheduled_at' => $drill->scheduled_at?->toIso8601String(),
            'started_at' => $drill->started_at?->toIso8601String(),
            'completed_at' => $drill->completed_at?->toIso8601String(),
            'duration_minutes' => $drill->duration_minutes,
            'evacuation_time_seconds' => $drill->evacuation_time_seconds,
            'weather_conditions' => $drill->weather_conditions,
            'total_participants' => $drill->total_participants,
            'residents_evacuated' => $drill->residents_evacuated,
            'all_areas_checked' => (bool) $drill->all_areas_checked,
            'assembly_point_reached' => (bool) $drill->assembly_point_reached,
            'roll_call_completed' => (bool) $drill->roll_call_completed,
            'scenario_description' => $drill->scenario_description,
            'is_unannounced' => (bool) $drill->is_unannounced,
            'assembly_point' => $drill->assembly_point,
            'evacuation_scheme' => $drill->evacuation_scheme,
            'observer_notes' => $drill->observer_notes,
            'improvements_identified' => $drill->improvements_identified,
            'site' => $drill->site ? [
                'id' => $drill->site->id,
                'name' => $drill->site->name,
                'region' => $drill->site->region ?: $drill->site->city,
            ] : null,
            'coordinator_name' => $drill->conductor?->name,
            'conducted_by' => $drill->conducted_by,
            'created_by_name' => $drill->creator?->name,
            'created_at' => $drill->created_at?->toIso8601String(),
            'participants' => $participants,
            'findings' => $findings,
            'attachments' => $attachments,
            'timeline' => $this->buildTimeline($drill, $findings, $hsEvent),
            'hs_event' => $hsEvent ? [
                'id' => $hsEvent->id,
                'reference_number' => $hsEvent->reference_number,
                'status' => $hsEvent->status,
                'severity' => $hsEvent->severity,
                'url' => "/health-safety/events?event={$hsEvent->id}",
            ] : null,
            'assignable_staff' => $canManage ? $this->assignableStaff() : [],
            'can' => ['manage' => $canManage],
        ];
    }

    /**
     * Derived audit timeline from the drill's own lifecycle timestamps + findings +
     * the linked safety event (no separate audit table needed).
     *
     * @param  Collection<int,array<string,mixed>>  $findings
     */
    private function buildTimeline(EmergencyDrill $drill, $findings, ?HsEvent $hsEvent): array
    {
        $events = [];

        $events[] = [
            'key' => 'scheduled',
            'label' => 'Drill scheduled',
            'icon' => 'plus',
            'at' => $drill->created_at?->toIso8601String(),
            'meta' => $drill->creator?->name,
        ];

        if ($drill->started_at) {
            $events[] = [
                'key' => 'started',
                'label' => 'Drill started',
                'icon' => 'play',
                'at' => $drill->started_at->toIso8601String(),
                'meta' => 'Now in progress',
            ];
        }

        if ($drill->completed_at) {
            $events[] = [
                'key' => 'completed',
                'label' => 'Completion recorded',
                'icon' => 'check-circle-2',
                'at' => $drill->completed_at->toIso8601String(),
                'meta' => $drill->outcome ? 'Outcome: '.$this->outcomeLabel($drill->outcome) : null,
            ];
        }

        if ($drill->status === 'cancelled') {
            $events[] = [
                'key' => 'cancelled',
                'label' => 'Drill cancelled',
                'icon' => 'x-circle',
                'at' => $drill->updated_at?->toIso8601String(),
                'meta' => null,
            ];
        }

        foreach ($findings as $f) {
            $events[] = [
                'key' => 'finding-'.$f['id'],
                'label' => 'Finding logged — '.$this->typeLabel($f['finding_type']),
                'icon' => 'clipboard-list',
                'at' => null,
                'meta' => $f['assignee_name'] ? 'Assigned to '.$f['assignee_name'] : null,
            ];
        }

        if ($hsEvent) {
            $events[] = [
                'key' => 'safety-event',
                'label' => 'Safety event raised',
                'icon' => 'shield-alert',
                'at' => null,
                'meta' => 'drill_failure · Control Room notified',
            ];
        }

        return $events;
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    /** Scope = the hero/tab "period + site" lens (never tab/refinements). */
    private function applyScope(Builder $query, Request $request): Builder
    {
        if ($request->filled('site_id')) {
            $query->where('site_id', (int) $request->input('site_id'));
        }
        if ($from = $this->periodFrom($request)) {
            $query->where('scheduled_at', '>=', $from);
        }

        return $query;
    }

    private function scopedBase(Request $request): Builder
    {
        return $this->applyScope(EmergencyDrill::query(), $request);
    }

    private function periodFrom(Request $request): ?Carbon
    {
        return match ((string) $request->input('period', '6mo')) {
            '30d' => now()->subDays(30),
            'quarter' => now()->subMonths(3),
            'all' => null,
            default => now()->subMonths(6),
        };
    }

    private function applyTab(Builder $query, string $tab): void
    {
        match ($tab) {
            'scheduled' => $query->where('status', 'scheduled')->where('scheduled_at', '>=', now()),
            'overdue' => $query->where('status', 'scheduled')->where('scheduled_at', '<', now()),
            'in_progress' => $query->where('status', 'in_progress'),
            'completed' => $query->where('status', 'completed'),
            'findings' => $query->whereHas('findings', fn (Builder $q) => $q->whereNotIn('status', self::OPEN_FINDING_STATUSES)),
            default => $query, // 'all'
        };
    }

    private function applyDrillTypeFilter(Builder $query, string $type): void
    {
        $normalized = $this->normalizeDrillType($type);

        if ($normalized === 'fire_evacuation') {
            $query->whereIn('drill_type', self::FIRE_DRILL_TYPES);

            return;
        }

        $query->where('drill_type', $normalized);
    }

    private function normalizeDrillType(string $type): string
    {
        return $type === 'fire' ? 'fire_evacuation' : $type;
    }

    private function typeLabel(string $type): string
    {
        return Str::headline($this->normalizeDrillType($type));
    }

    private function outcomeLabel(?string $outcome): string
    {
        return match ($outcome) {
            'passed' => 'Passed',
            'passed_actions' => 'Passed with actions',
            'failed' => 'Failed',
            default => $outcome ? Str::headline($outcome) : '—',
        };
    }

    private function peopleLabel(EmergencyDrill $d): ?string
    {
        $attended = (int) ($d->participants_count ?? 0);
        $expected = $d->total_participants;

        return match ($d->status) {
            'completed' => $attended > 0 ? $attended.' took part' : ($expected ? $expected.' recorded' : null),
            'in_progress' => $attended > 0 ? $attended.' on site' : ($expected ? $expected.' expected' : null),
            default => $expected ? $expected.' expected' : ($attended > 0 ? $attended.' assigned' : null),
        };
    }

    /** Active emergency / evacuation (FENZ) plan reviews currently overdue. */
    private function fenzReviewsDue(): int
    {
        if (! class_exists(SiteEmergencyPlan::class)) {
            return 0;
        }

        return SiteEmergencyPlan::query()
            ->where('status', 'active')
            ->get()
            ->filter(function ($plan) {
                $due = method_exists($plan, 'dueDate') ? $plan->dueDate() : null;

                return $due !== null && Carbon::parse($due)->isPast();
            })
            ->count();
    }

    /**
     * @return array<int,array{id:int,name:string}>
     */
    private function assignableStaff(): array
    {
        return User::query()
            ->whereNotNull('approved_at')
            ->orderBy('name')
            ->limit(300)
            ->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
            ->all();
    }
}
