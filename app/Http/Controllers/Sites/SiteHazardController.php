<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Sites\Concerns\ResolvesAllowedSiteTypes;
use App\Models\Site;
use App\Models\SiteHazard;
use App\Models\SiteHazardAction;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\References\ReferenceNumberGenerator;
use App\Services\Sites\HazardAssignmentService;
use App\Services\Sites\SiteChecklistFailureRiskMapper;
use App\Services\Sites\SiteHazardRiskCalculator;
use App\Services\SiteTypeAccessService;
use App\Services\UserSiteAccessService;
use App\Support\HazardDetailPresenter;
use App\Support\Incidents\IncidentOccurrencePeriod;
use App\Support\SiteRecommendedHazards;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SiteHazardController extends Controller
{
    use ResolvesAllowedSiteTypes;
    use ServesPrivateAttachments;

    private const TABS = ['all', 'active', 'open', 'in_progress', 'overdue', 'critical', 'closed', 'due_soon', 'unassigned', 'mitigated', 'closed_only'];

    private const SITE_BYPASS_PERMISSIONS = ['sites.viewAll'];

    public function __construct(
        private SiteHazardRiskCalculator $riskCalculator,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /* ====================================================================
     *  Register surfaces — global (compliance) + per-site, identical chrome
     * ==================================================================== */

    public function globalIndex(Request $request)
    {
        abort_unless($request->user()?->canDo('hazards.view'), 403);

        if ($redirect = $this->legacyRecordRedirect($request)) {
            return $redirect;
        }

        return inertia('compliance/hazards/index', $this->registerProps($request, null));
    }

    /** Streamed CSV of the current filtered/tab set (the hero's Export CSV). */
    public function export(Request $request)
    {
        abort_unless($request->user()?->canDo('hazards.view'), 403);

        return $this->exportRegister($request, null);
    }

    public function exportSite(Request $request, Site $site)
    {
        abort_unless($request->user()?->canDo('hazards.view'), 403);
        $this->authorizeHazardRead($request, $site);

        return $this->exportRegister($request, $site);
    }

    private function exportRegister(Request $request, ?Site $site)
    {
        $this->validatePeriod($request);
        $allowedSiteTypes = $this->allowedSiteTypes($request);
        $this->assertRequestedSiteAccess($request, $site?->id);
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'all';

        $query = $this->baseQuery($request, $allowedSiteTypes, $site?->id);
        $this->applyTab($query, $tab);

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');
            try {
                $this->putCsv($handle, ['Reference', 'Site', 'Site type', 'Hazard', 'Severity', 'Likelihood', 'Risk', 'Status', 'Assigned to', 'Due', 'Logged']);
                foreach ($query->with(['site:id,name,type', 'assignedTo:id,name'])->orderByDesc('created_at')->orderByDesc('id')->lazy(500) as $h) {
                    $this->putCsv($handle, [
                        $h->reference_number, $h->site?->name, $h->site?->type,
                        HazardDetailPresenter::hazardLabel($h), $h->severity, $h->likelihood,
                        $h->risk_rating, $h->status, $h->assignedTo?->name,
                        $h->due_date?->toDateString(),
                        $h->created_at?->copy()->timezone(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString(),
                    ]);
                }
            } finally {
                fclose($handle);
            }
        }, 'hazards-'.now(config('app.worker_timezone', 'Pacific/Auckland'))->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function index(Request $request, Site $site)
    {
        $this->authorizeHazardRead($request, $site);

        if ($redirect = $this->legacyRecordRedirect($request, $site)) {
            return $redirect;
        }

        return inertia('sites/hazards/index', [
            ...$this->registerProps($request, $site),
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'type' => $site->type,
                'suburb' => $site->suburb ?? null,
            ],
            'recommendedHazards' => SiteRecommendedHazards::forType($site->type),
        ]);
    }

    /**
     * Shared register payload (Events-shaped) used by both the global and the
     * per-site surfaces. When $site is set the whole register is scoped to it.
     */
    private function registerProps(Request $request, ?Site $site): array
    {
        $this->validatePeriod($request);
        $allowedSiteTypes = $this->allowedSiteTypes($request);
        $siteId = $site?->id;
        $this->assertRequestedSiteAccess($request, $siteId);

        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'all';

        // Fresh filtered base query each call so counts can chain different scopes.
        $base = fn (): Builder => $this->baseQuery($request, $allowedSiteTypes, $siteId);

        $tabCounts = [
            'all' => $base()->count(),
            'active' => $base()->whereIn('status', ['open', 'in_progress'])->count(),
            'open' => $base()->where('status', 'open')->count(),
            'in_progress' => $base()->where('status', 'in_progress')->count(),
            'overdue' => $base()->overdue()->count(),
            'critical' => $base()->criticalOpen()->count(),
            'closed' => $base()->whereIn('status', ['mitigated', 'closed'])->count(),
            'due_soon' => $base()->dueSoon()->count(),
            'unassigned' => $base()->unassignedOpen()->count(),
            'mitigated' => $base()->where('status', 'mitigated')->count(),
            'closed_only' => $base()->where('status', 'closed')->count(),
        ];

        $hero = [
            'live' => [
                'open' => $tabCounts['open'],
                'in_progress' => $tabCounts['in_progress'],
                'overdue' => $tabCounts['overdue'],
                'critical' => $tabCounts['critical'],
            ],
            'attention' => [
                'due_soon' => $tabCounts['due_soon'],
                'unassigned' => $tabCounts['unassigned'],
                'mitigated' => $tabCounts['mitigated'],
                'closed_period' => $tabCounts['closed_only'],
            ],
        ];

        $listQuery = $base();
        $this->applyTab($listQuery, $tab);

        $hazards = $listQuery
            ->with(['site:id,name,type', 'assignedTo:id,name', 'reportedBy:id,name'])
            ->withCount(['actions as open_action_count' => fn ($q) => $q->where('status', '!=', 'completed')])
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (SiteHazard $h) => $this->buildRow($h));

        $sites = Site::query()
            ->active()
            ->whereIn('type', $allowedSiteTypes)
            ->select(['id', 'name', 'type'])
            ->orderBy('name');
        $this->siteAccess->applySiteScope(
            $sites,
            $request->user(),
            self::SITE_BYPASS_PERMISSIONS,
        );

        $assignees = User::query()
            ->select(['id', 'name'])
            ->orderBy('name');
        $this->siteAccess->applyStaffScope(
            $assignees,
            $request->user(),
            self::SITE_BYPASS_PERMISSIONS,
        );

        return [
            'hazards' => $hazards,
            'tab' => $tab,
            'tabCounts' => $tabCounts,
            'hero' => $hero,
            'filters' => $request->only([
                'q', 'site_id', 'site_type', 'severity', 'risk_rating',
                'assignee_id', 'due_state', 'tab', 'from', 'to',
            ]),
            'sites' => $sites->get(),
            'assignees' => $assignees->get(),
            'detail' => $this->resolveDetail($request, $allowedSiteTypes, $siteId),
            'can' => $this->permissions($request),
            'severityOptions' => SiteHazardRiskCalculator::severities(),
            'likelihoodOptions' => SiteHazardRiskCalculator::likelihoods(),
            'riskRatings' => SiteHazardRiskCalculator::riskRatings(),
            'recommendedBySiteType' => [
                'house' => SiteRecommendedHazards::forType('house'),
                'facility' => SiteRecommendedHazards::forType('facility'),
                'head_office' => SiteRecommendedHazards::forType('head_office'),
            ],
        ];
    }

    /** Build the filtered, scope-aware base query (without the tab clause). */
    private function baseQuery(Request $request, array $allowedSiteTypes, ?int $siteId): Builder
    {
        $accessibleSiteIds = $this->siteAccess->accessibleSiteIds(
            $request->user(),
            self::SITE_BYPASS_PERMISSIONS,
        );

        $query = SiteHazard::query()
            ->whereIn('site_id', $accessibleSiteIds)
            ->whereHas('site', fn ($q) => $q->whereIn('type', $allowedSiteTypes))
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->when($request->filled('site_id') && ! $siteId, fn ($q) => $q->where('site_id', $request->query('site_id')))
            ->when($request->filled('site_type'), fn ($q) => $q->whereHas('site', fn ($sq) => $sq->where('type', $request->query('site_type'))))
            ->when($request->filled('severity'), fn ($q) => $q->where('severity', $request->query('severity')))
            ->when($request->filled('risk_rating'), fn ($q) => $q->where('risk_rating', $request->query('risk_rating')))
            ->when($request->filled('assignee_id'), fn ($q) => $q->where('assigned_to_user_id', $request->query('assignee_id')))
            ->when($request->query('due_state') === 'overdue', fn ($q) => $q->overdue())
            ->when($request->query('due_state') === 'due_soon', fn ($q) => $q->dueSoon())
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->query('q').'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('reference_number', 'like', $term)
                        ->orWhere('description', 'like', $term)
                        ->orWhere('hazard_type', 'like', $term)
                        ->orWhere('custom_hazard_type', 'like', $term)
                        ->orWhere('location', 'like', $term)
                        ->orWhereHas('site', fn ($s) => $s->where('name', 'like', $term));
                });
            });

        return IncidentOccurrencePeriod::apply($query, $request->query('from'), $request->query('to'), 'created_at');
    }

    private function validatePeriod(Request $request): void
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'site_id' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    /** H&S read authority does not grant access to the wider Site profile. */
    private function authorizeHazardRead(Request $request, ?Site $site): void
    {
        abort_unless($request->user()?->canDo('hazards.view'), 403);
        abort_unless($site
            && in_array($site->type, $this->allowedSiteTypes($request), true)
            && in_array((int) $site->id, $this->siteAccess->accessibleSiteIds($request->user(), self::SITE_BYPASS_PERMISSIONS), true), 404);
    }

    private function assertRequestedSiteAccess(Request $request, ?int $boundSiteId): void
    {
        if ($boundSiteId && $request->filled('site_id')) {
            abort_unless((int) $request->query('site_id') === $boundSiteId, 403);
        }
        if ($boundSiteId || ! $request->filled('site_id')) {
            return;
        }

        $this->siteAccess->assertCanAccessSiteId(
            $request->user(),
            (int) $request->query('site_id'),
            self::SITE_BYPASS_PERMISSIONS,
        );
    }

    private function applyTab(Builder $query, string $tab): void
    {
        match ($tab) {
            'active' => $query->whereIn('status', ['open', 'in_progress']),
            'open' => $query->where('status', 'open'),
            'in_progress' => $query->where('status', 'in_progress'),
            'overdue' => $query->overdue(),
            'critical' => $query->criticalOpen(),
            'closed' => $query->whereIn('status', ['mitigated', 'closed']),
            'due_soon' => $query->dueSoon(),
            'unassigned' => $query->unassignedOpen(),
            'mitigated' => $query->where('status', 'mitigated'),
            'closed_only' => $query->where('status', 'closed'),
            default => $query,
        };
    }

    /* ====================================================================
     *  Row + detail serialisers
     * ==================================================================== */

    private function buildRow(SiteHazard $h): array
    {
        return [
            'id' => $h->id,
            'reference_number' => $h->reference_number,
            'site_id' => $h->site_id,
            'site_name' => $h->site?->name,
            'site_type' => $h->site?->type,
            'hazard_type' => $h->hazard_type,
            'hazard_label' => HazardDetailPresenter::hazardLabel($h),
            'severity' => $h->severity,
            'likelihood' => $h->likelihood,
            'risk_rating' => $h->risk_rating,
            'description' => $h->description,
            'status' => $h->status,
            'assigned_to_id' => $h->assigned_to_user_id,
            'assigned_to_name' => $h->assignedTo?->name,
            'reported_by_name' => $h->reportedBy?->name,
            'due_date' => $h->due_date?->toDateString(),
            'created_at' => $h->created_at?->toISOString(),
            'worksafe' => $h->isWorksafeNotifiable(),
            'open_action_count' => (int) ($h->open_action_count ?? 0),
            'flags' => [
                'overdue' => $h->isOverdue(),
                'due_soon' => $h->isDueSoon(),
                'unassigned' => ! $h->assigned_to_user_id && in_array($h->status, ['open', 'in_progress']),
                'awaiting_closure' => $h->status === 'mitigated',
            ],
        ];
    }

    private function resolveDetail(Request $request, array $allowedSiteTypes, ?int $siteId): ?array
    {
        if (! $request->filled('hazard')) {
            return null;
        }

        $hazard = SiteHazard::query()
            ->with([
                'site:id,name,type',
                'reportedBy:id,name',
                'assignedTo:id,name',
                'statusChangedBy:id,name',
                'closedBy:id,name',
                'actions.assignedTo:id,name',
                'actions.completedBy:id,name',
            ])
            ->whereIn('site_id', $this->siteAccess->accessibleSiteIds(
                $request->user(),
                self::SITE_BYPASS_PERMISSIONS,
            ))
            ->find($request->query('hazard'));

        if (! $hazard
            || ! in_array($hazard->site?->type, $allowedSiteTypes, true)
            || ($siteId && $hazard->site_id !== $siteId)) {
            return null;
        }

        return HazardDetailPresenter::make($hazard, $this->permissions($request));
    }

    /* ====================================================================
     *  Create / edit  (deep-link fallbacks + modal store)
     * ==================================================================== */

    public function create(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        // The create flow is modal-first. This deep-link fallback opens the
        // create wizard on the scoped register (?action=add), so there is no
        // navigate-away full-page form for the primary path.
        return redirect()->to("/sites/{$site->id}/hazards?action=add");
    }

    private function legacyRecordRedirect(Request $request, ?Site $site = null)
    {
        if (! $request->filled('hazard')) {
            return null;
        }
        $request->validate(['hazard' => 'integer|min:1']);
        $this->validatePeriod($request);
        $this->assertRequestedSiteAccess($request, $site?->id);
        $detail = $this->resolveDetail($request, $this->allowedSiteTypes($request), $site?->id);
        abort_unless($detail, 404);
        $filters = array_filter($request->only(['q', 'tab', 'site_id', 'site_type', 'severity', 'risk_rating', 'assignee_id', 'due_state', 'from', 'to', 'page']), fn ($value) => is_scalar($value));
        $returnTo = $site ? "/sites/{$site->id}/hazards" : '/compliance/hazards';
        if ($filters) {
            $returnTo .= '?'.http_build_query($filters);
        }
        $query = ['return_to' => $returnTo];
        if (in_array($request->query('section'), ['overview', 'risk', 'actions', 'evidence', 'history', 'connections'], true)) {
            $query['section'] = $request->query('section');
        }
        $requestedAction = $request->query('action');
        $action = is_string($requestedAction) ? str_replace('_', '-', $requestedAction) : '';
        if (in_array($action, ['assign', 'start', 'mitigate', 'add-action', 'review', 'close'], true)) {
            $query['action'] = $action;
        }

        return redirect()->to('/hazards/'.$detail['id'].'?'.http_build_query($query));
    }

    public function show(Request $request, SiteHazard $hazard)
    {
        $this->authorizeHazardRead($request, $hazard->site);

        $hazard->load([
            'site:id,name,type',
            'reportedBy:id,name',
            'assignedTo:id,name',
            'statusChangedBy:id,name',
            'closedBy:id,name',
            'actions.assignedTo:id,name',
            'actions.completedBy:id,name',
        ]);

        return inertia('sites/hazards/show', [
            'detail' => HazardDetailPresenter::make($hazard, $this->permissions($request)),
        ]);
    }

    public function store(Request $request, Site $site)
    {
        $this->authorize('view', $site);
        abort_unless($request->user()->canDo('hazards.create'), 403);

        $validated = $request->validate([
            'hazard_type' => 'required|string|max:50',
            'custom_hazard_type' => 'nullable|string|max:100',
            'severity' => 'required|in:'.implode(',', SiteHazardRiskCalculator::severities()),
            'likelihood' => 'required|in:'.implode(',', SiteHazardRiskCalculator::likelihoods()),
            'description' => 'required|string',
            'location' => 'nullable|string|max:255',
            'witnesses' => 'nullable|string',
            'immediate_action_applied' => 'boolean',
            'immediate_action_taken' => [
                Rule::requiredIf(fn () => $request->boolean('immediate_action_applied')),
                'nullable',
                'string',
                'max:5000',
            ],
            'assigned_to_user_id' => 'nullable|exists:users,id',
            'due_date' => 'nullable|date',
            'photos' => 'nullable|array',
            'photos.*' => 'file|image|max:10240',
            'photo_paths' => 'prohibited',
        ]);

        $hazard = DB::transaction(function () use ($request, $site, $validated): SiteHazard {
            $site = $this->authorizeCurrentHazardCommand($request, (int) $site->id, ['hazards.create']);
            $this->assertAssignableStaff($request, $validated['assigned_to_user_id'] ?? null);
            $hazard = SiteHazard::create([
                'site_id' => $site->id,
                'reported_by_user_id' => $request->user()->id,
                'hazard_type' => $validated['hazard_type'],
                'custom_hazard_type' => $validated['custom_hazard_type'] ?? null,
                'severity' => $validated['severity'],
                'likelihood' => $validated['likelihood'],
                'description' => $validated['description'],
                'location' => $validated['location'] ?? null,
                'witnesses' => $validated['witnesses'] ?? null,
                'immediate_action_applied' => $request->boolean('immediate_action_applied'),
                'immediate_action_taken' => $validated['immediate_action_taken'] ?? null,
                'assigned_to_user_id' => $validated['assigned_to_user_id'] ?? null,
                'due_date' => $validated['due_date'] ?? null,
                'status' => 'open',
            ]);

            $paths = $this->storePaths($request->file('photos', []), $hazard->id, 'photos');
            if ($paths) {
                $hazard->forceFill(['photo_paths' => $paths])->saveQuietly();
            }

            return $hazard;
        }, attempts: 3);

        return back()->with('success', "Hazard {$hazard->reference_number} logged at {$site->name}.");
    }

    public function update(Request $request, SiteHazard $hazard)
    {
        $this->authorize('view', $hazard->site);
        abort_unless($request->user()->canDo('hazards.manage') || $request->user()->canDo('hazards.create'), 403);

        $validated = $request->validate([
            'description' => 'required|string',
            'severity' => 'required|in:'.implode(',', SiteHazardRiskCalculator::severities()),
            'likelihood' => 'required|in:'.implode(',', SiteHazardRiskCalculator::likelihoods()),
            'location' => 'nullable|string|max:255',
            'witnesses' => 'nullable|string',
        ]);

        DB::transaction(function () use ($request, $hazard, $validated): void {
            $lockedHazard = $this->lockHazardForCommand($request, $hazard, ['hazards.manage', 'hazards.create']);
            $this->assertHazardEditable($lockedHazard, 'description');
            $lockedHazard->update($validated);
        }, attempts: 3);

        return back()->with('success', 'Hazard updated.');
    }

    /* ====================================================================
     *  Lifecycle / workflow
     * ==================================================================== */

    public function assign(Request $request, SiteHazard $hazard)
    {
        $this->authorize('view', $hazard->site);
        $validated = $request->validate([
            'assigned_to_user_id' => 'required|integer|exists:users,id',
            'due_date' => 'nullable|date_format:Y-m-d',
            'expected_assigned_to_user_id' => 'sometimes|nullable|integer',
            'expected_due_date' => 'sometimes|nullable|date_format:Y-m-d',
        ]);
        $result = app(HazardAssignmentService::class)->assign(
            $request->user(), (int) $hazard->id, (int) $validated['assigned_to_user_id'],
            array_diff_key($validated, ['assigned_to_user_id' => true]),
        );

        return back()->with('success', 'Hazard assigned to '.$result['assignee_name'].'.');
    }

    public function transition(Request $request, SiteHazard $hazard)
    {
        $this->authorize('view', $hazard->site);

        $validated = $request->validate([
            'status' => 'required|in:in_progress,mitigated',
            'note' => 'nullable|string',
            'control_hierarchy' => 'nullable|array',
            'control_hierarchy.*' => 'string|in:elimination,substitution,isolation,engineering,administrative,ppe',
            'residual_severity' => 'nullable|in:'.implode(',', SiteHazardRiskCalculator::severities()),
            'residual_likelihood' => 'nullable|in:'.implode(',', SiteHazardRiskCalculator::likelihoods()),
        ]);

        $result = DB::transaction(function () use ($request, $hazard, $validated): array {
            $lockedHazard = $this->lockHazardForCommand($request, $hazard);
            $from = $lockedHazard->status;
            $to = $validated['status'];
            $valid = ($to === 'in_progress' && $from === 'open')
                || ($to === 'mitigated' && $from === 'in_progress');
            if (! $valid) {
                return ['success' => false, 'message' => "A {$from} hazard can't be moved to ".str_replace('_', ' ', $to).'.'];
            }
            $patch = ['status' => $to];
            if ($to === 'mitigated') {
                if (empty($validated['control_hierarchy'])) {
                    return ['success' => false, 'message' => 'Select at least one control from the hierarchy.'];
                }
                if (empty($validated['residual_severity']) || empty($validated['residual_likelihood'])) {
                    return ['success' => false, 'message' => 'Set the residual severity and likelihood.'];
                }
                $patch['control_hierarchy'] = array_values(array_unique($validated['control_hierarchy']));
                $patch['residual_severity'] = $validated['residual_severity'];
                $patch['residual_likelihood'] = $validated['residual_likelihood'];
                $patch['residual_risk_rating'] = $this->riskCalculator->calculate($validated['residual_severity'], $validated['residual_likelihood']);
                $patch['control_review_date'] = now(config('app.worker_timezone', 'Pacific/Auckland'))->addMonths(3)->toDateString();
            }
            $lockedHazard->update($patch);
            if (filled($validated['note'] ?? null)) {
                AuditLogger::logOrFail('hazard.progress_note', $lockedHazard, ['note' => $validated['note'], 'status' => $to]);
            }
            $label = $to === 'mitigated' ? 'Mitigated · residual '.Str::title($patch['residual_risk_rating']) : 'In progress';

            return ['success' => true, 'message' => "Hazard moved to {$label}."];
        }, attempts: 3);

        return back()->with($result['success'] ? 'success' : 'error', $result['message']);
    }

    public function review(Request $request, SiteHazard $hazard)
    {
        $this->authorize('view', $hazard->site);

        $validated = $request->validate(['note' => 'required|string']);

        DB::transaction(function () use ($request, $hazard, $validated): void {
            $lockedHazard = $this->lockHazardForCommand($request, $hazard);
            $this->assertHazardEditable($lockedHazard, 'note');
            $reviewDate = now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();
            $lockedHazard->forceFill(['review_date' => $reviewDate, 'control_review_date' => $reviewDate])->saveQuietly();
            AuditLogger::logOrFail('hazard.reviewed', $lockedHazard, ['note' => $validated['note'], 'review_date' => $reviewDate]);
        }, attempts: 3);

        return back()->with('success', 'Review recorded.');
    }

    public function close(Request $request, SiteHazard $hazard)
    {
        abort_unless(
            $hazard->site
                && Gate::forUser($request->user())->allows('view', $hazard->site),
            404,
        );

        $validated = $request->validate([
            'resolution_summary' => 'required|string',
            'resolution_evidence' => 'nullable|array',
            'resolution_evidence.*' => 'file|max:10240',
        ]);

        $result = DB::transaction(function () use ($request, $hazard, $validated): array {
            $lockedHazard = $this->lockHazardForCommand($request, $hazard, ['hazards.close']);

            if ($lockedHazard->status === 'closed') {
                return ['closed' => false, 'message' => 'This hazard is already closed.'];
            }

            if ($lockedHazard->actions()->where('status', '!=', 'completed')->exists()) {
                return ['closed' => false, 'message' => 'Complete all corrective actions before closing this hazard.'];
            }

            $evidence = $this->storeFilesWithMeta(
                $request->file('resolution_evidence', []),
                $lockedHazard->id,
                'resolution',
            );

            // Observer stamps closed_at / closed_by + status_changed audit on
            // the status → closed transition.
            $lockedHazard->update([
                'resolution_summary' => $validated['resolution_summary'],
                'resolution_evidence' => array_values(array_merge(HazardDetailPresenter::normaliseFiles($lockedHazard->resolution_evidence ?? []), $evidence)),
                'status' => 'closed',
            ]);

            return [
                'closed' => true,
                'message' => "Hazard {$lockedHazard->reference_number} closed.",
            ];
        }, attempts: 3);

        return back()->with($result['closed'] ? 'success' : 'error', $result['message']);
    }

    public function storeAction(Request $request, SiteHazard $hazard)
    {
        abort_unless(
            $hazard->site
                && Gate::forUser($request->user())->allows('view', $hazard->site),
            404,
        );

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'action_type' => [
                'nullable',
                'string',
                'max:50',
                Rule::notIn([SiteChecklistFailureRiskMapper::REQUIRED_ESCALATION_ACTION]),
            ],
            'assigned_to_user_id' => 'nullable|exists:users,id',
            'due_date' => 'nullable|date',
        ]);

        $action = DB::transaction(function () use ($request, $hazard, $validated): SiteHazardAction {
            $lockedHazard = $this->lockHazardForCommand($request, $hazard);

            $this->assertAssignableStaff($request, $validated['assigned_to_user_id'] ?? null);

            if ($lockedHazard->status === 'closed') {
                throw ValidationException::withMessages(['title' => 'This hazard is closed. No new corrective actions can be added.']);
            }

            $action = SiteHazardAction::create([
                'hazard_id' => $lockedHazard->id,
                'reference_number' => $this->nextActionReference(),
                'action_description' => $validated['title'],
                'action_type' => $validated['action_type'] ?? null,
                'status' => 'pending',
                'assigned_to_user_id' => $validated['assigned_to_user_id'] ?? null,
                'due_date' => $validated['due_date'] ?? null,
            ]);
            AuditLogger::logOrFail('hazard.action_added', $lockedHazard, [
                'action_id' => $action->id, 'reference_number' => $action->reference_number,
                'action_description' => $action->action_description,
                'assigned_to_user_id' => $action->assigned_to_user_id,
                'due_date' => $action->due_date?->toDateString(),
            ]);

            return $action;
        }, attempts: 3);

        return back()->with('success', "Corrective action {$action->reference_number} added.");
    }

    public function completeAction(Request $request, SiteHazardAction $action)
    {
        $action->loadMissing('hazard.site');
        abort_unless(
            $action->hazard
                && $action->hazard->site
                && Gate::forUser($request->user())->allows('view', $action->hazard->site),
            404,
        );

        $validated = $request->validate(['completion_notes' => 'nullable|string']);

        $replayed = DB::transaction(function () use ($request, $action, $validated): bool {
            $hazardId = SiteHazardAction::query()
                ->whereKey($action->getKey())
                ->value('hazard_id');
            abort_unless($hazardId, 404);

            $lockedHazard = $this->lockHazardForCommand($request, SiteHazard::findOrFail($hazardId));

            $lockedAction = SiteHazardAction::query()
                ->whereKey($action->getKey())
                ->where('hazard_id', $lockedHazard->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($lockedAction->status === 'completed') {
                return true;
            }

            $lockedAction->update([
                'status' => 'completed',
                'completed_at' => now(),
                'completed_by_user_id' => $request->user()->id,
                'completion_notes' => $validated['completion_notes'] ?? null,
            ]);

            return false;
        }, attempts: 3);

        return back()->with(
            'success',
            $replayed ? 'Corrective action already completed.' : 'Corrective action completed.',
        );
    }

    public function media(Request $request, SiteHazard $hazard)
    {
        $this->authorize('view', $hazard->site);

        $request->validate([
            'file' => 'required|file|max:10240',
            'kind' => 'nullable|in:photo,document',
        ]);

        DB::transaction(function () use ($request, $hazard): void {
            $lockedHazard = $this->lockHazardForCommand($request, $hazard);
            $this->assertHazardEditable($lockedHazard, 'file');
            $file = $request->file('file');
            $isPhoto = $request->input('kind') === 'photo'
                || ($request->input('kind') !== 'document' && str_starts_with((string) $file->getMimeType(), 'image/'));
            if ($isPhoto) {
                $paths = $this->storePaths([$file], $lockedHazard->id, 'photos');
                $lockedHazard->forceFill(['photo_paths' => array_values(array_merge($lockedHazard->photo_paths ?? [], $paths))])->saveQuietly();
            } else {
                $docs = $this->storeFilesWithMeta([$file], $lockedHazard->id, 'documents');
                $lockedHazard->forceFill(['document_paths' => array_values(array_merge(HazardDetailPresenter::normaliseFiles($lockedHazard->document_paths ?? []), $docs))])->saveQuietly();
            }
            AuditLogger::logOrFail('hazard.evidence_added', $lockedHazard, [
                'kind' => $isPhoto ? 'photo' : 'document', 'names' => [$file->getClientOriginalName()], 'count' => 1,
            ]);
        }, attempts: 3);

        return back()->with('success', 'Evidence uploaded.');
    }

    /* ====================================================================
     *  Helpers
     * ==================================================================== */

    /** @return array{manage:bool, assign:bool, close:bool, create:bool} */
    private function permissions(Request $request): array
    {
        $user = $request->user();
        // Commands retain the existing Site-profile gate as well as their
        // own hazard permission; read-only H&S admission does not add writes.
        $canUseSiteCommands = (bool) $user?->canDo('sites.viewAny');

        return [
            'manage' => $canUseSiteCommands && (bool) $user?->canDo('hazards.manage'),
            'assign' => $canUseSiteCommands && (bool) $user?->canDo('hazards.assign'),
            'close' => $canUseSiteCommands && (bool) $user?->canDo('hazards.close'),
            'create' => $canUseSiteCommands && (bool) $user?->canDo('hazards.create'),
        ];
    }

    /** Reload and authorize the canonical source while serializing competing writes. */
    private function lockHazardForCommand(Request $request, SiteHazard $hazard, array $permissions = ['hazards.manage']): SiteHazard
    {
        $lockedHazard = SiteHazard::query()->whereKey($hazard->getKey())->lockForUpdate()->firstOrFail();
        $lockedHazard->setRelation('site', $this->authorizeCurrentHazardCommand($request, (int) $lockedHazard->site_id, $permissions));

        return $lockedHazard;
    }

    private function authorizeCurrentHazardCommand(Request $request, int $siteId, array $permissions): Site
    {
        return CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($request, $siteId, $permissions): Site {
            $actor = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($request->user(), [
                ...$permissions, 'sites.viewAny', 'sites.viewAll', 'sites.type.house.view', 'sites.type.facility.view', 'sites.type.head_office.view',
            ]);
            $site = $reads->query(Site::query())->find($siteId);
            abort_unless($site && in_array($siteId, $this->siteAccess->accessibleSiteIds($actor, self::SITE_BYPASS_PERMISSIONS, $reads), true), 404);
            abort_unless(in_array($site->type, app(SiteTypeAccessService::class)->allowedTypes($actor), true), 404);
            Gate::forUser($actor)->authorize('view', $site);
            abort_unless(collect($permissions)->contains(fn (string $permission) => $actor->canDo($permission)), 403);

            return $site;
        });
    }

    private function assertHazardEditable(SiteHazard $hazard, string $field): void
    {
        if ($hazard->status === 'closed') {
            throw ValidationException::withMessages([$field => 'This hazard is closed. Its recorded details cannot be changed.']);
        }
    }

    private function assertAssignableStaff(Request $request, ?int $staffId): void
    {
        if ($staffId === null) {
            return;
        }
        $candidate = CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($request, $staffId): ?User {
            $actor = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($request->user(), ['sites.viewAll']);

            return app(HazardAssignmentService::class)->eligibleStaff($actor, $staffId, $reads);
        });
        if (! $candidate) {
            throw ValidationException::withMessages(['assigned_to_user_id' => 'Choose current staff from your approved sites.']);
        }
    }

    private function nextActionReference(): string
    {
        // HZA (hazard action) — race-safe via the central allocator, and a
        // prefix distinct from the H&S corrective-action register's CA-YYYY-NNNN.
        // Pre-2026-07 rows keep their legacy CA-NNNN references.
        return app(ReferenceNumberGenerator::class)->next('HZA');
    }

    /**
     * Store images and return relative storage paths (string[]).
     *
     * @param  array<int,UploadedFile|null>  $files
     * @return array<int,string>
     */
    private function storePaths(array $files, int $hazardId, string $sub): array
    {
        $paths = [];
        foreach (array_filter($files) as $file) {
            $paths[] = $file->store("hazards/{$hazardId}/{$sub}", 'private');
        }

        return $paths;
    }

    /**
     * Store files and return {name, path, size} metadata objects.
     *
     * @param  array<int,UploadedFile|null>  $files
     * @return array<int,array{name:string, path:string, size:int}>
     */
    private function storeFilesWithMeta(array $files, int $hazardId, string $sub): array
    {
        $out = [];
        foreach (array_filter($files) as $file) {
            $out[] = [
                'name' => $file->getClientOriginalName(),
                'path' => $file->store("hazards/{$hazardId}/{$sub}", 'private'),
                'size' => $file->getSize(),
            ];
        }

        return $out;
    }

    /**
     * Stream a hazard photo (photo_paths[index]) or document (document_paths[index])
     * through this authenticated, hazards.view-gated route — hazard evidence is no
     * longer reachable at a public /storage/... URL. Hazard media has no per-file disk
     * column, so the disk is resolved by existence: new uploads live on the private
     * disk; any legacy file still on the public disk keeps serving until the backfill
     * relocates it. nosniff + CSP sandbox come from ServesPrivateAttachments.
     */
    public function showMedia(SiteHazard $hazard, string $kind, int $index): StreamedResponse
    {
        $this->authorizeHazardRead(request(), $hazard->site);

        if ($kind === 'photo') {
            $path = array_values($hazard->photo_paths ?? [])[$index] ?? null;
            $name = is_string($path) ? basename($path) : null;
        } else {
            // 'document' → document_paths; 'resolution' → resolution_evidence. Both are
            // normalised identically to the presenter so the [index] lines up.
            $source = $kind === 'resolution' ? $hazard->resolution_evidence : $hazard->document_paths;
            $doc = HazardDetailPresenter::normaliseFiles($source ?? [])[$index] ?? null;
            $path = is_array($doc) ? ($doc['path'] ?? null) : null;
            $name = is_array($doc) ? ($doc['name'] ?? (is_string($path) ? basename($path) : null)) : null;
        }

        abort_unless(is_string($path) && $path !== '', 404);

        // New uploads are 'private'; legacy evidence may still be on 'public' pre-backfill.
        $disk = collect(['private', 'public'])->first(fn (string $d) => Storage::disk($d)->exists($path)) ?? 'private';

        return $this->streamPrivateAttachment($disk, $path, $name ?: 'attachment');
    }
}
