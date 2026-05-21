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

    public function create()
    {
        return Inertia::render('Governance/Risks/Create', [
            'categories' => $this->getCategories(),
        ]);
    }

    public function index(Request $request)
    {
        $query = RiskRegisterEntry::with(['riskOwner', 'treatments', 'acceptances']);

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

        $risks = $query->orderByDesc('residual_score')
            ->paginate(20);

        return Inertia::render('Governance/Risks/Index', [
            'risks' => $risks,
            'categories' => $this->getCategories(),
            'summary' => $this->riskService->getCategorySummary(),
            'filters' => $request->only(['category', 'status', 'severity']),
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

        $canEdit = auth()->user()->can('update', $risk);

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

    public function edit(RiskRegisterEntry $risk)
    {
        return Inertia::render('Governance/Risks/Edit', [
            'risk' => $risk,
        ]);
    }

    public function heatmap()
    {
        return Inertia::render('Governance/Risks/Heatmap', [
            'heatmap' => $this->riskService->generateHeatmapData(),
            'trend' => $this->riskService->getTrendAnalysis(),
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
