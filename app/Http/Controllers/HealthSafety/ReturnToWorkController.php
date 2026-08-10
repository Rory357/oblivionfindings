<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ClientIncident;
use App\Models\ModifiedDuty;
use App\Models\ReturnToWorkPlan;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkCapacityAssessment;
use App\Models\WorkplaceInjury;
use App\Models\WorkplaceInjuryAttachment;
use App\Services\HealthSafety\HsKpiService;
use App\Services\HealthSafety\WorkplaceInjuryJourneyService;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Injuries & Return-to-Work register (Health & Safety gold standard).
 *
 * Modal-first register at /health-safety/injuries — mirrors the Events / Incidents /
 * Safeguarding / Fleet / Hazards pattern: HeroShell + TabStrip + detail-over-list
 * (?injury=). Lifecycle: reported → under_treatment → return_to_work → recovered →
 * closed. Staff data (user_id = injured worker), gated by hazards.view / hazards.manage.
 */
class ReturnToWorkController extends Controller
{
    use ServesPrivateAttachments;

    private const SITE_BYPASS_PERMISSIONS = ['healthSafety.viewAllSites'];

    /** Canonical injury_type → human label (15 enum values). */
    private const TYPE_LABELS = [
        'strain' => 'Muscle strain',
        'laceration' => 'Laceration',
        'fracture' => 'Fracture',
        'burn' => 'Burn',
        'contusion' => 'Contusion / bruise',
        'concussion' => 'Concussion',
        'repetitive_strain' => 'Repetitive strain',
        'chemical_exposure' => 'Chemical exposure',
        'biological_exposure' => 'Biological exposure',
        'needle_stick' => 'Needle-stick',
        'slip_trip_fall' => 'Slip / trip / fall',
        'manual_handling' => 'Manual handling',
        'psychological' => 'Psychological',
        'illness' => 'Work illness',
        'other' => 'Other',
    ];

    private const TREATMENT_LABELS = [
        'none' => 'None required',
        'first_aid' => 'First aid',
        'gp_visit' => 'GP visit',
        'hospital' => 'Hospital',
        'emergency_department' => 'Emergency department',
        'hospitalisation' => 'Hospitalisation',
        'specialist' => 'Specialist',
        'ongoing' => 'Ongoing treatment',
    ];

    /** Allowed lifecycle transitions (from → [to,...]). 'closed' reachable from any non-closed. */
    private const TRANSITIONS = [
        'reported' => ['under_treatment', 'closed'],
        'under_treatment' => ['return_to_work', 'recovered', 'closed'],
        'return_to_work' => ['recovered', 'closed'],
        'recovered' => ['closed'],
        'closed' => [],
    ];

    public function __construct(
        private readonly HsKpiService $kpi,
        private readonly UserSiteAccessService $siteAccess,
        private readonly WorkplaceInjuryJourneyService $journey,
    ) {}

    /* ================================================================== */
    /*  Register */
    /* ================================================================== */

    public function index(Request $request): Response
    {
        $tab = (string) $request->input('tab', 'all');
        $siteId = $request->filled('site_id') ? (int) $request->input('site_id') : null;
        $accessibleSiteIds = $this->siteAccess->accessibleSiteIds(
            $request->user(),
            self::SITE_BYPASS_PERMISSIONS,
        );
        if ($siteId) {
            $this->siteAccess->assertCanAccessSiteId(
                $request->user(),
                $siteId,
                self::SITE_BYPASS_PERMISSIONS,
            );
        }

        // ── List query (scope + tab + refinements + paginate) ──
        $injuries = $this->applyRefinements(
            $this->applyTab($this->scopedBase($request), $tab),
            $request
        )
            ->with(['user:id,name', 'site:id,name', 'relatedIncident:id,reference_number'])
            ->withCount(['returnToWorkPlans', 'capacityAssessments', 'attachments'])
            ->orderByDesc('injury_date')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (WorkplaceInjury $i) => $this->shapeRow($i));

        // ── Aggregates (scope-only — never the tab/refinements, so badges stay stable) ──
        $statusCounts = $this->scopedBase($request)
            ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $count = fn (string $s): int => (int) ($statusCounts[$s] ?? 0);

        $worksafeAwaiting = (int) $this->scopedBase($request)->where('worksafe_notifiable', true)->where('status', 'reported')->count();
        $accOpen = (int) $this->scopedBase($request)->where('acc_claim_lodged', true)->where('status', '!=', 'closed')->count();
        $accUnlodged = (int) $this->scopedBase($request)->where('acc_claim_lodged', false)->whereNotIn('status', ['closed', 'recovered'])->count();
        $lostTimeCount = (int) $this->scopedBase($request)->where('lost_time_days', '>', 0)->count();
        $lostTimeDays = (int) $this->scopedBase($request)->sum('lost_time_days');

        $rtwReviewDue = ReturnToWorkPlan::query()
            ->whereNotNull('next_review_date')->whereDate('next_review_date', '<=', now())->where('status', 'active')
            ->whereHas('workplaceInjury', fn (Builder $q) => $this->siteAccess->applyWorkplaceInjuryScope(
                $q,
                $request->user(),
                self::SITE_BYPASS_PERMISSIONS,
            ))
            ->when($siteId, fn (Builder $q) => $q->whereHas('workplaceInjury', fn (Builder $w) => $w->where('site_id', $siteId)))
            ->count();

        $tabCounts = [
            'all' => (int) $statusCounts->sum(),
            'reported' => $count('reported'),
            'under_treatment' => $count('under_treatment'),
            'return_to_work' => $count('return_to_work'),
            'recovered' => $count('recovered'),
            'closed' => $count('closed'),
            'worksafe' => (int) $this->scopedBase($request)->where('worksafe_notifiable', true)->count(),
            'acc' => $accOpen,
        ];

        $hero = [
            'live' => [
                'reported' => $count('reported'),
                'under_treatment' => $count('under_treatment'),
                'return_to_work' => $count('return_to_work'),
                'recovered' => $count('recovered'),
            ],
            'attention' => [
                'worksafe_awaiting' => $worksafeAwaiting,
                'acc_unlodged' => $accUnlodged,
                'rtw_review_due' => $rtwReviewDue,
                'lost_time' => $lostTimeCount,
            ],
            'badges' => [
                'worksafe_awaiting' => $worksafeAwaiting,
                'acc_open' => $accOpen,
                'ltifr' => $this->kpi->ltifr(null, null, $siteId ?? $accessibleSiteIds),
                'trifr' => $this->kpi->trifr(null, null, $siteId ?? $accessibleSiteIds),
                'lost_time_days' => $lostTimeDays,
            ],
        ];

        // ── Detail-over-list (?injury=) ──
        $detail = null;
        if ($request->filled('injury')) {
            $target = WorkplaceInjury::findOrFail($request->integer('injury'));
            $this->assertCanAccessInjury($request, $target);
            $detail = $this->buildInjuryDetail($target);
        }

        $siteQuery = Site::query()->select('id', 'name')->where('is_active', true);
        $this->siteAccess->applySiteScope($siteQuery, $request->user(), self::SITE_BYPASS_PERMISSIONS);
        $staffQuery = User::query()->select('id', 'name');
        $this->siteAccess->applyStaffScope($staffQuery, $request->user(), self::SITE_BYPASS_PERMISSIONS);

        return Inertia::render('health-safety/injuries/index', [
            'injuries' => $injuries,
            'tab' => $tab,
            'tabCounts' => $tabCounts,
            'hero' => $hero,
            'filters' => [
                'q' => $request->input('q', ''),
                'site_id' => $siteId,
                'severity' => $request->input('severity'),
                'status' => $request->input('status'),
                'treatment' => $request->input('treatment'),
                'acc_open' => $request->boolean('acc_open') ?: null,
                'worksafe' => $request->boolean('worksafe') ?: null,
                'period' => $request->input('period', 'all'),
                'from' => $request->input('from'),
                'to' => $request->input('to'),
                'type' => $request->input('type'),
                'body_part' => $request->input('body_part'),
            ],
            'sites' => $siteQuery->orderBy('name')->get(),
            'staff' => $staffQuery->orderBy('name')->get(),
            'incidents' => $this->incidentOptions($request, $siteId),
            'detail' => $detail,
            'can' => ['manage' => (bool) ($request->user()?->canDo('hazards.manage') ?? false)],
        ]);
    }

    /* ================================================================== */
    /*  Scope / tab / refinement helpers */
    /* ================================================================== */

    private function scopedBase(Request $request): Builder
    {
        $query = WorkplaceInjury::query();
        $this->siteAccess->applyWorkplaceInjuryScope(
            $query,
            $request->user(),
            self::SITE_BYPASS_PERMISSIONS,
        );
        if ($request->filled('site_id')) {
            $siteId = (int) $request->input('site_id');
            $this->siteAccess->assertCanAccessSiteId(
                $request->user(),
                $siteId,
                self::SITE_BYPASS_PERMISSIONS,
            );
            $query->where('site_id', $siteId);
        }
        [$from, $to] = $this->resolveRange($request);
        if ($from) {
            $query->where('injury_date', '>=', $from);
        }
        if ($to) {
            $query->where('injury_date', '<=', $to);
        }

        return $query;
    }

    private function applyTab(Builder $query, string $tab): Builder
    {
        return match ($tab) {
            'reported', 'under_treatment', 'return_to_work', 'recovered', 'closed' => $query->where('status', $tab),
            'worksafe' => $query->where('worksafe_notifiable', true),
            'acc' => $query->where('acc_claim_lodged', true)->where('status', '!=', 'closed'),
            default => $query, // 'all'
        };
    }

    private function applyRefinements(Builder $query, Request $request): Builder
    {
        return $query
            ->when($request->filled('severity'), fn (Builder $q) => $q->where('severity', $request->input('severity')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->input('status')))
            ->when($request->filled('treatment'), fn (Builder $q) => $q->where('medical_treatment_type', $request->input('treatment')))
            ->when($request->filled('type'), fn (Builder $q) => $q->where('injury_type', $request->input('type')))
            ->when($request->filled('body_part'), fn (Builder $q) => $q->where('body_part_affected', 'like', '%'.$request->input('body_part').'%'))
            ->when($request->boolean('acc_open'), fn (Builder $q) => $q->where('acc_claim_lodged', true)->where('status', '!=', 'closed'))
            ->when($request->boolean('worksafe'), fn (Builder $q) => $q->where('worksafe_notifiable', true))
            ->when($request->filled('q'), function (Builder $q) use ($request) {
                $search = $request->input('q');
                $q->where(function (Builder $q2) use ($search) {
                    $q2->where('description', 'like', "%{$search}%")
                        ->orWhere('body_part_affected', 'like', "%{$search}%")
                        ->orWhereHas('user', fn (Builder $uq) => $uq->where('name', 'like', "%{$search}%"));
                });
            });
    }

    /** Resolve a [from, to] window from explicit from/to or the period preset (default = all-time). */
    private function resolveRange(Request $request): array
    {
        if ($request->filled('from') || $request->filled('to')) {
            return [
                $request->filled('from') ? Carbon::parse($request->input('from'))->startOfDay() : null,
                $request->filled('to') ? Carbon::parse($request->input('to'))->endOfDay() : null,
            ];
        }

        return match ((string) $request->input('period', 'all')) {
            'week' => [now()->subDays(7), null],
            '30d' => [now()->subDays(30), null],
            'quarter' => [now()->subDays(90), null],
            'year' => [now()->subDays(365), null],
            default => [null, null], // all
        };
    }

    private function reference(WorkplaceInjury $injury): string
    {
        return $injury->reference_number ?? 'WI-'.str_pad((string) $injury->id, 4, '0', STR_PAD_LEFT);
    }

    private function shapeRow(WorkplaceInjury $i): array
    {
        return [
            'id' => $i->id,
            'reference' => $this->reference($i),
            'status' => $i->status,
            'severity' => $i->severity,
            'injury_type' => $i->injury_type,
            'injury_type_label' => self::TYPE_LABELS[$i->injury_type] ?? ucfirst(str_replace('_', ' ', (string) $i->injury_type)),
            'body_part_affected' => $i->body_part_affected,
            'injury_date' => optional($i->injury_date)->toIso8601String(),
            'lost_time_days' => (int) $i->lost_time_days,
            'worksafe_notifiable' => (bool) $i->worksafe_notifiable,
            'acc_claim_lodged' => (bool) $i->acc_claim_lodged,
            'acc_claim_number' => $i->acc_claim_number,
            'related_incident_id' => $i->related_incident_id,
            'related_incident_ref' => $i->relatedIncident?->reference_number,
            'worker' => $i->user ? ['id' => $i->user->id, 'name' => $i->user->name] : null,
            'site' => $i->site ? ['id' => $i->site->id, 'name' => $i->site->name] : null,
            'rtw_count' => (int) ($i->return_to_work_plans_count ?? 0),
            'capacity_count' => (int) ($i->capacity_assessments_count ?? 0),
            'attachment_count' => (int) ($i->attachments_count ?? 0),
        ];
    }

    /** Recent client incidents for the wizard's "link to incident" picker. */
    private function incidentOptions(Request $request, ?int $selectedSiteId = null)
    {
        $query = ClientIncident::query();
        $this->siteAccess->applyClientIncidentScope(
            $query,
            $request->user(),
            self::SITE_BYPASS_PERMISSIONS,
        );

        return $query
            ->latest('occurred_at')
            ->limit(100)
            ->get(['id', 'client_id', 'site_id', 'shift_id', 'reference_number', 'type', 'title', 'occurred_at'])
            ->filter(function (ClientIncident $incident) use ($selectedSiteId): bool {
                try {
                    $siteId = $this->siteAccess->effectiveClientIncidentSiteId($incident);
                } catch (\LogicException) {
                    return false;
                }

                return $selectedSiteId === null || $siteId === $selectedSiteId;
            })
            ->map(fn (ClientIncident $c) => [
                'id' => $c->id,
                'label' => $c->reference_number ?? 'INC-'.str_pad((string) $c->id, 4, '0', STR_PAD_LEFT),
                'title' => $c->title ?: ucfirst(str_replace('_', ' ', (string) $c->type)),
                'occurred_at' => optional($c->occurred_at)->toIso8601String(),
            ])->values();
    }

    /* ================================================================== */
    /*  Detail payload (shared by ?injury= modal) */
    /* ================================================================== */

    private function buildInjuryDetail(WorkplaceInjury $injury): array
    {
        $injury->load([
            'user:id,name', 'site:id,name',
            'relatedIncident:id,reference_number,type,title,occurred_at',
            'returnToWorkPlans' => fn ($q) => $q->with(['worker:id,name', 'manager:id,name', 'modifiedDuties.user:id,name'])->orderByDesc('created_at'),
            'capacityAssessments' => fn ($q) => $q->with('user:id,name')->orderByDesc('assessment_date'),
            'attachments' => fn ($q) => $q->with('uploader:id,name')->orderByDesc('created_at'),
        ]);

        return [
            'id' => $injury->id,
            'reference' => $this->reference($injury),
            'status' => $injury->status,
            'severity' => $injury->severity,
            'injury_type' => $injury->injury_type,
            'injury_type_label' => self::TYPE_LABELS[$injury->injury_type] ?? ucfirst(str_replace('_', ' ', (string) $injury->injury_type)),
            'body_part_affected' => $injury->body_part_affected,
            'description' => $injury->description,
            'injury_date' => optional($injury->injury_date)->toIso8601String(),
            'immediate_treatment' => $injury->immediate_treatment,
            'medical_treatment_type' => $injury->medical_treatment_type,
            'medical_treatment_label' => self::TREATMENT_LABELS[$injury->medical_treatment_type] ?? null,
            'worksafe_notifiable' => (bool) $injury->worksafe_notifiable,
            'acc_claim_lodged' => (bool) $injury->acc_claim_lodged,
            'acc_claim_number' => $injury->acc_claim_number,
            'lost_time_days' => (int) $injury->lost_time_days,
            'expected_return_date' => optional($injury->expected_return_date)->toIso8601String(),
            'actual_return_date' => optional($injury->actual_return_date)->toIso8601String(),
            'notes' => $injury->notes,
            'worker' => $injury->user ? ['id' => $injury->user->id, 'name' => $injury->user->name] : null,
            'site' => $injury->site ? ['id' => $injury->site->id, 'name' => $injury->site->name] : null,
            'related_incident' => $injury->relatedIncident ? [
                'id' => $injury->relatedIncident->id,
                'label' => $injury->relatedIncident->reference_number ?? 'INC-'.str_pad((string) $injury->relatedIncident->id, 4, '0', STR_PAD_LEFT),
                'title' => $injury->relatedIncident->title ?: ucfirst(str_replace('_', ' ', (string) $injury->relatedIncident->type)),
            ] : null,
            'rtw_plans' => $injury->returnToWorkPlans->map(fn (ReturnToWorkPlan $p) => [
                'id' => $p->id,
                'status' => $p->status,
                'plan_start_date' => optional($p->plan_start_date)->toIso8601String(),
                'plan_end_date' => optional($p->plan_end_date)->toIso8601String(),
                'goals' => $p->goals ?? [],
                'stages' => $p->stages ?? [],
                'medical_clearance_notes' => $p->medical_clearance_notes,
                'medical_clearance_provider' => $p->medical_clearance_provider,
                'medical_clearance_date' => optional($p->medical_clearance_date)->toIso8601String(),
                'next_review_date' => optional($p->next_review_date)->toIso8601String(),
                'review_notes' => $p->review_notes,
                'worker' => $p->worker ? ['id' => $p->worker->id, 'name' => $p->worker->name] : null,
                'manager' => $p->manager ? ['id' => $p->manager->id, 'name' => $p->manager->name] : null,
                'modified_duties' => $p->modifiedDuties->map(fn (ModifiedDuty $d) => [
                    'id' => $d->id,
                    'status' => $d->status,
                    'start_date' => optional($d->start_date)->toIso8601String(),
                    'end_date' => optional($d->end_date)->toIso8601String(),
                    'modified_duties_description' => $d->modified_duties_description,
                    'restrictions' => $d->restrictions,
                    'accommodations' => $d->accommodations,
                    'hours_per_day' => $d->hours_per_day !== null ? (float) $d->hours_per_day : null,
                    'user' => $d->user ? ['id' => $d->user->id, 'name' => $d->user->name] : null,
                ])->values(),
            ])->values(),
            'capacity_assessments' => $injury->capacityAssessments->map(fn (WorkCapacityAssessment $a) => [
                'id' => $a->id,
                'assessment_date' => optional($a->assessment_date)->toIso8601String(),
                'assessor_name' => $a->assessor_name,
                'assessor_type' => $a->assessor_type,
                'capacity_status' => $a->capacity_status,
                'restrictions' => $a->restrictions,
                'recommendations' => $a->recommendations,
                'next_assessment_date' => optional($a->next_assessment_date)->toIso8601String(),
                'assessment_summary' => $a->assessment_summary,
                'assessor' => $a->user ? ['id' => $a->user->id, 'name' => $a->user->name] : null,
            ])->values(),
            'attachments' => $injury->attachments->map(fn (WorkplaceInjuryAttachment $a) => [
                'id' => $a->id,
                'original_name' => $a->original_name,
                // Private disk → no public URL; the thumbnail/preview loads through the
                // authenticated download route (Content-Disposition is ignored for <img>).
                'url' => route('health-safety.injuries.attachments.download', [$injury->id, $a->id]),
                'mime' => $a->mime,
                'kind' => $a->kind,
                'notes' => $a->notes,
                'alt_text' => $a->alt_text,
                'size' => $a->size,
                'is_image' => $a->isImage(),
                'uploaded_by' => $a->uploader?->name,
                'created_at' => optional($a->created_at)->toIso8601String(),
            ])->values(),
            'audits' => $this->auditTimeline($injury),
            'created_at' => optional($injury->created_at)->toIso8601String(),
            'updated_at' => optional($injury->updated_at)->toIso8601String(),
            'can' => ['manage' => (bool) (request()->user()?->canDo('hazards.manage') ?? false)],
        ];
    }

    private function auditTimeline(WorkplaceInjury $injury): array
    {
        return AuditLog::query()
            ->where('auditable_type', $injury->getMorphClass())
            ->where('auditable_id', $injury->id)
            ->with('user:id,name')
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'fields' => $log->meta['fields'] ?? [],
                'actor' => $log->user?->name,
                'at' => optional($log->created_at)->toIso8601String(),
            ])->values()->all();
    }

    /* ================================================================== */
    /*  Create / store / update */
    /* ================================================================== */

    /** Full-page create is retired — the wizard is modal-first. Redirect to the register. */
    public function create(): RedirectResponse
    {
        return redirect()->route('health-safety.injuries.index');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'site_id' => ['required', 'exists:sites,id'],
            'related_incident_id' => ['nullable', 'exists:client_incidents,id'],
            'injury_date' => ['required', 'date'],
            'injury_type' => ['required', 'string', 'in:'.implode(',', array_keys(self::TYPE_LABELS))],
            'body_part_affected' => ['required', 'string', 'max:255'],
            'severity' => ['required', 'string', 'in:minor,moderate,serious,critical'],
            'description' => ['required', 'string', 'max:5000'],
            'medical_treatment_type' => ['required', 'string', 'in:'.implode(',', array_keys(self::TREATMENT_LABELS))],
            'immediate_treatment' => ['nullable', 'string', 'max:2000'],
            'worksafe_notifiable' => ['boolean'],
            'acc_claim_number' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->assertWriteContext(
            $request,
            (int) $validated['site_id'],
            (int) $validated['user_id'],
            isset($validated['related_incident_id']) ? (int) $validated['related_incident_id'] : null,
        );

        $injury = DB::transaction(function () use ($request, $validated): WorkplaceInjury {
            $injury = WorkplaceInjury::create(array_merge($validated, [
                'status' => 'reported',
                'lost_time_days' => 0,
                // ACC is "lodged" when a claim number is captured at intake.
                'acc_claim_lodged' => filled($validated['acc_claim_number'] ?? null),
                'created_by' => $request->user()->id,
            ]));

            $this->journey->synchronize($injury);

            return $injury;
        }, 3);

        if ($request->boolean('stay')) {
            return back()->with('success', 'Workplace injury recorded.')->with('created_injury_id', $injury->id);
        }

        return redirect()->route('health-safety.injuries.index')
            ->with('success', 'Workplace injury recorded.')
            ->with('created_injury_id', $injury->id);
    }

    public function update(Request $request, WorkplaceInjury $injury): RedirectResponse
    {
        $this->assertCanAccessInjury($request, $injury);

        $validated = $request->validate([
            // Edit-mode content fields (the wizard PUTs these).
            'user_id' => ['sometimes', 'exists:users,id'],
            'site_id' => ['sometimes', 'exists:sites,id'],
            'related_incident_id' => ['sometimes', 'nullable', 'exists:client_incidents,id'],
            'injury_date' => ['sometimes', 'date'],
            'injury_type' => ['sometimes', 'string', 'in:'.implode(',', array_keys(self::TYPE_LABELS))],
            'body_part_affected' => ['sometimes', 'string', 'max:255'],
            'severity' => ['sometimes', 'string', 'in:minor,moderate,serious,critical'],
            'description' => ['sometimes', 'string', 'max:5000'],
            'medical_treatment_type' => ['sometimes', 'string', 'in:'.implode(',', array_keys(self::TREATMENT_LABELS))],
            'immediate_treatment' => ['sometimes', 'nullable', 'string', 'max:2000'],
            // Claim / lifecycle-data fields (detail panes). NOTE: status is intentionally
            // NOT accepted here — all lifecycle moves go through transitionStatus() so the
            // allowed-transition graph is always enforced.
            'lost_time_days' => ['sometimes', 'integer', 'min:0'],
            'expected_return_date' => ['sometimes', 'nullable', 'date'],
            'actual_return_date' => ['sometimes', 'nullable', 'date'],
            'acc_claim_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'acc_claim_lodged' => ['sometimes', 'boolean'],
            'worksafe_notifiable' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($injury, $request, $validated): void {
            $locked = WorkplaceInjury::query()->lockForUpdate()->findOrFail($injury->id);
            $this->assertCanAccessInjury($request, $locked);

            $siteId = (int) ($validated['site_id'] ?? $locked->site_id);
            $staffId = (int) ($validated['user_id'] ?? $locked->user_id);
            $incidentId = array_key_exists('related_incident_id', $validated)
                ? ($validated['related_incident_id'] ? (int) $validated['related_incident_id'] : null)
                : ($locked->related_incident_id ? (int) $locked->related_incident_id : null);

            if (array_key_exists('site_id', $validated) || array_key_exists('user_id', $validated)) {
                $this->assertWriteContext($request, $siteId, $staffId, $incidentId);
            } elseif (array_key_exists('related_incident_id', $validated)) {
                $this->assertLinkedIncidentAtSite($request, $incidentId, $siteId);
            }

            $locked->update(array_merge($validated, ['updated_by' => $request->user()->id]));
            $this->journey->synchronize($locked->fresh());
        }, 3);

        return back()->with('success', 'Injury record updated.');
    }

    /**
     * Explicit lifecycle transition (Start treatment / Begin RTW / Mark recovered / Close).
     * Validates the transition is legal and sets derived dates.
     */
    public function transitionStatus(Request $request, WorkplaceInjury $injury): RedirectResponse
    {
        $this->assertCanAccessInjury($request, $injury);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:reported,under_treatment,return_to_work,recovered,closed'],
        ]);

        $from = (string) $injury->status;
        $to = $validated['status'];

        if ($to === $from) {
            return back(); // no-op — don't bump updated_by or emit a phantom audit entry
        }

        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            return back()->with('error', 'That status change is not allowed from "'.str_replace('_', ' ', $from).'".');
        }

        $changes = ['status' => $to, 'updated_by' => $request->user()->id];
        if ($to === 'recovered' && ! $injury->actual_return_date) {
            $changes['actual_return_date'] = now()->toDateString();
        }

        $injury->update($changes);

        return back()->with('success', 'Injury moved to '.str_replace('_', ' ', $to).'.');
    }

    /** Export the filtered register to CSV (honours the same scope/tab/refinements as index). */
    public function export(Request $request): StreamedResponse
    {
        $tab = (string) $request->input('tab', 'all');
        $query = $this->applyRefinements($this->applyTab($this->scopedBase($request), $tab), $request)
            ->with(['user:id,name', 'site:id,name'])
            ->orderByDesc('injury_date');

        $filename = 'injuries-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            $this->putCsv($out, ['Reference', 'Worker', 'Site', 'Injury date', 'Type', 'Body part', 'Severity', 'Status', 'Lost days', 'ACC lodged', 'ACC number', 'WorkSafe notifiable']);
            $query->chunk(200, function ($rows) use ($out) {
                foreach ($rows as $i) {
                    $this->putCsv($out, [
                        $this->reference($i),
                        $i->user?->name,
                        $i->site?->name,
                        optional($i->injury_date)->format('Y-m-d'),
                        self::TYPE_LABELS[$i->injury_type] ?? $i->injury_type,
                        $i->body_part_affected,
                        $i->severity,
                        $i->status,
                        (int) $i->lost_time_days,
                        $i->acc_claim_lodged ? 'Yes' : 'No',
                        $i->acc_claim_number,
                        $i->worksafe_notifiable ? 'Yes' : 'No',
                    ]);
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /** Detail is modal-first — the full page is retired. Deep-link opens the dialog over the register. */
    public function show(Request $request, WorkplaceInjury $injury): RedirectResponse
    {
        $this->assertCanAccessInjury($request, $injury);

        return redirect()->route('health-safety.injuries.index', ['injury' => $injury->id]);
    }

    /* ================================================================== */
    /*  RTW plans / capacity / modified duties (existing signatures) */
    /* ================================================================== */

    public function storeRtwPlan(Request $request, WorkplaceInjury $injury): RedirectResponse
    {
        $this->assertCanAccessInjury($request, $injury);

        $validated = $request->validate([
            'plan_start_date' => ['required', 'date'],
            'plan_end_date' => ['nullable', 'date', 'after_or_equal:plan_start_date'],
            'goals' => ['required', 'array', 'min:1'],
            'goals.*' => ['string', 'max:500'],
            'stages' => ['required', 'array', 'min:1'],
            'stages.*.name' => ['required', 'string', 'max:255'],
            'stages.*.start_date' => ['required', 'date'],
            'stages.*.end_date' => ['nullable', 'date'],
            'stages.*.hours_per_week' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'stages.*.duties_description' => ['nullable', 'string', 'max:1000'],
            'medical_clearance_notes' => ['nullable', 'string', 'max:2000'],
            'medical_clearance_provider' => ['nullable', 'string', 'max:255'],
            'next_review_date' => ['nullable', 'date'],
            'worker_id' => ['nullable', 'exists:users,id'],
            'manager_id' => ['nullable', 'exists:users,id'],
        ]);

        $workerId = (int) ($validated['worker_id'] ?? $injury->user_id);
        $this->assertStaffAtInjurySite($request, $injury, $workerId);
        if (! empty($validated['manager_id'])) {
            $this->assertStaffAtInjurySite($request, $injury, (int) $validated['manager_id']);
        }

        $injury->returnToWorkPlans()->create(array_merge($validated, [
            'worker_id' => $workerId,
            'status' => 'active',
            'created_by' => $request->user()->id,
        ]));

        return back()->with('success', 'Return-to-work plan created.');
    }

    public function updateRtwPlan(Request $request, ReturnToWorkPlan $rtwPlan): RedirectResponse
    {
        $injury = $rtwPlan->workplaceInjury;
        abort_unless($injury, 403, UserSiteAccessService::DEFAULT_MESSAGE);
        $this->assertCanAccessInjury($request, $injury);

        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:active,in_progress,completed,cancelled'],
            'plan_start_date' => ['sometimes', 'date'],
            'plan_end_date' => ['sometimes', 'nullable', 'date'],
            'goals' => ['sometimes', 'array', 'min:1'],
            'goals.*' => ['string', 'max:500'],
            'stages' => ['sometimes', 'array', 'min:1'],
            'stages.*.name' => ['required_with:stages', 'string', 'max:255'],
            'stages.*.start_date' => ['required_with:stages', 'date'],
            'stages.*.end_date' => ['nullable', 'date'],
            'stages.*.hours_per_week' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'stages.*.duties_description' => ['nullable', 'string', 'max:1000'],
            'medical_clearance_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'medical_clearance_provider' => ['sometimes', 'nullable', 'string', 'max:255'],
            'next_review_date' => ['sometimes', 'nullable', 'date'],
            'review_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $validated['updated_by'] = $request->user()->id;
        $rtwPlan->update($validated);

        return back()->with('success', 'Return-to-work plan updated.');
    }

    public function storeCapacityAssessment(Request $request, WorkplaceInjury $injury): RedirectResponse
    {
        $this->assertCanAccessInjury($request, $injury);

        $validated = $request->validate([
            'assessment_date' => ['required', 'date'],
            'user_id' => ['nullable', 'exists:users,id'],
            'assessor_name' => ['nullable', 'string', 'max:255'],
            'assessor_type' => ['required', 'string', 'in:gp,specialist,physiotherapist,occupational_therapist,employer'],
            'capacity_status' => ['required', 'string', 'in:fit_for_full_duties,fit_for_modified_duties,unfit_for_work,requires_review'],
            'restrictions' => ['nullable', 'string', 'max:5000'],
            'recommendations' => ['nullable', 'string', 'max:5000'],
            'next_assessment_date' => ['nullable', 'date', 'after:assessment_date'],
            'assessment_summary' => ['nullable', 'string', 'max:5000'],
        ]);

        $assessorId = (int) ($validated['user_id'] ?? $injury->user_id);
        $this->assertStaffAtInjurySite($request, $injury, $assessorId);

        $injury->capacityAssessments()->create(array_merge($validated, [
            'user_id' => $assessorId,
            'created_by' => $request->user()->id,
        ]));

        return back()->with('success', 'Capacity assessment recorded.');
    }

    public function storeModifiedDuty(Request $request, ReturnToWorkPlan $rtwPlan): RedirectResponse
    {
        $injury = $rtwPlan->workplaceInjury;
        abort_unless($injury, 403, UserSiteAccessService::DEFAULT_MESSAGE);
        $this->assertCanAccessInjury($request, $injury);

        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'modified_duties_description' => ['required', 'string', 'max:2000'],
            'hours_per_day' => ['required', 'numeric', 'min:0', 'max:24'],
            'restrictions' => ['nullable', 'string', 'max:2000'],
            'accommodations' => ['nullable', 'string', 'max:2000'],
            'user_id' => ['nullable', 'exists:users,id'],
        ]);

        $workerId = (int) ($validated['user_id'] ?? $rtwPlan->worker_id);
        $this->assertStaffAtInjurySite($request, $injury, $workerId);

        $rtwPlan->modifiedDuties()->create(array_merge($validated, [
            'user_id' => $workerId,
            'status' => 'active',
            'created_by' => $request->user()->id,
        ]));

        return back()->with('success', 'Modified duty added.');
    }

    /* ================================================================== */
    /*  Attachments (premium document upload — IDOR-safe trio) */
    /* ================================================================== */

    public function uploadAttachment(Request $request, WorkplaceInjury $injury): RedirectResponse
    {
        $this->assertCanAccessInjury($request, $injury);

        $validated = $request->validate([
            // Allowlist the expected evidence formats — never accept scriptable types
            // (svg/html). Files are stored on the private disk and streamed through the
            // authenticated download route with nosniff + CSP sandbox (defence in depth).
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,gif,doc,docx'],
            'kind' => ['nullable', 'string', 'in:medical_cert,acc_form,rtw_clearance,photo,document'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'alt_text' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $request->file('file');
        $disk = 'private';
        $path = $file->store('workplace_injury_attachments', $disk);

        $injury->attachments()->create([
            'uploaded_by' => $request->user()?->id,
            'disk' => $disk,
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'kind' => $validated['kind'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'alt_text' => $validated['alt_text'] ?? null,
        ]);

        return back()->with('success', 'Document uploaded.');
    }

    public function downloadAttachment(Request $request, WorkplaceInjury $injury, WorkplaceInjuryAttachment $attachment): StreamedResponse
    {
        $this->assertCanAccessInjury($request, $injury);
        abort_unless((int) $attachment->workplace_injury_id === (int) $injury->id, 404);

        // Private disk + nosniff + CSP sandbox — see ServesPrivateAttachments.
        return $this->streamPrivateAttachment(
            $attachment->disk,
            $attachment->path,
            $attachment->original_name,
            $attachment->mime,
        );
    }

    public function destroyAttachment(Request $request, WorkplaceInjury $injury, WorkplaceInjuryAttachment $attachment): RedirectResponse
    {
        $this->assertCanAccessInjury($request, $injury);
        abort_unless((int) $attachment->workplace_injury_id === (int) $injury->id, 404);

        $disk = $attachment->disk ?: 'private';
        if ($attachment->path && Storage::disk($disk)->exists($attachment->path)) {
            Storage::disk($disk)->delete($attachment->path);
        }
        $attachment->delete();

        return back()->with('success', 'Document removed.');
    }

    private function assertCanAccessInjury(Request $request, WorkplaceInjury $injury): void
    {
        $this->siteAccess->assertCanAccessWorkplaceInjury(
            $request->user(),
            $injury,
            self::SITE_BYPASS_PERMISSIONS,
        );
    }

    private function assertWriteContext(
        Request $request,
        int $siteId,
        int $staffId,
        ?int $incidentId,
    ): void {
        $this->siteAccess->assertCanUseCurrentStaffAtSite(
            $request->user(),
            $staffId,
            $siteId,
            self::SITE_BYPASS_PERMISSIONS,
        );
        $this->assertLinkedIncidentAtSite($request, $incidentId, $siteId);
    }

    private function assertLinkedIncidentAtSite(Request $request, ?int $incidentId, int $siteId): void
    {
        if ($incidentId === null) {
            return;
        }

        $incident = ClientIncident::query()->findOrFail($incidentId);
        $this->siteAccess->assertCanUseClientIncidentAtSite(
            $request->user(),
            $incident,
            $siteId,
            self::SITE_BYPASS_PERMISSIONS,
        );
    }

    private function assertStaffAtInjurySite(
        Request $request,
        WorkplaceInjury $injury,
        int $staffId,
    ): void {
        $this->siteAccess->assertCanUseCurrentStaffAtSite(
            $request->user(),
            $staffId,
            (int) $injury->site_id,
            self::SITE_BYPASS_PERMISSIONS,
        );
    }
}
