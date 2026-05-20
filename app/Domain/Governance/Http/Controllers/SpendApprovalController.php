<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\SpendApproval;
use App\Domain\Governance\Services\GovernanceAuditService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
}
