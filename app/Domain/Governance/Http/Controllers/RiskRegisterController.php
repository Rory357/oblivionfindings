<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Http\Requests\StoreRiskRegisterRequest;
use App\Domain\Governance\Http\Requests\UpdateRiskRegisterRequest;
use App\Domain\Governance\Models\RiskRegisterEntry;
use App\Domain\Governance\Models\RiskTreatment;
use App\Domain\Governance\Services\GovernanceAuditService;
use App\Domain\Governance\Services\RiskScoringService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class RiskRegisterController extends Controller
{
    public function __construct(
        protected RiskScoringService $riskService
    ) {}

    /**
     * The full-page create form was retired for the shared wizard dialog on the
     * register index; old deep links open that dialog instead.
     */
    public function create()
    {
        return redirect()->route('governance.risks.index', ['create' => 1]);
    }

    public function index(Request $request)
    {
        $query = RiskRegisterEntry::with(['riskOwner', 'treatments', 'acceptances'])
            ->withCount('treatments');

        // Filters
        if ($request->has('category')) {
            $query->byCategory($request->category);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('severity')) {
            match ($request->severity) {
                'critical' => $query->critical(),
                'high' => $query->high(),
                default => $query,
            };
        }

        if ($request->boolean('above_appetite')) {
            $query->aboveAppetite();
        }

        if ($request->filled('search')) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string) $request->search)).'%';
            $query->where(fn ($q) => $q->where('title', 'like', $term)
                ->orWhere('risk_reference', 'like', $term));
        }

        $risks = $query->orderByDesc('residual_score')
            ->paginate(20)
            ->withQueryString();

        $canCreate = $this->canCreateRisks($request->user());

        return Inertia::render('Governance/Risks/Index', [
            'risks' => $risks,
            'categories' => $this->getCategories(),
            'summary' => $this->riskService->getCategorySummary(),
            'filters' => $request->only(['category', 'status', 'severity', 'above_appetite', 'search']),
            'canCreate' => $canCreate,
            // Wizard reference data — only for users who can actually register a risk.
            'formOptions' => $canCreate ? fn () => $this->riskFormOptions() : null,
        ]);
    }

    public function show(RiskRegisterEntry $risk)
    {
        $risk->load([
            'riskOwner',
            'treatments.assignedTo',
            'acceptances.acceptedBy',
            'events',
        ]);

        // Mirrors the update route (governance.risks.manage) and the policy.
        $canEdit = auth()->user()->can('update', $risk)
            && auth()->user()->canDo('governance.risks.manage');

        $treatmentsPayload = $risk->treatments->map(function ($treatment) use ($risk) {
            return [
                'id' => $treatment->id,
                'action_description' => $treatment->action_description,
                'assigned_to' => $treatment->assignedTo
                    ? ['id' => $treatment->assignedTo->id, 'name' => $treatment->assignedTo->name]
                    : null,
                'due_date' => $treatment->due_date?->toDateString(),
                'status' => $treatment->status,
                'expected_score_reduction' => $treatment->expected_score_reduction,
                'evidence_required' => (bool) $treatment->evidence_required,
                'evidence_attachments' => $this->presentTreatmentAttachments($risk, $treatment),
            ];
        })->all();

        return Inertia::render('Governance/Risks/Show', [
            'risk' => array_merge($risk->toArray(), ['treatments' => $treatmentsPayload]),
            'assignees' => User::staff()
                ->select('id', 'name', 'email')
                ->orderBy('name')
                ->get(),
            'canEdit' => $canEdit,
            'canAccept' => auth()->user()->can('accept', $risk),
            // Edit wizard reference data — only for users who can edit this risk.
            'formOptions' => $canEdit ? fn () => $this->riskFormOptions() : null,
        ]);
    }

    public function store(StoreRiskRegisterRequest $request)
    {
        $validated = $request->validated();

        $inherentScore = $this->riskService->calculateInherentScore(
            $validated['likelihood_score'],
            $validated['impact_score']
        );

        $residualScore = $this->riskService->calculateResidualScore(
            $inherentScore,
            $validated['control_effectiveness']
        );

        $threshold = $this->riskService->getAppetiteThreshold($validated['category']);

        $risk = RiskRegisterEntry::create([
            'category' => $validated['category'],
            'title' => $validated['title'],
            'description' => $validated['description'],
            'likelihood_score' => $validated['likelihood_score'] ?? 3,
            'impact_score' => $validated['impact_score'] ?? 3,
            'control_effectiveness' => $validated['control_effectiveness'] ?? 'moderate',
            'risk_owner_id' => $validated['risk_owner_id'] ?? auth()->id(),
            'mitigation_strategy' => $validated['mitigation_strategy'] ?? 'treat',
            'review_frequency' => $validated['review_frequency'] ?? 'quarterly',
            'inherent_score' => $inherentScore,
            'residual_score' => $residualScore,
            'appetite_threshold' => $threshold,
            'within_appetite' => $residualScore <= $threshold,
            'identified_at' => now(),
            'identified_by' => auth()->id(),
            'next_review_date' => now()->addMonths(
                match ($validated['review_frequency'] ?? 'quarterly') {
                    'monthly' => 1,
                    'quarterly' => 3,
                    'annual' => 12,
                }
            ),
        ]);

        // The register's wizard dialog stays on the page and shows its success
        // pane — preserve that context instead of redirecting to the record.
        if ($request->boolean('_modal')) {
            return back()->with('success', "Risk {$risk->risk_reference} registered successfully.");
        }

        return redirect()->route('governance.risks.show', $risk)
            ->with('success', 'Risk registered successfully.');
    }

    public function update(UpdateRiskRegisterRequest $request, RiskRegisterEntry $risk)
    {
        $validated = $request->validated();

        $risk->update($validated);

        // Recalculate scores if necessary
        if (isset($validated['likelihood_score']) ||
            isset($validated['impact_score']) ||
            isset($validated['control_effectiveness'])) {
            $this->riskService->recalculateRisk($risk);
        }

        return redirect()->back()->with('success', 'Risk updated.');
    }

    public function accept(Request $request, RiskRegisterEntry $risk)
    {
        $this->authorize('accept', $risk);

        $validated = $request->validate([
            'justification' => 'required|string|min:50',
            'expiry_months' => 'required|integer|min:1|max:24',
            'conditions' => 'nullable|array',
            'resolution_id' => 'nullable|exists:resolutions,id',
        ]);

        $resolutionId = $validated['resolution_id'] ?? null;

        if (! $risk->within_appetite && empty($resolutionId)) {
            return redirect()->back()->with('error', 'Above-appetite risks require a Board resolution for acceptance. Please create and link a resolution first.');
        }

        $acceptance = $this->riskService->acceptRisk(
            $risk,
            $resolutionId ? 'board_resolution' : 'delegated_authority',
            $validated['justification'],
            auth()->user(),
            $resolutionId,
            null,
            $validated['expiry_months'],
            $validated['conditions'] ?? []
        );

        $risk->update(['status' => 'accepted']);

        return redirect()->back()->with('success', 'Risk acceptance recorded.');
    }

    public function close(Request $request, RiskRegisterEntry $risk)
    {
        $this->authorize('close', $risk);

        $validated = $request->validate([
            'rationale' => 'required|string|min:20',
        ]);

        $risk->close($validated['rationale'], auth()->id());

        return redirect()->route('governance.risks.index')
            ->with('success', 'Risk closed.');
    }

    public function addTreatment(Request $request, RiskRegisterEntry $risk)
    {
        $validated = $request->validate([
            'action_description' => 'required|string',
            'assigned_to' => 'required|exists:users,id',
            'due_date' => 'required|date|after:today',
            'expected_score_reduction' => 'nullable|integer|min:1|max:24',
            'evidence_required' => 'boolean',
        ]);

        $treatment = $this->riskService->createTreatment(
            $risk,
            $validated['action_description'],
            \App\Models\User::find($validated['assigned_to']),
            new \DateTime($validated['due_date']),
            auth()->user(),
            $validated['expected_score_reduction'] ?? null,
            $validated['evidence_required'] ?? false
        );

        return redirect()->back()->with('success', 'Treatment action added.');
    }

    public function linkEvent(Request $request, RiskRegisterEntry $risk)
    {
        $validated = $request->validate([
            'event_type' => 'required|in:incident,alert,safeguarding,audit,breach,complaint',
            'event_id' => 'required|integer',
            'event_reference' => 'nullable|string',
            'event_severity' => 'required|string',
            'event_occurred_at' => 'required|date',
            'link_rationale' => 'nullable|string',
        ]);

        $risk->events()->create([
            ...$validated,
            'linked_by' => auth()->id(),
            'linked_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Event linked to risk.');
    }

    /**
     * The full-page edit form was retired for the shared wizard dialog on the
     * risk record; old deep links open that dialog instead.
     */
    public function edit(RiskRegisterEntry $risk)
    {
        return redirect()->route('governance.risks.show', ['risk' => $risk, 'edit' => 1]);
    }

    public function heatmap(Request $request)
    {
        $validCategories = array_column($this->getCategories(), 'value');
        $category = in_array($request->query('category'), $validCategories, true)
            ? $request->query('category')
            : null;
        $activeOnly = $request->boolean('active');

        return Inertia::render('Governance/Risks/Heatmap', [
            'heatmap' => $this->heatmapCells($category, $activeOnly),
            'trend' => $this->newRiskTrend($category),
            'categories' => $this->getCategories(),
            'filters' => array_filter([
                'category' => $category,
                'active' => $activeOnly ? '1' : null,
            ]),
        ]);
    }

    public function trends()
    {
        $snapshots = \App\Domain\Governance\Models\RiskHeatmapSnapshot::orderByDesc('snapshot_date')
            ->limit(12)
            ->get();

        return Inertia::render('Governance/Risks/Trends', [
            'snapshots' => $snapshots,
        ]);
    }

    public function committeeView(string $committee)
    {
        $validCommittees = ['audit_risk', 'people', 'finance'];
        if (! in_array($committee, $validCommittees)) {
            abort(404);
        }

        $categoryMap = [
            'audit_risk' => ['financial', 'legal_compliance', 'it_cyber'],
            'people' => ['workforce', 'client_safety'],
            'finance' => ['financial', 'operational'],
        ];

        $risks = RiskRegisterEntry::active()
            ->whereIn('category', $categoryMap[$committee])
            ->orderByDesc('residual_score')
            ->get();

        return Inertia::render('Governance/Risks/Committee', [
            'committee' => $committee,
            'risks' => $risks,
        ]);
    }

    protected function canCreateRisks(?User $user): bool
    {
        return $user !== null
            && $user->can('create', RiskRegisterEntry::class)
            && $user->canDo('governance.risks.manage');
    }

    /**
     * Reference data for the risk wizard dialog (add on the index, edit on the record).
     *
     * @return array<string, mixed>
     */
    protected function riskFormOptions(): array
    {
        return [
            'categories' => $this->getCategories(),
            'owners' => User::staff()
                ->select('id', 'name')
                ->orderBy('name')
                ->get(),
        ];
    }

    /**
     * The 5×5 likelihood × impact matrix (rows: likelihood 5→1, columns:
     * impact 1→5). Each cell counts the risks assessed at exactly that
     * likelihood and impact, so a risk is never counted in two cells.
     *
     * @return array<int, array<int, array{score: int, count: int, color: string}>>
     */
    protected function heatmapCells(?string $category, bool $activeOnly): array
    {
        $counts = RiskRegisterEntry::query()
            ->when($category, fn ($q) => $q->byCategory($category))
            ->when($activeOnly, fn ($q) => $q->active())
            ->selectRaw('likelihood_score, impact_score, COUNT(*) as aggregate')
            ->groupBy('likelihood_score', 'impact_score')
            ->get()
            ->mapWithKeys(fn ($row) => ["{$row->likelihood_score}:{$row->impact_score}" => (int) $row->aggregate]);

        $heatmap = [];
        for ($likelihood = 5; $likelihood >= 1; $likelihood--) {
            $row = [];
            for ($impact = 1; $impact <= 5; $impact++) {
                $score = $this->riskService->calculateInherentScore($likelihood, $impact);
                $row[] = [
                    'score' => $score,
                    'count' => $counts["{$likelihood}:{$impact}"] ?? 0,
                    'color' => $this->riskService->getRiskColor($score),
                ];
            }
            $heatmap[] = $row;
        }

        return $heatmap;
    }

    /**
     * New risks identified per month over the last 12 months.
     *
     * @return array<int, array{month: string, new_risks: int}>
     */
    protected function newRiskTrend(?string $category): array
    {
        if ($category === null) {
            return $this->riskService->getTrendAnalysis();
        }

        $trend = [];
        for ($i = 11; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $trend[] = [
                'month' => $date->format('Y-m'),
                'new_risks' => RiskRegisterEntry::byCategory($category)
                    ->whereYear('created_at', $date->year)
                    ->whereMonth('created_at', $date->month)
                    ->count(),
            ];
        }

        return $trend;
    }

    protected function getCategories(): array
    {
        return [
            ['value' => 'client_safety', 'label' => 'Client Safety'],
            ['value' => 'reputational', 'label' => 'Reputational'],
            ['value' => 'financial', 'label' => 'Financial'],
            ['value' => 'it_cyber', 'label' => 'IT/Cyber'],
            ['value' => 'workforce', 'label' => 'Workforce'],
            ['value' => 'legal_compliance', 'label' => 'Legal/Compliance'],
            ['value' => 'operational', 'label' => 'Operational'],
            ['value' => 'clinical', 'label' => 'Clinical'],
        ];
    }

    /**
     * Upload evidence files (control test results, vendor audit reports, SOC2 docs)
     * against a specific treatment action under a risk.
     */
    public function attachTreatmentFiles(Request $request, RiskRegisterEntry $risk, RiskTreatment $treatment)
    {
        $this->authorize('update', $risk);
        $this->ensureTreatmentBelongsToRisk($risk, $treatment);

        $request->validate([
            'files' => 'required|array|min:1|max:10',
            'files.*' => [
                'required',
                'file',
                'max:20480',
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,gif,webp,csv,txt,md',
            ],
        ]);

        $existing = is_array($treatment->evidence_attachments) ? $treatment->evidence_attachments : [];

        foreach ($request->file('files') as $file) {
            $directory = "governance/risks/{$risk->id}/treatments/{$treatment->id}";
            $extension = $file->getClientOriginalExtension() ?: $file->extension();
            $storedName = Str::uuid()->toString() . ($extension ? ".{$extension}" : '');
            $path = $file->storeAs($directory, $storedName, 'local');

            $existing[] = [
                'id' => Str::uuid()->toString(),
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'uploaded_at' => now()->toIso8601String(),
                'uploaded_by_id' => auth()->id(),
                'uploaded_by_name' => auth()->user()?->name,
            ];
        }

        $treatment->update(['evidence_attachments' => $existing]);

        GovernanceAuditService::log(
            'risk_treatment.attachment_added',
            'RiskTreatment',
            $treatment->id,
            ['risk_id' => $risk->id, 'count' => count($request->file('files'))],
        );

        return $request->wantsJson()
            ? response()->json(['attachments' => $this->presentTreatmentAttachments($risk, $treatment->fresh())])
            : redirect()->back()->with('success', 'Evidence attached to treatment.');
    }

    public function deleteTreatmentAttachment(
        Request $request,
        RiskRegisterEntry $risk,
        RiskTreatment $treatment,
        string $attachment,
    ) {
        $this->authorize('update', $risk);
        $this->ensureTreatmentBelongsToRisk($risk, $treatment);

        $existing = is_array($treatment->evidence_attachments) ? $treatment->evidence_attachments : [];
        $target = collect($existing)->firstWhere('id', $attachment);

        if (! $target) {
            abort(404, 'Attachment not found.');
        }

        if (isset($target['path']) && Storage::disk('local')->exists($target['path'])) {
            Storage::disk('local')->delete($target['path']);
        }

        $remaining = array_values(
            array_filter($existing, fn (array $row) => ($row['id'] ?? null) !== $attachment),
        );

        $treatment->update(['evidence_attachments' => $remaining]);

        GovernanceAuditService::log(
            'risk_treatment.attachment_removed',
            'RiskTreatment',
            $treatment->id,
            ['risk_id' => $risk->id, 'attachment_id' => $attachment, 'original_name' => $target['original_name'] ?? null],
        );

        return $request->wantsJson()
            ? response()->json(['attachments' => $this->presentTreatmentAttachments($risk, $treatment->fresh())])
            : redirect()->back()->with('success', 'Evidence removed.');
    }

    public function downloadTreatmentAttachment(
        RiskRegisterEntry $risk,
        RiskTreatment $treatment,
        string $attachment,
    ) {
        $this->authorize('view', $risk);
        $this->ensureTreatmentBelongsToRisk($risk, $treatment);

        $existing = is_array($treatment->evidence_attachments) ? $treatment->evidence_attachments : [];
        $target = collect($existing)->firstWhere('id', $attachment);

        if (! $target || empty($target['path']) || ! Storage::disk('local')->exists($target['path'])) {
            abort(404, 'Attachment not found.');
        }

        return Storage::disk('local')->download(
            $target['path'],
            $target['original_name'] ?? 'attachment',
            ['Content-Type' => $target['mime_type'] ?? 'application/octet-stream'],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function presentTreatmentAttachments(RiskRegisterEntry $risk, RiskTreatment $treatment): array
    {
        $existing = is_array($treatment->evidence_attachments) ? $treatment->evidence_attachments : [];

        return collect($existing)->map(fn (array $row) => [
            'id' => $row['id'] ?? null,
            'original_name' => $row['original_name'] ?? 'attachment',
            'mime_type' => $row['mime_type'] ?? null,
            'size_bytes' => $row['size_bytes'] ?? null,
            'uploaded_at' => $row['uploaded_at'] ?? null,
            'uploaded_by_name' => $row['uploaded_by_name'] ?? null,
            'download_url' => isset($row['id'])
                ? "/governance/risks/{$risk->id}/treatments/{$treatment->id}/attachments/{$row['id']}/download"
                : null,
        ])->all();
    }

    /**
     * Guard rail — make sure the URL pair (risk, treatment) actually matches.
     */
    protected function ensureTreatmentBelongsToRisk(RiskRegisterEntry $risk, RiskTreatment $treatment): void
    {
        if ($treatment->risk_register_entry_id !== $risk->id) {
            abort(404, 'Treatment not found on this risk.');
        }
    }
}
