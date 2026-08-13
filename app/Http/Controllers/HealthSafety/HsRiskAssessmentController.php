<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Controller;
use App\Http\Requests\HealthSafety\ActivateHsRiskAssessmentRequest;
use App\Http\Requests\HealthSafety\StoreHsRiskAssessmentRequest;
use App\Http\Requests\HealthSafety\SupersedeHsRiskAssessmentRequest;
use App\Http\Requests\HealthSafety\UpdateHsRiskAssessmentRequest;
use App\Http\Requests\HealthSafety\UpdateResidualRiskRequest;
use App\Models\Client;
use App\Models\HsEvent;
use App\Models\HsRiskAssessment;
use App\Models\HsRiskAssessmentAttachment;
use App\Models\Site;
use App\Services\HealthSafety\HsRiskAssessmentService;
use App\Services\UserSiteAccessService;
use App\Support\HealthSafety\RiskAssessmentPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function index(Request $request): Response
    {
        $canManage = (bool) ($request->user()?->canDo('hazards.manage') ?? false);

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

        // Entity + search scope (NOT tab/status/level) — drives tab badges + hero so they
        // stay stable as the user flips status/level pills.
        $this->assertAccessibleFilters($request, $filters);

        $base = fn (): Builder => $this->applyScope(HsRiskAssessment::query(), $filters, $request);

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
        $list = $this->applyScope(HsRiskAssessment::query(), $filters, $request);
        $this->applyTab($list, (string) $filters['tab']);
        $this->applyFacets($list, $filters);

        $assessments = $list
            ->with(['assessedBy:id,name', 'assessable', 'hsEvent:id,reference_number'])
            ->withCount('attachments')
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (HsRiskAssessment $ra) => RiskAssessmentPresenter::row($ra));

        $detail = null;
        if ($request->filled('assessment')) {
            $ra = $this->siteAccess->applyHsRiskAssessmentScope(
                HsRiskAssessment::query(),
                $request->user(),
                $this->bypassPermissions(),
            )->find((int) $request->input('assessment'));
            if ($ra) {
                $detail = RiskAssessmentPresenter::detail($ra, $canManage);
            }
        }

        return Inertia::render('health-safety/risk-assessments/index', [
            'assessments' => $assessments,
            'tabCounts' => $tabCounts,
            'hero' => $hero,
            'detail' => $detail,
            'pickers' => RiskAssessmentPresenter::siteScopedPickers(
                $this->siteAccess->accessibleSiteIds($request->user(), $this->bypassPermissions()),
            ),
            'can' => [
                'manage' => $canManage,
                'viewReports' => (bool) ($request->user()?->canDo('governance.view') ?? false),
            ],
            'filters' => $filters,
        ]);
    }

    /** JSON detail — fetched on demand by the embedded Client/Site profile sections. */
    public function show(Request $request, HsRiskAssessment $assessment): JsonResponse
    {
        $this->siteAccess->assertCanAccessHsRiskAssessment(
            $request->user(),
            $assessment,
            $this->bypassPermissions(),
        );
        $canManage = (bool) ($request->user()?->canDo('hazards.manage') ?? false);

        return response()->json([
            'detail' => RiskAssessmentPresenter::detail($assessment, $canManage),
        ]);
    }

    /* ====================================================================== */
    /*  Lifecycle write actions (all → HsRiskAssessmentService, ->back()) */
    /* ====================================================================== */

    public function store(StoreHsRiskAssessmentRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $this->assertCanUseContext($request, $data);
        $assessment = $this->service->create($this->mapAssessable($data));

        return back()
            ->with('success', 'Risk assessment created as a draft.')
            ->with('created_risk_assessment_id', $assessment->id);
    }

    public function update(UpdateHsRiskAssessmentRequest $request, HsRiskAssessment $assessment): RedirectResponse
    {
        $this->assertCanAccess($request, $assessment);

        if ($assessment->status !== HsRiskAssessment::STATUS_DRAFT) {
            return back()->with('error', 'Only draft assessments can be edited.');
        }

        $validated = $request->validated();
        $this->assertCanUseContext($request, $validated);
        $data = $this->mapAssessable($validated);
        $inherent = HsRiskAssessment::calculateScore((int) $data['likelihood'], (int) $data['consequence']);
        $residual = isset($data['residual_likelihood'], $data['residual_consequence'])
            ? HsRiskAssessment::calculateScore((int) $data['residual_likelihood'], (int) $data['residual_consequence'])
            : null;

        $assessment->update([
            'assessable_type' => $data['assessable_type'],
            'assessable_id' => $data['assessable_id'],
            'hs_event_id' => $data['hs_event_id'],
            'title' => $data['title'],
            'risk_description' => $data['risk_description'] ?? null,
            'likelihood' => (int) $data['likelihood'],
            'consequence' => (int) $data['consequence'],
            'risk_score' => $inherent['score'],
            'risk_level' => $inherent['level'],
            'existing_controls' => $data['existing_controls'] ?? null,
            'additional_controls' => $data['additional_controls'] ?? null,
            'residual_likelihood' => $data['residual_likelihood'] ?? null,
            'residual_consequence' => $data['residual_consequence'] ?? null,
            'residual_risk_score' => $residual['score'] ?? null,
            'residual_risk_level' => $residual['level'] ?? null,
            'risk_acceptable' => $data['risk_acceptable'] ?? null,
            'review_frequency_days' => $data['review_frequency_days'] ?? null,
            'review_due_at' => $data['review_due_at'] ?? null,
            'updated_by' => $request->user()?->id,
        ]);

        return back()->with('success', 'Draft updated.');
    }

    public function activate(ActivateHsRiskAssessmentRequest $request, HsRiskAssessment $assessment): RedirectResponse
    {
        $this->assertCanAccess($request, $assessment);

        try {
            $this->service->activate($assessment);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($request->filled('approver_note')) {
            $assessment->update(['approval_note' => $request->input('approver_note')]);
        }

        return back()->with('success', 'Approved & activated.');
    }

    public function markForReview(Request $request, HsRiskAssessment $assessment): RedirectResponse
    {
        $this->assertCanAccess($request, $assessment);

        try {
            $this->service->markForReview($assessment);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Marked for review.');
    }

    public function updateResidual(UpdateResidualRiskRequest $request, HsRiskAssessment $assessment): RedirectResponse
    {
        $this->assertCanAccess($request, $assessment);

        $this->service->updateResidualRisk(
            $assessment,
            (int) $request->input('residual_likelihood'),
            (int) $request->input('residual_consequence'),
            // Preserve the existing acceptability if the toggle wasn't submitted.
            $request->exists('risk_acceptable') ? $request->boolean('risk_acceptable') : $assessment->risk_acceptable,
        );

        if ($request->filled('review_note')) {
            $assessment->update(['last_review_note' => $request->input('review_note')]);
        }

        return back()->with('success', 'Residual risk recorded.');
    }

    public function supersede(SupersedeHsRiskAssessmentRequest $request, HsRiskAssessment $assessment): RedirectResponse
    {
        $this->assertCanAccess($request, $assessment);
        $data = $request->validated();
        $this->assertCanUseContext($request, $data);

        try {
            $new = $this->service->supersede($assessment, $this->mapAssessable($data));
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()
            ->with('success', "Superseded — {$new->reference_number} created as a draft.")
            ->with('created_risk_assessment_id', $new->id);
    }

    public function archive(Request $request, HsRiskAssessment $assessment): RedirectResponse
    {
        $this->assertCanAccess($request, $assessment);
        $this->service->archive($assessment);

        return back()->with('success', 'Assessment archived.');
    }

    /* ====================================================================== */
    /*  Attachments (premium evidence upload) */
    /* ====================================================================== */

    public function uploadAttachment(Request $request, HsRiskAssessment $assessment): RedirectResponse
    {
        $this->assertCanAccess($request, $assessment);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,csv,txt,png,jpg,jpeg,gif,webp,heic'], // 20 MB
            'kind' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'alt_text' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $request->file('file');
        $disk = 'private';
        $path = $file->store('hs_risk_assessment_attachments', $disk);

        $assessment->attachments()->create([
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

    public function downloadAttachment(Request $request, HsRiskAssessment $assessment, HsRiskAssessmentAttachment $attachment): StreamedResponse
    {
        $this->assertCanAccess($request, $assessment);
        abort_unless((int) $attachment->hs_risk_assessment_id === (int) $assessment->id, 404);

        // Private disk + nosniff + CSP sandbox — see ServesPrivateAttachments.
        return $this->streamPrivateAttachment(
            $attachment->disk,
            $attachment->path,
            $attachment->original_name,
            $attachment->mime,
        );
    }

    public function destroyAttachment(Request $request, HsRiskAssessment $assessment, HsRiskAssessmentAttachment $attachment): RedirectResponse
    {
        $this->assertCanAccess($request, $assessment);
        abort_unless((int) $attachment->hs_risk_assessment_id === (int) $assessment->id, 404);

        $disk = $attachment->disk ?: 'private';
        if ($attachment->path && Storage::disk($disk)->exists($attachment->path)) {
            Storage::disk($disk)->delete($attachment->path);
        }
        $attachment->delete();

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
    private function applyScope(Builder $query, array $filters, Request $request): Builder
    {
        $this->siteAccess->applyHsRiskAssessmentScope(
            $query,
            $request->user(),
            $this->bypassPermissions(),
        );

        if ($filters['site_id']) {
            $this->siteAccess->applyHsRiskAssessmentSiteScopeForSiteIds($query, [$filters['site_id']]);
        }
        if ($filters['client_id']) {
            $query->where('assessable_type', Client::class)->where('assessable_id', $filters['client_id']);
        }
        if ($filters['hs_event_id']) {
            $query->where('hs_event_id', $filters['hs_event_id']);
        }
        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function (Builder $q) use ($term) {
                $q->where('title', 'like', $term)
                    ->orWhere('reference_number', 'like', $term)
                    ->orWhere('risk_description', 'like', $term);
            });
        }

        return $query;
    }

    private function assertAccessibleFilters(Request $request, array $filters): void
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
            $event = HsEvent::query()->findOrFail($filters['hs_event_id']);
            $this->siteAccess->assertCanAccessHsEvent(
                $request->user(),
                $event,
                $this->bypassPermissions(),
            );
        }
    }

    private function assertCanAccess(Request $request, HsRiskAssessment $assessment): void
    {
        $this->siteAccess->assertCanAccessHsRiskAssessment(
            $request->user(),
            $assessment,
            $this->bypassPermissions(),
        );
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
                'reviews_overdue' => (clone $base())->active()
                    ->whereNotNull('review_due_at')
                    ->whereDate('review_due_at', '<', now()->toDateString())
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
