<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Controller;
use App\Http\Requests\HealthSafety\ActivateHsRiskAssessmentRequest;
use App\Http\Requests\HealthSafety\CompleteHsRiskAssessmentReviewRequest;
use App\Http\Requests\HealthSafety\StoreHsRiskAssessmentRequest;
use App\Http\Requests\HealthSafety\SupersedeHsRiskAssessmentRequest;
use App\Http\Requests\HealthSafety\UpdateHsRiskAssessmentRequest;
use App\Http\Requests\HealthSafety\UpdateResidualRiskRequest;
use App\Models\Client;
use App\Models\HsEvent;
use App\Models\HsRiskAssessment;
use App\Models\HsRiskAssessmentAttachment;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HsEventAccessService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\HsRiskAssessmentService;
use App\Services\UserSiteAccessService;
use App\Support\HealthSafety\RiskAssessmentPresenter;
use App\Support\HealthSafety\RiskAssessmentReturn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Risk Assessments register (`/health-safety/risk-assessments`) — H&S gold standard.
 * Read (index + attachment download) gated by `hazards.view`; every write gated by
 * `hazards.manage`. Lifecycle is delegated verbatim to HsRiskAssessmentService.
 */
class HsRiskAssessmentController extends Controller
{
    use ServesPrivateAttachments;

    public function __construct(
        private readonly HsRiskAssessmentService $service,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /* ====================================================================== */
    /*  Register */
    /* ====================================================================== */

    public function index(Request $request): Response|RedirectResponse
    {
        $payload = RiskAssessmentPresenter::materialise($request->user(), null, function (User $current, $reads, HealthSafetyReadContext $context) use ($request): array {
            $request = clone $request; $request->setUserResolver(fn () => $current);
            return $this->indexSnapshot($request, $context);
        });
        return isset($payload['redirect']) ? redirect($payload['redirect']) : Inertia::render('health-safety/risk-assessments/index', $payload);
    }

    private function indexSnapshot(Request $request, HealthSafetyReadContext $context): array
    {
        $canManage = (bool) ($request->user()?->canDo('hazards.manage') ?? false);
        if ($request->has('assessment')) {
            $id = $request->input('assessment');
            abort_unless(is_string($id) && preg_match('/^[1-9]\d*$/D', $id), 404);
            $assessment = $this->identity($id);
            $this->assertCanAccess($request, $assessment, $context ?? null);

            return ['redirect' => RiskAssessmentReturn::href((int) $assessment->id, RiskAssessmentReturn::fromRegister($request->query()))];
        }

        $filters = [
            'tab' => $request->input('tab', 'all'),
            'status' => $request->input('status'),
            'risk_level' => $request->input('risk_level'),
            'due_for_review' => $request->input('due_for_review'),
            'risk_acceptable' => $request->input('risk_acceptable'),
            'site_id' => $request->filled('site_id') ? (int) $request->input('site_id') : null,
            'client_id' => $request->filled('client_id') ? (int) $request->input('client_id') : null,
            'hs_event_id' => $request->filled('hs_event_id') ? (int) $request->input('hs_event_id') : null,
            'search' => $request->input('search'),
        ];

        // Tab counts and hero share every current facet, excluding only the active tab.
        $this->assertAccessibleFilters($request, $filters, $context);

        $base = function () use ($filters, $request, $context): Builder {
            $query = $this->applyScope(HsRiskAssessment::query(), $filters, $request, $context);
            $this->applyFacets($query, $filters);

            return $query;
        };

        $tabCounts = [
            'all' => (clone $base())->count(),
            'active' => (clone $base())->active()->count(),
            'drafts' => (clone $base())->where('status', HsRiskAssessment::STATUS_DRAFT)->count(),
            'due' => (clone $base())->dueForReview()->count(),
            'high' => (clone $base())->highOrExtreme()->count(),
            'closed' => (clone $base())->whereIn('status', [HsRiskAssessment::STATUS_SUPERSEDED, HsRiskAssessment::STATUS_ARCHIVED])->count(),
        ];

        $hero = $this->buildHero($base);

        // Displayed list — scope + tab + facet filters.
        $list = $this->applyScope(HsRiskAssessment::query(), $filters, $request, $context);
        $this->applyTab($list, (string) $filters['tab']);
        $this->applyFacets($list, $filters);

        $assessments = $list
            ->with(['assessedBy:id,name', 'assessable', 'hsEvent:id,reference_number'])
            ->withCount('attachments')
            ->tap(fn (Builder $query) => RiskAssessmentPresenter::guardEagerQuery($query, $request->user(), $context))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (HsRiskAssessment $ra) => RiskAssessmentPresenter::row($ra, $request->user(), $context, $list))->toArray();

        return [
            'assessments' => $assessments,
            'tabCounts' => $tabCounts,
            'hero' => $hero,
            'detail' => null,
            'pickers' => RiskAssessmentPresenter::pickerRowsInContext($request->user(),
                $this->siteAccess->accessibleSiteIds($request->user(), $this->bypassPermissions()), $context),
            'can' => [
                'manage' => $canManage,
                'viewReports' => (bool) ($request->user()?->canDo('governance.view') ?? false),
            ],
            'filters' => $filters, 'source_scope' => 'readable_subset', 'source_completeness' => 'unknown',
        ];
    }

    /** Permanent record page; explicit JSON remains available to embedded consumers. */
    public function show(Request $request, $assessment): Response|JsonResponse
    {
        $assessment = $this->identity($assessment);
        $payload = RiskAssessmentPresenter::materialise($request->user(), null, function (User $current, $reads, HealthSafetyReadContext $context) use ($request, $assessment): array {
            $request = clone $request; $request->setUserResolver(fn () => $current);
            return $this->showSnapshot($request, $assessment, $context);
        });
        return $request->expectsJson() ? response()->json(['detail' => $payload['detail']]) : Inertia::render('health-safety/risk-assessments/show', $payload);
    }

    private function showSnapshot(Request $request, HsRiskAssessment $assessment, HealthSafetyReadContext $context): array
    {
        $this->assertCanAccess($request, $assessment, $context ?? null);
        $canManage = (bool) ($request->user()?->canDo('hazards.manage') ?? false);
        $returnTo = RiskAssessmentReturn::sanitize($request->query('return_to')) ?? RiskAssessmentReturn::REGISTER;
        $detail = RiskAssessmentPresenter::detail($assessment, $canManage, $request->user(), $returnTo, $context);
        return [
            'detail' => $detail, 'return_to' => $returnTo,
            'pickers' => RiskAssessmentPresenter::pickerRowsInContext($request->user(),
                $this->siteAccess->accessibleSiteIds($request->user(), $this->bypassPermissions()), $context),
        ];
    }

    /* ====================================================================== */
    /*  Lifecycle write actions (all → HsRiskAssessmentService, ->back()) */
    /* ====================================================================== */

    public function store(StoreHsRiskAssessmentRequest $request): RedirectResponse
    {
        $receipt = $this->service->withinCommandContext($request->user(), function (User $current, $reads, HealthSafetyReadContext $context) use ($request): array {
            $created = $this->service->create($this->mapAssessable($request->validated()), $current, $context);
            return ['id' => $created->id];
        });

        return back()->with('success', 'Risk assessment created as a draft.')
            ->with('created_risk_assessment_id', $receipt['id']);
    }

    public function update(UpdateHsRiskAssessmentRequest $request, $assessment): RedirectResponse
    {
        $assessment = $this->identity($assessment);
        $this->assertCanAccess($request, $assessment, $context ?? null);
        try {
            $this->service->withinCommandContext($request->user(), function (User $current, $reads, HealthSafetyReadContext $context) use ($request, $assessment): array {
                $result = $this->service->updateDraft($assessment, $this->mapAssessable($request->validated()), $current, $context);
                return ['id' => $result->id];
            });
        } catch (\InvalidArgumentException $error) {
            return back()->with('error', $error->getMessage());
        }

        return back()->with('success', 'Draft updated.');
    }

    public function activate(ActivateHsRiskAssessmentRequest $request, $assessment): RedirectResponse
    {
        $assessment = $this->identity($assessment);
        $this->assertCanAccess($request, $assessment, $context ?? null);
        try {
            $this->service->withinCommandContext($request->user(), function (User $current, $reads, HealthSafetyReadContext $context) use ($request, $assessment): array {
                $result = $this->service->activate($assessment, $request->validated(), $current, $context);
                return ['id' => $result->id];
            });
        } catch (\InvalidArgumentException $error) {
            return back()->with('error', $error->getMessage());
        }

        return back()->with('success', 'Approved and activated.');
    }

    public function markForReview(Request $request, $assessment): RedirectResponse
    {
        $assessment = $this->identity($assessment);
        $this->assertCanAccess($request, $assessment, $context ?? null);
        try {
            $this->service->withinCommandContext($request->user(), function (User $current, $reads, HealthSafetyReadContext $context) use ($request, $assessment): array {
                $result = $this->service->markForReview($assessment, $current, $context);
                return ['id' => $result->id];
            });
        } catch (\InvalidArgumentException $error) {
            return back()->with('error', $error->getMessage());
        }

        return back()->with('success', 'Marked for review.');
    }

    public function updateResidual(UpdateResidualRiskRequest $request, $assessment): RedirectResponse
    {
        $assessment = $this->identity($assessment);
        $this->assertCanAccess($request, $assessment, $context ?? null);
        $data = $request->validated();
        try {
            $this->service->withinCommandContext($request->user(), function (User $current, $reads, HealthSafetyReadContext $context) use ($assessment, $data): array {
                $result = $this->service->updateResidualRisk($assessment, (int) $data['residual_likelihood'], (int) $data['residual_consequence'],
                    isset($data['risk_acceptable']) ? (bool) $data['risk_acceptable'] : null, $current, $data['review_note'] ?? null, array_key_exists('risk_acceptable', $data), $context);
                return ['id' => $result->id];
            });
        } catch (\InvalidArgumentException $error) {
            return back()->with('error', $error->getMessage());
        }

        return back()->with('success', 'Residual risk recorded.');
    }

    public function completeReview(CompleteHsRiskAssessmentReviewRequest $request, $assessment): RedirectResponse
    {
        $assessment = $this->identity($assessment);
        $this->assertCanAccess($request, $assessment, $context ?? null);
        try {
            $this->service->withinCommandContext($request->user(), function (User $current, $reads, HealthSafetyReadContext $context) use ($request, $assessment): array {
                $result = $this->service->completeReview($assessment, $request->validated(), $current, $context);
                return ['id' => $result->id];
            });
        } catch (\InvalidArgumentException $error) {
            return back()->with('error', $error->getMessage());
        }

        return back()->with('success', 'Review completed and the next review scheduled.');
    }

    public function supersede(SupersedeHsRiskAssessmentRequest $request, $assessment): RedirectResponse
    {
        $assessment = $this->identity($assessment);
        $this->assertCanAccess($request, $assessment, $context ?? null);
        $data = $request->validated();
        try {
            $new = $this->service->withinCommandContext($request->user(), function (User $current, $reads, HealthSafetyReadContext $context) use ($assessment, $data): array {
                $result = $this->service->supersede($assessment, $this->mapAssessable($data), $current, $context);
                return ['id' => $result->id, 'reference_number' => $result->reference_number];
            });
        } catch (\InvalidArgumentException $error) {
            return back()->with('error', $error->getMessage());
        }

        return back()->with('success', "Superseded; {$new['reference_number']} created as a draft.")
            ->with('created_risk_assessment_id', $new['id']);
    }

    public function archive(Request $request, $assessment): RedirectResponse
    {
        $assessment = $this->identity($assessment);
        $this->assertCanAccess($request, $assessment, $context ?? null);
        try {
            $this->service->withinCommandContext($request->user(), function (User $current, $reads, HealthSafetyReadContext $context) use ($request, $assessment): array {
                $result = $this->service->archive($assessment, $current, $context);
                return ['id' => $result->id];
            });
        } catch (\InvalidArgumentException $error) {
            return back()->with('error', $error->getMessage());
        }

        return back()->with('success', 'Assessment archived.');
    }

    /* ====================================================================== */
    /*  Attachments (premium evidence upload) */
    /* ====================================================================== */

    public function uploadAttachment(Request $request, $assessment): RedirectResponse
    {
        $assessment = $this->identity($assessment);
        $this->assertCanAccess($request, $assessment, $context ?? null);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,csv,txt,png,jpg,jpeg,gif,webp,heic'], // 20 MB
            'kind' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'alt_text' => ['nullable', 'string', 'max:255'],
        ]);

        $storedPath = null;
        try {
            $this->service->command($assessment, $request->user(), function (HsRiskAssessment $locked, User $actor) use ($request, $data, &$storedPath): void {
                $this->service->requireMutable($locked);
                $file = $request->file('file');
                $storedPath = $file->store('hs_risk_assessment_attachments', 'private');
                if (! is_string($storedPath) || $storedPath === '') {
                    throw ValidationException::withMessages(['file' => 'Evidence could not be stored. Try uploading the file again.']);
                }
                $attachment = $locked->attachments()->create([
                    'uploaded_by' => $actor->id, 'disk' => 'private', 'path' => $storedPath,
                    'original_name' => $file->getClientOriginalName(), 'mime' => $file->getClientMimeType(), 'size' => $file->getSize(),
                    'kind' => $data['kind'] ?? null, 'notes' => $data['notes'] ?? null, 'alt_text' => $data['alt_text'] ?? null,
                ]);
                if (! $attachment->exists) { throw new \LogicException('Risk evidence creation was not persisted.'); }
                $this->service->audit('risk_assessment.evidence_added', $locked, $actor, null, ['names' => [$attachment->original_name], 'attachment_id' => $attachment->id]);
            });
        } catch (Throwable $error) {
            if (is_string($storedPath) && $storedPath !== '') {
                Storage::disk('private')->delete($storedPath);
            }
            if ($error instanceof \InvalidArgumentException) {
                return back()->with('error', $error->getMessage());
            }
            throw $error;
        }

        return back()->with('success', 'Evidence uploaded.');
    }

    public function downloadAttachment(Request $request, $assessment, $attachment): StreamedResponse
    {
        $assessment = $this->identity($assessment); $attachment = $this->attachmentIdentity($attachment);
        $payload = RiskAssessmentPresenter::materialise($request->user(), null, function (User $current, $reads, HealthSafetyReadContext $context) use ($assessment, $attachment): array {
            $parent = RiskAssessmentPresenter::guardEagerQuery(HsRiskAssessment::query()->whereKey($assessment->id), $current, $context);
            abort_unless((clone $parent)->exists(), 404);
            $file = RiskAssessmentPresenter::guardChildQuery(HsRiskAssessmentAttachment::query()->whereKey($attachment->id), 'hs_risk_assessment_attachments.hs_risk_assessment_id', $parent, $context)->firstOrFail();
            $disk = $file->disk ?: 'private'; abort_unless(Storage::disk($disk)->exists($file->path), 404);
            abort_unless((clone $parent)->exists(), 404);
            $bytes = Storage::disk($disk)->get($file->path); abort_unless(is_string($bytes), 404);
            abort_unless((clone $parent)->exists(), 404);
            $mime = $file->mime ?: (Storage::disk($disk)->mimeType($file->path) ?: 'application/octet-stream');
            return ['bytes' => $bytes, 'name' => $file->original_name, 'mime' => $mime];
        });
        $response = new StreamedResponse(static function () use ($payload): void { echo $payload['bytes']; });
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox; frame-ancestors 'none'");
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Content-Type', $payload['mime']);
        $response->headers->set('Content-Length', (string) strlen($payload['bytes']));
        $response->headers->set('Content-Disposition', \Symfony\Component\HttpFoundation\HeaderUtils::makeDisposition('attachment', $payload['name'], preg_replace('/[^\x20-\x7E]/', '_', $payload['name'])));
        return $response;
    }

    public function destroyAttachment(Request $request, $assessment, $attachment): RedirectResponse
    {
        $assessment = $this->identity($assessment);
        $attachment = $this->attachmentIdentity($attachment);
        $this->assertCanAccess($request, $assessment, $context ?? null);

        try {
            $this->service->command($assessment, $request->user(), function (HsRiskAssessment $locked, User $actor, $reads, HealthSafetyReadContext $context, $source, Builder $parent) use ($attachment): void {
                $this->service->requireMutable($locked);
                $current = RiskAssessmentPresenter::guardChildQuery(HsRiskAssessmentAttachment::query()->whereKey($attachment->id), 'hs_risk_assessment_attachments.hs_risk_assessment_id', $parent, $context)->lockForUpdate()->firstOrFail();
                $disk = $current->disk ?: 'private';
                $path = $current->path;
                $this->service->audit('risk_assessment.evidence_removed', $locked, $actor, null, ['names' => [$current->original_name], 'attachment_id' => $current->id]);
                $this->service->deleteAttachment($current, $parent, $context);
                DB::afterCommit(function () use ($disk, $path): void {
                    try {
                        if ($path) {
                            Storage::disk($disk)->delete($path);
                        }
                    } catch (Throwable $error) {
                        Log::warning('Risk evidence file removal failed after commit', ['error_class' => $error::class]);
                    }
                });
            });
        } catch (\InvalidArgumentException $error) {
            return back()->with('error', $error->getMessage());
        }

        return back()->with('success', 'Evidence removed.');
    }

    /* ====================================================================== */
    /*  Internals */
    /* ====================================================================== */

    /** Map the wizard's attach_type/attach_id onto the model's polymorphic columns. */
    private function mapAssessable(array $data): array
    {
        $type = $data['attach_type'] ?? 'standalone';
        $id = $data['attach_id'] ?? null;
        unset($data['attach_type'], $data['attach_id']);

        $data['assessable_type'] = null;
        $data['assessable_id'] = null;
        $data['hs_event_id'] = null;

        match ($type) {
            'site' => [$data['assessable_type'] = Site::class, $data['assessable_id'] = $id],
            'client' => [$data['assessable_type'] = Client::class, $data['assessable_id'] = $id],
            'event' => [$data['hs_event_id'] = $id],
            default => null,
        };

        return $data;
    }

    /** Entity + free-text scope shared by the list, tab counts and the hero. */
    private function applyScope(Builder $query, array $filters, Request $request, HealthSafetyReadContext $context): Builder
    {
        if ($filters['site_id']) {
            RiskAssessmentPresenter::applySiteScopeInContext($query, $request->user(), $context, [$filters['site_id']]);
        }
        if ($filters['client_id']) {
            $query->where('assessable_type', Client::class)->where('assessable_id', $filters['client_id']);
        }
        if ($filters['hs_event_id']) {
            $query->where(fn (Builder $events) => $events->where('hs_event_id', $filters['hs_event_id'])
                ->orWhere(fn (Builder $modern) => $modern->whereIn('assessable_type', array_unique([HsEvent::class, (new HsEvent)->getMorphClass()]))->where('assessable_id', $filters['hs_event_id'])));
        }
        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function (Builder $q) use ($term) {
                $q->where('title', 'like', $term)
                    ->orWhere('reference_number', 'like', $term)
                    ->orWhere('risk_description', 'like', $term);
            });
        }

        return RiskAssessmentPresenter::guardEagerQuery($query, $request->user(), $context);
    }

    private function assertAccessibleFilters(Request $request, array $filters, HealthSafetyReadContext $context): void
    {
        if ($filters['site_id']) {
            $this->siteAccess->assertCanAccessSiteId(
                $request->user(),
                $filters['site_id'],
                $this->bypassPermissions(),
            );
        }
        if ($filters['client_id']) {
            $this->siteAccess->assertCanAccessClientId(
                $request->user(),
                $filters['client_id'],
                $this->bypassPermissions(),
            );
        }
        if ($filters['hs_event_id']) {
            $eventQuery = $this->siteAccess->applyHsEventScope(HsEvent::query()->whereKey($filters['hs_event_id']), $request->user(), $this->bypassPermissions());
            $eventQuery->whereExists($this->service->authorityQueryInContext($request->user(), $context)->selectRaw('1')->toBase());
            $event = app(HsEventAccessService::class)->applyFirstAidSourceScope($eventQuery, $request->user(), $context)->firstOrFail(['id', 'site_id']);
            $this->siteAccess->assertCanAccessHsEvent(
                $request->user(),
                $event,
                $this->bypassPermissions(),
            );
        }
    }

    private function assertCanAccess(Request $request, HsRiskAssessment $assessment, ?HealthSafetyReadContext $context = null): void
    {
        $consume = function (User $current, $reads, HealthSafetyReadContext $active) use ($assessment, $request): void {
            $key = $request->isMethod('GET') ? 'hazards.view' : 'hazards.manage'; abort_unless($current->canDo($key), 403);
            $query = RiskAssessmentPresenter::applySiteScopeInContext(HsRiskAssessment::query()->whereKey($assessment->id), $current, $active);
            $query->whereExists($this->service->authorityQueryInContext($current, $active, [$key])->selectRaw('1')->toBase());
            abort_unless(app(HsEventAccessService::class)->applyFirstAidRiskSourceScope($query, $current, $active)->exists(), 403);
        };
        if ($context) { $context->consumeSourceViewer(RiskAssessmentPresenter::readPermissions(), fn ($current, $reads) => $consume($current, $reads, $context)); }
        else { HealthSafetyReadContext::within($request->user(), RiskAssessmentPresenter::readPermissions(), fn ($active) => $active->consumeSourceViewer(RiskAssessmentPresenter::readPermissions(), fn ($current, $reads) => $consume($current, $reads, $active))); }
    }

    private function identity(mixed $id): HsRiskAssessment
    {
        $id = $id instanceof HsRiskAssessment ? $id->getKey() : $id;
        abort_unless((is_string($id) || is_int($id)) && preg_match('/^[1-9][0-9]*$/D', (string) $id) && filter_var($id, FILTER_VALIDATE_INT) !== false, 404);
        $model = new HsRiskAssessment; $model->setRawAttributes(['id' => (int) $id], true); $model->exists = true;
        return $model;
    }

    private function attachmentIdentity(mixed $id): HsRiskAssessmentAttachment
    {
        $id = $id instanceof HsRiskAssessmentAttachment ? $id->getKey() : $id;
        abort_unless((is_string($id) || is_int($id)) && preg_match('/^[1-9][0-9]*$/D', (string) $id) && filter_var($id, FILTER_VALIDATE_INT) !== false, 404);
        $model = new HsRiskAssessmentAttachment; $model->setRawAttributes(['id' => (int) $id], true); $model->exists = true;
        return $model;
    }

    private function assertCanUseContext(Request $request, array $data): void
    {
        $this->siteAccess->assertCanUseHsRiskAssessmentContext(
            $request->user(),
            (string) ($data['attach_type'] ?? 'standalone'),
            isset($data['attach_id']) ? (int) $data['attach_id'] : null,
            $this->bypassPermissions(),
        );
    }

    /** @return array<int, string> */
    private function bypassPermissions(): array
    {
        return UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS;
    }

    private function applyTab(Builder $query, string $tab): void
    {
        match ($tab) {
            'active' => $query->active(),
            'drafts' => $query->where('status', HsRiskAssessment::STATUS_DRAFT),
            'due' => $query->dueForReview(),
            'high' => $query->highOrExtreme(),
            'closed' => $query->whereIn('status', [HsRiskAssessment::STATUS_SUPERSEDED, HsRiskAssessment::STATUS_ARCHIVED]),
            default => null,
        };
    }

    private function applyFacets(Builder $query, array $filters): void
    {
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['risk_level'])) {
            $query->where('risk_level', $filters['risk_level']);
        }
        if (($filters['due_for_review'] ?? null) === 'true') {
            $query->dueForReview();
        }
        // Residual acceptability: '0' → not acceptable, '1' → acceptable.
        if (($filters['risk_acceptable'] ?? null) !== null && $filters['risk_acceptable'] !== '') {
            $query->where('risk_acceptable', $filters['risk_acceptable'] === '1');
        }
    }

    /** @param  callable():Builder  $base */
    private function buildHero(callable $base): array
    {
        $active = (clone $base())->active()->count();
        $activeWithReview = (clone $base())->active()->whereNotNull('review_due_at')->count();

        return [
            'total' => (clone $base())->count(),
            'active' => $active,
            'high_extreme_active' => (clone $base())->active()->highOrExtreme()->count(),
            'drafts' => (clone $base())->where('status', HsRiskAssessment::STATUS_DRAFT)->count(),
            'under_review' => (clone $base())->where('status', HsRiskAssessment::STATUS_UNDER_REVIEW)->count(),
            'due_for_review' => (clone $base())->dueForReview()->count(),
            'residual_not_acceptable' => (clone $base())->where('risk_acceptable', false)
                ->whereIn('status', [HsRiskAssessment::STATUS_ACTIVE, HsRiskAssessment::STATUS_UNDER_REVIEW])->count(),
            'awaiting_approval' => (clone $base())->where('status', HsRiskAssessment::STATUS_DRAFT)->count(),
            'compliance' => [
                'reviews_overdue' => (clone $base())->overdueForReview()
                    ->count(),
                'high_extreme_without_approval' => (clone $base())->highOrExtreme()
                    ->whereNull('approved_by_user_id')
                    ->whereIn('status', [HsRiskAssessment::STATUS_DRAFT, HsRiskAssessment::STATUS_ACTIVE, HsRiskAssessment::STATUS_UNDER_REVIEW])
                    ->count(),
                'residual_not_acceptable' => (clone $base())->where('risk_acceptable', false)
                    ->whereIn('status', [HsRiskAssessment::STATUS_ACTIVE, HsRiskAssessment::STATUS_UNDER_REVIEW])->count(),
                'pct_active_scheduled' => $active > 0 ? (int) round($activeWithReview / $active * 100) : 0,
            ],
        ];
    }
}
