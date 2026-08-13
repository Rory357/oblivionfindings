<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Controller;
use App\Models\BehaviourSupportPlan;
use App\Models\BehaviourSupportPlanReview;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\RespiteStay;
use App\Models\RestraintEvent;
use App\Models\RestraintEventAttachment;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RestraintController extends Controller
{
    use ServesPrivateAttachments;

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    private const RESTRAINT_TYPES = ['physical', 'chemical', 'mechanical', 'seclusion', 'environmental'];

    private const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    private const PLAN_STATUSES = ['draft', 'active', 'under_review', 'archived'];

    /* ================================================================== */
    /*  Register (hero + lenses + detail-as-modal) */
    /* ================================================================== */

    public function index(Request $request): Response
    {
        abort_unless($this->canView($request), 403);

        $lens = in_array($request->get('lens'), ['events', 'plans'], true) ? $request->get('lens') : 'events';
        $tab = (string) $request->get('tab', 'all');

        $filters = [
            'q' => trim((string) $request->get('q', '')),
            // Cast to int so the EntityFilter pill (strict id === value) renders as selected.
            'client_id' => $request->filled('client_id') ? (int) $request->get('client_id') : null,
            'site_id' => $request->filled('site_id') ? (int) $request->get('site_id') : null,
            'restraint_type' => $request->get('restraint_type'),
            'severity' => $request->get('severity'),
            'within_plan' => $request->get('within_plan'),
            'review_state' => $request->get('review_state'),
            'period' => $request->get('period'),
            'from' => $request->get('from'),
            'to' => $request->get('to'),
        ];

        if ($filters['site_id'] !== null) {
            $this->siteAccess->assertCanAccessHealthSafetySiteId($request->user(), $filters['site_id']);
        }
        if ($filters['client_id'] !== null) {
            $this->assertCanAccessClientId($request, $filters['client_id']);
        }

        // The hero period pills map to a "from" date; an explicit from wins. The UI
        // defaults the pill to "30 days", so a null period resolves to the same 30-day
        // window (only the explicit "all" pill clears it) — pill and data agree.
        $effectiveFrom = $filters['from'] ?: match ($filters['period'] ?? '30d') {
            'week' => now()->startOfWeek()->toDateTimeString(),
            'quarter' => now()->subDays(90)->toDateTimeString(),
            'all' => null,
            default => now()->subDays(30)->toDateTimeString(),
        };

        // ---- Events (filtered base, then per-tab) ----
        $eventsBase = fn () => $this->restraintEventQuery($request)
            ->when($filters['client_id'], fn ($q) => $q->where('client_id', $filters['client_id']))
            ->when($filters['site_id'], fn ($q) => $q->where('site_id', $filters['site_id']))
            ->when($filters['restraint_type'], fn ($q) => $q->where('restraint_type', $filters['restraint_type']))
            ->when($filters['severity'], fn ($q) => $q->where('severity', $filters['severity']))
            ->when($filters['within_plan'] === 'yes', fn ($q) => $q->where('within_support_plan', true))
            ->when($filters['within_plan'] === 'no', fn ($q) => $q->where('within_support_plan', false))
            ->when($effectiveFrom, fn ($q) => $q->where('started_at', '>=', $effectiveFrom))
            ->when($filters['to'], fn ($q) => $q->where('started_at', '<=', $filters['to']))
            ->when($filters['q'] !== '', fn ($q) => $q->where(function ($w) use ($filters) {
                $w->where('restraint_description', 'like', "%{$filters['q']}%")
                    ->orWhere('trigger_description', 'like', "%{$filters['q']}%")
                    ->orWhereHas('client', fn ($c) => $c
                        ->where('first_name', 'like', "%{$filters['q']}%")
                        ->orWhere('last_name', 'like', "%{$filters['q']}%"));
            }));

        $applyEventTab = fn ($q, string $t) => match ($t) {
            'unreviewed' => $q->whereNull('reviewed_at'),
            'out_of_plan' => $q->where('within_support_plan', false),
            'injury' => $q->where('injury_occurred', true),
            'critical' => $q->where('severity', 'critical'),
            '30d' => $q->where('started_at', '>=', now()->subDays(30)),
            default => $q,
        };

        // ---- Plans (filtered base, then per-tab) ----
        $plansBase = fn () => $this->behaviourSupportPlanQuery($request)
            ->when($filters['client_id'], fn ($q) => $q->where('client_id', $filters['client_id']))
            ->when($filters['site_id'], fn ($q) => $q->whereHas('client', fn ($client) => $client->where('site_id', $filters['site_id'])))
            ->when($filters['restraint_type'], fn ($q) => $q->where('restrictive_practice_type', $filters['restraint_type']))
            ->when($effectiveFrom, fn ($q) => $q->where('created_at', '>=', $effectiveFrom))
            ->when($filters['to'], fn ($q) => $q->where('created_at', '<=', $filters['to']))
            ->when($filters['q'] !== '', fn ($q) => $q->where(function ($w) use ($filters) {
                $w->where('title', 'like', "%{$filters['q']}%")
                    ->orWhereHas('client', fn ($c) => $c
                        ->where('first_name', 'like', "%{$filters['q']}%")
                        ->orWhere('last_name', 'like', "%{$filters['q']}%"));
            }));

        $reviewDueWindow = now()->addDays(30);
        $applyPlanTab = fn ($q, string $t) => match ($t) {
            'active' => $q->where('status', 'active'),
            'draft' => $q->where('status', 'draft'),
            'under_review' => $q->where('status', 'under_review'),
            'archived' => $q->where('status', 'archived'),
            'review_due' => $q->where('status', 'active')->whereNotNull('review_date')->where('review_date', '<=', $reviewDueWindow),
            default => $q->where('status', '!=', 'archived'),
        };

        // Active lens drives the table tab; the inactive lens shows its default.
        $events = ($lens === 'events' ? $applyEventTab($eventsBase(), $tab) : $eventsBase())
            ->with(['client:id,first_name,last_name', 'site:id,name'])
            ->orderByDesc('started_at')
            ->paginate(20, ['*'], 'events_page')
            ->withQueryString()
            ->through(fn (RestraintEvent $e) => $this->serializeEventRow($e));

        $plans = ($lens === 'plans' ? $applyPlanTab($plansBase(), $tab) : $applyPlanTab($plansBase(), 'all'))
            ->with('client:id,first_name,last_name')
            ->orderByDesc('created_at')
            ->paginate(20, ['*'], 'plans_page')
            ->withQueryString()
            ->through(fn (BehaviourSupportPlan $p) => $this->serializePlanRow($p));

        return Inertia::render('health-safety/restraints/index', [
            'lens' => $lens,
            'tab' => $tab,
            'events' => $events,
            'plans' => $plans,
            'tabCounts' => [
                'events' => [
                    'all' => (clone $eventsBase())->count(),
                    'unreviewed' => $applyEventTab($eventsBase(), 'unreviewed')->count(),
                    'out_of_plan' => $applyEventTab($eventsBase(), 'out_of_plan')->count(),
                    'injury' => $applyEventTab($eventsBase(), 'injury')->count(),
                    'critical' => $applyEventTab($eventsBase(), 'critical')->count(),
                    '30d' => $applyEventTab($eventsBase(), '30d')->count(),
                ],
                'plans' => [
                    'all' => $applyPlanTab($plansBase(), 'all')->count(),
                    'active' => $applyPlanTab($plansBase(), 'active')->count(),
                    'draft' => $applyPlanTab($plansBase(), 'draft')->count(),
                    'review_due' => $applyPlanTab($plansBase(), 'review_due')->count(),
                    'under_review' => $applyPlanTab($plansBase(), 'under_review')->count(),
                    'archived' => $applyPlanTab($plansBase(), 'archived')->count(),
                ],
            ],
            'hero' => $this->heroBlock($request),
            'filters' => $filters,
            'clients' => $this->clientQuery($request)
                ->select('id', 'first_name', 'last_name', 'site_id')
                ->orderBy('last_name')
                ->get()
                ->map(fn ($c) => ['id' => $c->id, 'name' => trim("{$c->first_name} {$c->last_name}"), 'site_id' => $c->site_id]),
            'sites' => $this->siteQuery($request)->select('id', 'name')->where('is_active', true)->orderBy('name')->get(),
            'staff' => $this->staffQuery($request)->select('id', 'name')->orderBy('name')->get(),
            'incidents' => $this->recentIncidentsForPicker($request),
            'plansForPicker' => $this->behaviourSupportPlanQuery($request)
                ->where('status', '!=', 'archived')
                ->orderByDesc('created_at')
                ->get(['id', 'reference_number', 'client_id', 'title', 'status', 'restrictive_practice_type'])
                ->map(fn (BehaviourSupportPlan $p) => [
                    'id' => $p->id,
                    'client_id' => $p->client_id,
                    'title' => $p->title,
                    'status' => $p->status,
                    'reference' => $this->planRef($p),
                    'restrictive_practice_type' => $p->restrictive_practice_type,
                ]),
            'detail' => $this->resolveDetail($request),
            'can' => [
                'create' => $this->canCreate($request),
                'review' => $this->canReview($request),
                'manage' => $this->canManage($request),
            ],
        ]);
    }

    private function heroBlock(Request $request): array
    {
        $p30 = now()->subDays(30);
        $p60 = now()->subDays(60);

        $events = fn (): Builder => $this->restraintEventQuery($request);
        $plans = fn (): Builder => $this->behaviourSupportPlanQuery($request);
        $events30 = $events()->where('started_at', '>=', $p30)->count();
        $eventsPrev30 = $events()->whereBetween('started_at', [$p60, $p30])->count();
        $reduction = $eventsPrev30 > 0
            ? (int) round((($events30 - $eventsPrev30) / $eventsPrev30) * 100)
            : 0;

        $reviewDueWindow = now()->addDays(30);

        // Clients with restraint events but no active behaviour support plan — a
        // least-restrictive-practice compliance gap (restrained without a current plan).
        $clientsWithEvents = $events()->distinct()->pluck('client_id');
        $clientsWithActiveBsp = $plans()->where('status', 'active')->distinct()->pluck('client_id');
        $clientsNoActiveBsp = $clientsWithEvents->diff($clientsWithActiveBsp)->count();

        return [
            'live' => [
                'events_30d' => $events30,
                'out_of_plan' => $events()->where('started_at', '>=', $p30)->where('within_support_plan', false)->count(),
                'injuries' => $events()->where('started_at', '>=', $p30)->where('injury_occurred', true)->count(),
                'critical' => $events()->where('started_at', '>=', $p30)->where('severity', 'critical')->count(),
            ],
            'attention' => [
                'unreviewed' => $events()->whereNull('reviewed_at')->count(),
                'plans_review_due' => $plans()->where('status', 'active')->whereNotNull('review_date')->where('review_date', '<=', $reviewDueWindow)->count(),
                'plans_under_review' => $plans()->where('status', 'under_review')->count(),
                'clients_no_active_bsp' => $clientsNoActiveBsp,
            ],
            'badges' => [
                'unreviewed' => $events()->whereNull('reviewed_at')->count(),
                'plans_overdue' => $plans()->where('status', 'active')->whereNotNull('review_date')->where('review_date', '<', now())->count(),
                'nga_paerewa_certified' => true,
                'reduction_trend_pct' => $reduction,
            ],
        ];
    }

    /* ================================================================== */
    /*  Detail (lazy — only when ?event= / ?plan= present) */
    /* ================================================================== */

    private function resolveDetail(Request $request): ?array
    {
        if ($request->filled('event')) {
            return $this->eventDetail((int) $request->get('event'), $request);
        }
        if ($request->filled('plan')) {
            return $this->planDetail((int) $request->get('plan'), $request);
        }

        return null;
    }

    private function eventDetail(int $id, Request $request): ?array
    {
        $e = $this->restraintEventQuery($request)->with([
            'client:id,first_name,last_name',
            'site:id,name',
            'behaviourSupportPlan:id,reference_number,title,status',
            'relatedIncident:id,reference_number,type,occurred_at',
            'reviewedBy:id,name',
            'authorisedBy:id,name',
            'attachments.uploader:id,name',
        ])->find($id);

        if (! $e) {
            return null;
        }

        $staff = collect($e->staff_involved ?? []);
        $staffQuery = $this->staffQuery($request)
            ->whereIn('id', $staff->filter(fn ($v) => is_numeric($v))->all());
        $staffNames = $staff->isNotEmpty() ? $staffQuery->pluck('name', 'id') : collect();

        return [
            'kind' => 'event',
            'id' => $e->id,
            'reference' => $this->eventRef($e),
            'client' => $e->client ? ['id' => $e->client->id, 'name' => trim("{$e->client->first_name} {$e->client->last_name}")] : null,
            'site' => $e->site ? ['id' => $e->site->id, 'name' => $e->site->name] : null,
            'restraint_type' => $e->restraint_type,
            'severity' => $e->severity,
            'started_at' => $e->started_at,
            'ended_at' => $e->ended_at,
            'duration_minutes' => $e->duration_minutes,
            'trigger_description' => $e->trigger_description,
            'de_escalation_attempted' => $e->de_escalation_attempted,
            'restraint_description' => $e->restraint_description,
            'person_response' => $e->person_response,
            'post_incident_support' => $e->post_incident_support,
            'injury_occurred' => (bool) $e->injury_occurred,
            'injury_details' => $e->injury_details,
            'within_support_plan' => (bool) $e->within_support_plan,
            'deviation_reason' => $e->deviation_reason,
            'staff_involved' => $staff->map(fn ($v) => [
                'id' => is_numeric($v) && $staffNames->has((int) $v) ? (int) $v : null,
                'name' => is_numeric($v) ? ($staffNames[(int) $v] ?? 'Staff member unavailable') : (string) $v,
            ])->values(),
            'authorised_by' => $e->authorisedBy ? ['id' => $e->authorisedBy->id, 'name' => $e->authorisedBy->name] : null,
            'plan' => $e->behaviourSupportPlan ? [
                'id' => $e->behaviourSupportPlan->id,
                'reference' => $this->planRef($e->behaviourSupportPlan),
                'title' => $e->behaviourSupportPlan->title,
                'status' => $e->behaviourSupportPlan->status,
            ] : null,
            'related_incident' => $e->relatedIncident ? [
                'id' => $e->relatedIncident->id,
                'reference' => $this->incidentRef($e->relatedIncident),
                'type' => $e->relatedIncident->type,
            ] : null,
            'reviewed_at' => $e->reviewed_at,
            'reviewed_by' => $e->reviewedBy ? ['id' => $e->reviewedBy->id, 'name' => $e->reviewedBy->name] : null,
            'review_notes' => $e->review_notes,
            'lessons_learned' => $e->lessons_learned,
            'flags' => [
                'unreviewed' => $e->reviewed_at === null,
                'out_of_plan' => $e->within_support_plan === false,
                'injury' => (bool) $e->injury_occurred,
                'linked_incident' => $e->related_incident_id !== null,
            ],
            'attachments' => $e->attachments->map(fn (RestraintEventAttachment $a) => [
                'id' => $a->id,
                'name' => $a->original_name,
                'mime' => $a->mime ?: $a->mime_type,
                'size' => $a->size,
                'category' => $a->category,
                'notes' => $a->notes,
                'uploaded_by' => $a->uploader?->name,
                'created_at' => $a->created_at,
                'download_url' => "/health-safety/restraints/events/{$e->id}/attachments/{$a->id}/download",
            ])->values(),
            'can' => [
                'review' => $this->canReview($request),
                'manage' => $this->canManage($request),
            ],
        ];
    }

    private function planDetail(int $id, Request $request): ?array
    {
        $p = $this->behaviourSupportPlanQuery($request)->with([
            'client:id,first_name,last_name',
            'developedBy:id,name',
            'statusChangedBy:id,name',
            'reviews' => fn ($q) => $q->with('reviewer:id,name')->orderByDesc('reviewed_at'),
        ])->find($id);

        if (! $p) {
            return null;
        }

        return [
            'kind' => 'plan',
            'id' => $p->id,
            'reference' => $this->planRef($p),
            'title' => $p->title,
            'client' => $p->client ? ['id' => $p->client->id, 'name' => trim("{$p->client->first_name} {$p->client->last_name}")] : null,
            'status' => $p->status,
            'restrictive_practice_type' => $p->restrictive_practice_type,
            'triggers' => $p->triggers,
            'de_escalation_strategies' => $p->de_escalation_strategies,
            'approved_interventions' => $this->splitList($p->approved_interventions),
            'prohibited_interventions' => $this->splitList($p->prohibited_interventions),
            'notes' => $p->notes,
            'review_date' => $p->review_date,
            'review_state' => $this->reviewState($p),
            'developed_by' => $p->developedBy ? ['id' => $p->developedBy->id, 'name' => $p->developedBy->name] : null,
            'developed_at' => $p->developed_at,
            'status_changed_at' => $p->status_changed_at,
            'status_changed_by' => $p->statusChangedBy ? ['id' => $p->statusChangedBy->id, 'name' => $p->statusChangedBy->name] : null,
            'events_count' => $this->siteAccess
                ->applyRestraintEventScope($p->restraintEvents()->getQuery(), $request->user())
                ->count(),
            'reviews' => $p->reviews->map(fn (BehaviourSupportPlanReview $r) => [
                'id' => $r->id,
                'outcome' => $r->outcome,
                'reviewed_by' => $r->reviewer?->name,
                'reviewed_at' => $r->reviewed_at,
                'next_review_date' => $r->next_review_date,
                'resulting_status' => $r->resulting_status,
                'notes' => $r->notes,
            ])->values(),
            'can' => [
                'review' => $this->canReview($request),
                'manage' => $this->canManage($request),
            ],
        ];
    }

    /**
     * Read-only restrictive-practice summary for a client — feeds the BSP panel on
     * the client profile's Behaviour/ABC tab (self-fetched, JSON). All actions on the
     * panel deep-link into the register; no mutation here.
     */
    public function clientSummary(Request $request, Client $client): JsonResponse
    {
        abort_unless($this->canView($request), 403);
        $client = $this->resolveAccessibleClient($request, $client->id);

        $plan = $this->behaviourSupportPlanQuery($request)->where('client_id', $client->id)
            ->where('status', 'active')
            ->orderByDesc('created_at')
            ->first();

        $eventsQuery = $this->restraintEventQuery($request)->where('client_id', $client->id);
        $events = (clone $eventsQuery)
            ->orderByDesc('started_at')
            ->limit(5)
            ->get();

        return response()->json([
            'active_plan' => $plan ? [
                'id' => $plan->id,
                'reference' => $this->planRef($plan),
                'title' => $plan->title,
                'status' => $plan->status,
                'review_date' => $plan->review_date,
                'review_state' => $this->reviewState($plan),
            ] : null,
            'recent_events' => $events->map(fn (RestraintEvent $e) => [
                'id' => $e->id,
                'reference' => $this->eventRef($e),
                'restraint_type' => $e->restraint_type,
                'severity' => $e->severity,
                'started_at' => $e->started_at,
                'within_support_plan' => (bool) $e->within_support_plan,
                'injury_occurred' => (bool) $e->injury_occurred,
                'reviewed_at' => $e->reviewed_at,
            ])->values(),
            'total_events' => $eventsQuery->count(),
        ]);
    }

    /* ================================================================== */
    /*  Event create + review */
    /* ================================================================== */

    public function storeEvent(Request $request): RedirectResponse
    {
        abort_unless($this->canCreate($request), 403);

        $validated = $request->validate([
            'client_id' => 'required|integer',
            'behaviour_support_plan_id' => 'nullable|integer',
            'stay_id' => 'nullable|integer',
            'site_id' => 'nullable|integer',
            'started_at' => 'required|date',
            'ended_at' => 'nullable|date|after:started_at',
            'duration_minutes' => 'nullable|integer|min:0',
            'restraint_type' => 'required|in:'.implode(',', self::RESTRAINT_TYPES),
            'severity' => 'required|in:'.implode(',', self::SEVERITIES),
            'trigger_description' => 'required|string',
            'de_escalation_attempted' => 'required|string',
            'restraint_description' => 'required|string',
            'staff_involved' => 'nullable|array',
            'staff_involved.*' => 'integer',
            'person_response' => 'nullable|string',
            'post_incident_support' => 'nullable|string',
            'injury_occurred' => 'boolean',
            'injury_details' => 'nullable|string|required_if:injury_occurred,true',
            'within_support_plan' => 'boolean',
            'deviation_reason' => 'nullable|string|required_if:within_support_plan,false',
            'authorised_by' => 'nullable|integer',
            'related_incident_id' => 'nullable|integer',
        ]);

        $client = $this->resolveAccessibleClient($request, (int) $validated['client_id']);
        $siteId = (int) ($validated['site_id'] ?? $client->site_id);
        abort_unless($siteId > 0 && $siteId === (int) $client->site_id, 403, UserSiteAccessService::DEFAULT_MESSAGE);
        $this->siteAccess->assertCanAccessHealthSafetySiteId($request->user(), $siteId);
        $validated['site_id'] = $siteId;

        if (! empty($validated['behaviour_support_plan_id'])) {
            $plan = $this->resolveAccessiblePlan($request, (int) $validated['behaviour_support_plan_id']);
            abort_unless((int) $plan->client_id === (int) $client->id, 403, UserSiteAccessService::DEFAULT_MESSAGE);
        }
        if (! empty($validated['stay_id'])) {
            $stay = RespiteStay::query()->whereKey((int) $validated['stay_id'])->where('client_id', $client->id)->firstOrFail();
            abort_unless((int) $stay->client_id === (int) $client->id, 404);
        }
        $this->assertIncidentAtEventContext(
            $request,
            isset($validated['related_incident_id']) ? (int) $validated['related_incident_id'] : null,
            $client->id,
            $siteId,
        );
        foreach ($validated['staff_involved'] ?? [] as $staffId) {
            $this->siteAccess->assertCanUseCurrentStaffAtSite($request->user(), (int) $staffId, $siteId, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS);
        }
        if (! empty($validated['authorised_by'])) {
            $this->siteAccess->assertCanUseCurrentStaffAtSite($request->user(), (int) $validated['authorised_by'], $siteId, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS);
        }

        // Derive duration server-side when both ends are known and it wasn't supplied.
        if (empty($validated['duration_minutes']) && ! empty($validated['ended_at'])) {
            $validated['duration_minutes'] = Carbon::parse($validated['started_at'])
                ->diffInMinutes(Carbon::parse($validated['ended_at']));
        }

        // Mirror the respite active-BSP auto-link (RespiteStayController@recordRestraint):
        // an in-plan restraint with no explicit plan links to the client's active BSP, so
        // the shared wizard (incl. the respite entry point) keeps that behaviour.
        if (empty($validated['behaviour_support_plan_id']) && ($validated['within_support_plan'] ?? true)) {
            $validated['behaviour_support_plan_id'] = $this->behaviourSupportPlanQuery($request)
                ->where('client_id', $validated['client_id'])
                ->where('status', 'active')
                ->value('id');
        }

        $validated['created_by'] = $request->user()->id;

        $event = RestraintEvent::create($validated);

        if ($request->boolean('stay')) {
            return back()->with('success', 'Restraint event recorded.');
        }

        return redirect()
            ->route('health-safety.restraints.index', ['event' => $event->id])
            ->with('success', 'Restraint event recorded.');
    }

    public function updateEvent(Request $request, RestraintEvent $event): RedirectResponse
    {
        abort_unless($this->canReview($request), 403);
        $event = $this->resolveAccessibleEvent($request, $event->id);

        $validated = $request->validate([
            'reviewed_by' => 'nullable|integer',
            'reviewed_at' => 'nullable|date',
            'review_notes' => 'nullable|string',
            'lessons_learned' => 'nullable|string',
            // Fix (audit #13): the review form offers Critical — accept it.
            'severity' => 'nullable|in:'.implode(',', self::SEVERITIES),
            'post_incident_support' => 'nullable|string',
        ]);

        if (! empty($validated['reviewed_by'])) {
            $this->siteAccess->assertCanUseCurrentStaffAtSite(
                $request->user(),
                (int) $validated['reviewed_by'],
                (int) $event->site_id,
                UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS,
            );
        }

        // Stamp reviewer/time exactly once — on first review. Later edits (e.g.
        // correcting lessons_learned via "Update review") must not overwrite the
        // original audit attribution; updated_by captures those.
        if (! $event->reviewed_at) {
            if (empty($validated['reviewed_at'])) {
                $validated['reviewed_at'] = now();
            }
            if (empty($validated['reviewed_by'])) {
                $validated['reviewed_by'] = $request->user()->id;
            }
        } else {
            unset($validated['reviewed_at'], $validated['reviewed_by']);
        }

        $validated['updated_by'] = $request->user()->id;

        $event->update($validated);

        return back()->with('success', 'Restraint event reviewed.');
    }

    /**
     * Link (or unlink) an existing incident to a recorded restraint event.
     *
     * Kept deliberately separate from updateEvent(): the capture handover scopes
     * related_incident_id to create-time only, so post-hoc linking is its own
     * review-gated verb rather than a field the review form can silently change.
     * Mirrors the First Aid linkIncident pattern.
     */
    public function linkIncident(Request $request, RestraintEvent $event): RedirectResponse
    {
        abort_unless($this->canReview($request), 403);
        $event = $this->resolveAccessibleEvent($request, $event->id);

        $validated = $request->validate([
            'related_incident_id' => 'nullable|integer',
        ]);

        // Data integrity: a restraint event can only point at an incident raised
        // for the same client. Without this guard a reviewer could cross-link
        // two unrelated people's records.
        if (! empty($validated['related_incident_id'])) {
            $this->assertIncidentAtEventContext(
                $request,
                (int) $validated['related_incident_id'],
                (int) $event->client_id,
                (int) $event->site_id,
            );
        }

        $event->update([
            'related_incident_id' => $validated['related_incident_id'] ?: null,
            'updated_by' => $request->user()->id,
        ]);

        return back()->with(
            'success',
            $validated['related_incident_id'] ? 'Incident linked to restraint event.' : 'Incident link removed.'
        );
    }

    /* ================================================================== */
    /*  Plan create / update / lifecycle / review */
    /* ================================================================== */

    public function storePlan(Request $request): RedirectResponse
    {
        abort_unless($this->canCreate($request), 403);

        $validated = $request->validate([
            'client_id' => 'required|integer',
            'title' => 'required|string|max:255',
            'triggers' => 'nullable|string',
            'de_escalation_strategies' => 'nullable|string',
            'approved_interventions' => 'nullable|string',
            'prohibited_interventions' => 'nullable|string',
            'restrictive_practice_type' => 'nullable|in:'.implode(',', self::RESTRAINT_TYPES),
            'developed_by' => 'nullable|integer',
            'developed_at' => 'nullable|date',
            'review_date' => 'nullable|date',
            'status' => 'nullable|in:'.implode(',', self::PLAN_STATUSES),
            'notes' => 'nullable|string',
        ]);

        $client = $this->resolveAccessibleClient($request, (int) $validated['client_id']);
        abort_unless((int) $client->site_id > 0, 403, UserSiteAccessService::DEFAULT_MESSAGE);
        if (! empty($validated['developed_by'])) {
            $this->siteAccess->assertCanUseCurrentStaffAtSite(
                $request->user(),
                (int) $validated['developed_by'],
                (int) $client->site_id,
                UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS,
            );
        }

        $validated['created_by'] = $request->user()->id;
        $validated['status'] = $validated['status'] ?? 'draft';
        $validated['status_changed_at'] = now();
        $validated['status_changed_by'] = $request->user()->id;

        $plan = BehaviourSupportPlan::create($validated);

        return redirect()
            ->route('health-safety.restraints.index', ['lens' => 'plans', 'plan' => $plan->id])
            ->with('success', 'Behaviour support plan created.');
    }

    public function updatePlan(Request $request, BehaviourSupportPlan $plan): RedirectResponse
    {
        abort_unless($this->canManage($request), 403);
        $plan = $this->resolveAccessiblePlan($request, $plan->id);

        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'triggers' => 'nullable|string',
            'de_escalation_strategies' => 'nullable|string',
            'approved_interventions' => 'nullable|string',
            'prohibited_interventions' => 'nullable|string',
            'restrictive_practice_type' => 'nullable|in:'.implode(',', self::RESTRAINT_TYPES),
            'developed_by' => 'nullable|integer',
            'developed_at' => 'nullable|date',
            'review_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        if (! empty($validated['developed_by'])) {
            $this->siteAccess->assertCanUseCurrentStaffAtSite(
                $request->user(),
                (int) $validated['developed_by'],
                (int) $plan->client()->value('site_id'),
                UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS,
            );
        }

        $validated['updated_by'] = $request->user()->id;

        $plan->update($validated);

        return back()->with('success', 'Behaviour support plan updated.');
    }

    public function activatePlan(Request $request, BehaviourSupportPlan $plan): RedirectResponse
    {
        abort_unless($this->canManage($request), 403);
        $plan = $this->resolveAccessiblePlan($request, $plan->id);
        $this->transitionPlan($plan, 'active', $request->user()->id);

        return back()->with('success', 'Plan activated.');
    }

    public function submitPlanReview(Request $request, BehaviourSupportPlan $plan): RedirectResponse
    {
        abort_unless($this->canManage($request), 403);
        $plan = $this->resolveAccessiblePlan($request, $plan->id);
        $this->transitionPlan($plan, 'under_review', $request->user()->id);

        return back()->with('success', 'Plan submitted for review.');
    }

    public function archivePlan(Request $request, BehaviourSupportPlan $plan): RedirectResponse
    {
        abort_unless($this->canManage($request), 403);
        $plan = $this->resolveAccessiblePlan($request, $plan->id);
        $this->transitionPlan($plan, 'archived', $request->user()->id);

        return back()->with('success', 'Plan archived.');
    }

    public function reviewPlan(Request $request, BehaviourSupportPlan $plan): RedirectResponse
    {
        abort_unless($this->canReview($request), 403);
        $plan = $this->resolveAccessiblePlan($request, $plan->id);

        $validated = $request->validate([
            'outcome' => 'required|in:continued,modified,reduced,discontinued,escalated',
            'next_review_date' => 'nullable|date',
            'resulting_status' => 'nullable|in:'.implode(',', self::PLAN_STATUSES),
            'notes' => 'nullable|string',
        ]);

        BehaviourSupportPlanReview::create([
            'behaviour_support_plan_id' => $plan->id,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'outcome' => $validated['outcome'],
            'next_review_date' => $validated['next_review_date'] ?? null,
            'resulting_status' => $validated['resulting_status'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        $changes = ['updated_by' => $request->user()->id];
        if (! empty($validated['next_review_date'])) {
            $changes['review_date'] = $validated['next_review_date'];
        }
        // By design, a review outcome may conclude with a status change (e.g.
        // "discontinued" → archived). This is a review action (gated restraints.review),
        // distinct from the manage-only direct lifecycle endpoints; the resulting status
        // is captured on the review record for the audit trail.
        if (! empty($validated['resulting_status']) && $validated['resulting_status'] !== $plan->status) {
            $changes['status'] = $validated['resulting_status'];
            $changes['status_changed_at'] = now();
            $changes['status_changed_by'] = $request->user()->id;
        }
        $plan->update($changes);

        return back()->with('success', 'Plan review recorded.');
    }

    private function transitionPlan(BehaviourSupportPlan $plan, string $status, int $userId): void
    {
        $plan->update([
            'status' => $status,
            'status_changed_at' => now(),
            'status_changed_by' => $userId,
            'updated_by' => $userId,
        ]);
    }

    /* ================================================================== */
    /*  Attachments (premium document/photo evidence) */
    /* ================================================================== */

    public function storeAttachment(Request $request, RestraintEvent $event): RedirectResponse
    {
        abort_unless($this->canCreate($request), 403);
        $event = $this->resolveAccessibleEvent($request, $event->id);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
            'category' => ['nullable', 'in:body_map,injury_photo,authorisation,debrief,other'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $file = $request->file('file');
        $disk = 'private';
        $path = $file->store('restraint_attachments', $disk);

        $event->attachments()->create([
            'uploaded_by' => $request->user()?->id,
            'disk' => $disk,
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime' => $file->getClientMimeType(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'category' => $data['category'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('success', 'Attachment uploaded.');
    }

    public function destroyAttachment(Request $request, RestraintEvent $event, RestraintEventAttachment $attachment): RedirectResponse
    {
        abort_unless($this->canManage($request), 403);
        $event = $this->resolveAccessibleEvent($request, $event->id);
        abort_unless((int) $attachment->restraint_event_id === (int) $event->id, 404);

        $disk = $attachment->disk ?: 'private';
        if ($attachment->path && Storage::disk($disk)->exists($attachment->path)) {
            Storage::disk($disk)->delete($attachment->path);
        }
        $attachment->delete();

        return back()->with('success', 'Attachment removed.');
    }

    public function downloadAttachment(Request $request, RestraintEvent $event, RestraintEventAttachment $attachment): StreamedResponse
    {
        abort_unless($this->canView($request), 403);
        $event = $this->resolveAccessibleEvent($request, $event->id);
        abort_unless((int) $attachment->restraint_event_id === (int) $event->id, 404);

        // Private disk + nosniff + CSP sandbox — see ServesPrivateAttachments.
        return $this->streamPrivateAttachment(
            $attachment->disk,
            $attachment->path,
            $attachment->original_name,
            $attachment->mime,
        );
    }

    /* ================================================================== */
    /*  Export (CSV / board report) */
    /* ================================================================== */

    public function export(Request $request): StreamedResponse
    {
        abort_unless($this->canView($request), 403);

        $lens = in_array($request->get('lens'), ['events', 'plans'], true) ? $request->get('lens') : 'events';
        $from = $request->get('from');
        $to = $request->get('to');
        $clientId = $request->get('client_id');
        $siteId = $request->filled('site_id') ? (int) $request->get('site_id') : null;
        $type = $request->get('restraint_type');

        if ($siteId !== null) {
            $this->siteAccess->assertCanAccessHealthSafetySiteId($request->user(), $siteId);
        }
        if ($clientId !== null) {
            $this->assertCanAccessClientId($request, (int) $clientId);
        }

        $eventQuery = $this->restraintEventQuery($request);
        $planQuery = $this->behaviourSupportPlanQuery($request);

        $filename = "restraint-{$lens}-".now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($lens, $from, $to, $clientId, $siteId, $type, $eventQuery, $planQuery) {
            $out = fopen('php://output', 'w');

            if ($lens === 'plans') {
                $this->putCsv($out, ['Reference', 'Client', 'Title', 'Status', 'Restrictive practice', 'Review date', 'Review state']);
                $planQuery->with('client:id,first_name,last_name')
                    ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
                    ->when($type, fn ($q) => $q->where('restrictive_practice_type', $type))
                    ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
                    ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
                    ->orderByDesc('created_at')
                    ->chunk(200, function ($plans) use ($out) {
                        foreach ($plans as $p) {
                            $this->putCsv($out, [
                                $this->planRef($p),
                                $p->client ? trim("{$p->client->first_name} {$p->client->last_name}") : '',
                                $p->title,
                                $p->status,
                                $p->restrictive_practice_type,
                                optional($p->review_date)->format('Y-m-d'),
                                $this->reviewState($p),
                            ]);
                        }
                    });
            } else {
                $this->putCsv($out, ['Reference', 'Client', 'Site', 'Type', 'Severity', 'Started', 'Ended', 'Duration (min)', 'Within plan', 'Injury', 'Reviewed']);
                $eventQuery->with(['client:id,first_name,last_name', 'site:id,name'])
                    ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
                    ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
                    ->when($type, fn ($q) => $q->where('restraint_type', $type))
                    ->when($from, fn ($q) => $q->where('started_at', '>=', $from))
                    ->when($to, fn ($q) => $q->where('started_at', '<=', $to))
                    ->orderByDesc('started_at')
                    ->chunk(200, function ($events) use ($out) {
                        foreach ($events as $e) {
                            $this->putCsv($out, [
                                $this->eventRef($e),
                                $e->client ? trim("{$e->client->first_name} {$e->client->last_name}") : '',
                                $e->site?->name,
                                $e->restraint_type,
                                $e->severity,
                                optional($e->started_at)->format('Y-m-d H:i'),
                                optional($e->ended_at)->format('Y-m-d H:i'),
                                $e->duration_minutes,
                                $e->within_support_plan ? 'Yes' : 'No',
                                $e->injury_occurred ? 'Yes' : 'No',
                                $e->reviewed_at ? 'Yes' : 'No',
                            ]);
                        }
                    });
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /* ================================================================== */
    /*  Serializers + helpers */
    /* ================================================================== */

    private function restraintEventQuery(Request $request): Builder
    {
        return $this->siteAccess->applyRestraintEventScope(
            RestraintEvent::query(),
            $request->user(),
        );
    }

    private function behaviourSupportPlanQuery(Request $request): Builder
    {
        return $this->siteAccess->applyBehaviourSupportPlanScope(
            BehaviourSupportPlan::query(),
            $request->user(),
        );
    }

    private function clientQuery(Request $request): Builder
    {
        return $this->siteAccess->applyClientScope(
            Client::query(),
            $request->user(),
            UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS,
        );
    }

    private function siteQuery(Request $request): Builder
    {
        return $this->siteAccess->applySiteScope(
            Site::query(),
            $request->user(),
            UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS,
        );
    }

    private function staffQuery(Request $request): Builder
    {
        return $this->siteAccess->applyStaffScope(
            User::query(),
            $request->user(),
            UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS,
        );
    }

    private function resolveAccessibleEvent(Request $request, int $eventId): RestraintEvent
    {
        return $this->restraintEventQuery($request)->findOrFail($eventId);
    }

    private function resolveAccessiblePlan(Request $request, int $planId): BehaviourSupportPlan
    {
        return $this->behaviourSupportPlanQuery($request)->findOrFail($planId);
    }

    private function resolveAccessibleClient(Request $request, int $clientId): Client
    {
        return $this->clientQuery($request)->findOrFail($clientId);
    }

    private function assertCanAccessClientId(Request $request, int $clientId): void
    {
        $this->siteAccess->assertCanAccessClientId(
            $request->user(),
            $clientId,
            UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS,
        );
    }

    private function assertIncidentAtEventContext(
        Request $request,
        ?int $incidentId,
        int $clientId,
        int $siteId,
    ): void {
        if ($incidentId === null) {
            return;
        }

        $query = ClientIncident::query();
        $this->siteAccess->applyClientIncidentScope(
            $query,
            $request->user(),
            UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS,
        );
        $incident = $query->findOrFail($incidentId);

        try {
            $incidentSiteId = $this->siteAccess->effectiveClientIncidentSiteId($incident);
        } catch (\LogicException) {
            abort(404);
        }

        abort_unless($incidentSiteId === $siteId, 404);
        if ((int) $incident->client_id !== $clientId) {
            throw ValidationException::withMessages([
                'related_incident_id' => 'The incident must belong to the same client as the restraint event.',
            ]);
        }
    }

    private function serializeEventRow(RestraintEvent $e): array
    {
        return [
            'id' => $e->id,
            'reference' => $this->eventRef($e),
            'client' => $e->client ? ['id' => $e->client->id, 'name' => trim("{$e->client->first_name} {$e->client->last_name}")] : null,
            'site' => $e->site ? ['id' => $e->site->id, 'name' => $e->site->name] : null,
            'restraint_type' => $e->restraint_type,
            'severity' => $e->severity,
            'started_at' => $e->started_at,
            'ended_at' => $e->ended_at,
            'duration_minutes' => $e->duration_minutes,
            'within_support_plan' => (bool) $e->within_support_plan,
            'injury_occurred' => (bool) $e->injury_occurred,
            'reviewed_at' => $e->reviewed_at,
            'behaviour_support_plan_id' => $e->behaviour_support_plan_id,
            'related_incident_id' => $e->related_incident_id,
            'flags' => [
                'unreviewed' => $e->reviewed_at === null,
                'out_of_plan' => $e->within_support_plan === false,
                'injury' => (bool) $e->injury_occurred,
                'linked_incident' => $e->related_incident_id !== null,
            ],
        ];
    }

    private function serializePlanRow(BehaviourSupportPlan $p): array
    {
        return [
            'id' => $p->id,
            'reference' => $this->planRef($p),
            'title' => $p->title,
            'client' => $p->client ? ['id' => $p->client->id, 'name' => trim("{$p->client->first_name} {$p->client->last_name}")] : null,
            'status' => $p->status,
            'restrictive_practice_type' => $p->restrictive_practice_type,
            'review_date' => $p->review_date,
            'review_state' => $this->reviewState($p),
        ];
    }

    private function reviewState(BehaviourSupportPlan $p): string
    {
        if ($p->status !== 'active' || ! $p->review_date) {
            return 'ok';
        }
        if ($p->review_date->isPast()) {
            return 'overdue';
        }
        if ($p->review_date->lte(now()->addDays(30))) {
            return 'due';
        }

        return 'ok';
    }

    private function splitList(?string $raw): array
    {
        if (! $raw) {
            return [];
        }

        return collect(preg_split('/\r\n|\r|\n/', $raw))
            ->map(fn ($s) => trim($s))
            ->filter()
            ->values()
            ->all();
    }

    private function recentIncidentsForPicker(Request $request): array
    {
        $query = ClientIncident::query();
        $this->siteAccess->applyClientIncidentScope(
            $query,
            $request->user(),
            UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS,
        );

        return $query
            ->select('id', 'reference_number', 'client_id', 'type', 'occurred_at')
            ->orderByDesc('occurred_at')
            ->limit(100)
            ->get()
            ->map(fn (ClientIncident $i) => [
                'id' => $i->id,
                'client_id' => $i->client_id,
                'reference' => $this->incidentRef($i),
                'label' => $this->incidentRef($i).' · '.ucfirst(str_replace('_', ' ', (string) $i->type)).' · '.optional($i->occurred_at)->format('d M Y'),
            ])
            ->all();
    }

    private function eventRef(RestraintEvent $event): string
    {
        return $event->reference_number ?? 'RE-'.str_pad((string) $event->id, 3, '0', STR_PAD_LEFT);
    }

    private function planRef(BehaviourSupportPlan $plan): string
    {
        // Stored ticket number, with the legacy display format as fallback for
        // rows created before the reference_number backfill ran.
        return $plan->reference_number ?? 'BSP-'.str_pad((string) $plan->id, 3, '0', STR_PAD_LEFT);
    }

    private function incidentRef(ClientIncident $incident): string
    {
        return $incident->reference_number ?? 'INC-'.str_pad((string) $incident->id, 4, '0', STR_PAD_LEFT);
    }

    /* ================================================================== */
    /*  Permission gates (restraints.*) */
    /* ================================================================== */

    private function canView(Request $request): bool
    {
        return (bool) $request->user()?->canDo('restraints.view');
    }

    private function canCreate(Request $request): bool
    {
        $user = $request->user();

        return (bool) ($user?->canDo('restraints.create') || $user?->canDo('restraints.manage'));
    }

    private function canReview(Request $request): bool
    {
        $user = $request->user();

        return (bool) ($user?->canDo('restraints.review') || $user?->canDo('restraints.manage'));
    }

    private function canManage(Request $request): bool
    {
        return (bool) $request->user()?->canDo('restraints.manage');
    }
}
