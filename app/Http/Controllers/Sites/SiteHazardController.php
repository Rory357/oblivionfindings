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
use App\Services\Sites\SiteHazardRiskCalculator;
use App\Services\UserSiteAccessService;
use App\Support\HazardComplianceSnapshot;
use App\Support\HazardDetailPresenter;
use App\Support\SiteRecommendedHazards;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SiteHazardController extends Controller
{
    use ResolvesAllowedSiteTypes;
    use ServesPrivateAttachments;

    private const TABS = ['all', 'open', 'in_progress', 'overdue', 'critical', 'closed'];

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
        $this->authorize('viewAny', Site::class);

        return inertia('compliance/hazards/index', $this->registerProps($request, null));
    }

    /** Streamed CSV of the current filtered/tab set (the hero's Export CSV). */
    public function export(Request $request)
    {
        abort_unless($request->user()?->canDo('hazards.view'), 403);
        $this->authorize('viewAny', Site::class);

        $allowedSiteTypes = $this->allowedSiteTypes($request);
        $this->assertRequestedSiteAccess($request, null);
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'all';

        $query = $this->baseQuery($request, $allowedSiteTypes, null);
        $this->applyTab($query, $tab);

        $rows = $query->with(['site:id,name,type', 'assignedTo:id,name'])
            ->orderByDesc('created_at')
            ->limit(5000)
            ->get();

        $handle = fopen('php://temp', 'r+');
        $this->putCsv($handle, ['Reference', 'Site', 'Site type', 'Hazard', 'Severity', 'Likelihood', 'Risk', 'Status', 'Assigned to', 'Due', 'Logged']);
        foreach ($rows as $h) {
            $this->putCsv($handle, [
                $h->reference_number,
                $h->site?->name,
                $h->site?->type,
                HazardDetailPresenter::hazardLabel($h),
                $h->severity,
                $h->likelihood,
                $h->risk_rating,
                $h->status,
                $h->assignedTo?->name,
                $h->due_date?->toDateString(),
                $h->created_at?->toDateString(),
            ]);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="hazards-' . now()->format('Y-m-d') . '.csv"',
        ]);
    }

    public function index(Request $request, Site $site)
    {
        $this->authorize('view', $site);

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
        $allowedSiteTypes = $this->allowedSiteTypes($request);
        $siteId = $site?->id;
        $this->assertRequestedSiteAccess($request, $siteId);

        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'all';

        // Fresh filtered base query each call so counts can chain different scopes.
        $base = fn (): Builder => $this->baseQuery($request, $allowedSiteTypes, $siteId);

        $tabCounts = [
            'all' => $base()->count(),
            'open' => $base()->where('status', 'open')->count(),
            'in_progress' => $base()->where('status', 'in_progress')->count(),
            'overdue' => $base()->overdue()->count(),
            'critical' => $base()->criticalOpen()->count(),
            'closed' => $base()->whereIn('status', ['mitigated', 'closed'])->count(),
        ];

        $hero = [
            'live' => [
                'open' => $tabCounts['open'],
                'in_progress' => $tabCounts['in_progress'],
                'overdue' => $tabCounts['overdue'],
                'critical' => $tabCounts['critical'],
            ],
            'attention' => [
                'due_soon' => $base()->dueSoon()->count(),
                'unassigned' => $base()->unassignedOpen()->count(),
                'mitigated' => $base()->where('status', 'mitigated')->count(),
                'closed_period' => $base()->where('status', 'closed')->count(),
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
            'nzBadges' => HazardComplianceSnapshot::badges(),
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

        return SiteHazard::query()
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
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->query('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->query('to')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%' . $request->query('q') . '%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('reference_number', 'like', $term)
                        ->orWhere('description', 'like', $term)
                        ->orWhere('hazard_type', 'like', $term)
                        ->orWhere('custom_hazard_type', 'like', $term)
                        ->orWhere('location', 'like', $term)
                        ->orWhereHas('site', fn ($s) => $s->where('name', 'like', $term));
                });
            });
    }

    private function assertRequestedSiteAccess(Request $request, ?int $boundSiteId): void
    {
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
            'open' => $query->where('status', 'open'),
            'in_progress' => $query->where('status', 'in_progress'),
            'overdue' => $query->overdue(),
            'critical' => $query->criticalOpen(),
            'closed' => $query->whereIn('status', ['mitigated', 'closed']),
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
            'created_at' => $h->created_at?->toDateTimeString(),
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

    public function show(SiteHazard $hazard)
    {
        $this->authorize('view', $hazard->site);

        $hazard->load([
            'site:id,name,type',
            'reportedBy:id,name',
            'assignedTo:id,name',
            'actions.assignedTo:id,name',
            'actions.completedBy:id,name',
        ]);

        $users = User::query()
            ->select(['id', 'name'])
            ->orderBy('name');
        $this->siteAccess->applyStaffScope(
            $users,
            auth()->user(),
            self::SITE_BYPASS_PERMISSIONS,
        );

        return inertia('sites/hazards/show', [
            'hazard' => $hazard,
            'users' => $users->get(),
            'canAssign' => auth()->user()->canDo('hazards.assign'),
            'canClose' => auth()->user()->canDo('hazards.close'),
        ]);
    }

    public function store(Request $request, Site $site)
    {
        $this->authorize('view', $site);
        abort_unless($request->user()->canDo('hazards.create'), 403);

        $validated = $request->validate([
            'hazard_type' => 'required|string|max:50',
            'custom_hazard_type' => 'nullable|string|max:100',
            'severity' => 'required|in:' . implode(',', SiteHazardRiskCalculator::severities()),
            'likelihood' => 'required|in:' . implode(',', SiteHazardRiskCalculator::likelihoods()),
            'description' => 'required|string',
            'location' => 'nullable|string|max:255',
            'witnesses' => 'nullable|string',
            'immediate_action_applied' => 'boolean',
            'immediate_action_taken' => 'nullable|string',
            'assigned_to_user_id' => 'nullable|exists:users,id',
            'due_date' => 'nullable|date',
            'photos' => 'nullable|array',
            'photos.*' => 'file|image|max:10240',
            'photo_paths' => 'nullable|array',
        ]);

        // The observer fills reference_number, risk_rating, due_date, the H&S
        // officer auto-assignment, the HsEvent and the Control-Room bridge.
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
        if (! $paths && ! empty($validated['photo_paths'])) {
            $paths = array_values($validated['photo_paths']);
        }
        if ($paths) {
            $hazard->forceFill(['photo_paths' => $paths])->saveQuietly();
        }

        return back()->with('success', "Hazard {$hazard->reference_number} logged at {$site->name}.");
    }

    public function update(Request $request, SiteHazard $hazard)
    {
        $this->authorize('view', $hazard->site);
        abort_unless($request->user()->canDo('hazards.manage') || $request->user()->canDo('hazards.create'), 403);

        $validated = $request->validate([
            'description' => 'required|string',
            'severity' => 'required|in:' . implode(',', SiteHazardRiskCalculator::severities()),
            'likelihood' => 'required|in:' . implode(',', SiteHazardRiskCalculator::likelihoods()),
            'location' => 'nullable|string|max:255',
            'witnesses' => 'nullable|string',
        ]);

        // Observer recomputes risk_rating from severity/likelihood on update.
        $hazard->update($validated);

        return back()->with('success', 'Hazard updated.');
    }

    /* ====================================================================
     *  Lifecycle / workflow
     * ==================================================================== */

    public function assign(Request $request, SiteHazard $hazard)
    {
        $this->authorize('view', $hazard->site);

        $validated = $request->validate([
            'assigned_to_user_id' => 'required|exists:users,id',
            'due_date' => 'nullable|date',
        ]);

        // Observer notifies the assignee + stamps assigned_at.
        $hazard->update([
            'assigned_to_user_id' => $validated['assigned_to_user_id'],
            'due_date' => $validated['due_date'] ?? $hazard->due_date,
        ]);

        $assignee = User::find($validated['assigned_to_user_id']);
        AuditLogger::log('hazard.assigned', $hazard, [
            'assignee_id' => $validated['assigned_to_user_id'],
            'assignee_name' => $assignee?->name,
        ]);

        return back()->with('success', 'Hazard assigned to ' . ($assignee?->name ?? 'owner') . '.');
    }

    public function transition(Request $request, SiteHazard $hazard)
    {
        $this->authorize('view', $hazard->site);

        $validated = $request->validate([
            'status' => 'required|in:in_progress,mitigated',
            'note' => 'nullable|string',
            'control_hierarchy' => 'nullable|array',
            'control_hierarchy.*' => 'string',
            'residual_severity' => 'nullable|in:' . implode(',', SiteHazardRiskCalculator::severities()),
            'residual_likelihood' => 'nullable|in:' . implode(',', SiteHazardRiskCalculator::likelihoods()),
        ]);

        $from = $hazard->status;
        $to = $validated['status'];

        $valid = ($to === 'in_progress' && $from === 'open')
            || ($to === 'mitigated' && $from === 'in_progress');

        if (! $valid) {
            return back()->with('error', "A {$from} hazard can't be moved to " . str_replace('_', ' ', $to) . '.');
        }

        $patch = ['status' => $to];

        if ($to === 'mitigated') {
            if (empty($validated['control_hierarchy'])) {
                return back()->with('error', 'Select at least one control from the hierarchy.');
            }
            if (empty($validated['residual_severity']) || empty($validated['residual_likelihood'])) {
                return back()->with('error', 'Set the residual severity and likelihood.');
            }
            $patch['control_hierarchy'] = array_values($validated['control_hierarchy']);
            $patch['residual_severity'] = $validated['residual_severity'];
            $patch['residual_likelihood'] = $validated['residual_likelihood'];
            $patch['residual_risk_rating'] = $this->riskCalculator->calculate(
                $validated['residual_severity'],
                $validated['residual_likelihood']
            );
            $patch['control_review_date'] = now()->addMonths(3);
        }

        // Observer stamps status_changed_at / status_changed_by + audit log.
        $hazard->update($patch);

        $label = $to === 'mitigated' ? 'Mitigated · residual ' . Str::title($patch['residual_risk_rating']) : 'In progress';

        return back()->with('success', "Hazard moved to {$label}.");
    }

    public function review(Request $request, SiteHazard $hazard)
    {
        $this->authorize('view', $hazard->site);

        $validated = $request->validate(['note' => 'required|string']);

        $hazard->forceFill([
            'review_date' => now(),
            'control_review_date' => now(),
        ])->saveQuietly();

        AuditLogger::log('hazard.reviewed', $hazard, ['note' => $validated['note']]);

        return back()->with('success', 'Review recorded.');
    }

    public function close(Request $request, SiteHazard $hazard)
    {
        $this->authorize('view', $hazard->site);

        if ($hazard->status === 'closed') {
            return back()->with('error', 'This hazard is already closed.');
        }

        $validated = $request->validate([
            'resolution_summary' => 'required|string',
            'resolution_evidence' => 'nullable|array',
            'resolution_evidence.*' => 'file|max:10240',
        ]);

        $evidence = $this->storeFilesWithMeta($request->file('resolution_evidence', []), $hazard->id, 'resolution');

        // Observer stamps closed_at / closed_by + status_changed audit on the
        // status → closed transition.
        $hazard->update([
            'resolution_summary' => $validated['resolution_summary'],
            'resolution_evidence' => $evidence ?: ($hazard->resolution_evidence ?? []),
            'status' => 'closed',
        ]);

        return back()->with('success', "Hazard {$hazard->reference_number} closed.");
    }

    public function storeAction(Request $request, SiteHazard $hazard)
    {
        $this->authorize('view', $hazard->site);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'action_type' => 'nullable|string|max:50',
            'assigned_to_user_id' => 'nullable|exists:users,id',
            'due_date' => 'nullable|date',
        ]);

        $action = SiteHazardAction::create([
            'hazard_id' => $hazard->id,
            'reference_number' => $this->nextActionReference(),
            'action_description' => $validated['title'],
            'action_type' => $validated['action_type'] ?? null,
            'status' => 'pending',
            'assigned_to_user_id' => $validated['assigned_to_user_id'] ?? null,
            'due_date' => $validated['due_date'] ?? null,
        ]);

        return back()->with('success', "Corrective action {$action->reference_number} added.");
    }

    public function completeAction(Request $request, SiteHazardAction $action)
    {
        $action->loadMissing('hazard.site');
        $this->authorize('view', $action->hazard->site);

        $validated = $request->validate(['completion_notes' => 'nullable|string']);

        $action->update([
            'status' => 'completed',
            'completed_at' => now(),
            'completed_by_user_id' => $request->user()->id,
            'completion_notes' => $validated['completion_notes'] ?? null,
        ]);

        return back()->with('success', 'Corrective action completed.');
    }

    public function media(Request $request, SiteHazard $hazard)
    {
        $this->authorize('view', $hazard->site);

        $request->validate([
            'file' => 'required|file|max:10240',
            'kind' => 'nullable|in:photo,document',
        ]);

        $file = $request->file('file');
        // The Evidence section's two uploaders post only `file`; infer the
        // bucket from the mime type (image → photo, otherwise document), with
        // an optional explicit `kind` override.
        $isPhoto = $request->input('kind') === 'photo'
            || ($request->input('kind') !== 'document' && str_starts_with((string) $file->getMimeType(), 'image/'));

        if ($isPhoto) {
            $paths = $this->storePaths([$file], $hazard->id, 'photos');
            $hazard->forceFill([
                'photo_paths' => array_values(array_merge($hazard->photo_paths ?? [], $paths)),
            ])->saveQuietly();
        } else {
            $docs = $this->storeFilesWithMeta([$request->file('file')], $hazard->id, 'documents');
            $hazard->forceFill([
                'document_paths' => array_values(array_merge(HazardDetailPresenter::normaliseFiles($hazard->document_paths ?? []), $docs)),
            ])->saveQuietly();
        }

        return back()->with('success', 'Evidence uploaded.');
    }

    /* ====================================================================
     *  Helpers
     * ==================================================================== */

    /** @return array{manage:bool, assign:bool, close:bool, create:bool} */
    private function permissions(Request $request): array
    {
        $user = $request->user();

        return [
            'manage' => (bool) $user?->canDo('hazards.manage'),
            'assign' => (bool) $user?->canDo('hazards.assign'),
            'close' => (bool) $user?->canDo('hazards.close'),
            'create' => (bool) $user?->canDo('hazards.create'),
        ];
    }

    private function nextActionReference(): string
    {
        // HZA (hazard action) — race-safe via the central allocator, and a
        // prefix distinct from the H&S corrective-action register's CA-YYYY-NNNN.
        // Pre-2026-07 rows keep their legacy CA-NNNN references.
        return app(\App\Services\References\ReferenceNumberGenerator::class)->next('HZA');
    }

    /**
     * Store images and return relative storage paths (string[]).
     *
     * @param  array<int,\Illuminate\Http\UploadedFile|null>  $files
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
     * @param  array<int,\Illuminate\Http\UploadedFile|null>  $files
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
        $this->authorize('view', $hazard->site);

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
