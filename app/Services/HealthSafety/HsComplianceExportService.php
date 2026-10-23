<?php

namespace App\Services\HealthSafety;

use App\Models\Client;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\HsRiskAssessment;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Structured compliance exports for WorkSafe, auditors, and governance.
 *
 * Each method returns a consistently shaped array with this envelope:
 *  - report_type: machine identifier
 *  - title: human-readable report name
 *  - generated_at: ISO 8601 timestamp
 *  - period: {from, to} date range (nullable for point-in-time reports)
 *  - summary: aggregated metrics
 *  - items: detail rows
 *
 * Designed for: JSON API response, future PDF rendering, board pack
 * attachment, and audit evidence packs.
 */
class HsComplianceExportService
{
    /** @var list<string> */
    private const SITE_BYPASS_PERMISSIONS = UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS;

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Report type identifiers — stable contract values */
    /*  These are used in report envelopes and must not be renamed. */
    /* ------------------------------------------------------------------ */

    public const REPORT_WORKSAFE_REGISTER = 'worksafe_register';

    public const REPORT_INVESTIGATION_OUTCOMES = 'investigation_outcomes';

    public const REPORT_CORRECTIVE_ACTION_TRACEABILITY = 'corrective_action_traceability';

    public const REPORT_RISK_ASSESSMENT_REGISTER = 'risk_assessment_register';

    /** All available report types. */
    public const REPORT_TYPES = [
        self::REPORT_WORKSAFE_REGISTER,
        self::REPORT_INVESTIGATION_OUTCOMES,
        self::REPORT_CORRECTIVE_ACTION_TRACEABILITY,
        self::REPORT_RISK_ASSESSMENT_REGISTER,
    ];

    /* ------------------------------------------------------------------ */
    /*  WorkSafe Notifiable Events Register */
    /* ------------------------------------------------------------------ */

    public function worksafeRegister(
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?User $viewer = null,
        ?int $siteId = null,
        ?HealthSafetyReadContext $context = null,
    ): array {
        return $this->withSourceRead($viewer, $context, fn (?User $viewer, ?HealthSafetyReadContext $context) => $this->worksafeRegisterSnapshot($from, $to, $viewer, $siteId, $context));
    }

    private function worksafeRegisterSnapshot(
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?User $viewer = null,
        ?int $siteId = null,
        ?HealthSafetyReadContext $context = null,
    ): array {
        $query = $this->eventQuery($viewer, $siteId, $context)
            ->where('worksafe_notifiable', true)
            ->withExists('investigations')
            ->with(['site:id,name', 'client:id,first_name,last_name', 'staff:id,name'])
            ->orderByDesc('reported_at');

        if ($from) {
            $query->where('reported_at', '>=', $from);
        }
        if ($to) {
            $query->where('reported_at', '<=', $to);
        }

        return $this->envelope(self::REPORT_WORKSAFE_REGISTER, 'WorkSafe Notifiable Events Register', $from, $to, [
            'total' => (clone $query)->count(),
            'pending_notification' => (clone $query)->where('worksafe_status', HsEvent::WORKSAFE_PENDING)->count(),
            'notified' => (clone $query)->where('worksafe_status', HsEvent::WORKSAFE_NOTIFIED)->count(),
            'acknowledged' => (clone $query)->where('worksafe_status', HsEvent::WORKSAFE_ACKNOWLEDGED)->count(),
        ], $this->protectSourceEagerLoads($query)->get()->map(fn (HsEvent $e) => [
            'reference_number' => $e->reference_number,
            'event_category' => $e->event_category,
            'severity' => $e->severity,
            'status' => $e->status,
            'worksafe_status' => $e->worksafe_status,
            'worksafe_reference' => $e->worksafe_reference,
            'occurred_at' => $e->occurred_at?->toIso8601String(),
            'reported_at' => $e->reported_at?->toIso8601String(),
            'site_name' => $e->site?->name,
            'investigation_required' => $e->investigation_required,
            'has_investigation' => (bool) $e->investigations_exists,
        ])->toArray());
    }

    /* ------------------------------------------------------------------ */
    /*  Investigation Outcomes Report */
    /* ------------------------------------------------------------------ */

    public function investigationOutcomes(
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?User $viewer = null,
        ?int $siteId = null,
        ?HealthSafetyReadContext $context = null,
    ): array {
        return $this->withSourceRead($viewer, $context, fn (?User $viewer, ?HealthSafetyReadContext $context) => $this->investigationOutcomesSnapshot($from, $to, $viewer, $siteId, $context));
    }

    private function investigationOutcomesSnapshot(
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?User $viewer = null,
        ?int $siteId = null,
        ?HealthSafetyReadContext $context = null,
    ): array {
        $query = $this->investigationQuery($viewer, $siteId, $context)
            ->ofStatus(HsInvestigation::STATUS_COMPLETED)
            ->with([
                'hsEvent:id,reference_number,event_category,severity,site_id',
                'hsEvent.site:id,name',
                'leadInvestigator:id,name',
                'approvedBy:id,name',
            ])
            ->orderByDesc('completed_at');

        if ($from) {
            $query->where('completed_at', '>=', $from);
        }
        if ($to) {
            $query->where('completed_at', '<=', $to);
        }

        $results = $this->protectSourceEagerLoads($query)->get();

        return $this->envelope(self::REPORT_INVESTIGATION_OUTCOMES, 'Investigation Outcomes Summary', $from, $to, [
            'total_completed' => $results->count(),
            'by_type' => $results->countBy('investigation_type')->toArray(),
            'by_methodology' => $results->countBy('methodology')->filter()->toArray(),
            'total_recommendations' => $results->sum(fn ($inv) => count($inv->recommendations ?? [])),
        ], $results->map(fn (HsInvestigation $inv) => [
            'reference_number' => $inv->reference_number,
            'investigation_type' => $inv->investigation_type,
            'methodology' => $inv->methodology,
            'event_reference' => $inv->hsEvent?->reference_number,
            'event_category' => $inv->hsEvent?->event_category,
            'event_severity' => $inv->hsEvent?->severity,
            'site_name' => $inv->hsEvent?->site?->name,
            'lead_investigator' => $inv->leadInvestigator?->name,
            'started_at' => $inv->started_at?->toIso8601String(),
            'completed_at' => $inv->completed_at?->toIso8601String(),
            'approved_by' => $inv->approvedBy?->name,
            'immediate_causes_count' => count($inv->immediate_causes ?? []),
            'root_causes_count' => count($inv->root_causes ?? []),
            'recommendation_count' => count($inv->recommendations ?? []),
            'findings_summary' => $inv->findings_summary,
            'lessons_learned' => $inv->lessons_learned,
        ])->toArray());
    }

    /* ------------------------------------------------------------------ */
    /*  Corrective Action Traceability Report */
    /* ------------------------------------------------------------------ */

    public function correctiveActionTraceability(
        ?string $statusFilter = null,
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?User $viewer = null,
        ?int $siteId = null,
        ?HealthSafetyReadContext $context = null,
    ): array {
        return $this->withSourceRead($viewer, $context, fn (?User $viewer, ?HealthSafetyReadContext $context) => $this->correctiveActionTraceabilitySnapshot($statusFilter, $from, $to, $viewer, $siteId, $context));
    }

    private function correctiveActionTraceabilitySnapshot(
        ?string $statusFilter = null,
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?User $viewer = null,
        ?int $siteId = null,
        ?HealthSafetyReadContext $context = null,
    ): array {
        $summaryQuery = $this->correctiveActionQuery($viewer, $siteId, $context);
        $query = (clone $summaryQuery)->with([
            'hsEvent:id,reference_number,event_category,severity',
            'hsInvestigation' => fn (BelongsTo $investigation) => app(HsEventAccessService::class)->applyFirstAidChildSourceScope($investigation->getQuery(), 'hs_investigations.hs_event_id', $viewer, $context)->select(['id', 'hs_event_id', 'reference_number', 'investigation_type']),
            'assignedTo:id,name',
            'completedBy:id,name',
            'verifiedBy:id,name',
        ])->orderBy('due_date');

        if ($statusFilter) {
            $query->where('status', $statusFilter);
        }
        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<=', $to);
        }

        return $this->envelope(self::REPORT_CORRECTIVE_ACTION_TRACEABILITY, 'Corrective Action Traceability Report', $from, $to, [
            'total' => (clone $summaryQuery)->count(),
            'open' => (clone $summaryQuery)->open()->count(),
            'overdue' => (clone $summaryQuery)->overdue()->count(),
            'awaiting_verification' => (clone $summaryQuery)->awaitingVerification()->count(),
            'verified' => (clone $summaryQuery)
                ->where('status', HsCorrectiveAction::STATUS_VERIFIED)
                ->count(),
            'closed' => (clone $summaryQuery)
                ->where('status', HsCorrectiveAction::STATUS_CLOSED)
                ->count(),
            'effectiveness_rate' => $this->effectivenessRate($summaryQuery),
            'applied_filter' => $statusFilter,
        ], $this->protectSourceEagerLoads($query)->get()->map(fn (HsCorrectiveAction $a) => [
            'reference_number' => $a->reference_number,
            'title' => $a->title,
            'action_type' => $a->action_type,
            'priority' => $a->priority,
            'status' => $a->status,
            'event_reference' => $a->hsEvent?->reference_number,
            'event_category' => $a->hsEvent?->event_category,
            'investigation_reference' => (int) $a->hsInvestigation?->hs_event_id === (int) $a->hs_event_id ? $a->hsInvestigation?->reference_number : null,
            'assigned_to' => $a->assignedTo?->name,
            'due_date' => $a->due_date?->toDateString(),
            'is_overdue' => $a->isOverdue(),
            'completed_at' => $a->completed_at?->toIso8601String(),
            'completed_by' => $a->completedBy?->name,
            'verified_at' => $a->verified_at?->toIso8601String(),
            'verified_by' => $a->verifiedBy?->name,
            'effectiveness_confirmed' => $a->effectiveness_confirmed,
        ])->toArray());
    }

    /* ------------------------------------------------------------------ */
    /*  Risk Assessment Register */
    /* ------------------------------------------------------------------ */

    public function riskAssessmentRegister(?User $viewer = null, ?int $siteId = null, array $filters = [], ?HealthSafetyReadContext $context = null): array
    {
        return $this->withSourceRead($viewer, $context, fn (?User $viewer, ?HealthSafetyReadContext $context) => $this->riskAssessmentRegisterSnapshot($viewer, $siteId, $filters, $context));
    }

    private function riskAssessmentRegisterSnapshot(?User $viewer = null, ?int $siteId = null, array $filters = [], ?HealthSafetyReadContext $context = null): array
    {
        $query = HsRiskAssessment::query();
        if ($viewer) {
            if ($siteId !== null) {
                $this->assertRequestedSite($viewer, $siteId);
                $this->siteAccess->applyHsRiskAssessmentSiteScopeForSiteIds($query, [$siteId]);
            } else {
                $this->siteAccess->applyHsRiskAssessmentScope(
                    $query,
                    $viewer,
                    self::SITE_BYPASS_PERMISSIONS,
                );
            }
        } else {
            abort_if($siteId !== null, 403, UserSiteAccessService::DEFAULT_MESSAGE);
            $query->whereRaw('1 = 0');
        }

        if ($viewer) {
            // Site filters narrow the viewer's canonical report scope; they never replace it.
            $this->siteAccess->applyHsRiskAssessmentScope($query, $viewer, self::SITE_BYPASS_PERMISSIONS);
            if (! empty($filters['client_id'])) {
                $this->siteAccess->assertCanAccessClientId($viewer, (int) $filters['client_id'], self::SITE_BYPASS_PERMISSIONS);
                $query->where('assessable_type', Client::class)->where('assessable_id', $filters['client_id']);
            }
            if (! empty($filters['hs_event_id'])) {
                $event = app(HsEventAccessService::class)->applyFirstAidSourceScope(HsEvent::query()->whereKey($filters['hs_event_id']), $viewer, $context)->firstOrFail();
                $this->siteAccess->assertCanAccessHsEvent($viewer, $event, self::SITE_BYPASS_PERMISSIONS);
                $query->where('hs_event_id', $event->id);
            }
        }
        match ($filters['tab'] ?? 'active') {
            'active' => $query->active(), 'drafts' => $query->where('status', HsRiskAssessment::STATUS_DRAFT),
            'due' => $query->dueForReview(), 'high' => $query->highOrExtreme(),
            'closed' => $query->whereIn('status', [HsRiskAssessment::STATUS_SUPERSEDED, HsRiskAssessment::STATUS_ARCHIVED]),
            default => null,
        };
        foreach (['status', 'risk_level'] as $facet) {
            if (! empty($filters[$facet])) {
                $query->where($facet, $filters[$facet]);
            }
        }
        if (($filters['due_for_review'] ?? null) === 'true') {
            $query->dueForReview();
        }
        if (isset($filters['risk_acceptable']) && $filters['risk_acceptable'] !== '') {
            $query->where('risk_acceptable', (string) $filters['risk_acceptable'] === '1');
        }
        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(fn ($search) => $search->where('title', 'like', $term)->orWhere('reference_number', 'like', $term)->orWhere('risk_description', 'like', $term));
        }

        app(HsEventAccessService::class)->applyFirstAidRiskSourceScope($query, $viewer, $context);

        $assessments = $query
            ->with(['assessedBy:id,name', 'approvedBy:id,name'])
            ->tap(fn (Builder $parents) => $this->protectSourceEagerLoads($parents))
            ->orderByDesc('risk_score')
            ->get();

        return $this->envelope(self::REPORT_RISK_ASSESSMENT_REGISTER, 'H&S Risk Assessment Register', null, null, [
            'total' => $assessments->count(),
            'total_active' => $assessments->where('status', HsRiskAssessment::STATUS_ACTIVE)->count(),
            'under_review' => $assessments->where('status', HsRiskAssessment::STATUS_UNDER_REVIEW)->count(),
            'extreme' => $assessments->where('risk_level', 'extreme')->count(),
            'high' => $assessments->where('risk_level', 'high')->count(),
            'medium' => $assessments->where('risk_level', 'medium')->count(),
            'low' => $assessments->where('risk_level', 'low')->count(),
            'due_for_review' => $assessments->filter(fn ($a) => $a->isDueForReview())->count(),
            'unacceptable' => $assessments->filter(fn ($assessment) => $assessment->risk_acceptable === false)->count(),
            'acceptability_unknown' => $assessments->filter(fn ($assessment) => $assessment->risk_acceptable === null)->count(),
        ], $assessments->map(fn (HsRiskAssessment $ra) => [
            'reference_number' => $ra->reference_number,
            'title' => $ra->title,
            'status' => $ra->status,
            'assessable_type' => $ra->assessable_type ? class_basename($ra->assessable_type) : null,
            'likelihood' => $ra->likelihood,
            'consequence' => $ra->consequence,
            'risk_score' => $ra->risk_score,
            'risk_level' => $ra->risk_level,
            'residual_risk_score' => $ra->residual_risk_score,
            'residual_risk_level' => $ra->residual_risk_level,
            'risk_acceptable' => $ra->risk_acceptable,
            'assessed_by' => $ra->assessedBy?->name,
            'approved_by' => $ra->approvedBy?->name,
            'review_due_at' => $ra->review_due_at?->toDateString(),
            'is_due_for_review' => $ra->isDueForReview(),
        ])->toArray());
    }

    /* ------------------------------------------------------------------ */
    /*  Internal helpers */
    /* ------------------------------------------------------------------ */

    /**
     * Consistent report envelope for all exports.
     */
    private function envelope(string $reportType, string $title, ?Carbon $from, ?Carbon $to, array $summary, array $items): array
    {
        return [
            'report_type' => $reportType,
            'title' => $title,
            'generated_at' => now()->toIso8601String(),
            'period' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
            'summary' => $summary,
            'item_count' => count($items),
            'items' => $items,
        ];
    }

    private function effectivenessRate(Builder $actions): ?int
    {
        $verified = (clone $actions)->where('status', HsCorrectiveAction::STATUS_VERIFIED);
        $total = (clone $verified)->count();
        if ($total === 0) {
            return null;
        }
        $effective = (clone $verified)
            ->where('effectiveness_confirmed', true)->count();

        return (int) round(($effective / $total) * 100);
    }

    private function eventQuery(?User $viewer, ?int $siteId, ?HealthSafetyReadContext $context = null): Builder
    {
        $query = HsEvent::query();
        if (! $viewer) {
            abort_if($siteId !== null, 403, UserSiteAccessService::DEFAULT_MESSAGE);

            return $query->whereRaw('1 = 0');
        }

        $this->siteAccess->applyHsEventScope($query, $viewer, self::SITE_BYPASS_PERMISSIONS);
        if ($siteId !== null) {
            $this->assertRequestedSite($viewer, $siteId);
            $query->where('site_id', $siteId);
        }

        return $this->protectMaterialisation(app(HsEventAccessService::class)->applyFirstAidSourceScope($query, $viewer, $context));
    }

    private function investigationQuery(?User $viewer, ?int $siteId, ?HealthSafetyReadContext $context = null): Builder
    {
        $query = HsInvestigation::query();
        $children = (clone $query)->withoutEagerLoads()->select('hs_investigations.hs_event_id');
        $candidates = HsEvent::query()->whereIn('hs_events.id', $children);

        return $this->protectMaterialisation($query->whereHas(
            'hsEvent',
            fn (Builder $event): Builder => $this->scopeEventBuilder($event, $viewer, $siteId, $candidates, $context),
        ));
    }

    private function correctiveActionQuery(?User $viewer, ?int $siteId, ?HealthSafetyReadContext $context = null): Builder
    {
        $query = HsCorrectiveAction::query();
        $children = (clone $query)->withoutEagerLoads()->select('hs_corrective_actions.hs_event_id');
        $candidates = HsEvent::query()->whereIn('hs_events.id', $children);

        return $this->protectMaterialisation($query->whereHas(
            'hsEvent',
            fn (Builder $event): Builder => $this->scopeEventBuilder($event, $viewer, $siteId, $candidates, $context),
        ));
    }

    private function scopeEventBuilder(Builder $query, ?User $viewer, ?int $siteId, Builder $sourceCandidates, ?HealthSafetyReadContext $context = null): Builder
    {
        if (! $viewer) {
            abort_if($siteId !== null, 403, UserSiteAccessService::DEFAULT_MESSAGE);

            return $query->whereRaw('1 = 0');
        }

        $this->siteAccess->applyHsEventScope($query, $viewer, self::SITE_BYPASS_PERMISSIONS);
        $this->siteAccess->applyHsEventScope($sourceCandidates, $viewer, self::SITE_BYPASS_PERMISSIONS);
        if ($siteId !== null) {
            $this->assertRequestedSite($viewer, $siteId);
            $query->where($query->qualifyColumn('site_id'), $siteId);
            $sourceCandidates->where($sourceCandidates->qualifyColumn('site_id'), $siteId);
        }

        return $this->protectMaterialisation(app(HsEventAccessService::class)->applyFirstAidSourceScope($query, $viewer, $context, $sourceCandidates));
    }

    private function assertRequestedSite(User $viewer, int $siteId): void
    {
        $this->siteAccess->assertCanAccessSiteId(
            $viewer,
            $siteId,
            self::SITE_BYPASS_PERMISSIONS,
        );
    }

    /** All source decisions and their final SQL share the caller's original prepared evidence. */
    private ?HealthSafetyReadMaterialisationProof $materialisationProof = null;

    private function protectMaterialisation(Builder $query): Builder
    {
        return $this->materialisationProof?->protect($query) ?? $query;
    }
    private function withSourceRead(?User $viewer, ?HealthSafetyReadContext $context, callable $consumer): mixed
    {
        $project = static fn (mixed $result): mixed => is_array($result) && ! array_is_list($result)
            ? [...$result, 'source_scope' => 'readable_subset', 'source_completeness' => 'unknown'] : $result;
        if ($viewer === null) {
            abort_unless($context === null, 403);

            return DB::transaction(fn () => $project($consumer(null, null)));
        }
        $permissions = array_values(array_unique([...FirstAidAccessService::PERMISSIONS,
            ...RestraintAccessService::PERMISSIONS, ...InjuryAccessService::PERMISSIONS]));
        $consume = function (HealthSafetyReadContext $active) use ($viewer, $permissions, $consumer, $project): mixed {
            $active->assertViewer($viewer);

            return $active->consumeSourceViewer($permissions,
                function (User $current) use ($active, $project, $consumer): mixed {
                    return HealthSafetyReadMaterialisationProof::within($active, function (HealthSafetyReadMaterialisationProof $proof) use ($current, $active, $project, $consumer): mixed {
                        $previous = $this->materialisationProof;
                        $this->materialisationProof = $proof;
                        try { return $project($consumer($current, $active)); }
                        finally { $this->materialisationProof = $previous; }
                    });
                });
        };

        return $context !== null ? $consume($context) : HealthSafetyReadContext::within($viewer, $permissions, $consume);
    }

    /** Eager names and event bodies retain the original admitted report query through their own SELECT. */
    private function protectSourceEagerLoads(Builder $parents): Builder
    {
        $eagerLoads = $parents->getEagerLoads();
        $columns = [
            'hsEvent' => 'hs_event_id', 'hsInvestigation' => 'hs_investigation_id', 'client' => 'client_id', 'staff' => 'staff_id',
            'site' => 'site_id', 'owner' => 'owner_user_id', 'assignedTo' => 'assigned_to_user_id',
            'completedBy' => 'completed_by_user_id', 'verifiedBy' => 'verified_by_user_id',
            'leadInvestigator' => 'lead_investigator_id', 'assessedBy' => 'assessed_by_user_id',
            'approvedBy' => $parents->getModel() instanceof HsInvestigation ? 'approved_by_id' : 'approved_by_user_id',
        ];
        foreach ($columns as $name => $column) {
            if (! isset($eagerLoads[$name])) {
                continue;
            }
            $original = $eagerLoads[$name];
            $proof = (clone $parents)->setEagerLoads([])->reorder()->limit(null)->offset(0)
                ->select($parents->getModel()->qualifyColumn($column));
            $parents->with([$name => function ($relation) use ($original, $proof): void {
                $original($relation);
                $relation->whereIn($relation->getRelated()->qualifyColumn('id'), clone $proof);
                $this->reconsumeOriginalSource($relation->getQuery(), $proof);
            }]);
        }
        if (isset($eagerLoads['hsEvent.site'])) {
            $original = $eagerLoads['hsEvent.site'];
            $proof = (clone $parents)->setEagerLoads([])->reorder()->limit(null)->offset(0)
                ->select($parents->getModel()->qualifyColumn('hs_event_id'));
            $sites = HsEvent::query()->whereIn('hs_events.id', $proof)->select('hs_events.site_id');
            $parents->with(['hsEvent.site' => function ($relation) use ($original, $sites, $proof): void {
                $original($relation);
                $relation->whereIn('sites.id', clone $sites);
                $this->reconsumeOriginalSource($relation->getQuery(), $proof);
            }]);
        }

        return $this->protectMaterialisation($parents);
    }

    /** whereIn compiles subqueries immediately; reconsume the SAME original guard at final body compilation. */
    private function reconsumeOriginalSource(Builder $destination, Builder $originalSource): Builder
    {
        return $this->protectMaterialisation($destination->whereRaw(new class(clone $originalSource) extends \Illuminate\Database\Query\Expression {
            public function __construct(private readonly Builder $originalSource)
            {
                parent::__construct('1 = 1');
            }

            public function getValue(\Illuminate\Database\Grammar $grammar)
            {
                // SQL compilation only: no new authority, source discovery or replacement baseline.
                (clone $this->originalSource)->toSql();

                return parent::getValue($grammar);
            }
        }));
    }
}
