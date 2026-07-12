<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Models\ClientIncident;
use App\Models\EmergencyDrill;
use App\Models\FleetIncident;
use App\Models\FleetWorkOrder;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\HsRiskAssessment;
use App\Models\RestraintEvent;
use App\Models\SafeguardingConcern;
use App\Models\Site;
use App\Models\SiteHazard;
use App\Models\SiteInspectionRecord;
use App\Models\SubstanceExposureRecord;
use App\Models\User;
use App\Models\WorkplaceInjury;
use App\Services\HealthSafety\HsEventService;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class HsEventController extends Controller
{
    public function __construct(
        private readonly HsEventService $events,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /**
     * H&S Events register — the governance convergence view.
     *
     * Hero counts, tab counts, standardised rows (source + governance flags) and,
     * on ?event=, the detail payload for the over-the-list modal.
     */
    public function index(Request $request): Response
    {
        $tab = (string) $request->input('tab', 'all');

        // ── List query: scope (site + period) + tab + refinements ──
        $query = HsEvent::query()
            ->with(['site:id,name', 'client:id,first_name,last_name', 'staff:id,name'])
            ->withCount([
                'investigations',
                'investigations as overdue_investigations_count' => fn (Builder $q) => $q
                    ->whereNotNull('target_completion_date')
                    ->where('target_completion_date', '<', now())
                    ->where('status', '!=', HsInvestigation::STATUS_COMPLETED),
                'correctiveActions as open_actions_count' => fn (Builder $q) => $q
                    ->whereNotIn('status', [HsCorrectiveAction::STATUS_VERIFIED, HsCorrectiveAction::STATUS_CLOSED]),
                'correctiveActions as awaiting_verification_count' => fn (Builder $q) => $q
                    ->where('status', HsCorrectiveAction::STATUS_COMPLETED),
            ])
            ->orderByDesc('reported_at');

        $this->applyScope($query, $request);
        $this->applyTab($query, $tab);

        if ($request->filled('severity')) {
            $query->where('severity', $request->input('severity'));
        }
        if ($request->filled('category')) {
            $query->where('event_category', $request->input('category'));
        }
        if ($sourceType = $this->sourceTypeForFilter($request->input('source'))) {
            $query->where('source_type', $sourceType);
        }
        if ($request->boolean('worksafe')) {
            $query->where('worksafe_notifiable', true);
        }
        if ($request->filled('q')) {
            $term = trim((string) $request->input('q'));
            $query->where('reference_number', 'like', "%{$term}%");
        }

        $paginator = $query->paginate(25)->withQueryString();

        // Batch-resolve the two source types whose jump URL needs a record lookup
        // (exposure → substance, inspection → site) so the per-row resolveSource()
        // below issues no extra queries (was a small N+1 across the page).
        $rows = $paginator->getCollection();
        $exposureIds = $rows->where('source_type', SubstanceExposureRecord::class)->pluck('source_id');
        $inspectionIds = $rows->where('source_type', SiteInspectionRecord::class)->pluck('source_id');
        $exposureMap = $exposureIds->isEmpty()
            ? collect()
            : SubstanceExposureRecord::whereIn('id', $exposureIds)->pluck('hazardous_substance_id', 'id');
        $inspectionMap = $inspectionIds->isEmpty()
            ? collect()
            : SiteInspectionRecord::whereIn('id', $inspectionIds)->pluck('site_id', 'id');

        $events = $paginator->through(fn (HsEvent $e) => [
            'id' => $e->id,
            'reference_number' => $e->reference_number,
            'event_category' => $e->event_category,
            'severity' => $e->severity,
            'status' => $e->status,
            'occurred_at' => $e->occurred_at?->toIso8601String(),
            'reported_at' => $e->reported_at?->toIso8601String(),
            'site_name' => $e->site?->name,
            'client_name' => $e->client ? trim($e->client->first_name.' '.$e->client->last_name) : null,
            'staff_name' => $e->staff?->name,
            'worksafe_notifiable' => (bool) $e->worksafe_notifiable,
            'worksafe_status' => $e->worksafe_status,
            'investigation_required' => (bool) $e->investigation_required,
            'source' => $this->resolveSource($e->source_type, $e->source_id, $exposureMap, $inspectionMap),
            'flags' => [
                'investigation_overdue' => $e->overdue_investigations_count > 0,
                'awaiting_verification' => (int) $e->awaiting_verification_count,
                'worksafe_pending' => $e->worksafe_notifiable && $e->worksafe_status === HsEvent::WORKSAFE_PENDING,
                'unwired' => $e->source_id === null,
            ],
            'has_investigation' => $e->investigations_count > 0,
            'has_open_actions' => $e->open_actions_count > 0,
        ]);

        // ── Hero + tab counts (respect scope only — not tab/refinements) ──
        $statusCounts = $this->scopedBase($request)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $count = fn (string $s): int => (int) ($statusCounts[$s] ?? 0);

        $tabCounts = [
            'all' => (int) $statusCounts->sum(),
            'open' => $count(HsEvent::STATUS_OPEN),
            'investigating' => $count(HsEvent::STATUS_INVESTIGATING),
            'corrective_actions' => $count(HsEvent::STATUS_CORRECTIVE_ACTION),
            'monitoring' => $count(HsEvent::STATUS_MONITORING),
            'closed' => $count(HsEvent::STATUS_CLOSED),
            'worksafe' => (int) $this->scopedBase($request)->where('worksafe_notifiable', true)->count(),
        ];

        $hero = [
            'live' => [
                'open' => $count(HsEvent::STATUS_OPEN),
                'investigating' => $count(HsEvent::STATUS_INVESTIGATING),
                'corrective_action' => $count(HsEvent::STATUS_CORRECTIVE_ACTION),
                'monitoring' => $count(HsEvent::STATUS_MONITORING),
            ],
            'attention' => [
                'investigation_due' => (int) $this->scopedBase($request)
                    ->where('investigation_required', true)
                    ->where('status', '!=', HsEvent::STATUS_CLOSED)
                    ->whereDoesntHave('investigations', fn (Builder $q) => $q->where('status', HsInvestigation::STATUS_COMPLETED))
                    ->count(),
                'awaiting_verification' => (int) $this->scopedBase($request)
                    ->whereHas('correctiveActions', fn (Builder $q) => $q->where('status', HsCorrectiveAction::STATUS_COMPLETED))
                    ->count(),
                'worksafe_due' => (int) $this->scopedBase($request)
                    ->where('worksafe_notifiable', true)
                    ->where('worksafe_status', HsEvent::WORKSAFE_PENDING)
                    ->count(),
                'closed_period' => $count(HsEvent::STATUS_CLOSED),
            ],
        ];

        // ── Detail-over-list (?event=) ──
        $detail = null;
        if ($request->filled('event')) {
            $target = $this->resolveAccessibleEvent($request, $request->integer('event'));
            $target = $this->scopedBase($request)->whereKey($target->id)->first();
            $detail = $target ? $this->buildEventDetail($target) : null;
        }

        $siteIds = $this->scopedBase($request)->whereNotNull('site_id')->distinct()->pluck('site_id');

        return Inertia::render('health-safety/events/index', [
            'events' => $events,
            'tab' => $tab,
            'tabCounts' => $tabCounts,
            'hero' => $hero,
            'filters' => [
                'q' => $request->input('q'),
                'tab' => $tab,
                'severity' => $request->input('severity'),
                'category' => $request->input('category'),
                'source' => $request->input('source'),
                'site_id' => $request->filled('site_id') ? (int) $request->input('site_id') : null,
                'worksafe' => $request->boolean('worksafe') ?: null,
                'from' => $request->input('from'),
                'to' => $request->input('to'),
            ],
            'sites' => Site::whereIn('id', $siteIds)->orderBy('name')->get(['id', 'name']),
            'detail' => $detail,
            'can' => ['manage' => (bool) ($request->user()?->canDo('hazards.manage') ?? false)],
        ]);
    }

    /** Scope = the hero/tab "period + site" lens (never the tab or list refinements). */
    private function applyScope(Builder $query, Request $request): void
    {
        $this->siteAccess->applyHsEventScope(
            $query,
            $request->user(),
            $this->hsEventBypassPermissions(),
        );

        if ($request->filled('site_id')) {
            $this->siteAccess->assertCanAccessSiteId(
                $request->user(),
                (int) $request->input('site_id'),
                $this->hsEventBypassPermissions(),
            );
            $query->where('site_id', (int) $request->input('site_id'));
        }
        if ($request->filled('from')) {
            $query->whereDate('occurred_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('occurred_at', '<=', $request->input('to'));
        }
    }

    /** A fresh, scope-applied base query for the hero/tab aggregates. */
    private function scopedBase(Request $request): Builder
    {
        $query = HsEvent::query();
        $this->applyScope($query, $request);

        return $query;
    }

    /** Tab → governance-status filter (category stays a filter, never a tab). */
    private function applyTab(Builder $query, string $tab): void
    {
        match ($tab) {
            'open' => $query->where('status', HsEvent::STATUS_OPEN),
            'investigating' => $query->where('status', HsEvent::STATUS_INVESTIGATING),
            'corrective_actions' => $query->where('status', HsEvent::STATUS_CORRECTIVE_ACTION),
            'monitoring' => $query->where('status', HsEvent::STATUS_MONITORING),
            'closed' => $query->where('status', HsEvent::STATUS_CLOSED),
            'worksafe' => $query->where('worksafe_notifiable', true),
            default => $query, // 'all'
        };
    }

    private function sourceTypeForFilter(mixed $source): ?string
    {
        return match ((string) $source) {
            'incidents' => ClientIncident::class,
            'safeguarding' => SafeguardingConcern::class,
            'fleet' => FleetIncident::class,
            'injuries' => WorkplaceInjury::class,
            'exposure' => SubstanceExposureRecord::class,
            'site_hazards' => SiteHazard::class,
            'inspection' => SiteInspectionRecord::class,
            'equipment' => FleetWorkOrder::class,
            'restraints' => RestraintEvent::class,
            'drills' => EmergencyDrill::class,
            default => null,
        };
    }

    /**
     * H&S Event detail — thin deep-link / share fallback. Renders the same
     * governance modal (HsEventDialog) on a thin shell as the over-the-list
     * modal opened from the register.
     */
    public function show(Request $request, int $hsEvent): Response
    {
        $hsEvent = $this->resolveAccessibleEvent($request, $hsEvent);

        return Inertia::render('health-safety/events/show', [
            'detail' => $this->buildEventDetail($hsEvent),
        ]);
    }

    /**
     * Close an event through the governance gate (E-Gap 1). A blocked closure
     * (incomplete required investigation / unverified actions) requires a logged
     * override reason; a closure summary is always required.
     */
    public function close(Request $request, int $hsEvent)
    {
        $hsEvent = $this->resolveAccessibleEvent($request, $hsEvent);

        $data = $request->validate([
            'closure_summary' => ['required', 'string', 'max:2000'],
            'override_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->events->closeEvent(
                $hsEvent,
                $data['closure_summary'],
                $request->user(),
                $data['override_reason'] ?? null,
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Event closed.');
    }

    /**
     * Record the WorkSafe NZ notification for a notifiable event (E-Gap 2):
     * pending → notified, persisting date/method/reference + site preservation.
     */
    public function worksafeNotify(Request $request, int $hsEvent)
    {
        $hsEvent = $this->resolveAccessibleEvent($request, $hsEvent);

        $data = $request->validate([
            'notified_at' => ['required', 'date'],
            'method' => ['required', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:50'],
            'site_preserved' => ['boolean'],
        ]);

        try {
            $this->events->recordWorksafeNotification(
                $hsEvent,
                $data['notified_at'],
                $data['method'],
                $data['reference'] ?? null,
                $request->boolean('site_preserved'),
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'WorkSafe notification recorded.');
    }

    /**
     * Record WorkSafe's acknowledgement of the notification (notified → acknowledged).
     */
    public function worksafeAcknowledge(Request $request, int $hsEvent)
    {
        $hsEvent = $this->resolveAccessibleEvent($request, $hsEvent);

        $data = $request->validate([
            'acknowledged_at' => ['required', 'date'],
        ]);

        try {
            $this->events->acknowledgeWorksafe($hsEvent, $data['acknowledged_at']);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'WorkSafe acknowledgement recorded.');
    }

    /**
     * Two-way convergence (E-Gap 4): resolve a polymorphic source to a label + a
     * jump back to the originating record. Categories with no source (the orphan
     * categories) return null. Modules without a per-record route resolve to a
     * label only (no jump) rather than a broken link.
     */
    /**
     * Resolve a polymorphic source to a label + jump URL. The two source types
     * whose URL needs a record lookup (exposure → its substance, inspection → its
     * site) accept an optional pre-loaded id→value map so the list can batch-resolve
     * them in one query instead of one-per-row (the detail path passes none and
     * looks the single record up directly).
     */
    private function resolveSource(?string $sourceType, ?int $sourceId, ?Collection $exposureMap = null, ?Collection $inspectionMap = null): ?array
    {
        if (! $sourceType || ! $sourceId) {
            return null;
        }

        $basename = class_basename($sourceType);

        [$label, $url] = match ($basename) {
            'ClientIncident' => ['Incident', "/incidents/{$sourceId}"],
            'SafeguardingConcern' => ['Safeguarding concern', "/safeguarding/{$sourceId}"],
            'FleetIncident' => ['Fleet incident', "/fleet-assets/incidents?incident={$sourceId}"],
            'WorkplaceInjury' => ['Workplace injury', "/health-safety/injuries/{$sourceId}"],
            'SubstanceExposureRecord' => $this->resolveSubstanceExposureSource($sourceId, $exposureMap),
            'SiteInspectionRecord' => $this->resolveSiteInspectionSource($sourceId, $inspectionMap),
            'FleetWorkOrder' => ['Fleet work order', "/fleet-assets/maintenance/work-orders/{$sourceId}"],
            'EmergencyDrill' => ['Emergency drill', "/health-safety/drills/{$sourceId}"],
            'SiteHazard' => ['Hazard', null],
            'RestraintEvent' => ['Restraint event', null],
            default => [$basename, null],
        };

        return [
            'type' => $basename,
            'id' => $sourceId,
            'label' => "{$label} #{$sourceId}",
            'url' => $url,
            'unwired' => false,
        ];
    }

    private function resolveSubstanceExposureSource(int $sourceId, ?Collection $map = null): array
    {
        $substanceId = $map !== null
            ? $map->get($sourceId)
            : SubstanceExposureRecord::query()->select(['id', 'hazardous_substance_id'])->find($sourceId)?->hazardous_substance_id;

        return ['Substance exposure', $substanceId ? "/health-safety/substances/{$substanceId}" : null];
    }

    private function resolveSiteInspectionSource(int $sourceId, ?Collection $map = null): array
    {
        $siteId = $map !== null
            ? $map->get($sourceId)
            : SiteInspectionRecord::query()->select(['id', 'site_id'])->find($sourceId)?->site_id;

        return ['Site inspection', $siteId ? "/sites/{$siteId}/inspections" : null];
    }

    /**
     * The full governance detail payload — the contract behind the HsEventDialog
     * (mirrored by `EventDetail` in event-detail-dialog.tsx). Shared by index()
     * (over-the-list modal on ?event=) and show() (deep-link shell).
     */
    private function buildEventDetail(HsEvent $hsEvent): array
    {
        $hsEvent->loadMissing([
            'site:id,name',
            'client:id,first_name,last_name',
            'staff:id,name',
            'asset:id,name',
            'controlRoomAlert:id,severity,status',
            'creator:id,name',
        ]);

        $investigations = $hsEvent->investigations()
            ->with(['leadInvestigator:id,name', 'reviewedBy:id,name', 'approvedBy:id,name'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (HsInvestigation $inv) => [
                'id' => $inv->id,
                'reference_number' => $inv->reference_number,
                'investigation_type' => $inv->investigation_type,
                'status' => $inv->status,
                'methodology' => $inv->methodology,
                'lead_investigator_name' => $inv->leadInvestigator?->name,
                'started_at' => $inv->started_at?->toIso8601String(),
                'target_completion_date' => $inv->target_completion_date?->toDateString(),
                'completed_at' => $inv->completed_at?->toIso8601String(),
                'is_overdue' => $inv->isOverdue(),
                'has_findings' => $inv->hasFindings(),
                'has_recommendations' => $inv->hasRecommendations(),
                'recommendation_count' => count($inv->recommendations ?? []),
                'immediate_causes' => $inv->immediate_causes,
                'root_causes' => $inv->root_causes,
                'contributing_factors' => $inv->contributing_factors,
                'findings_summary' => $inv->findings_summary,
                'recommendations' => $inv->recommendations,
                'lessons_learned' => $inv->lessons_learned,
                'reviewed_by_name' => $inv->reviewedBy?->name,
                'approved_by_name' => $inv->approvedBy?->name,
            ]);

        $canManage = (bool) (auth()->user()?->canDo('hazards.manage') ?? false);
        $currentUserId = auth()->id();

        $assignableStaff = [];
        if ($canManage) {
            $staffQuery = User::query()->staff()->whereNotNull('approved_at')->orderBy('name')->limit(200);
            $this->siteAccess->applyStaffScope(
                $staffQuery,
                auth()->user(),
                $this->hsEventBypassPermissions(),
            );
            $assignableStaff = $staffQuery->get(['id', 'name'])
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
                ->all();
        }

        $correctiveActions = $hsEvent->correctiveActions()
            ->with(['assignedTo:id,name', 'completedBy:id,name', 'verifiedBy:id,name'])
            ->orderByRaw("FIELD(status, 'open', 'in_progress', 'completed', 'verified', 'closed')")
            ->orderBy('due_date')
            ->get()
            ->map(fn (HsCorrectiveAction $a) => [
                'id' => $a->id,
                'reference_number' => $a->reference_number,
                'title' => $a->title,
                'action_type' => $a->action_type,
                'priority' => $a->priority,
                'status' => $a->status,
                'assigned_to_name' => $a->assignedTo?->name,
                'due_date' => $a->due_date?->toDateString(),
                'is_overdue' => $a->isOverdue(),
                'completed_at' => $a->completed_at?->toIso8601String(),
                'completed_by_user_id' => $a->completed_by_user_id,
                'completed_by_name' => $a->completedBy?->name,
                'can_verify' => $canManage
                    && $a->status === 'completed'
                    && $a->completed_by_user_id !== $currentUserId,
                'verified_at' => $a->verified_at?->toIso8601String(),
                'verified_by_name' => $a->verifiedBy?->name,
                'effectiveness_confirmed' => $a->effectiveness_confirmed,
                'hs_investigation_id' => $a->hs_investigation_id,
                'recommendation_index' => $a->recommendation_index,
            ]);

        $riskAssessments = $hsEvent->riskAssessments()
            ->with(['assessedBy:id,name'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (HsRiskAssessment $ra) => [
                'id' => $ra->id,
                'reference_number' => $ra->reference_number,
                'title' => $ra->title,
                'status' => $ra->status,
                'likelihood' => $ra->likelihood,
                'consequence' => $ra->consequence,
                'risk_score' => $ra->risk_score,
                'risk_level' => $ra->risk_level,
                'residual_likelihood' => $ra->residual_likelihood,
                'residual_consequence' => $ra->residual_consequence,
                'residual_risk_score' => $ra->residual_risk_score,
                'residual_risk_level' => $ra->residual_risk_level,
                'risk_acceptable' => $ra->risk_acceptable,
                'assessed_by_name' => $ra->assessedBy?->name,
                'review_due_at' => $ra->review_due_at?->toDateString(),
                'is_due_for_review' => $ra->isDueForReview(),
            ]);

        $source = $this->resolveSource($hsEvent->source_type, $hsEvent->source_id);

        return [
            'id' => $hsEvent->id,
            'reference_number' => $hsEvent->reference_number,
            'event_category' => $hsEvent->event_category,
            'severity' => $hsEvent->severity,
            'status' => $hsEvent->status,
            'occurred_at' => $hsEvent->occurred_at?->toIso8601String(),
            'reported_at' => $hsEvent->reported_at?->toIso8601String(),
            'description' => null,
            'site' => $hsEvent->site ? ['id' => $hsEvent->site->id, 'name' => $hsEvent->site->name] : null,
            'client' => $hsEvent->client ? ['id' => $hsEvent->client->id, 'name' => trim($hsEvent->client->first_name.' '.$hsEvent->client->last_name)] : null,
            'staff' => $hsEvent->staff ? ['id' => $hsEvent->staff->id, 'name' => $hsEvent->staff->name] : null,
            'asset' => $hsEvent->asset ? ['id' => $hsEvent->asset->id, 'name' => $hsEvent->asset->name] : null,
            'worksafe_notifiable' => (bool) $hsEvent->worksafe_notifiable,
            'worksafe_status' => $hsEvent->worksafe_status,
            'worksafe_reference' => $hsEvent->worksafe_reference,
            'worksafe_notified_at' => $hsEvent->worksafe_notified_at?->toIso8601String(),
            'worksafe_acknowledged_at' => $hsEvent->worksafe_acknowledged_at?->toIso8601String(),
            'worksafe_method' => $hsEvent->worksafe_method,
            'worksafe_site_preserved' => (bool) $hsEvent->worksafe_site_preserved,
            'worksafe_reason' => null,
            'investigation_required' => (bool) $hsEvent->investigation_required,
            'control_room_alert' => $hsEvent->controlRoomAlert ? [
                'id' => $hsEvent->controlRoomAlert->id,
                'severity' => $hsEvent->controlRoomAlert->severity,
                'status' => $hsEvent->controlRoomAlert->status,
            ] : null,
            'closed_at' => $hsEvent->closed_at?->toIso8601String(),
            'closure_summary' => $hsEvent->closure_summary,
            'created_by_name' => $hsEvent->creator?->name,
            'source' => $source,
            'investigations' => $investigations,
            'corrective_actions' => $correctiveActions,
            'risk_assessments' => $riskAssessments,
            'attachments' => [],   // evidence gallery wired in a later step
            'close_gate' => [
                'investigation_ok' => ! $hsEvent->investigation_required || $hsEvent->hasCompletedInvestigation(),
                'actions_ok' => ! $hsEvent->hasOpenCorrectiveActions(),
                'blockers' => $this->events->closeBlockers($hsEvent),
            ],
            'assignable_staff' => $assignableStaff,
            'can' => ['manage' => $canManage],
        ];
    }

    /**
     * Corrective actions listing — all actions across all events.
     */
    public function correctiveActions(Request $request): Response
    {
        $tab = (string) $request->input('tab', $this->legacyActionTab($request));

        $canManage = (bool) ($request->user()?->canDo('hazards.manage') ?? false);
        $currentUserId = $request->user()?->id;

        $query = HsCorrectiveAction::query()
            ->with([
                'hsEvent:id,reference_number,event_category,severity,status,site_id',
                'hsEvent.site:id,name',
                'assignedTo:id,name',
                'completedBy:id,name',
            ])
            ->orderByRaw("FIELD(status, 'open', 'in_progress', 'completed', 'verified', 'closed')")
            ->orderBy('due_date');

        $this->applyActionScope($query, $request);
        $this->applyActionTab($query, $tab);

        if ($request->filled('priority')) {
            $query->whereIn('priority', array_filter(explode(',', (string) $request->input('priority'))));
        }

        if ($request->boolean('unassigned')) {
            $query->whereNull('assigned_to_user_id')
                ->whereNotIn('status', [HsCorrectiveAction::STATUS_VERIFIED, HsCorrectiveAction::STATUS_CLOSED]);
        }

        if ($request->filled('q')) {
            $term = trim((string) $request->input('q'));
            $query->where(function (Builder $q) use ($term) {
                $q->where('reference_number', 'like', "%{$term}%")
                    ->orWhere('title', 'like', "%{$term}%")
                    ->orWhereHas('hsEvent', fn (Builder $event) => $event->where('reference_number', 'like', "%{$term}%"));
            });
        }

        $actions = $query->paginate(25)->withQueryString()->through(fn (HsCorrectiveAction $a) => [
            'id' => $a->id,
            'reference_number' => $a->reference_number,
            'title' => $a->title,
            'action_type' => $a->action_type,
            'priority' => $a->priority,
            'status' => $a->status,
            'assigned_to_name' => $a->assignedTo?->name,
            'due_date' => $a->due_date?->toDateString(),
            'is_overdue' => $a->isOverdue(),
            'completed_at' => $a->completed_at?->toIso8601String(),
            'verified_at' => $a->verified_at?->toIso8601String(),
            'completed_by_user_id' => $a->completed_by_user_id,
            'completed_by_name' => $a->completedBy?->name,
            'can_verify' => $canManage
                && $a->status === 'completed'
                && $a->completed_by_user_id !== $currentUserId,
            'event' => $a->hsEvent ? [
                'id' => $a->hsEvent->id,
                'reference_number' => $a->hsEvent->reference_number,
                'event_category' => $a->hsEvent->event_category,
                'severity' => $a->hsEvent->severity,
                'status' => $a->hsEvent->status,
                'site_name' => $a->hsEvent->site?->name,
                'url' => "/health-safety/events?event={$a->hsEvent->id}",
                'monitoring' => $a->hsEvent->status === HsEvent::STATUS_MONITORING,
            ] : null,
        ]);

        $base = $this->actionScopedBase($request);
        $countStatus = fn (string $status): int => (int) (clone $base)->where('status', $status)->count();
        $openCount = $countStatus(HsCorrectiveAction::STATUS_OPEN);
        $inProgressCount = $countStatus(HsCorrectiveAction::STATUS_IN_PROGRESS);
        $awaitingCount = $countStatus(HsCorrectiveAction::STATUS_COMPLETED);
        $verifiedCount = $countStatus(HsCorrectiveAction::STATUS_VERIFIED);
        $closedCount = $countStatus(HsCorrectiveAction::STATUS_CLOSED);

        $tabCounts = [
            'all' => (int) (clone $base)->count(),
            'open' => $openCount,
            'in_progress' => $inProgressCount,
            'awaiting_verification' => $awaitingCount,
            'overdue' => (int) (clone $base)->overdue()->count(),
            'verified' => $verifiedCount,
            'closed' => $closedCount,
        ];

        $hero = [
            'live' => [
                'open' => $openCount,
                'in_progress' => $inProgressCount,
                'awaiting_verification' => $awaitingCount,
                'verified' => $verifiedCount,
            ],
            'attention' => [
                'overdue' => $tabCounts['overdue'],
                'critical_open' => (int) (clone $base)
                    ->whereIn('priority', [HsCorrectiveAction::PRIORITY_HIGH, HsCorrectiveAction::PRIORITY_CRITICAL])
                    ->whereNotIn('status', [HsCorrectiveAction::STATUS_VERIFIED, HsCorrectiveAction::STATUS_CLOSED])
                    ->count(),
                'unassigned' => (int) (clone $base)
                    ->whereNull('assigned_to_user_id')
                    ->whereNotIn('status', [HsCorrectiveAction::STATUS_VERIFIED, HsCorrectiveAction::STATUS_CLOSED])
                    ->count(),
                'monitoring_events' => (int) (clone $base)
                    ->whereHas('hsEvent', fn (Builder $q) => $q->where('status', HsEvent::STATUS_MONITORING))
                    ->distinct('hs_event_id')
                    ->count('hs_event_id'),
            ],
        ];

        $detail = null;
        if ($request->filled('event')) {
            $target = $this->resolveAccessibleEvent($request, $request->integer('event'));
            $detail = $this->buildEventDetail($target);
        }

        $siteIds = $this->actionScopedBase($request)->whereHas('hsEvent', fn (Builder $q) => $q->whereNotNull('site_id'))
            ->with('hsEvent:id,site_id')
            ->get()
            ->pluck('hsEvent.site_id')
            ->filter()
            ->unique()
            ->values();

        return Inertia::render('health-safety/corrective-actions/index', [
            'actions' => $actions,
            'tab' => $tab,
            'tabCounts' => $tabCounts,
            'hero' => $hero,
            'filters' => [
                'q' => $request->input('q'),
                'tab' => $tab,
                'priority' => $request->input('priority'),
                'unassigned' => $request->boolean('unassigned') ?: null,
                'site_id' => $request->filled('site_id') ? (int) $request->input('site_id') : null,
                'from' => $request->input('from'),
                'to' => $request->input('to'),
            ],
            'sites' => Site::whereIn('id', $siteIds)->orderBy('name')->get(['id', 'name']),
            'detail' => $detail,
            'can' => [
                'manage' => $canManage,
                // Traceability report is a governance artefact, gated on governance.view
                // (NOT hazards.manage) per the corrective-actions handover.
                'viewReports' => (bool) ($request->user()?->canDo('governance.view') ?? false),
            ],
        ]);
    }

    private function legacyActionTab(Request $request): string
    {
        if ($request->input('overdue') === 'true') {
            return 'overdue';
        }

        if ($request->input('awaiting_verification') === 'true') {
            return 'awaiting_verification';
        }

        return match ((string) $request->input('status', 'all')) {
            HsCorrectiveAction::STATUS_IN_PROGRESS => 'in_progress',
            HsCorrectiveAction::STATUS_COMPLETED => 'awaiting_verification',
            HsCorrectiveAction::STATUS_VERIFIED => 'verified',
            HsCorrectiveAction::STATUS_CLOSED => 'closed',
            HsCorrectiveAction::STATUS_OPEN => 'open',
            default => 'all',
        };
    }

    private function applyActionScope(Builder $query, Request $request): void
    {
        $query->whereHas('hsEvent', function (Builder $eventQuery) use ($request) {
            $this->siteAccess->applyHsEventScope(
                $eventQuery,
                $request->user(),
                $this->hsEventBypassPermissions(),
            );
        });

        if ($request->filled('site_id')) {
            $this->siteAccess->assertCanAccessSiteId(
                $request->user(),
                (int) $request->input('site_id'),
                $this->hsEventBypassPermissions(),
            );
            $siteId = (int) $request->input('site_id');
            $query->whereHas('hsEvent', fn (Builder $q) => $q->where('site_id', $siteId));
        }
        if ($request->filled('from')) {
            $query->whereDate('due_date', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('due_date', '<=', $request->input('to'));
        }
    }

    private function actionScopedBase(Request $request): Builder
    {
        $query = HsCorrectiveAction::query();
        $this->applyActionScope($query, $request);

        return $query;
    }

    private function applyActionTab(Builder $query, string $tab): void
    {
        match ($tab) {
            'open' => $query->where('status', HsCorrectiveAction::STATUS_OPEN),
            'in_progress' => $query->where('status', HsCorrectiveAction::STATUS_IN_PROGRESS),
            'awaiting_verification' => $query->where('status', HsCorrectiveAction::STATUS_COMPLETED),
            'verified' => $query->where('status', HsCorrectiveAction::STATUS_VERIFIED),
            'closed' => $query->where('status', HsCorrectiveAction::STATUS_CLOSED),
            'overdue' => $query->overdue(),
            default => $query,
        };
    }

    private function resolveAccessibleEvent(Request $request, int $eventId): HsEvent
    {
        $query = HsEvent::query();
        $this->siteAccess->applyHsEventScope(
            $query,
            $request->user(),
            $this->hsEventBypassPermissions(),
        );

        return $query->findOrFail($eventId);
    }

    /**
     * @return array<int, string>
     */
    private function hsEventBypassPermissions(): array
    {
        return ['healthSafety.viewAllSites'];
    }

    /**
     * Risk assessments listing.
     */
    public function riskAssessments(Request $request): Response
    {
        $query = HsRiskAssessment::query()
            ->with(['assessedBy:id,name'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('risk_level')) {
            $query->where('risk_level', $request->input('risk_level'));
        }

        if ($request->input('due_for_review') === 'true') {
            $query->dueForReview();
        }

        $assessments = $query->paginate(25)->through(fn (HsRiskAssessment $ra) => [
            'id' => $ra->id,
            'reference_number' => $ra->reference_number,
            'title' => $ra->title,
            'status' => $ra->status,
            'risk_score' => $ra->risk_score,
            'risk_level' => $ra->risk_level,
            'residual_risk_level' => $ra->residual_risk_level,
            'risk_acceptable' => $ra->risk_acceptable,
            'assessed_by_name' => $ra->assessedBy?->name,
            'review_due_at' => $ra->review_due_at?->toDateString(),
            'is_due_for_review' => $ra->isDueForReview(),
            'assessable_type' => $ra->assessable_type ? class_basename($ra->assessable_type) : null,
        ]);

        return Inertia::render('health-safety/risk-assessments/index', [
            'assessments' => $assessments,
            'filters' => $request->only(['status', 'risk_level', 'due_for_review']),
        ]);
    }
}
