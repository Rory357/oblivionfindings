<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Controller;
use App\Models\ClientIncident;
use App\Models\ClientIncidentAttachment;
use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoom\EvidenceItem;
use App\Models\ControlRoomAlert;
use App\Models\EmergencyDrill;
use App\Models\FleetIncident;
use App\Models\FleetWorkOrder;
use App\Models\HsClosureException;
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
use App\Services\ControlRoom\ControlRoomAlertAccessService;
use App\Services\HealthSafety\EmergencyDrillAccessService;
use App\Services\HealthSafety\FirstAidAccessService;
use App\Services\HealthSafety\FirstAidSourceProjectionService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\HsEventAccessService;
use App\Services\HealthSafety\HsEventClosureService;
use App\Services\HealthSafety\HsEventService;
use App\Services\HealthSafety\NotifiableEventClassifier;
use App\Services\HealthSafety\RestraintAccessService;
use App\Services\HealthSafety\SafeWorkProcedureAccessService;
use App\Services\Incidents\IncidentJourneyPresenter;
use App\Services\Incidents\IncidentJourneyService;
use App\Services\Tasks\TaskAggregator;
use App\Services\UserSiteAccessService;
use App\Support\HealthSafety\EmergencyDrillReturn;
use App\Support\HealthSafety\HsCorrectiveActionPresenter;
use App\Support\Incidents\IncidentOccurrencePeriod;
use App\Support\Incidents\LinkedOperationalEvidencePresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Support\HealthSafety\QueryPaging;

class HsEventController extends Controller
{
    use ServesPrivateAttachments;

    public function __construct(
        private readonly HsEventService $events,
        private readonly HsEventClosureService $closures,
        private readonly UserSiteAccessService $siteAccess,
        private readonly HsCorrectiveActionPresenter $correctiveActionPresenter,
        private readonly LinkedOperationalEvidencePresenter $linkedEvidence,
        private readonly IncidentJourneyService $journeys,
        private readonly IncidentJourneyPresenter $journeyPresenter,
        private readonly NotifiableEventClassifier $notifiableEvents,
    ) {}

    /**
     * H&S Events register â€” the governance convergence view.
     *
     * Hero counts, tab counts, standardised rows (source + governance flags) and,
     * on ?event=, the detail payload for the over-the-list modal.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('health-safety/events/index', $this->registerRead($request,
            fn (HealthSafetyReadContext $context): array => $this->indexWithinSourceAuthority($request, $context)));
    }

    private function indexWithinSourceAuthority(Request $request, HealthSafetyReadContext $context): array
    {
        $this->validateRegisterPeriod($request);
        $request->validate([
            'action_context' => ['nullable', 'array:q,priority,unassigned,site_id,from,to,tab'],
            'action_context.q' => ['nullable', 'string', 'max:2000'],
            'action_context.priority' => ['nullable', 'string', 'max:60'],
            'action_context.unassigned' => ['nullable', 'boolean'],
            'action_context.site_id' => ['nullable', 'integer'],
            'action_context.tab' => ['nullable', 'string', 'max:60'],
            'action_context.from' => ['nullable', 'date_format:Y-m-d'],
            'action_context.to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('action_context.from') ? ['after_or_equal:action_context.from'] : [])],
        ]);
        $tab = (string) $request->input('tab', 'all');

        // â”€â”€ List query: scope (site + period) + tab + refinements â”€â”€
        $query = HsEvent::query()
            ->with([
                'site:id,name',
                'client:id,first_name,last_name',
                'staff:id,name',
                'owner:id,name',
                'acceptedBy:id,name',
            ])
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

        $this->applyTab($query, $tab);
        $this->applyEventRefinements($query, $request, $context);
        $this->applyScope($query, $request, $context);

        $paginator = $query->paginate(25)->withQueryString();

        // Batch-resolve the two source types whose jump URL needs a record lookup
        // (exposure â†’ substance, inspection â†’ site) so the per-row resolveSource()
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

        $events = $paginator->through(function (HsEvent $e) use ($request, $exposureMap, $inspectionMap) {
            $source = $this->resolveSource($e->source_type, $e->source_id, $request->user(), $exposureMap, $inspectionMap, $e);

            return [
                'id' => $e->id,
                'reference_number' => $e->reference_number,
                'event_category' => $e->event_category,
                'severity' => $e->severity,
                'status' => $e->status,
                'occurred_at' => $e->occurred_at?->toIso8601String(),
                'reported_at' => $e->reported_at?->toIso8601String(),
                'site_name' => $e->site?->name,
                'client_name' => $e->client && (! app(FirstAidSourceProjectionService::class)->isFirstAidSource($e->source_type) || $source !== null) ? trim($e->client->first_name.' '.$e->client->last_name) : null,
                'staff_name' => $e->staff?->name,
                'worksafe_notifiable' => $e->worksafe_notifiable,
                'worksafe_status' => $e->worksafe_status,
                'worksafe_decision_signed' => $e->hasSignedWorksafeDecision(),
                'handover' => [
                    'status' => $e->handover_status,
                    'owner' => $e->owner ? ['id' => $e->owner->id, 'name' => $e->owner->name] : null,
                    'accepted_by' => $e->acceptedBy ? ['id' => $e->acceptedBy->id, 'name' => $e->acceptedBy->name] : null,
                    'accepted_at' => $e->accepted_at?->toIso8601String(),
                ],
                'investigation_required' => (bool) $e->investigation_required,
                'source' => $source,
                'flags' => [
                    'investigation_overdue' => $e->overdue_investigations_count > 0,
                    'awaiting_verification' => (int) $e->awaiting_verification_count,
                    'worksafe_pending' => $e->worksafe_notifiable && $e->worksafe_status === HsEvent::WORKSAFE_PENDING,
                    'unwired' => $e->source_id === null,
                ],
                'has_investigation' => $e->investigations_count > 0,
                'has_open_actions' => $e->open_actions_count > 0,
            ];
        });

        // Counts use the same active refinements as their linked worklists.
        $statusCounts = $this->scopedBase($request, $context)
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
            'handover' => (int) $this->scopedBase($request, $context)
                ->where('handover_status', HsEvent::HANDOVER_AWAITING_ACCEPTANCE)
                ->count(),
            'worksafe' => (int) $this->scopedBase($request, $context)->where('worksafe_notifiable', true)->count(),
        ];
        foreach (['investigation_due', 'awaiting_verification', 'worksafe_pending'] as $attentionTab) {
            $attentionQuery = $this->scopedBase($request, $context);
            $this->applyTab($attentionQuery, $attentionTab);
            $tabCounts[$attentionTab] = $attentionQuery->count();
        }

        $hero = [
            'live' => [
                'open' => $count(HsEvent::STATUS_OPEN),
                'investigating' => $count(HsEvent::STATUS_INVESTIGATING),
                'corrective_action' => $count(HsEvent::STATUS_CORRECTIVE_ACTION),
                'monitoring' => $count(HsEvent::STATUS_MONITORING),
            ],
            'attention' => [
                'investigation_due' => $tabCounts['investigation_due'],
                'awaiting_verification' => $tabCounts['awaiting_verification'],
                'worksafe_due' => $tabCounts['worksafe_pending'],
                'handover_due' => $tabCounts['handover'],
                'closed_period' => $count(HsEvent::STATUS_CLOSED),
            ],
        ];

        // â”€â”€ Detail-over-list (?event=) â”€â”€
        $detail = null;
        if ($request->filled('event')) {
            $target = $this->resolveAccessibleEvent($request, $request->integer('event'), $context);
            $detailQuery = HsEvent::query()->whereKey($target->id);
            $this->applyScope($detailQuery, $request, $context);
            $target = $context->query($detailQuery)->first();
            $detail = $target ? $context->consumeViewer($this->detailReadPermissionKeys(),
                fn (User $current): array => $this->buildEventDetail($target, $current, $context)) : null;
        }

        $siteIds = $this->scopedBase($request, $context)->whereNotNull('site_id')->distinct()->pluck('site_id');

        return [
            'events' => $events->toArray(),
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
                'action_context' => $request->input('action_context'),
            ],
            'actionReturnUrl' => $request->filled('action_context')
                ? '/health-safety/corrective-actions?'.http_build_query($request->input('action_context'), '', '&', PHP_QUERY_RFC3986)
                : null,
            'sites' => Site::whereIn('id', $siteIds)->orderBy('name')->get(['id', 'name'])->toArray(),
            'detail' => $detail,
            'can' => ['manage' => (bool) ($request->user()?->canDo('hazards.manage') ?? false)],
        ];
    }

    /** Canonical Site authority and the selected New Zealand occurrence period. */
    private function applyScope(Builder $query, Request $request, ?HealthSafetyReadContext $context = null): void
    {
        if ($context !== null) {
            $context->applyEventSiteScope($query, $this->hsEventBypassPermissions());
        } else {
            $this->siteAccess->applyHsEventScope(
                $query,
                $request->user(),
                $this->hsEventBypassPermissions(),
            );
        }
        if ($request->filled('site_id')) {
            if ($context !== null) {
                $context->consumeSourceViewer(FirstAidAccessService::PERMISSIONS, function ($current, $reads) use ($request): void {
                    $sites = $this->siteAccess->accessibleSiteIds($current, $this->hsEventBypassPermissions(), $reads);
                    abort_unless(in_array((int) $request->input('site_id'), $sites, true), 403);
                });
            } else {
                $this->siteAccess->assertCanAccessSiteId(
                    $request->user(),
                    (int) $request->input('site_id'),
                    $this->hsEventBypassPermissions(),
                );
            }
            $query->where('site_id', (int) $request->input('site_id'));
        }
        IncidentOccurrencePeriod::apply($query, $request->input('from'), $request->input('to'));
        // Candidate discovery sees the selected list filters before private proof.
        app(HsEventAccessService::class)->applyFirstAidSourceScope($query, $request->user(), $context);
    }

    /** A fresh, scope-applied base query for the hero/tab aggregates. */
    private function scopedBase(Request $request, ?HealthSafetyReadContext $context = null): Builder
    {
        $query = HsEvent::query();
        $this->applyEventRefinements($query, $request, $context);
        $this->applyScope($query, $request, $context);

        return $query;
    }

    /** Tab â†’ governance-status filter (category stays a filter, never a tab). */
    private function applyTab(Builder $query, string $tab): void
    {
        match ($tab) {
            'open' => $query->where('status', HsEvent::STATUS_OPEN),
            'investigating' => $query->where('status', HsEvent::STATUS_INVESTIGATING),
            'corrective_actions' => $query->where('status', HsEvent::STATUS_CORRECTIVE_ACTION),
            'monitoring' => $query->where('status', HsEvent::STATUS_MONITORING),
            'closed' => $query->where('status', HsEvent::STATUS_CLOSED),
            'handover' => $query->where('handover_status', HsEvent::HANDOVER_AWAITING_ACCEPTANCE),
            'worksafe' => $query->where('worksafe_notifiable', true),
            'investigation_due' => $query->where('investigation_required', true)
                ->where('status', '!=', HsEvent::STATUS_CLOSED)
                ->whereDoesntHave('investigations', fn (Builder $q) => $q->where('status', HsInvestigation::STATUS_COMPLETED)),
            'awaiting_verification' => $query->whereHas('correctiveActions', fn (Builder $q) => $q->where('status', HsCorrectiveAction::STATUS_COMPLETED)),
            'worksafe_pending' => $query->where('worksafe_notifiable', true)->where('worksafe_status', HsEvent::WORKSAFE_PENDING),
            default => $query, // 'all'
        };
    }

    private function validateRegisterPeriod(Request $request): void
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
        ]);
    }

    private function applyEventRefinements(Builder $query, Request $request, ?HealthSafetyReadContext $context = null): void
    {
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
            $query->where('reference_number', 'like', '%'.trim((string) $request->input('q')).'%');
        }
        if ($request->filled('action_context')) {
            // An action due date is not the parent review's occurrence date.
            $actionRequest = Request::create('/', 'GET', $request->input('action_context'));
            $actionRequest->setUserResolver(fn () => $request->user());
            $parentCandidates = (clone $query)->setEagerLoads([])->reorder()->when(true, QueryPaging::clear(...))->select('hs_events.id');
            $actionCandidates = HsCorrectiveAction::query()->whereIn('hs_event_id', $parentCandidates);
            $this->applyActionRefinements($actionCandidates, $actionRequest);
            $query->whereHas('correctiveActions', function (Builder $actions) use ($actionRequest, $actionCandidates, $context): void {
                $this->applyActionRefinements($actions, $actionRequest);
                $this->applyActionScope($actions, $actionRequest, $actionCandidates, $context);
            });
        }
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
     * H&S Event detail â€” thin deep-link / share fallback. Renders the same
     * governance modal (HsEventDialog) on a thin shell as the over-the-list
     * modal opened from the register.
     */
    public function show(Request $request, int $hsEvent): Response
    {
        return DB::transaction(fn () => $this->showWithinSourceAuthority($request, $hsEvent));
    }

    private function showWithinSourceAuthority(Request $request, int $hsEvent): Response
    {
        foreach ([TaskAggregator::NAVIGATION_REQUEST_ATTRIBUTE, TaskAggregator::NAVIGATION_USER_ATTRIBUTE, TaskAggregator::NAVIGATION_EVENT_ATTRIBUTE] as $attribute) {
            $request->attributes->remove($attribute);
        }
        $projection = HealthSafetyReadContext::within($request->user(), $this->detailReadPermissionKeys(), function (HealthSafetyReadContext $context) use ($request, $hsEvent): array {
            $event = $this->resolveAccessibleEvent($request, $hsEvent, $context);
            $detail = $context->consumeViewer($this->detailReadPermissionKeys(),
                fn (User $current): array => $this->buildEventDetail($event, $current, $context));
            $navigation = app(TaskAggregator::class)->navigationBadgeInContext($request->user(), $context);

            return ['detail' => $detail, 'navigation' => $navigation, 'event_id' => (int) $event->id, 'user_id' => (int) $request->user()->id];
        });
        // Only detached projections are published, after successful callback materialization.
        $request->attributes->set(TaskAggregator::NAVIGATION_REQUEST_ATTRIBUTE, $projection['navigation']);
        $request->attributes->set(TaskAggregator::NAVIGATION_USER_ATTRIBUTE, $projection['user_id']);
        $request->attributes->set(TaskAggregator::NAVIGATION_EVENT_ATTRIBUTE, $projection['event_id']);

        return Inertia::render('health-safety/events/show', ['detail' => $projection['detail']]);
    }

    /** Close through the one canonical H&S readiness/exception aggregate. */
    public function close(Request $request, int $hsEvent)
    {
        return DB::transaction(fn () => $this->onAccessibleEvent($request, $hsEvent, fn (HsEvent $event) => $this->closeWithinSourceAuthority($request, $event)));
    }

    private function closeWithinSourceAuthority(Request $request, HsEvent $hsEvent)
    {

        $data = $request->validate([
            'closure_summary' => ['required', 'string', 'max:2000'],
            'exception_id' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $this->closures->closeEvent(
                $hsEvent,
                $data['closure_summary'],
                $request->user(),
                isset($data['exception_id']) ? (int) $data['exception_id'] : null,
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Event closed.');
    }

    public function requestClosureException(Request $request, int $hsEvent)
    {
        return DB::transaction(fn () => $this->onAccessibleEvent($request, $hsEvent, fn (HsEvent $event) => $this->requestClosureExceptionWithinSourceAuthority($request, $event)));
    }

    private function requestClosureExceptionWithinSourceAuthority(Request $request, HsEvent $hsEvent)
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'in:'.implode(',', array_keys(HsClosureException::CATEGORY_SCOPES))],
            'reason' => ['required', 'string', 'min:20', 'max:2000'],
            'evidence_reference' => ['required', 'string', 'min:5', 'max:500'],
            'scope' => ['required', 'array', 'min:1', 'max:1'],
            'scope.*' => ['required', 'string', 'in:hs_acceptance,hs_investigation,recommendation_dispositions,corrective_actions'],
            'expires_at' => ['required', 'date', 'after:now'],
            'review_at' => ['required', 'date', 'after:now', 'before_or_equal:expires_at'],
        ]);

        try {
            $this->closures->requestException($hsEvent, $request->user(), $data);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Closure exception sent for independent decision.');
    }

    public function decideClosureException(Request $request, int $hsEvent, int $exception)
    {
        return DB::transaction(fn () => $this->onAccessibleEvent($request, $hsEvent, fn (HsEvent $event) => $this->decideClosureExceptionWithinSourceAuthority($request, $event, $exception)));
    }

    private function decideClosureExceptionWithinSourceAuthority(Request $request, HsEvent $hsEvent, int $exception)
    {
        $exception = $hsEvent->closureExceptions()->findOrFail($exception);
        $data = $request->validate([
            'decision' => ['required', 'string', 'in:approved,rejected'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        try {
            $this->closures->decideException(
                $hsEvent,
                $exception,
                $request->user(),
                $data['decision'],
                $data['reason'],
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Closure exception decision recorded.');
    }

    public function revokeClosureException(Request $request, int $hsEvent, int $exception)
    {
        return DB::transaction(fn () => $this->onAccessibleEvent($request, $hsEvent, fn (HsEvent $event) => $this->revokeClosureExceptionWithinSourceAuthority($request, $event, $exception)));
    }

    private function revokeClosureExceptionWithinSourceAuthority(Request $request, HsEvent $hsEvent, int $exception)
    {
        $exception = $hsEvent->closureExceptions()->findOrFail($exception);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        try {
            $this->closures->revokeException($hsEvent, $exception, $request->user(), $data['reason']);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Closure exception revoked.');
    }

    /**
     * Accept a submitted incident handover while leaving H&S governance status unchanged.
     */
    public function acceptHandover(Request $request, int $hsEvent)
    {
        return DB::transaction(fn () => $this->onAccessibleEvent($request, $hsEvent, fn (HsEvent $event) => $this->acceptHandoverWithinSourceAuthority($request, $event)));
    }

    private function acceptHandoverWithinSourceAuthority(Request $request, HsEvent $hsEvent)
    {
        $data = $request->validate([
            'owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'acceptance_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $ownerId = (int) ($data['owner_user_id'] ?? $request->user()->id);
        $owner = $this->handoverOwnerQuery($hsEvent, $request->user())
            ->whereKey($ownerId)
            ->first();

        if (! $owner || ! $this->canOwnEvent($owner, $hsEvent, $request->user())) {
            throw ValidationException::withMessages([
                'owner_user_id' => empty($data['owner_user_id'])
                    ? 'Choose an approved H&S owner for this site before accepting.'
                    : 'Choose an approved H&S owner available for this site.',
            ]);
        }

        try {
            $this->events->acceptHandover(
                $hsEvent,
                $request->user(),
                $owner,
                $data['acceptance_notes'] ?? null,
            );
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['handover' => $e->getMessage()]);
        }

        return back()->with('success', 'H&S handover accepted.');
    }

    /**
     * Record the WorkSafe NZ notification for a notifiable event (E-Gap 2):
     * pending â†’ notified, persisting date/method/reference + site preservation.
     */
    public function worksafeNotify(Request $request, int $hsEvent)
    {
        return DB::transaction(fn () => $this->onAccessibleEvent($request, $hsEvent, fn (HsEvent $event) => $this->worksafeNotifyWithinSourceAuthority($request, $event)));
    }

    private function worksafeNotifyWithinSourceAuthority(Request $request, HsEvent $hsEvent)
    {

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
                $request->user(),
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'WorkSafe notification recorded.');
    }

    /**
     * Record WorkSafe's acknowledgement of the notification (notified â†’ acknowledged).
     */
    public function worksafeAcknowledge(Request $request, int $hsEvent)
    {
        return DB::transaction(fn () => $this->onAccessibleEvent($request, $hsEvent, fn (HsEvent $event) => $this->worksafeAcknowledgeWithinSourceAuthority($request, $event)));
    }

    private function worksafeAcknowledgeWithinSourceAuthority(Request $request, HsEvent $hsEvent)
    {

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

    public function worksafeSitePreservation(Request $request, int $hsEvent)
    {
        return DB::transaction(fn () => $this->onAccessibleEvent($request, $hsEvent, fn (HsEvent $event) => $this->worksafeSitePreservationWithinSourceAuthority($request, $event)));
    }

    private function worksafeSitePreservationWithinSourceAuthority(Request $request, HsEvent $hsEvent)
    {
        $data = $request->validate([
            'required' => ['required', 'boolean'],
            'evidence_reference' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        try {
            $this->events->recordSitePreservationDecision(
                $hsEvent,
                $request->boolean('required'),
                $data['evidence_reference'],
                $request->user(),
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Site-preservation decision recorded.');
    }

    public function worksafeSitePreservationRelease(Request $request, int $hsEvent)
    {
        return DB::transaction(fn () => $this->onAccessibleEvent($request, $hsEvent, fn (HsEvent $event) => $this->worksafeSitePreservationReleaseWithinSourceAuthority($request, $event)));
    }

    private function worksafeSitePreservationReleaseWithinSourceAuthority(Request $request, HsEvent $hsEvent)
    {
        $data = $request->validate([
            'released_at' => ['required', 'date'],
            'evidence_reference' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        try {
            $this->events->releaseSitePreservation(
                $hsEvent,
                $data['released_at'],
                $data['evidence_reference'],
                $request->user(),
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Site-preservation release recorded.');
    }

    /**
     * Two-way convergence (E-Gap 4): resolve a polymorphic source to a label + a
     * jump back to the originating record. Categories with no source (the orphan
     * categories) return null. Modules without a per-record route resolve to a
     * label only (no jump) rather than a broken link.
     */
    /**
     * Resolve a polymorphic source to a label + jump URL. The two source types
     * whose URL needs a record lookup (exposure â†’ its substance, inspection â†’ its
     * site) accept an optional pre-loaded idâ†’value map so the list can batch-resolve
     * them in one query instead of one-per-row (the detail path passes none and
     * looks the single record up directly).
     */
    private function resolveSource(?string $sourceType, ?int $sourceId, User $viewer, ?Collection $exposureMap = null, ?Collection $inspectionMap = null, ?HsEvent $sourceEvent = null, ?HealthSafetyReadContext $context = null): ?array
    {
        if (! $sourceType || ! $sourceId) {
            return null;
        }

        $firstAid = app(FirstAidSourceProjectionService::class);
        if ($firstAid->isFirstAidSource($sourceType)) {
            return $sourceEvent ? $firstAid->sourceForReview($sourceEvent, $viewer, $context) : null;
        }

        $basename = class_basename($sourceType);
        if (in_array($sourceType, [WorkplaceInjury::class, (new WorkplaceInjury)->getMorphClass()], true)) {
            $record = app(\App\Services\HealthSafety\InjurySourceProjectionService::class)->forInjury($sourceId, $viewer, $context);
            if (! $record || ! $sourceEvent || $sourceEvent->event_category !== HsEvent::CATEGORY_INJURY
                || (int) $sourceEvent->staff_id !== $record['worker']['id']
                || (int) $sourceEvent->site_id !== $record['site']['id']
                || $sourceEvent->client_id !== null || $sourceEvent->asset_id !== null) {
                return null;
            }
            return ['type' => 'WorkplaceInjury', 'id' => $record['id'], 'label' => $record['reference'],
                'url' => \App\Support\HealthSafety\InjuryReturn::record($record['id'], request()->getRequestUri(), 'connections'),
                'unwired' => false];
        }
        if ($basename === 'WorkplaceInjury') {
            // A matching basename is not the canonical source class or morph.
            return null;
        }
        if ($basename === 'EmergencyDrill') {
            if (! in_array($sourceType, [EmergencyDrill::class, (new EmergencyDrill)->getMorphClass()], true)) {
                return null;
            }
            try {
                $record = app(EmergencyDrillAccessService::class)->readableQuery($viewer)->find($sourceId);
                if (! $record) {
                    return null;
                }
                $back = EmergencyDrillReturn::sanitize(request()->query('return_to'));
                $href = $back && parse_url($back, PHP_URL_PATH) === EmergencyDrillReturn::REGISTER.'/'.$record->id
                    ? $back : EmergencyDrillReturn::href($record->id, EmergencyDrillReturn::sanitize(request()->getRequestUri()), 'connections');

                return ['type' => 'EmergencyDrill', 'id' => $record->id, 'label' => 'Emergency drill #'.$record->id, 'url' => $href, 'unwired' => false];
            } catch (HttpException $error) {
                if (! in_array($error->getStatusCode(), [403, 404], true)) {
                    throw $error;
                }

                return null;
            }
        }

        if ($basename === 'ClientIncident') {
            if ($context === null || $sourceEvent === null) { return null; }
            $context->assertViewer($viewer);
            $incident = $context->consumeSourceViewer(\App\Services\Incidents\IncidentHsEventSourceReadService::PERMISSIONS,
                fn (User $current, \App\Services\CurrentAuthorizationReads $reads) => $context->incidentQueryForEvent($sourceEvent)->first(['id']));
            if ($incident === null || (int) $incident->id !== $sourceId) { return null; }
        }
        if ($basename === 'ControlRoomAlert') {
            $alert = ControlRoomAlert::query()->find($sourceId);
            if (! $alert || ! app(ControlRoomAlertAccessService::class)->canView($alert, $viewer)) {
                return null;
            }
        }

        [$label, $url] = match ($basename) {
            'ClientIncident' => ['Incident', "/incidents/{$sourceId}"],
            'SafeguardingConcern' => ['Safeguarding concern', "/safeguarding/{$sourceId}"],
            'FleetIncident' => ['Fleet incident', "/fleet-assets/incidents?incident={$sourceId}"],
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

    private function detailReadPermissionKeys(): array
    {
        return [...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS,
            ...\App\Services\HealthSafety\InjuryAccessService::PERMISSIONS,
            ...SafeWorkProcedureAccessService::PERMISSIONS, ...EmergencyDrillAccessService::PERMISSIONS,
            'healthSafety.events.close', 'healthSafety.closureExceptions.request', 'healthSafety.closureExceptions.approve'];
    }

    /**
     * The full governance detail payload â€” the contract behind the HsEventDialog
     * (mirrored by `EventDetail` in event-detail-dialog.tsx). Shared by index()
     * (over-the-list modal on ?event=) and show() (deep-link shell).
     */
    private function buildEventDetail(HsEvent $hsEvent, User $currentUser, ?HealthSafetyReadContext $context = null): array
    {
        $hsEvent->loadMissing([
            'site:id,name',
            'client:id,first_name,last_name',
            'staff:id,name',
            'asset:id,name',
            'creator:id,name',
            'owner:id,name',
            'acceptedBy:id,name',
            'worksafeDecidedBy:id,name',
            'worksafeSitePreservationDecidedBy:id,name',
            'worksafeSitePreservationReleasedBy:id,name',
        ]);

        $investigations = $hsEvent->investigations()
            ->with([
                'leadInvestigator:id,name',
                'submittedBy:id,name',
                'reviewedBy:id,name',
                'approvedBy:id,name',
                'recommendationDispositions.correctiveAction:id,reference_number,status',
                'recommendationDispositions.decidedBy:id,name',
            ])
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
                'recommendations' => $this->presentInvestigationRecommendations($inv),
                'lessons_learned' => $inv->lessons_learned,
                'submitted_by_name' => $inv->submittedBy?->name,
                'reviewed_by_name' => $inv->reviewedBy?->name,
                'approved_by_name' => $inv->approvedBy?->name,
            ]);

        $canManage = $currentUser->canDo('hazards.manage');
        $currentUserId = $currentUser->id;
        $evidenceScope = HsEvent::query()->whereKey($hsEvent->id);
        $this->siteAccess->applyHsEventScope($evidenceScope, $currentUser, []);
        $canAccessActionEvidence = $evidenceScope->exists();

        $assignableStaff = [];
        if ($canManage) {
            $assignableStaff = $this->handoverOwnerQuery($hsEvent, $currentUser)
                ->with(['permissionOverrides', 'roles.permissions'])
                ->orderBy('name')
                ->get(['id', 'name'])
                ->filter(fn (User $user): bool => $this->canOwnEvent($user, $hsEvent, $currentUser))
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
                ->values()
                ->all();
        }

        $correctiveActionRelations = [
            'hsEvent:id,created_by',
            'assignedTo:id,name',
            'completedBy:id,name',
            'verifiedBy:id,name',
            'hsInvestigation:id,recommendations',
            'sourceControlRoomTask:id,title',
        ];

        $correctiveActions = $hsEvent->correctiveActions()
            ->with($correctiveActionRelations)
            ->orderByRaw("FIELD(status, 'open', 'in_progress', 'completed', 'verified', 'closed')")
            ->orderBy('due_date')
            ->get();
        $evidenceLoadState = HsCorrectiveActionPresenter::EVIDENCE_LOADED;
        if ($canAccessActionEvidence) {
            try {
                $correctiveActions
                    ->filter(fn (HsCorrectiveAction $action): bool => $canManage
                        || (int) $action->assigned_to_user_id === (int) $currentUserId)
                    ->load([
                        'attachments.uploader:id,name',
                        'auditLogs' => fn ($query) => $query
                            ->with('user:id,name')
                            ->orderBy('created_at')
                            ->orderBy('id'),
                    ]);
            } catch (\Throwable $exception) {
                report($exception);
                $evidenceLoadState = HsCorrectiveActionPresenter::EVIDENCE_UNAVAILABLE;
            }
        }
        $correctiveActions = $correctiveActions->map(
            fn (HsCorrectiveAction $action) => $this->correctiveActionPresenter->present(
                $action,
                $currentUser,
                $canManage,
                $canAccessActionEvidence,
                $evidenceLoadState,
            ),
        );

        $riskAssessmentQuery = $hsEvent->riskAssessments();
        $this->siteAccess->applyHsRiskAssessmentSiteScopeForSiteIds(
            $riskAssessmentQuery->getQuery(),
            $hsEvent->site_id ? [(int) $hsEvent->site_id] : [],
        );
        $riskAssessments = $riskAssessmentQuery
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

        $source = $this->resolveSource($hsEvent->source_type, $hsEvent->source_id, $currentUser, sourceEvent: $hsEvent, context: $context);
        $incidentSource = $this->incidentDetailSourceInContext($hsEvent, $context);
        $sourceIncident = $incidentSource['incident'];
        $canOpenIncident = $sourceIncident !== null;
        $readableIncident = $sourceIncident;
        if ($source !== null && $sourceIncident !== null) {
            if ($canOpenIncident && $sourceIncident->reference_number) {
                $source['label'] = 'Incident '.$sourceIncident->reference_number;
            }
            if (! $canOpenIncident) {
                $source = null;
            }
        }
        $alert = $this->detailControlRoomAlertInContext($hsEvent, $sourceIncident, $incidentSource['parent'], $currentUser, $context);
        $handoverAttachments = $readableIncident?->attachments->map(fn (ClientIncidentAttachment $attachment) => [
            'id' => $attachment->id,
            'name' => $attachment->original_name,
            'mime' => $attachment->mime ?? $attachment->mime_type,
            'size' => $attachment->size,
            'uploaded_by' => $attachment->uploader?->name,
            'created_at' => $attachment->created_at?->toIso8601String(),
            'download_url' => "/health-safety/events/{$hsEvent->id}/incident-attachments/{$attachment->id}/download",
        ])->values() ?? collect();
        // A linked alert this viewer can't open is shown as restricted, from the id already on the record; the alert
        // itself isn't loaded.
        $linkedAlertId = $sourceIncident?->control_room_alert_id ?? $hsEvent->control_room_alert_id;
        $linkedOperationalEvidence = $alert
            ? $this->linkedEvidence->present(
                $alert,
                $currentUser,
                fn (EvidenceItem $item): string => "/health-safety/events/{$hsEvent->id}/control-room-evidence/{$item->id}/download",
            )
            : ($linkedAlertId !== null ? LinkedOperationalEvidencePresenter::restricted() : null);
        $canOpenAlert = data_get($linkedOperationalEvidence, 'access') === 'readable';
        if ($canOpenAlert) {
            // Operational source authority is independent of the H&S parent.
            $alert->loadMissing([
                'playbookRun.playbook:id,name',
                'tasks.transferredCorrectiveAction:id,source_control_room_task_id',
            ]);
        }
        $incidentFollowups = $readableIncident?->followups->map(fn ($followup): array => [
            'id' => $followup->id,
            'notes' => $followup->notes,
            'assigned_to' => $followup->assignedTo?->name,
            'due_at' => $followup->due_at?->toIso8601String(),
            'completed_at' => $followup->completed_at?->toIso8601String(),
        ])->values()->all() ?? [];
        $unresolvedControlRoomTasks = $canManage && $canOpenAlert
            ? $alert->tasks
                ->reject(fn (AlertTask $task): bool => in_array($task->status, AlertTask::TERMINAL_STATUSES, true)
                    || $task->transferred_to_hs_corrective_action_id !== null
                    || $task->transferred_at !== null
                    || $task->transferred_by_user_id !== null
                    || $task->transferredCorrectiveAction !== null)
                ->map(fn (AlertTask $task) => [
                    'id' => $task->id,
                    'reference' => "CR task #{$task->id}",
                    'title' => $task->title,
                    'description' => $task->description,
                    'status' => $task->status,
                    'priority' => $task->priority,
                    'due_at' => $task->due_at?->toIso8601String(),
                ])
                ->values()
                ->all()
            : [];
        $canAccept = $canManage
            && $hsEvent->handover_status === HsEvent::HANDOVER_AWAITING_ACCEPTANCE
            && $hsEvent->source_type === ClientIncident::class
            && $sourceIncident?->status !== 'draft'
            && $sourceIncident?->submitted_at !== null;
        $canDecideWorksafe = $canManage
            && $hsEvent->status !== HsEvent::STATUS_CLOSED
            && in_array($hsEvent->handover_status, [
                HsEvent::HANDOVER_ACCEPTED,
                HsEvent::HANDOVER_NOT_REQUIRED,
            ], true);
        $canNotifyWorksafe = $canManage
            && $hsEvent->worksafe_notifiable === true
            && $hsEvent->hasSignedWorksafeDecision()
            && $hsEvent->worksafe_status === HsEvent::WORKSAFE_PENDING;
        $canAcknowledgeWorksafe = $canManage
            && $hsEvent->hasSignedWorksafeDecision()
            && $hsEvent->worksafe_status === HsEvent::WORKSAFE_NOTIFIED;
        $canReviewSitePreservation = $canManage
            && $hsEvent->worksafe_notifiable === true
            && in_array($hsEvent->worksafe_status, [HsEvent::WORKSAFE_NOTIFIED, HsEvent::WORKSAFE_ACKNOWLEDGED], true)
            && ! in_array($hsEvent->worksafe_site_preservation_status, [
                HsEvent::SITE_PRESERVATION_ACTIVE,
                HsEvent::SITE_PRESERVATION_RELEASED,
            ], true);
        $canReleaseSitePreservation = $canManage
            && $hsEvent->worksafe_site_preservation_status === HsEvent::SITE_PRESERVATION_ACTIVE;
        $nextAction = match (true) {
            $canAccept => ['label' => 'Accept this H&S handover', 'href' => null],
            $canDecideWorksafe && ! $hsEvent->hasSignedWorksafeDecision() => [
                'label' => 'Record the WorkSafe decision',
                'href' => "/health-safety/events/{$hsEvent->id}?action=worksafe-decision",
            ],
            $canNotifyWorksafe => [
                'label' => 'Record the WorkSafe notification',
                'href' => "/health-safety/events/{$hsEvent->id}?action=worksafe-notify",
            ],
            $canAcknowledgeWorksafe => [
                'label' => 'Record the WorkSafe acknowledgement',
                'href' => "/health-safety/events/{$hsEvent->id}?action=worksafe-acknowledge",
            ],
            $canReleaseSitePreservation => [
                'label' => 'Record the Site-preservation release',
                'href' => "/health-safety/events/{$hsEvent->id}?action=worksafe-site-release",
            ],
            $canReviewSitePreservation => [
                'label' => 'Review Site-preservation obligations',
                'href' => "/health-safety/events/{$hsEvent->id}?action=worksafe-site-preservation",
            ],
            $canManage && $hsEvent->investigation_required && ! $hsEvent->hasCompletedInvestigation() => [
                'label' => 'Continue the H&S investigation',
                'href' => "/health-safety/events/{$hsEvent->id}",
            ],
            $canManage && $hsEvent->hasOpenCorrectiveActions() => [
                'label' => 'Continue corrective actions',
                'href' => "/health-safety/events/{$hsEvent->id}",
            ],
            default => null,
        };
        $closureReadiness = $this->closures->readiness($hsEvent);
        $closureExceptions = $hsEvent->closureExceptions()
            ->with(['requester:id,name', 'latestDecision.decidedBy:id,name'])
            ->orderByDesc('requested_at')
            ->get()
            ->map(fn (HsClosureException $exception): array => [
                'id' => $exception->id,
                'status' => $exception->status(),
                'category' => $exception->category,
                'reason' => $exception->reason,
                'evidence_reference' => $exception->evidence_reference,
                'scope' => $exception->scope,
                'requester' => $exception->requester ? [
                    'id' => $exception->requester->id,
                    'name' => $exception->requester->name,
                ] : null,
                'approver' => $exception->latestDecision?->decidedBy ? [
                    'id' => $exception->latestDecision->decidedBy->id,
                    'name' => $exception->latestDecision->decidedBy->name,
                ] : null,
                'decision_reason' => $exception->latestDecision?->reason,
                'created_at' => $exception->created_at?->toIso8601String(),
                'requested_at' => $exception->requested_at?->toIso8601String(),
                'decided_at' => $exception->latestDecision?->decided_at?->toIso8601String(),
                'expires_at' => $exception->expires_at?->toIso8601String(),
                'review_at' => $exception->review_at?->toIso8601String(),
                'provenance_hash' => $exception->provenance_hash,
            ])
            ->values()
            ->all();

        $detail = [
            'id' => $hsEvent->id,
            'reference_number' => $hsEvent->reference_number,
            'event_category' => $hsEvent->event_category,
            'severity' => $hsEvent->severity,
            'status' => $hsEvent->status,
            'occurred_at' => $hsEvent->occurred_at?->toIso8601String(),
            'reported_at' => $hsEvent->reported_at?->toIso8601String(),
            'description' => $readableIncident?->description,
            'site' => $hsEvent->site ? ['id' => $hsEvent->site->id, 'name' => $hsEvent->site->name] : null,
            'client' => $hsEvent->client && (! app(FirstAidSourceProjectionService::class)->isFirstAidSource($hsEvent->source_type) || $source !== null) ? ['id' => $hsEvent->client->id, 'name' => trim($hsEvent->client->first_name.' '.$hsEvent->client->last_name)] : null,
            'staff' => $hsEvent->staff ? ['id' => $hsEvent->staff->id, 'name' => $hsEvent->staff->name] : null,
            'asset' => $hsEvent->asset ? ['id' => $hsEvent->asset->id, 'name' => $hsEvent->asset->name] : null,
            'worksafe' => [
                'notifiable' => $hsEvent->worksafe_notifiable,
                'status' => $hsEvent->worksafe_status,
                'decision_reason' => $hsEvent->worksafe_decision_reason,
                'decision_source' => $hsEvent->worksafe_decision_source,
                'decision_signed' => $hsEvent->hasSignedWorksafeDecision(),
                'decision_tree_version' => $hsEvent->worksafe_decision_tree_version,
                'source_effective_date' => $hsEvent->worksafe_source_effective_date?->toDateString(),
                'decision_support' => $this->notifiableEvents->policy(),
                'decided_at' => $hsEvent->worksafe_decided_at?->toIso8601String(),
                'decided_by' => $hsEvent->worksafeDecidedBy ? [
                    'id' => $hsEvent->worksafeDecidedBy->id,
                    'name' => $hsEvent->worksafeDecidedBy->name,
                ] : null,
                'reference' => $hsEvent->worksafe_reference,
                'notified_at' => $hsEvent->worksafe_notified_at?->toIso8601String(),
                'acknowledged_at' => $hsEvent->worksafe_acknowledged_at?->toIso8601String(),
                'method' => $hsEvent->worksafe_method,
                'site_preserved' => (bool) $hsEvent->worksafe_site_preserved,
                'site_preservation_status' => $hsEvent->worksafe_site_preservation_status,
                'site_preservation_decided_at' => $hsEvent->worksafe_site_preservation_decided_at?->toIso8601String(),
                'site_preservation_decided_by' => $hsEvent->worksafeSitePreservationDecidedBy ? [
                    'id' => $hsEvent->worksafeSitePreservationDecidedBy->id,
                    'name' => $hsEvent->worksafeSitePreservationDecidedBy->name,
                ] : null,
                'site_preservation_decision_reference' => $hsEvent->worksafe_site_preservation_decision_reference,
                'site_preservation_released_at' => $hsEvent->worksafe_site_preservation_released_at?->toIso8601String(),
                'site_preservation_released_by' => $hsEvent->worksafeSitePreservationReleasedBy ? [
                    'id' => $hsEvent->worksafeSitePreservationReleasedBy->id,
                    'name' => $hsEvent->worksafeSitePreservationReleasedBy->name,
                ] : null,
                'site_preservation_release_reference' => $hsEvent->worksafe_site_preservation_release_reference,
                'can_decide' => $canDecideWorksafe,
                'can_notify' => $canNotifyWorksafe,
                'can_acknowledge' => $canAcknowledgeWorksafe,
                'can_review_site_preservation' => $canReviewSitePreservation,
                'can_release_site_preservation' => $canReleaseSitePreservation,
            ],
            'investigation_required' => (bool) $hsEvent->investigation_required,
            'control_room_alert' => $canOpenAlert ? [
                'id' => $alert->id,
                'reference_number' => $alert->reference_number,
                'severity' => $alert->severity,
                'status' => $alert->status,
                'url' => data_get($linkedOperationalEvidence, 'source.href'),
            ] : null,
            'linked_operational_evidence' => $linkedOperationalEvidence,
            'linked_access' => [
                // An incident-sourced event whose incident this viewer can't read is "restricted", not "absent".
                'incident' => $sourceIncident !== null ? ($canOpenIncident ? 'readable' : 'restricted')
                    : (ltrim((string) $hsEvent->getRawOriginal('source_type'), '\\') === ClientIncident::class ? 'restricted' : 'absent'),
                'control_room' => $alert === null ? ($linkedAlertId !== null ? 'restricted' : 'absent') : ($canOpenAlert ? 'readable' : 'restricted'),
            ],
            'incident_followups' => $incidentFollowups,
            'journey_state' => $this->journeyPresenter->journeyState(
                $sourceIncident,
                $alert,
                $hsEvent,
            ),
            'closed_at' => $hsEvent->closed_at?->toIso8601String(),
            'closure_summary' => $hsEvent->closure_summary,
            'created_by_name' => $hsEvent->creator?->name,
            'source' => $source,
            'handover' => [
                'status' => $hsEvent->handover_status,
                'owner' => $hsEvent->owner ? [
                    'id' => $hsEvent->owner->id,
                    'name' => $hsEvent->owner->name,
                ] : null,
                'accepted_by' => $hsEvent->acceptedBy ? [
                    'id' => $hsEvent->acceptedBy->id,
                    'name' => $hsEvent->acceptedBy->name,
                ] : null,
                'accepted_at' => $hsEvent->accepted_at?->toIso8601String(),
                'notes' => $hsEvent->acceptance_notes,
                'can_accept' => $canAccept,
            ],
            'lifecycle' => [
                'control_room' => $alert?->status,
                'incident' => $sourceIncident?->status,
                'health_safety' => $hsEvent->status,
            ],
            'handover_summary' => [
                'incident_reference' => $readableIncident?->reference_number,
                'alert_reference' => $canOpenAlert ? $alert->reference_number : null,
                'narrative' => $readableIncident?->description,
                'immediate_controls' => $readableIncident?->immediate_action_taken
                    ?? $readableIncident?->immediate_action,
                'witnesses' => $readableIncident?->witnesses,
                'potential_consequence' => $readableIncident?->potential_consequence,
                'reporter' => $readableIncident?->reporter?->name,
                'source_label' => $source['label'] ?? null,
                'site_name' => $hsEvent->site?->name,
                'attachments' => $handoverAttachments->all(),
                'control_room_evidence' => data_get($linkedOperationalEvidence, 'evidence_packs', []),
                'playbook' => $canOpenAlert && $alert->playbookRun ? [
                    'name' => $alert->playbookRun->playbook?->name,
                    'status' => $alert->playbookRun->status,
                    'outcome' => data_get($alert->playbookRun->context, 'outcome'),
                ] : null,
                'communications' => data_get($linkedOperationalEvidence, 'communications', []),
                'operational_tasks' => data_get($linkedOperationalEvidence, 'tasks', []),
                'next_action' => $nextAction,
            ],
            'investigations' => $investigations->all(),
            'corrective_actions' => $correctiveActions->all(),
            'risk_assessments' => $riskAssessments->all(),
            'attachments' => $handoverAttachments->all(),
            'close_gate' => $closureReadiness->toJourneyGate()->toArray(),
            'close_readiness' => $closureReadiness->toArray(),
            'closure_exceptions' => $closureExceptions,
            'assignable_staff' => $assignableStaff,
            'action_handover' => [
                'eligible_owners' => $assignableStaff,
                'unresolved_control_room_tasks' => $unresolvedControlRoomTasks,
            ],
            'can' => [
                'manage' => $canManage,
                'close' => $currentUser->canDo('healthSafety.events.close'),
                'request_closure_exception' => $currentUser->canDo('healthSafety.closureExceptions.request'),
                'approve_closure_exception' => $currentUser->canDo('healthSafety.closureExceptions.approve'),
                ...app(SafeWorkProcedureAccessService::class)->sourceCan($hsEvent, $currentUser, $context),
                'manage_corrective_action_lifecycle' => $canManage
                    && $canAccessActionEvidence,
                'verify_corrective_actions' => $canManage && $canAccessActionEvidence,
            ],
        ];
        if ($context !== null && ltrim((string) $hsEvent->getRawOriginal('source_type'), '\\') === ClientIncident::class) {
            if ($sourceIncident !== null && $incidentSource['parent'] !== null) {
                // Incident content was included: the incident must still be readable as the page is published.
                abort_unless($context->query(clone $incidentSource['parent'])->exists(), 404);
            } else {
                // Restricted view (Stephan, 25 Oct): no incident content was read, but the event must still be visible.
                abort_unless($context->applyIncidentEventScope(HsEvent::query()->whereKey($hsEvent->id))->exists(), 404);
            }
        }
        if ($sourceIncident !== null && $context !== null) {
            $context->consumeSourceViewer(\App\Services\Incidents\IncidentHsEventSourceReadService::PERMISSIONS,
                function (User $current, \App\Services\CurrentAuthorizationReads $reads) use ($sourceIncident, $incidentSource): void {
                    abort_unless(app(\App\Services\Incidents\IncidentTaskReadService::class)
                        ->isStillIncident($sourceIncident, $incidentSource['parent'], $reads), 404);
                    foreach (['attachments' => 'incident_id', 'followups' => 'client_incident_id'] as $relation => $column) {
                        foreach ($sourceIncident->getRelation($relation) as $child) {
                            $guard = app(\App\Services\Incidents\IncidentHsEventSourceReadService::class)
                                ->childQueryInReads($child->newQuery()->whereKey($child->id), $column, $incidentSource['parent'], $reads);
                            foreach ($child->getRawOriginal() as $field => $value) {
                                if ($value === null) { $guard->whereNull($field); }
                                elseif (is_string($value)) { $guard->whereRaw('BINARY '.$guard->getQuery()->getGrammar()->wrap($field).' = BINARY ?', [$value]); }
                                else { $guard->where($field, $value); }
                            }
                            abort_unless($guard->exists(), 404);
                        }
                    }
                });
        }
        return $detail;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function presentInvestigationRecommendations(HsInvestigation $investigation): array
    {
        $dispositions = $investigation->recommendationDispositions->keyBy('recommendation_index');

        return collect($investigation->recommendations ?? [])
            ->map(function (array $recommendation, int $index) use ($dispositions): array {
                $disposition = $dispositions->get($index);

                return [
                    ...$recommendation,
                    'disposition' => $disposition ? [
                        'disposition' => $disposition->disposition,
                        'reason' => $disposition->reason,
                        'corrective_action' => $disposition->correctiveAction ? [
                            'id' => $disposition->correctiveAction->id,
                            'reference_number' => $disposition->correctiveAction->reference_number,
                            'status' => $disposition->correctiveAction->status,
                        ] : null,
                        'decided_by_name' => $disposition->decidedBy?->name,
                        'decided_at' => $disposition->decided_at?->toIso8601String(),
                    ] : null,
                ];
            })
            ->values()
            ->all();
    }

    public function downloadIncidentAttachment(
        Request $request,
        int $hsEvent,
        ClientIncidentAttachment $attachment,
    ): StreamedResponse {
        return DB::transaction(fn () => $this->onAccessibleEvent($request, $hsEvent, fn (HsEvent $event) => $this->downloadIncidentAttachmentWithinSourceAuthority($request, $event, $attachment), false));
    }

    private function downloadIncidentAttachmentWithinSourceAuthority(
        Request $request,
        HsEvent $event,
        ClientIncidentAttachment $attachment,
    ): StreamedResponse {
        $incidentId = $event->source_type === ClientIncident::class
            ? (int) $event->source_id
            : (int) ($event->clientIncident()->value('id') ?? 0);

        abort_unless($incidentId > 0 && (int) $attachment->incident_id === $incidentId, 404);
        $this->authorize('view', ClientIncident::query()->findOrFail($incidentId));

        return $this->streamPrivateAttachment(
            $attachment->disk,
            $attachment->path,
            $attachment->original_name,
            $attachment->mime ?? $attachment->mime_type,
        );
    }

    public function downloadControlRoomEvidence(
        Request $request,
        int $hsEvent,
        EvidenceItem $item,
    ): StreamedResponse {
        return DB::transaction(fn () => $this->onAccessibleEvent($request, $hsEvent, fn (HsEvent $event) => $this->downloadControlRoomEvidenceWithinSourceAuthority($request, $event, $item), false));
    }

    private function downloadControlRoomEvidenceWithinSourceAuthority(
        Request $request,
        HsEvent $event,
        EvidenceItem $item,
    ): StreamedResponse {
        $sourceIncident = $event->source_type === ClientIncident::class
            ? ClientIncident::query()->find($event->source_id)
            : $event->clientIncident()->first();
        $alert = $this->linkedControlRoomAlert($event, $sourceIncident);
        $belongsToJourney = $alert !== null
            && $item->evidencePack()
                ->where('alert_id', $alert->id)
                ->exists();

        abort_unless($belongsToJourney && filled($item->storage_path), 404);
        abort_unless(app(ControlRoomAlertAccessService::class)
            ->canView($alert, $request->user()), 403);

        return $this->streamPrivateAttachment(
            'local',
            $item->storage_path,
            data_get($item->metadata, 'original_name') ?: basename($item->storage_path),
            $item->mime_type,
        );
    }

    /**
     * Corrective actions listing â€” all actions across all events.
     */
    public function correctiveActions(Request $request): Response
    {
        return Inertia::render('health-safety/corrective-actions/index', $this->registerRead($request,
            fn (HealthSafetyReadContext $context): array => $this->correctiveActionsWithinSourceAuthority($request, $context)));
    }

    private function correctiveActionsWithinSourceAuthority(Request $request, HealthSafetyReadContext $context): array
    {
        $this->validateRegisterPeriod($request);
        $tab = (string) $request->input('tab', $this->legacyActionTab($request));

        $canManage = (bool) ($request->user()?->canDo('hazards.manage') ?? false);
        $currentUserId = $request->user()?->id;

        $query = HsCorrectiveAction::query()
            ->with([
                'hsEvent:id,reference_number,event_category,severity,status,site_id,created_by',
                'hsEvent.site:id,name',
                'assignedTo:id,name',
                'completedBy:id,name',
                'verifiedBy:id,name',
                'hsInvestigation:id,recommendations',
                'sourceControlRoomTask:id,title',
            ])
            ->orderByRaw("FIELD(status, 'open', 'in_progress', 'completed', 'verified', 'closed')")
            ->orderBy('due_date');

        $this->applyActionTab($query, $tab);
        $this->applyActionRefinements($query, $request);
        $this->applyActionScope($query, $request, null, $context);

        $actions = $query->paginate(25)->withQueryString();
        $pageActions = $actions->getCollection();
        $strictEventIds = HsEvent::query()
            ->whereKey($pageActions->pluck('hs_event_id')->filter()->unique()->all());
        $context->applyEventSiteScope($strictEventIds, []);
        app(HsEventAccessService::class)->applyFirstAidSourceScope($strictEventIds, $request->user(), $context);
        $strictEventIds = $strictEventIds->pluck('id')->map(fn ($id) => (int) $id);
        $evidenceLoadState = HsCorrectiveActionPresenter::EVIDENCE_LOADED;
        try {
            $pageActions
                ->filter(fn (HsCorrectiveAction $action): bool => $strictEventIds->contains((int) $action->hs_event_id)
                    && ($canManage || (int) $action->assigned_to_user_id === (int) $currentUserId))
                ->load([
                    'attachments.uploader:id,name',
                    'auditLogs' => fn ($query) => $query
                        ->with('user:id,name')
                        ->orderBy('created_at')
                        ->orderBy('id'),
                ]);
        } catch (\Throwable $exception) {
            report($exception);
            $evidenceLoadState = HsCorrectiveActionPresenter::EVIDENCE_UNAVAILABLE;
        }
        $actions = $actions->through(fn (HsCorrectiveAction $a) => [
            ...$this->correctiveActionPresenter->present(
                $a,
                $request->user(),
                $canManage,
                $strictEventIds->contains((int) $a->hs_event_id),
                $evidenceLoadState,
            ),
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

        $base = $this->actionScopedBase($request, $context);
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
        foreach (['critical_open', 'unassigned'] as $attentionTab) {
            $attentionQuery = clone $base;
            $this->applyActionTab($attentionQuery, $attentionTab);
            $tabCounts[$attentionTab] = $attentionQuery->count();
        }

        $hero = [
            'live' => [
                'open' => $openCount,
                'in_progress' => $inProgressCount,
                'awaiting_verification' => $awaitingCount,
                'verified' => $verifiedCount,
            ],
            'attention' => [
                'overdue' => $tabCounts['overdue'],
                'critical_open' => $tabCounts['critical_open'],
                'unassigned' => $tabCounts['unassigned'],
                'monitoring_events' => (int) (clone $base)
                    ->whereHas('hsEvent', fn (Builder $q) => $q->where('status', HsEvent::STATUS_MONITORING))
                    ->distinct('hs_event_id')
                    ->count('hs_event_id'),
            ],
        ];

        $detail = null;
        if ($request->filled('event')) {
            $target = $this->resolveAccessibleEvent($request, $request->integer('event'), $context);
            $detail = $context->consumeViewer($this->detailReadPermissionKeys(),
                fn (User $current): array => $this->buildEventDetail($target, $current, $context));
        }

        $siteIds = $this->actionScopedBase($request, $context)->whereHas('hsEvent', fn (Builder $q) => $q->whereNotNull('site_id'))
            ->with('hsEvent:id,site_id')
            ->get()
            ->pluck('hsEvent.site_id')
            ->filter()
            ->unique()
            ->values();

        return [
            'actions' => $actions->toArray(),
            'tab' => $tab,
            'tabCounts' => $tabCounts,
            'hero' => $hero,
            'monitoringReviewUrl' => '/health-safety/events?'.http_build_query([
                'tab' => 'monitoring',
                'action_context' => array_filter([
                    ...$request->only(['q', 'priority', 'site_id', 'from', 'to']),
                    'tab' => $tab,
                    'unassigned' => $request->boolean('unassigned') ? 1 : null,
                ], fn ($value) => $value !== null && $value !== ''),
            ], '', '&', PHP_QUERY_RFC3986),
            'filters' => [
                'q' => $request->input('q'),
                'tab' => $tab,
                'priority' => $request->input('priority'),
                'unassigned' => $request->boolean('unassigned') ?: null,
                'site_id' => $request->filled('site_id') ? (int) $request->input('site_id') : null,
                'from' => $request->input('from'),
                'to' => $request->input('to'),
            ],
            'sites' => Site::whereIn('id', $siteIds)->orderBy('name')->get(['id', 'name'])->toArray(),
            'detail' => $detail,
            'can' => [
                'manage' => $canManage,
                // Traceability report is a governance artefact, gated on governance.view
                // (NOT hazards.manage) per the corrective-actions handover.
                'viewReports' => (bool) ($request->user()?->canDo('governance.view') ?? false),
            ],
        ];
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

    private function applyActionScope(Builder $query, Request $request, ?Builder $sourceActions = null, ?HealthSafetyReadContext $context = null): void
    {
        $this->applyActionSourceFilters($query, $request);
        $candidateActions = clone ($sourceActions ?? $query);
        if ($sourceActions !== null) {
            $this->applyActionSourceFilters($candidateActions, $request);
        }
        $candidateActions->setEagerLoads([])->reorder()->when(true, QueryPaging::clear(...))->select('hs_corrective_actions.hs_event_id');
        $sourceCandidates = HsEvent::query()->whereIn('id', $candidateActions);
        $this->siteAccess->applyHsEventScope($sourceCandidates, $request->user(), $this->hsEventBypassPermissions());
        $query->whereHas('hsEvent', function (Builder $eventQuery) use ($request, $sourceCandidates, $context) {
            if ($context !== null) {
                $context->applyEventSiteScope($eventQuery, $this->hsEventBypassPermissions());
            } else {
                $this->siteAccess->applyHsEventScope(
                    $eventQuery,
                    $request->user(),
                    $this->hsEventBypassPermissions(),
                );
            }
            // Without the read context, actions on incident-linked events are refused.
            app(HsEventAccessService::class)->applyFirstAidSourceScope($eventQuery, $request->user(), $context, $sourceCandidates);
        });
    }

    private function applyActionSourceFilters(Builder $query, Request $request): void
    {
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

    private function actionScopedBase(Request $request, ?HealthSafetyReadContext $context = null): Builder
    {
        $query = HsCorrectiveAction::query();
        $this->applyActionRefinements($query, $request);
        $this->applyActionScope($query, $request, null, $context);

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
            'critical_open' => $query->whereIn('priority', [HsCorrectiveAction::PRIORITY_HIGH, HsCorrectiveAction::PRIORITY_CRITICAL])
                ->whereNotIn('status', [HsCorrectiveAction::STATUS_VERIFIED, HsCorrectiveAction::STATUS_CLOSED]),
            'unassigned' => $query->whereNull('assigned_to_user_id')
                ->whereNotIn('status', [HsCorrectiveAction::STATUS_VERIFIED, HsCorrectiveAction::STATUS_CLOSED]),
            default => $query,
        };
    }

    private function applyActionRefinements(Builder $query, Request $request): void
    {
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
    }

    /**
     * Event commands read the event as the event page does (incident-linked events need incident read) and run while
     * that read holds. Without the read, incident-linked events are always refused.
     *
     * @param  \Closure(HsEvent, HealthSafetyReadContext): mixed  $command
     */
    private function onAccessibleEvent(Request $request, int $eventId, \Closure $command, bool $forUpdate = true): mixed
    {
        return app(HsEventAccessService::class)->withReadableEvent($request->user(), $eventId, $command,
            $this->hsEventBypassPermissions(), $this->detailReadPermissionKeys(), $forUpdate);
    }

    /**
     * A register page read through the H&S source read, so incident-linked events show to viewers who can read the
     * incident. The read takes no-wait shared locks, which can meet a save in progress, so it is retried briefly.
     *
     * @param  \Closure(HealthSafetyReadContext): array  $read
     */
    private function registerRead(Request $request, \Closure $read): array
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return HealthSafetyReadContext::within($request->user(), $this->detailReadPermissionKeys(), $read);
            } catch (\Illuminate\Database\QueryException|\Illuminate\Database\DeadlockException $error) {
                $code = (int) ($error->errorInfo[1] ?? 0);
                if ($attempt >= 3 || DB::transactionLevel() > 0
                    || ! ($error instanceof \Illuminate\Database\DeadlockException || in_array($code, [1205, 1213, 3572], true))) {
                    throw $error;
                }
                usleep(100_000 * $attempt);
            }
        }
    }

    private function resolveAccessibleEvent(Request $request, int $eventId, ?HealthSafetyReadContext $context = null): HsEvent
    {
        $query = HsEvent::query()->whereKey($eventId);
        if ($context !== null) {
            $context->assertViewer($request->user());
            $context->applyEventSiteScope($query, $this->hsEventBypassPermissions());
        } else {
            $this->siteAccess->applyHsEventScope($query, $request->user(), $this->hsEventBypassPermissions());
        }
        // First Aid copied treatment evidence retains its independent source boundary.
        app(HsEventAccessService::class)->applyFirstAidSourceScope($query, $request->user(), $context);

        return $query->findOrFail($eventId);
    }

    /** The literal original parent Builder is reused on every body/child query, never replaced by a warmed policy/model. */
    private function incidentDetailSourceInContext(HsEvent $event, ?HealthSafetyReadContext $context): array
    {
        if ($context === null) { return ['incident' => null, 'parent' => null]; }
        return $context->consumeSourceViewer(\App\Services\Incidents\IncidentHsEventSourceReadService::PERMISSIONS,
            function (User $current, \App\Services\CurrentAuthorizationReads $reads) use ($event, $context): array {
                $source = app(\App\Services\Incidents\IncidentHsEventSourceReadService::class);
                $parent = $context->incidentQueryForEvent($event);
                $incident = $reads->query(clone $parent)->first();
                if ($incident === null) { return ['incident' => null, 'parent' => $parent]; }
                $reporter = $reads->query(User::query()->whereKey($incident->reported_by)
                    ->whereExists((clone $parent)->reorder()->selectRaw('1')->toBase()))->first(['id', 'name']);
                $incident->setRelation('reporter', $reporter);
                $attachments = $source->childQueryInReads(ClientIncidentAttachment::query(), 'incident_id', $parent, $reads)
                    ->with(['uploader' => function ($relation) use ($source, $parent, $reads): void {
                        $query = $relation->getQuery();
                        $query->whereExists($source->childQueryInReads(ClientIncidentAttachment::query(), 'incident_id', $parent, $reads)
                            ->whereColumn('client_incident_attachments.uploaded_by', 'users.id')->selectRaw('1')->toBase());
                        $reads->query($query)->select(['id', 'name']);
                    }])->get(['id', 'incident_id', 'uploaded_by', 'original_name', 'mime', 'mime_type', 'size', 'created_at']);
                $followups = $source->childQueryInReads(\App\Models\IncidentFollowup::query(), 'client_incident_id', $parent, $reads)
                    ->with(['assignedTo' => function ($relation) use ($source, $parent, $reads): void {
                        $query = $relation->getQuery();
                        $query->whereExists($source->childQueryInReads(\App\Models\IncidentFollowup::query(), 'client_incident_id', $parent, $reads)
                            ->whereColumn('incident_followups.assigned_to_user_id', 'users.id')->selectRaw('1')->toBase());
                        $reads->query($query)->select(['id', 'name']);
                    }])->get(['id', 'client_incident_id', 'assigned_to_user_id', 'due_at', 'completed_at', 'notes', 'created_by', 'created_at']);
                $incident->setRelation('attachments', $attachments);
                $incident->setRelation('followups', $followups);
                return ['incident' => $incident, 'parent' => $parent];
            });
    }

    /** Independent owning alert authority remains mandatory; never refresh an admitted incident through an unscoped Journey reload. */
    private function detailControlRoomAlertInContext(HsEvent $event, ?ClientIncident $incident, ?Builder $parent, User $viewer, ?HealthSafetyReadContext $context): ?ControlRoomAlert
    {
        $query = ControlRoomAlert::query();
        $alertId = $incident?->control_room_alert_id ?? $event->control_room_alert_id;
        if ($alertId !== null) { $query->whereKey($alertId); }
        elseif ($incident !== null) { $query->where('context->incident_id', $incident->id)->orderBy('id'); }
        else { return null; }
        if ($incident !== null) {
            // The direct alert and legacy context remain this original source's literal Site/Client/identity.
            $query->where('site_id', $event->site_id)->where('client_id', $incident->client_id)
                ->where(fn (Builder $claim) => $claim->whereNull('context->incident_id')
                    ->orWhereRaw('BINARY JSON_UNQUOTE(JSON_EXTRACT(`control_room_alerts`.`context`, ?)) = BINARY ?', ['$.incident_id', (string) $incident->id]))
                ->whereNotExists(DB::table('client_incidents as hs_other_alert_source')->selectRaw('1')
                    ->whereColumn('hs_other_alert_source.control_room_alert_id', 'control_room_alerts.id')->where('hs_other_alert_source.id', '!=', $incident->id));
        }
        $query = app(ControlRoomAlertAccessService::class)->applyReadableScope($query, $viewer);
        if ($incident !== null && $parent !== null) {
            $query->whereExists((clone $parent)->reorder()->selectRaw('1')->toBase());
        }
        return $context !== null ? $context->query($query)->first() : $query->first();
    }

    private function linkedControlRoomAlert(
        HsEvent $event,
        ?ClientIncident $incident,
    ): ?ControlRoomAlert {
        if ($incident !== null) {
            return $this->journeys->journeyForIncident($incident)->alert;
        }

        return $event->controlRoomAlert()->first();
    }

    /**
     * A handover owner must be able to open the event (Stephan, 25 Oct): H&S view and manage, and for an event from a
     * private source (incident, treatment, restraint, injury) the same source read as the event page. The person
     * already reading or acting on it passes; ordinary H&S events need no extra read.
     */
    private function canOwnEvent(User $person, HsEvent $event, User $viewer): bool
    {
        if (! $person->canDo('hazards.manage') || ! $person->canDo('hazards.view')) {
            return false;
        }
        if ((int) $person->id === (int) $viewer->id) {
            return true;
        }
        $source = ltrim((string) $event->getRawOriginal('source_type'), '\\');
        $private = in_array($source, [ClientIncident::class, \App\Models\FirstAidRecord::class, RestraintEvent::class, WorkplaceInjury::class], true)
            || ClientIncident::query()->where('hs_event_id', $event->id)->exists();

        return ! $private || app(HsEventAccessService::class)->canReadEvent($person, $event);
    }

    private function handoverOwnerQuery(HsEvent $event, User $viewer): Builder
    {
        $query = User::query();
        $this->siteAccess->applyHsEventStaffScope(
            $query,
            $event,
            $viewer,
            $this->hsEventBypassPermissions(),
        );

        return $query;
    }

    /**
     * @return array<int, string>
     */
    private function hsEventBypassPermissions(): array
    {
        return UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS;
    }

    /**
     * Risk assessments listing.
     */
    public function riskAssessments(Request $request): Response
    {
        $query = HsRiskAssessment::query()
            ->with(['assessedBy:id,name'])
            ->orderByDesc('created_at');
        $this->siteAccess->applyHsRiskAssessmentScope(
            $query,
            $request->user(),
            $this->hsEventBypassPermissions(),
        );

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
