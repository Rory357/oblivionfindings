<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\SpendApproval;
use App\Domain\Governance\Services\SpendApprovalCommandService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class SpendApprovalController extends Controller
{
    public function __construct(private readonly SpendApprovalCommandService $commands) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', SpendApproval::class);

        $scope = $this->commands->accessibleApprovalQuery($request->user());
        $query = (clone $scope)
            ->select([
                'id', 'reference', 'title', 'category', 'amount', 'currency',
                'status', 'requires_board', 'requested_by', 'decided_by',
                'resolution_id', 'submitted_at', 'decided_at', 'created_at',
            ])
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
            'pending' => (clone $scope)->whereIn('status', [SpendApproval::STATUS_DRAFT, SpendApproval::STATUS_SUBMITTED])->count(),
            'approved_ytd' => (clone $scope)->where('status', SpendApproval::STATUS_APPROVED)
                ->whereYear('decided_at', now()->year)->sum('amount'),
            'rejected_ytd' => (clone $scope)->where('status', SpendApproval::STATUS_REJECTED)
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

    public function create(Request $request): Response
    {
        $this->authorize('create', SpendApproval::class);
        $this->commands->assertHasAccessibleSite($request->user());

        return Inertia::render('Governance/SpendApprovals/Create', [
            'sites' => $this->commands->accessibleSiteOptions($request->user()),
            'categories' => SpendApproval::categories(),
            'thresholds' => [
                'capex' => SpendApproval::thresholdFor(SpendApproval::CATEGORY_CAPEX),
                'opex' => SpendApproval::thresholdFor(SpendApproval::CATEGORY_OPEX),
                'supplier_contract' => SpendApproval::thresholdFor(SpendApproval::CATEGORY_SUPPLIER_CONTRACT),
                'donor_restricted' => SpendApproval::thresholdFor(SpendApproval::CATEGORY_DONOR_RESTRICTED),
            ],
        ]);
    }

    public function edit(Request $request, SpendApproval $approval): Response
    {
        $this->authorize('requestAny', SpendApproval::class);
        $approval = $this->commands->resolveAccessibleApproval($request->user(), $approval->id);
        $this->authorize('update', $approval);

        return Inertia::render('Governance/SpendApprovals/Edit', [
            'approval' => $approval,
            'sites' => $this->commands->accessibleSiteOptions($request->user()),
            'categories' => SpendApproval::categories(),
            'thresholds' => [
                'capex' => SpendApproval::thresholdFor(SpendApproval::CATEGORY_CAPEX),
                'opex' => SpendApproval::thresholdFor(SpendApproval::CATEGORY_OPEX),
                'supplier_contract' => SpendApproval::thresholdFor(SpendApproval::CATEGORY_SUPPLIER_CONTRACT),
                'donor_restricted' => SpendApproval::thresholdFor(SpendApproval::CATEGORY_DONOR_RESTRICTED),
            ],
        ]);
    }

    public function show(Request $request, SpendApproval $approval): Response
    {
        $this->authorize('view', $approval);
        $approval = $this->commands->resolveAccessibleApproval($request->user(), $approval->id);
        $this->commands->assertCanonicalSourceForRead($request->user(), $approval);
        $approval->load([
            'requestedBy:id,name,email',
            'submittedBy:id,name,email',
            'decidedBy:id,name,email',
            'resolution:id,title,outcome,votes_for,votes_against',
            'budget:id,fiscal_year,title',
        ]);

        return Inertia::render('Governance/SpendApprovals/Show', [
            'approval' => $approval,
            'categories' => SpendApproval::categories(),
            'threshold' => SpendApproval::thresholdFor($approval->category),
            'attachments' => $this->presentAttachments($approval),
            'authority' => [
                'update' => Gate::forUser($request->user())->allows('update', $approval),
                'submit' => Gate::forUser($request->user())->allows('submit', $approval),
                'decide' => Gate::forUser($request->user())->allows('decide', $approval),
                'manage_attachments' => Gate::forUser($request->user())->allows('manageAttachments', $approval),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', SpendApproval::class);
        $this->commands->assertHasAccessibleSite($request->user());
        $data = $this->validatePayload($request);
        $approval = $this->commands->create($request->user(), $data);

        return redirect()->route('governance.spend-approvals.show', $approval)
            ->with('success', 'Spend approval drafted.');
    }

    public function update(Request $request, SpendApproval $approval): RedirectResponse
    {
        $this->authorize('requestAny', SpendApproval::class);
        $approval = $this->commands->resolveAccessibleApproval($request->user(), $approval->id);
        $this->authorize('update', $approval);

        $data = $this->validatePayload($request);
        $expectedVersion = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
        ])['expected_version'];

        $this->commands->update($request->user(), $approval->id, $data, (int) $expectedVersion);

        return back()->with('success', 'Spend approval updated.');
    }

    public function submit(Request $request, SpendApproval $approval): RedirectResponse
    {
        $this->authorize('requestAny', SpendApproval::class);
        $approval = $this->commands->resolveAccessibleApproval($request->user(), $approval->id);
        $this->authorize('submit', $approval);
        $expectedVersion = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
        ])['expected_version'];
        $this->commands->submit($request->user(), $approval->id, (int) $expectedVersion);

        return back()->with('success', 'Spend approval submitted for sign-off.');
    }

    public function approve(Request $request, SpendApproval $approval): RedirectResponse
    {
        $this->authorize('decideAny', SpendApproval::class);
        $approval = $this->commands->resolveAccessibleApproval($request->user(), $approval->id);
        $this->authorize('decide', $approval);
        $validated = $this->validateDecision($request);
        $this->commands->decide($request->user(), $approval->id, SpendApproval::STATUS_APPROVED, $validated);

        return back()->with('success', 'Spend approval approved.');
    }

    public function reject(Request $request, SpendApproval $approval): RedirectResponse
    {
        $this->authorize('decideAny', SpendApproval::class);
        $approval = $this->commands->resolveAccessibleApproval($request->user(), $approval->id);
        $this->authorize('decide', $approval);
        $validated = $this->validateDecision($request);
        $this->commands->decide($request->user(), $approval->id, SpendApproval::STATUS_REJECTED, $validated);

        return back()->with('success', 'Spend approval rejected.');
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['required', 'string', 'in:capex,opex,supplier_contract,donor_restricted'],
            'amount' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'currency' => ['nullable', 'string', 'size:3'],
            'source_type' => ['nullable', 'string', 'required_with:source_id', Rule::in(SpendApproval::SOURCE_TYPES)],
            'source_id' => ['nullable', 'integer', 'min:1', 'required_with:source_type'],
            'site_id' => ['nullable', 'integer', 'min:1'],
            'cost_centre_id' => ['nullable', 'integer', 'min:1'],
            'funding_stream_id' => ['nullable', 'integer', 'min:1'],
            'donor_fund_id' => ['nullable', 'integer', 'min:1'],
            'budget_id' => ['nullable', 'integer', 'min:1'],
            'budget_line_item_id' => ['nullable', 'integer', 'min:1'],
            'valid_until' => ['nullable', 'date'],
        ]);
    }

    private function validateDecision(Request $request): array
    {
        return $request->validate([
            'decision_key' => ['required', 'uuid'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'expected_content_digest' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'decision_notes' => ['required', 'string', 'max:2000'],
            'resolution_id' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    /**
     * Upload supporting documents (quotes, contracts, invoices, due diligence)
     * for a spend approval. Critical for board audit of money decisions.
     */
    public function attachFiles(Request $request, SpendApproval $approval): RedirectResponse|Response|JsonResponse
    {
        $this->authorize('requestAny', SpendApproval::class);
        $approval = $this->commands->resolveAccessibleApproval($request->user(), $approval->id);
        $this->authorize('manageAttachments', $approval);

        $request->validate([
            'files' => 'required|array|min:1|max:10',
            'files.*' => [
                'required',
                'file',
                'max:20480',
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,gif,webp,csv,txt,md',
            ],
        ]);

        $storedPaths = [];
        $attachments = [];

        try {
            foreach ($request->file('files') as $file) {
                $directory = "governance/spend-approvals/{$approval->id}";
                $extension = $file->getClientOriginalExtension() ?: $file->extension();
                $storedName = Str::uuid()->toString().($extension ? ".{$extension}" : '');
                $path = $file->storeAs($directory, $storedName, 'local');
                $storedPaths[] = $path;
                $attachments[] = [
                    'id' => Str::uuid()->toString(),
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                    'sha256' => hash_file('sha256', Storage::disk('local')->path($path)),
                    'uploaded_at' => now()->toIso8601String(),
                    'uploaded_by_id' => $request->user()->id,
                    'uploaded_by_name' => $request->user()->name,
                ];
            }

            $approval = $this->commands->appendAttachments($request->user(), $approval->id, $attachments);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storedPaths);
            throw $exception;
        }

        return $request->wantsJson()
            ? response()->json(['attachments' => $this->presentAttachments($approval->fresh())])
            : redirect()->back()->with('success', 'Document(s) attached.');
    }

    public function deleteAttachment(Request $request, SpendApproval $approval, string $attachment): RedirectResponse|JsonResponse
    {
        $this->authorize('requestAny', SpendApproval::class);
        $approval = $this->commands->resolveAccessibleApproval($request->user(), $approval->id);
        $this->authorize('manageAttachments', $approval);
        [$approval, $target] = $this->commands->removeAttachment($request->user(), $approval->id, $attachment);

        if (isset($target['path']) && Storage::disk('local')->exists($target['path'])) {
            Storage::disk('local')->delete($target['path']);
        }

        return $request->wantsJson()
            ? response()->json(['attachments' => $this->presentAttachments($approval->fresh())])
            : redirect()->back()->with('success', 'Attachment removed.');
    }

    public function downloadAttachment(Request $request, SpendApproval $approval, string $attachment)
    {
        $this->authorize('download', $approval);
        $approval = $this->commands->resolveAccessibleApproval($request->user(), $approval->id);
        $this->commands->assertCanonicalSourceForRead($request->user(), $approval);
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
