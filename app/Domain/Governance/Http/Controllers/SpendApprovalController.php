<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\SpendApproval;
use App\Domain\Governance\Services\GovernanceAuditService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SpendApprovalController extends Controller
{
    public function index(Request $request): Response
    {
        $query = SpendApproval::query()
            ->with(['requestedBy:id,name,email', 'decidedBy:id,name,email', 'resolution:id,title,outcome'])
            ->latest('id');

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }
        if ($category = $request->string('category')->toString()) {
            $query->where('category', $category);
        }
        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('reference', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $approvals = $query->paginate(25)->withQueryString();

        $summary = [
            'pending' => SpendApproval::whereIn('status', [SpendApproval::STATUS_DRAFT, SpendApproval::STATUS_SUBMITTED])->count(),
            'approved_ytd' => SpendApproval::where('status', SpendApproval::STATUS_APPROVED)
                ->whereYear('decided_at', now()->year)->sum('amount'),
            'rejected_ytd' => SpendApproval::where('status', SpendApproval::STATUS_REJECTED)
                ->whereYear('decided_at', now()->year)->sum('amount'),
        ];

        return Inertia::render('Governance/SpendApprovals/Index', [
            'approvals' => [
                'data' => $approvals->items(),
                'links' => $approvals->linkCollection()->toArray(),
                'current_page' => $approvals->currentPage(),
                'last_page' => $approvals->lastPage(),
                'total' => $approvals->total(),
                'per_page' => $approvals->perPage(),
            ],
            'filters' => [
                'status' => $request->string('status')->toString() ?: null,
                'category' => $request->string('category')->toString() ?: null,
                'search' => $request->string('search')->toString() ?: null,
            ],
            'summary' => $summary,
            'categories' => SpendApproval::categories(),
            'thresholds' => [
                'capex' => SpendApproval::thresholdFor(SpendApproval::CATEGORY_CAPEX),
                'opex' => SpendApproval::thresholdFor(SpendApproval::CATEGORY_OPEX),
                'supplier_contract' => SpendApproval::thresholdFor(SpendApproval::CATEGORY_SUPPLIER_CONTRACT),
                'donor_restricted' => SpendApproval::thresholdFor(SpendApproval::CATEGORY_DONOR_RESTRICTED),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Governance/SpendApprovals/Create', [
            'categories' => SpendApproval::categories(),
            'thresholds' => [
                'capex' => SpendApproval::thresholdFor(SpendApproval::CATEGORY_CAPEX),
                'opex' => SpendApproval::thresholdFor(SpendApproval::CATEGORY_OPEX),
                'supplier_contract' => SpendApproval::thresholdFor(SpendApproval::CATEGORY_SUPPLIER_CONTRACT),
                'donor_restricted' => SpendApproval::thresholdFor(SpendApproval::CATEGORY_DONOR_RESTRICTED),
            ],
        ]);
    }

    public function edit(SpendApproval $approval): Response
    {
        abort_unless($approval->status === SpendApproval::STATUS_DRAFT, 422, 'Cannot edit a submitted approval');

        return Inertia::render('Governance/SpendApprovals/Edit', [
            'approval' => $approval,
            'categories' => SpendApproval::categories(),
            'thresholds' => [
                'capex' => SpendApproval::thresholdFor(SpendApproval::CATEGORY_CAPEX),
                'opex' => SpendApproval::thresholdFor(SpendApproval::CATEGORY_OPEX),
                'supplier_contract' => SpendApproval::thresholdFor(SpendApproval::CATEGORY_SUPPLIER_CONTRACT),
                'donor_restricted' => SpendApproval::thresholdFor(SpendApproval::CATEGORY_DONOR_RESTRICTED),
            ],
        ]);
    }

    public function show(SpendApproval $approval): Response
    {
        $approval->load([
            'requestedBy:id,name,email',
            'decidedBy:id,name,email',
            'resolution:id,title,outcome,votes_for,votes_against',
            'budget:id,fiscal_year,title',
            'source',
        ]);

        return Inertia::render('Governance/SpendApprovals/Show', [
            'approval' => $approval,
            'categories' => SpendApproval::categories(),
            'threshold' => SpendApproval::thresholdFor($approval->category),
            'attachments' => $this->presentAttachments($approval),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatePayload($request);
        $data['reference'] = $this->nextReference();
        $data['status'] = SpendApproval::STATUS_DRAFT;
        $data['requested_by'] = auth()->id();
        $data['requires_board'] = $this->shouldRequireBoard($data['category'], (float) $data['amount']);

        $approval = SpendApproval::create($data);

        GovernanceAuditService::log('spend_approval.created', 'SpendApproval', $approval->id, [
            'amount' => $approval->amount,
            'category' => $approval->category,
            'requires_board' => $approval->requires_board,
        ]);

        return redirect()->route('governance.spend-approvals.show', $approval)
            ->with('success', 'Spend approval drafted.');
    }

    public function update(Request $request, SpendApproval $approval): RedirectResponse
    {
        abort_unless($approval->status === SpendApproval::STATUS_DRAFT, 422, 'Cannot edit a submitted approval');

        $data = $this->validatePayload($request);
        $data['requires_board'] = $this->shouldRequireBoard($data['category'], (float) $data['amount']);

        $approval->update($data);

        return back()->with('success', 'Spend approval updated.');
    }

    public function submit(SpendApproval $approval): RedirectResponse
    {
        abort_unless($approval->status === SpendApproval::STATUS_DRAFT, 422, 'Only drafts can be submitted');

        $approval->update([
            'status' => SpendApproval::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        GovernanceAuditService::log('spend_approval.submitted', 'SpendApproval', $approval->id);

        return back()->with('success', 'Spend approval submitted for sign-off.');
    }

    public function approve(Request $request, SpendApproval $approval): RedirectResponse
    {
        abort_unless($approval->status === SpendApproval::STATUS_SUBMITTED, 422, 'Only submitted approvals can be decided');

        $validated = $request->validate([
            'decision_notes' => ['nullable', 'string', 'max:2000'],
            'resolution_id' => ['nullable', 'integer', 'exists:resolutions,id'],
        ]);

        DB::transaction(function () use ($approval, $validated) {
            $approval->update([
                'status' => SpendApproval::STATUS_APPROVED,
                'decided_by' => auth()->id(),
                'decided_at' => now(),
                'decision_notes' => $validated['decision_notes'] ?? null,
                'resolution_id' => $validated['resolution_id'] ?? $approval->resolution_id,
            ]);

            GovernanceAuditService::log('spend_approval.approved', 'SpendApproval', $approval->id, [
                'amount' => $approval->amount,
                'resolution_id' => $approval->resolution_id,
            ]);
        });

        return back()->with('success', 'Spend approval approved.');
    }

    public function reject(Request $request, SpendApproval $approval): RedirectResponse
    {
        abort_unless($approval->status === SpendApproval::STATUS_SUBMITTED, 422, 'Only submitted approvals can be decided');

        $validated = $request->validate([
            'decision_notes' => ['required', 'string', 'max:2000'],
        ]);

        $approval->update([
            'status' => SpendApproval::STATUS_REJECTED,
            'decided_by' => auth()->id(),
            'decided_at' => now(),
            'decision_notes' => $validated['decision_notes'],
        ]);

        GovernanceAuditService::log('spend_approval.rejected', 'SpendApproval', $approval->id);

        return back()->with('success', 'Spend approval rejected.');
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['required', 'string', 'in:capex,opex,supplier_contract,donor_restricted'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'site_id' => ['nullable', 'integer'],
            'cost_centre_id' => ['nullable', 'integer'],
            'funding_stream_id' => ['nullable', 'integer'],
            'donor_fund_id' => ['nullable', 'integer'],
            'budget_id' => ['nullable', 'integer', 'exists:budgets,id'],
            'budget_line_item_id' => ['nullable', 'integer', 'exists:budget_line_items,id'],
            'attachments' => ['nullable', 'array'],
            'valid_until' => ['nullable', 'date'],
        ]);
    }

    private function shouldRequireBoard(string $category, float $amount): bool
    {
        return $amount >= SpendApproval::thresholdFor($category);
    }

    private function nextReference(): string
    {
        $year = now()->format('Y');
        $count = SpendApproval::whereYear('created_at', $year)->count() + 1;

        return sprintf('SA-%s-%04d', $year, $count);
    }

    /**
     * Upload supporting documents (quotes, contracts, invoices, due diligence)
     * for a spend approval. Critical for board audit of money decisions.
     */
    public function attachFiles(Request $request, SpendApproval $approval): RedirectResponse|Response|\Illuminate\Http\JsonResponse
    {
        // Drafts can be edited by their requester; submitted approvals lock
        // edits to manage-level users (handled by route middleware).
        if ($approval->status === SpendApproval::STATUS_DRAFT && $approval->requested_by !== auth()->id()) {
            abort(403, 'Only the requester can attach documents to a draft.');
        }

        $request->validate([
            'files' => 'required|array|min:1|max:10',
            'files.*' => [
                'required',
                'file',
                'max:20480',
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,gif,webp,csv,txt,md',
            ],
        ]);

        $existing = is_array($approval->attachments) ? $approval->attachments : [];

        foreach ($request->file('files') as $file) {
            $directory = "governance/spend-approvals/{$approval->id}";
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

        $approval->update(['attachments' => $existing]);

        GovernanceAuditService::log(
            'spend_approval.attachment_added',
            'SpendApproval',
            $approval->id,
            ['count' => count($request->file('files'))],
        );

        return $request->wantsJson()
            ? response()->json(['attachments' => $this->presentAttachments($approval->fresh())])
            : redirect()->back()->with('success', 'Document(s) attached.');
    }

    public function deleteAttachment(Request $request, SpendApproval $approval, string $attachment): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        if ($approval->status === SpendApproval::STATUS_DRAFT && $approval->requested_by !== auth()->id()) {
            abort(403, 'Only the requester can remove documents from a draft.');
        }

        $existing = is_array($approval->attachments) ? $approval->attachments : [];
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

        $approval->update(['attachments' => $remaining]);

        GovernanceAuditService::log(
            'spend_approval.attachment_removed',
            'SpendApproval',
            $approval->id,
            ['attachment_id' => $attachment, 'original_name' => $target['original_name'] ?? null],
        );

        return $request->wantsJson()
            ? response()->json(['attachments' => $this->presentAttachments($approval->fresh())])
            : redirect()->back()->with('success', 'Attachment removed.');
    }

    public function downloadAttachment(SpendApproval $approval, string $attachment)
    {
        // Route is gated by spend.view permission; anyone who can see the
        // approval can download the supporting documents.
        $existing = is_array($approval->attachments) ? $approval->attachments : [];
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
    public function presentAttachments(SpendApproval $approval): array
    {
        $existing = is_array($approval->attachments) ? $approval->attachments : [];

        return collect($existing)->map(fn (array $row) => [
            'id' => $row['id'] ?? null,
            'original_name' => $row['original_name'] ?? 'attachment',
            'mime_type' => $row['mime_type'] ?? null,
            'size_bytes' => $row['size_bytes'] ?? null,
            'uploaded_at' => $row['uploaded_at'] ?? null,
            'uploaded_by_name' => $row['uploaded_by_name'] ?? null,
            'download_url' => isset($row['id'])
                ? "/governance/spend-approvals/{$approval->id}/attachments/{$row['id']}/download"
                : null,
        ])->all();
    }
}
