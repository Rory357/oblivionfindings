<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Models\HsAttachment;
use App\Models\ProcedureAcknowledgement;
use App\Models\Role;
use App\Models\SafeWorkProcedure;
use App\Models\SafeWorkProcedureVersion;
use App\Models\Site;
use App\Models\TrainingCourse;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Safe Work Procedures — the controlled SWMS document library.
 *
 * Lifecycle: draft → under_review → approved → archived (+ review_date / current_version).
 * Gold-standard H&S register (twin of LoneWorkerController): modal-first, in-place
 * transitions, param-driven detail, version-stamped controlled-document attachments.
 */
class SafeWorkProcedureController extends Controller
{
    /** Canonical categories (the design's category→tone map). */
    private const PROCEDURE_CATEGORIES = [
        'manual_handling',
        'challenging_behaviour',
        'lone_working',
        'medication',
        'infection_control',
        'fire_safety',
        'emergency_procedures',
        'equipment_use',
        'personal_care',
    ];

    /** High-risk categories that must each carry an approved procedure (NZ supported-living). */
    private const HIGH_RISK_CATEGORIES = [
        'manual_handling',
        'challenging_behaviour',
        'lone_working',
        'medication',
    ];

    private const STATUSES = ['draft', 'under_review', 'approved', 'archived'];

    private const TABS = ['all', 'draft', 'under_review', 'approved', 'review_due', 'archived'];

    private const REVIEW_STATES = ['overdue', 'due_soon', 'current'];

    /* ================================================================== */
    /*  Register                                                          */
    /* ================================================================== */

    public function index(Request $request): \Inertia\Response
    {
        $filters = $this->resolveFilters($request);

        $procedures = $this->buildQuery($filters)
            ->with(['approvedBy:id,name', 'owner:id,name'])
            ->withCount('attachments')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (SafeWorkProcedure $p) => $this->mapRow($p));

        return Inertia::render('health-safety/procedures/index', array_merge([
            'procedures' => $procedures,
            'tab' => $filters['tab'],
            'tabCounts' => $this->tabCounts(),
            'hero' => $this->heroBlock(),
            'filters' => $filters,
            'detail' => $request->filled('procedure')
                ? $this->procedureDetail((int) $request->input('procedure'), $request->user())
                : null,
            'can' => $this->canBlock($request->user()),
        ], $this->pickerOptions()));
    }

    /* ================================================================== */
    /*  Deep-link fallbacks (modal-first is the primary path)             */
    /* ================================================================== */

    public function create(): RedirectResponse
    {
        return redirect()->route('health-safety.procedures.index', ['new' => 1]);
    }

    public function edit(SafeWorkProcedure $procedure): RedirectResponse
    {
        return redirect()->route('health-safety.procedures.index', ['procedure' => $procedure->id, 'edit' => 1]);
    }

    public function show(SafeWorkProcedure $procedure): RedirectResponse
    {
        // Modal-first: the permalink opens the detail-as-modal on the register.
        return redirect()->route('health-safety.procedures.index', ['procedure' => $procedure->id]);
    }

    /** CSV export of the register, honouring the current tab + filters. */
    public function export(Request $request): StreamedResponse
    {
        $procedures = $this->buildQuery($this->resolveFilters($request))
            ->with(['approvedBy:id,name', 'owner:id,name'])
            ->get();

        $filename = 'safe-work-procedures-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($procedures) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Reference', 'Title', 'Category', 'Status', 'Version', 'Owner', 'Approved by', 'Next review', 'Sites', 'Roles']);
            foreach ($procedures as $p) {
                fputcsv($out, [
                    $p->reference_number,
                    $p->title,
                    $this->categoryLabel((string) $p->category),
                    ucfirst(str_replace('_', ' ', (string) $p->status)),
                    'v'.$p->current_version,
                    $p->owner?->name ?? '',
                    $p->approvedBy?->name ?? '',
                    $p->review_date?->toDateString() ?? '',
                    is_array($p->applicable_sites) ? count($p->applicable_sites) : 0,
                    is_array($p->applicable_roles) ? count($p->applicable_roles) : 0,
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /* ================================================================== */
    /*  Create / edit (modal wizard posts in-place)                       */
    /* ================================================================== */

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->cleanPayload($this->validateProcedure($request, null));

        $procedure = SafeWorkProcedure::create(array_merge($validated, [
            'created_by' => $request->user()->id,
            'current_version' => 1,
            'status' => 'draft',
        ]));

        $this->snapshotVersion($procedure, 1, 'Initial version', $request->user()->id);

        return back()->with([
            'success' => 'Safe work procedure created (draft).',
            'created_procedure_id' => $procedure->id,
        ]);
    }

    public function update(Request $request, SafeWorkProcedure $procedure): RedirectResponse
    {
        $validated = $this->validateProcedure($request, $procedure);
        $changeSummary = $validated['change_summary'];
        unset($validated['change_summary']);

        $validated = $this->cleanPayload($validated);
        $validated['updated_by'] = $request->user()->id;
        $validated['current_version'] = $procedure->current_version + 1;

        // Content change un-approves an approved procedure (keep existing behaviour).
        if ($procedure->status === 'approved') {
            $validated['status'] = 'under_review';
        }

        $procedure->update($validated);
        $this->snapshotVersion($procedure->fresh(), $procedure->current_version, $changeSummary, $request->user()->id);

        return back()->with([
            'success' => "Procedure updated (v{$procedure->current_version}).",
            'created_procedure_id' => $procedure->id,
        ]);
    }

    /* ================================================================== */
    /*  Lifecycle transitions (all in-place)                              */
    /* ================================================================== */

    public function submitForReview(Request $request, SafeWorkProcedure $procedure): RedirectResponse
    {
        if ($procedure->status === 'approved') {
            return back()->with('success', 'Procedure is already approved.');
        }

        $procedure->update(['status' => 'under_review', 'updated_by' => $request->user()->id]);

        return back()->with('success', 'Procedure submitted for review.');
    }

    public function approve(Request $request, SafeWorkProcedure $procedure): RedirectResponse
    {
        $validated = $request->validate([
            'note' => 'nullable|string|max:2000',
            'review_date' => 'nullable|date',
        ]);

        $procedure->update([
            'status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => Carbon::now(),
            'review_date' => $validated['review_date'] ?? $this->computeNextReview($procedure),
            'updated_by' => $request->user()->id,
        ]);

        if (! empty($validated['note'])) {
            $this->snapshotNewVersion($procedure, 'Approved: '.$validated['note'], $request->user()->id);
        }

        return back()->with('success', 'Procedure approved.');
    }

    public function requestChanges(Request $request, SafeWorkProcedure $procedure): RedirectResponse
    {
        abort_unless(in_array($procedure->status, ['under_review', 'approved'], true), 422);

        $validated = $request->validate(['note' => 'nullable|string|max:2000']);

        $procedure->update(['status' => 'draft', 'updated_by' => $request->user()->id]);

        if (! empty($validated['note'])) {
            $this->snapshotNewVersion($procedure, 'Returned to draft: '.$validated['note'], $request->user()->id);
        }

        return back()->with('success', 'Returned to draft for changes.');
    }

    public function recordReview(Request $request, SafeWorkProcedure $procedure): RedirectResponse
    {
        $validated = $request->validate([
            'review_date' => 'required|date',
            'note' => 'nullable|string|max:2000',
        ]);

        $procedure->update(['review_date' => $validated['review_date'], 'updated_by' => $request->user()->id]);

        $this->snapshotNewVersion(
            $procedure,
            'Review recorded'.(! empty($validated['note']) ? ': '.$validated['note'] : ''),
            $request->user()->id,
        );

        return back()->with('success', 'Review recorded.');
    }

    public function archive(Request $request, SafeWorkProcedure $procedure): RedirectResponse
    {
        if ($procedure->status === 'archived') {
            return back()->with('success', 'Procedure is already archived.');
        }

        $procedure->update([
            'previous_status' => $procedure->status,
            'status' => 'archived',
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Procedure archived.');
    }

    public function restore(Request $request, SafeWorkProcedure $procedure): RedirectResponse
    {
        abort_unless($procedure->status === 'archived', 422);

        $restoreTo = in_array($procedure->previous_status, self::STATUSES, true) && $procedure->previous_status !== 'archived'
            ? $procedure->previous_status
            : 'draft';

        $procedure->update([
            'status' => $restoreTo,
            'previous_status' => null,
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Procedure restored from the archive.');
    }

    /** Record that the current worker has read & understood the procedure (version-stamped). */
    public function acknowledge(Request $request, SafeWorkProcedure $procedure): RedirectResponse
    {
        ProcedureAcknowledgement::updateOrCreate(
            ['safe_work_procedure_id' => $procedure->id, 'user_id' => $request->user()->id],
            ['version_acknowledged' => $procedure->current_version, 'acknowledged_at' => now()],
        );

        return back()->with('success', 'Procedure acknowledged.');
    }

    /* ================================================================== */
    /*  Controlled-document library (premium upload — polymorphic HsAttachment) */
    /* ================================================================== */

    public function uploadAttachment(Request $request, SafeWorkProcedure $procedure): RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg,webp'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $file = $request->file('file');
        $path = $file->store("health-safety/procedures/{$procedure->id}", 'private');

        $procedure->attachments()->create([
            'uploaded_by' => $request->user()->id,
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'disk' => 'private',
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'description' => $validated['description'] ?? null,
            'version_at_upload' => $procedure->current_version,
        ]);

        return back()->with('success', 'Document attached.');
    }

    public function downloadAttachment(SafeWorkProcedure $procedure, HsAttachment $attachment): StreamedResponse
    {
        $this->assertAttachmentBelongsTo($procedure, $attachment);

        $disk = $attachment->disk ?: 'private';
        abort_unless(Storage::disk($disk)->exists($attachment->path), 404);

        return Storage::disk($disk)->download($attachment->path, $attachment->original_name);
    }

    public function destroyAttachment(SafeWorkProcedure $procedure, HsAttachment $attachment): RedirectResponse
    {
        $this->assertAttachmentBelongsTo($procedure, $attachment);

        $disk = $attachment->disk ?: 'private';
        if ($attachment->path && Storage::disk($disk)->exists($attachment->path)) {
            Storage::disk($disk)->delete($attachment->path);
        }
        $attachment->delete();

        return back()->with('success', 'Document removed.');
    }

    /**
     * IDOR guard: HsAttachment is polymorphic, so assert BOTH the FK id AND the morph
     * type — otherwise procedure B could reach procedure A's document by id.
     */
    private function assertAttachmentBelongsTo(SafeWorkProcedure $procedure, HsAttachment $attachment): void
    {
        abort_unless(
            (int) $attachment->attachable_id === (int) $procedure->id
                && $attachment->attachable_type === $procedure->getMorphClass(),
            404,
        );
    }

    /* ================================================================== */
    /*  Row / detail mapping                                              */
    /* ================================================================== */

    private function mapRow(SafeWorkProcedure $p): array
    {
        return [
            'id' => $p->id,
            'reference_number' => $p->reference_number,
            'title' => $p->title,
            'purpose' => $p->purpose,
            'category' => $p->category,
            'status' => $p->status,
            'version' => $p->current_version,
            'review_date' => $p->review_date?->toDateString(),
            'owner' => $p->owner ? ['id' => $p->owner->id, 'name' => $p->owner->name] : null,
            'approved_by' => $p->approvedBy ? ['id' => $p->approvedBy->id, 'name' => $p->approvedBy->name] : null,
            'sites_count' => is_array($p->applicable_sites) ? count($p->applicable_sites) : 0,
            'roles_count' => is_array($p->applicable_roles) ? count($p->applicable_roles) : 0,
            'documents_count' => (int) ($p->attachments_count ?? 0),
        ];
    }

    private function procedureDetail(int $id, ?User $user): ?array
    {
        $procedure = SafeWorkProcedure::with([
            'approvedBy:id,name', 'owner:id,name', 'creator:id,name', 'updater:id,name',
            'versions.changedBy:id,name', 'attachments.uploader:id,name',
        ])->find($id);

        if (! $procedure) {
            return null;
        }

        return $this->mapDetail($procedure, $user);
    }

    private function mapDetail(SafeWorkProcedure $p, ?User $user): array
    {
        return [
            'id' => $p->id,
            'reference_number' => $p->reference_number,
            'title' => $p->title,
            'category' => $p->category,
            'status' => $p->status,
            'previous_status' => $p->previous_status,
            'version' => $p->current_version,
            'purpose' => $p->purpose,
            'scope' => $p->scope,
            'steps' => $this->normalizeSteps($p->steps),
            'ppe_required' => array_values($p->ppe_required ?? []),
            'hazards_addressed' => array_values($p->hazards_addressed ?? []),
            'emergency_procedures' => is_array($p->emergency_procedures)
                ? implode("\n", $p->emergency_procedures)
                : (string) $p->emergency_procedures,
            'review_date' => $p->review_date?->toDateString(),
            'review_frequency_months' => $p->review_frequency_months,
            'approved_at' => $p->approved_at?->toISOString(),
            'applies' => [
                'roles' => $this->resolveRoles(array_values($p->applicable_roles ?? [])),
                'sites' => $this->resolveSites(array_values($p->applicable_sites ?? [])),
                'training' => $this->resolveTraining(array_values($p->related_training ?? [])),
            ],
            'owner' => $p->owner ? ['id' => $p->owner->id, 'name' => $p->owner->name] : null,
            'approved_by' => $p->approvedBy ? ['id' => $p->approvedBy->id, 'name' => $p->approvedBy->name] : null,
            'creator' => $p->creator ? ['id' => $p->creator->id, 'name' => $p->creator->name] : null,
            'updater' => $p->updater ? ['id' => $p->updater->id, 'name' => $p->updater->name] : null,
            'versions' => $p->versions->map(fn (SafeWorkProcedureVersion $v) => [
                'id' => $v->id,
                'version' => $v->version_number,
                'change_summary' => $v->change_summary,
                'changed_by' => $v->changedBy ? ['id' => $v->changedBy->id, 'name' => $v->changedBy->name] : null,
                'created_at' => $v->created_at?->toISOString(),
            ])->values(),
            'attachments' => $p->attachments->map(fn (HsAttachment $a) => [
                'id' => $a->id,
                'original_name' => $a->original_name,
                'mime' => $a->mime_type,
                'size' => $a->size_bytes,
                'description' => $a->description,
                'version' => $a->version_at_upload,
                'uploaded_by_name' => $a->uploader?->name,
                'created_at' => $a->created_at?->toISOString(),
                'url' => "/health-safety/procedures/{$p->id}/attachments/{$a->id}/download",
            ])->values(),
            'acknowledged' => $user
                ? (int) $p->acknowledgements()->where('user_id', $user->id)->value('version_acknowledged') === (int) $p->current_version
                : false,
            'acknowledged_count' => $p->acknowledgements()->count(),
            'form' => $this->mapProcedureForForm($p),
            'can' => $this->canBlock($user),
        ];
    }

    /* ================================================================== */
    /*  Hero / tab counts                                                 */
    /* ================================================================== */

    private function tabCounts(): array
    {
        return [
            'all' => SafeWorkProcedure::count(),
            'draft' => SafeWorkProcedure::where('status', 'draft')->count(),
            'under_review' => SafeWorkProcedure::where('status', 'under_review')->count(),
            'approved' => SafeWorkProcedure::where('status', 'approved')->count(),
            'review_due' => SafeWorkProcedure::where('status', 'approved')->where(fn ($w) => $this->applyReviewDue($w))->count(),
            'archived' => SafeWorkProcedure::where('status', 'archived')->count(),
        ];
    }

    /**
     * Raw counts/booleans only — the page formats the copy (never pre-format here).
     */
    private function heroBlock(): array
    {
        $today = Carbon::today();
        $approvedCats = SafeWorkProcedure::where('status', 'approved')
            ->whereNotNull('category')->distinct()->pluck('category')->all();

        $coverageGaps = count(array_diff(self::PROCEDURE_CATEGORIES, $approvedCats));
        $highRiskCovered = count(array_intersect(self::HIGH_RISK_CATEGORIES, $approvedCats)) === count(self::HIGH_RISK_CATEGORIES);

        $reviewOverdue = SafeWorkProcedure::where('status', 'approved')
            ->whereNotNull('review_date')->where('review_date', '<', $today)->count();
        $reviewDueSoon = SafeWorkProcedure::where('status', 'approved')
            ->whereNotNull('review_date')->whereBetween('review_date', [$today, $today->copy()->addDays(30)])->count();

        $draft = SafeWorkProcedure::where('status', 'draft')->count();
        $underReview = SafeWorkProcedure::where('status', 'under_review')->count();
        $approved = SafeWorkProcedure::where('status', 'approved')->count();
        $archived = SafeWorkProcedure::where('status', 'archived')->count();

        return [
            'library' => [
                'approved' => $approved,
                'under_review' => $underReview,
                'draft' => $draft,
                'archived' => $archived,
            ],
            'attention' => [
                'review_due_soon' => $reviewDueSoon,
                'review_overdue' => $reviewOverdue,
                'unapproved' => $draft + $underReview,
                'coverage_gaps' => $coverageGaps,
            ],
            'nz' => [
                'worksafe_approved' => $approved,
                'nga_paerewa_documented' => $approved > 0,
                'review_overdue' => $reviewOverdue,
                'coverage_gaps' => $coverageGaps,
                'high_risk_covered' => $highRiskCovered,
            ],
        ];
    }

    /* ================================================================== */
    /*  Query scopes / options                                            */
    /* ================================================================== */

    /** Whitelisted filter set, shared by index() + export(). */
    private function resolveFilters(Request $request): array
    {
        $tab = in_array($request->input('tab'), self::TABS, true) ? $request->input('tab') : 'all';
        $q = trim((string) $request->input('q'));

        return [
            'q' => $q !== '' ? $q : null,
            'tab' => $tab,
            'category' => in_array($request->input('category'), self::PROCEDURE_CATEGORIES, true) ? $request->input('category') : null,
            'status' => in_array($request->input('status'), self::STATUSES, true) ? $request->input('status') : null,
            'site_id' => $request->filled('site_id') ? (int) $request->input('site_id') : null,
            'review_state' => in_array($request->input('review_state'), self::REVIEW_STATES, true) ? $request->input('review_state') : null,
        ];
    }

    /** Build the filtered register query (no pagination/eager-loads), shared by index() + export(). */
    private function buildQuery(array $filters): \Illuminate\Database\Eloquent\Builder
    {
        $tab = $filters['tab'];

        return SafeWorkProcedure::query()
            ->when($tab === 'review_due', fn ($query) => $query->where('status', 'approved')->where(fn ($w) => $this->applyReviewDue($w)))
            ->when(in_array($tab, self::STATUSES, true), fn ($query) => $query->where('status', $tab))
            ->when($filters['category'], fn ($query) => $query->where('category', $filters['category']))
            ->when($filters['status'], fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['site_id'], fn ($query) => $query->whereJsonContains('applicable_sites', $filters['site_id']))
            ->when($filters['review_state'], fn ($query) => $this->applyReviewState($query, $filters['review_state']))
            ->when($filters['q'], fn ($query) => $query->where(function ($sub) use ($filters) {
                $sub->where('title', 'like', "%{$filters['q']}%")
                    ->orWhere('reference_number', 'like', "%{$filters['q']}%");
            }))
            ->orderBy('title');
    }

    /** Approved review-due predicate (overdue OR due within 30 days). Single source of truth. */
    private function applyReviewDue($query): void
    {
        $query->whereNotNull('review_date')
            ->where('review_date', '<=', Carbon::today()->addDays(30));
    }

    private function applyReviewState($query, string $state)
    {
        $today = Carbon::today();

        return match ($state) {
            'overdue' => $query->whereNotNull('review_date')->where('review_date', '<', $today),
            'due_soon' => $query->whereNotNull('review_date')->whereBetween('review_date', [$today, $today->copy()->addDays(30)]),
            'current' => $query->where(fn ($w) => $w->whereNull('review_date')->orWhere('review_date', '>', $today->copy()->addDays(30))),
            default => $query,
        };
    }

    private function computeNextReview(SafeWorkProcedure $procedure): ?string
    {
        if ($procedure->review_frequency_months) {
            return Carbon::today()->addMonths($procedure->review_frequency_months)->toDateString();
        }

        return $procedure->review_date?->toDateString();
    }

    private function pickerOptions(): array
    {
        return [
            'sites' => Site::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'roles' => Role::query()->orderBy('label')->get(['id', 'name', 'label']),
            'trainingCourses' => TrainingCourse::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'owners' => User::query()->orderBy('name')->get(['id', 'name']),
            'categories' => array_map(fn ($key) => [
                'value' => $key,
                'label' => $this->categoryLabel($key),
            ], self::PROCEDURE_CATEGORIES),
        ];
    }

    private function canBlock(?User $user): array
    {
        return [
            'view' => (bool) $user?->canDo('procedures.view'),
            'create' => (bool) $user?->canDo('procedures.create'),
            'manage' => (bool) $user?->canDo('procedures.manage'),
            'approve' => (bool) $user?->canDo('procedures.approve'),
        ];
    }

    /* ================================================================== */
    /*  Resolution + normalisation                                       */
    /* ================================================================== */

    private function resolveSites(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $names = Site::whereIn('id', array_filter($ids, 'is_numeric'))->pluck('name', 'id');

        return collect($ids)->map(fn ($id) => [
            'id' => $id,
            'name' => $names[$id] ?? (is_numeric($id) ? "Site #{$id}" : (string) $id),
        ])->all();
    }

    private function resolveRoles(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $labels = Role::whereIn('name', $keys)->pluck('label', 'name');

        return collect($keys)->map(fn ($key) => [
            'key' => $key,
            'label' => $labels[$key] ?? $this->categoryLabel((string) $key),
        ])->all();
    }

    private function resolveTraining(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $courses = TrainingCourse::whereIn('id', array_filter($ids, 'is_numeric'))->get(['id', 'name', 'code'])->keyBy('id');

        return collect($ids)->map(function ($id) use ($courses) {
            $course = $courses[$id] ?? null;

            return [
                'id' => $id,
                'name' => $course?->name ?? (is_numeric($id) ? "Course #{$id}" : (string) $id),
                'code' => $course?->code,
            ];
        })->all();
    }

    private function normalizeSteps(?array $steps): array
    {
        return collect($steps ?? [])->map(fn ($step, int $index) => [
            'step_number' => (int) ($step['step_number'] ?? ($index + 1)),
            'description' => (string) ($step['description'] ?? ''),
            'safety_notes' => (string) ($step['safety_notes'] ?? ''),
        ])->values()->all();
    }

    private function categoryLabel(string $key): string
    {
        return ucwords(str_replace('_', ' ', $key));
    }

    /* ================================================================== */
    /*  Validation + persistence helpers                                 */
    /* ================================================================== */

    private function validateProcedure(Request $request, ?SafeWorkProcedure $procedure): array
    {
        $request->merge([
            'emergency_procedures' => $this->normalizeTextList($request->input('emergency_procedures')),
        ]);

        $refRule = $procedure
            ? ['required', 'string', 'max:100', Rule::unique('safe_work_procedures', 'reference_number')->ignore($procedure->id)]
            : ['required', 'string', 'max:100', 'unique:safe_work_procedures,reference_number'];

        $rules = [
            'title' => 'required|string|max:255',
            'reference_number' => $refRule,
            'category' => ['required', Rule::in(self::PROCEDURE_CATEGORIES)],
            'purpose' => 'nullable|string',
            'scope' => 'nullable|string',
            'hazards_addressed' => 'nullable|array',
            'hazards_addressed.*' => 'string|max:255',
            'ppe_required' => 'nullable|array',
            'ppe_required.*' => 'string|max:255',
            'steps' => 'nullable|array',
            'steps.*.step_number' => 'nullable|integer',
            'steps.*.description' => 'nullable|string',
            'steps.*.safety_notes' => 'nullable|string',
            'emergency_procedures' => 'nullable|array',
            'review_date' => 'nullable|date',
            'review_frequency_months' => 'nullable|integer|min:1|max:120',
            'owner_id' => ['nullable', Rule::exists('users', 'id')],
            'applicable_roles' => 'nullable|array',
            'applicable_roles.*' => 'string|max:100',
            'applicable_sites' => 'nullable|array',
            'applicable_sites.*' => 'integer',
            'related_training' => 'nullable|array',
            'related_training.*' => 'integer',
        ];

        if ($procedure) {
            $rules['change_summary'] = 'required|string|max:2000';
        }

        return $request->validate($rules);
    }

    private function cleanPayload(array $validated): array
    {
        $validated['steps'] = $this->cleanSteps($validated['steps'] ?? []);
        $validated['ppe_required'] = $this->cleanStringList($validated['ppe_required'] ?? []);
        $validated['hazards_addressed'] = $this->cleanStringList($validated['hazards_addressed'] ?? []);
        $validated['applicable_roles'] = $this->cleanStringList($validated['applicable_roles'] ?? []);
        $validated['applicable_sites'] = $this->cleanIntList($validated['applicable_sites'] ?? []);
        $validated['related_training'] = $this->cleanIntList($validated['related_training'] ?? []);

        return $validated;
    }

    private function cleanSteps(?array $steps): array
    {
        return collect($steps ?? [])
            ->filter(fn ($step) => trim((string) ($step['description'] ?? '')) !== '')
            ->values()
            ->map(fn ($step, int $index) => [
                'step_number' => $index + 1,
                'description' => trim((string) ($step['description'] ?? '')),
                'safety_notes' => trim((string) ($step['safety_notes'] ?? '')),
            ])->all();
    }

    private function cleanStringList(?array $items): array
    {
        return collect($items ?? [])
            ->map(fn ($item) => trim((string) $item))
            ->filter(fn ($item) => $item !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function cleanIntList(?array $items): array
    {
        return collect($items ?? [])
            ->map(fn ($item) => (int) $item)
            ->filter(fn ($item) => $item > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function snapshotVersion(SafeWorkProcedure $procedure, int $version, string $summary, int $userId): void
    {
        SafeWorkProcedureVersion::create([
            'safe_work_procedure_id' => $procedure->id,
            'version_number' => $version,
            'content_snapshot' => $procedure->toArray(),
            'change_summary' => $summary,
            'changed_by' => $userId,
        ]);
    }

    /**
     * Record a controlled-document lifecycle revision (review / approval / return-to-
     * draft note): bump current_version and snapshot at the NEW number. A version row
     * for the current number already exists (store/update/seed wrote it), so reusing
     * it would violate the unique (procedure, version) index — bump first.
     */
    private function snapshotNewVersion(SafeWorkProcedure $procedure, string $summary, int $userId): void
    {
        $procedure->update(['current_version' => $procedure->current_version + 1]);
        $this->snapshotVersion($procedure->fresh(), $procedure->current_version, $summary, $userId);
    }

    private function mapProcedureForForm(SafeWorkProcedure $procedure): array
    {
        $steps = $this->normalizeSteps($procedure->steps);

        return [
            'id' => $procedure->id,
            'title' => $procedure->title,
            'reference_number' => $procedure->reference_number,
            'category' => $procedure->category,
            'purpose' => $procedure->purpose ?? '',
            'scope' => $procedure->scope ?? '',
            'steps' => $steps !== [] ? $steps : [['step_number' => 1, 'description' => '', 'safety_notes' => '']],
            'ppe_required' => array_values($procedure->ppe_required ?? []),
            'hazards_addressed' => array_values($procedure->hazards_addressed ?? []),
            'emergency_procedures' => is_array($procedure->emergency_procedures)
                ? implode("\n", array_filter($procedure->emergency_procedures))
                : (string) ($procedure->emergency_procedures ?? ''),
            'applicable_roles' => array_values($procedure->applicable_roles ?? []),
            'applicable_sites' => array_map('intval', array_values($procedure->applicable_sites ?? [])),
            'related_training' => array_map('intval', array_values($procedure->related_training ?? [])),
            'review_date' => $procedure->review_date?->toDateString(),
            'review_frequency_months' => $procedure->review_frequency_months,
            'owner_id' => $procedure->owner_id,
        ];
    }

    private function normalizeTextList(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            $items = array_map(static fn ($item) => trim((string) $item), $value);

            return array_values(array_filter($items, static fn ($item) => $item !== ''));
        }

        $items = preg_split('/\r\n|\r|\n/', (string) $value) ?: [];
        $items = array_map(static fn ($item) => trim($item), $items);

        return array_values(array_filter($items, static fn ($item) => $item !== ''));
    }
}
