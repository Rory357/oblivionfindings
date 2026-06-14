<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Http\Requests\Hr\StoreExpenseClaimRequest;
use App\Domain\Hr\Models\HrExpenseClaim;
use App\Domain\Hr\Services\ExpenseService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ExpenseController extends Controller
{
    use ResolvesHrTenant;

    public function __construct(
        private readonly ExpenseService $expenseService,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Index — expense claims list                                        */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.expenses.view'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $status = $request->query('status');
        $search = trim((string) $request->query('q', ''));
        $canManage = $user->canDo('hr.expenses.manage');

        $claims = HrExpenseClaim::forTenant($tenantId)
            ->when(! $canManage, fn ($q) => $q->where('user_id', $user->id))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($search !== '', fn ($q) => $q->whereHas('user', fn ($u) =>
                $u->where('name', 'like', "%{$search}%")
            ))
            ->with('user:id,name,email')
            ->withCount('items')
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $claims->through(fn ($claim) => [
            'id' => $claim->id,
            'claim_number' => $claim->claim_number,
            'title' => $claim->title,
            'staff_name' => $claim->user?->name ?? 'Unknown',
            'status' => $claim->status,
            'total_amount' => (float) $claim->total_amount,
            'currency' => $claim->currency,
            'items_count' => $claim->items_count,
            'submitted_at' => $claim->submitted_at?->toDateString(),
            'created_at' => $claim->created_at?->toDateString(),
        ]);

        return Inertia::render('hr/expenses/index', [
            'claims' => $claims,
            'filters' => [
                'status' => $status,
                'q' => $search,
            ],
            'can' => [
                'create' => $user->canDo('hr.expenses.manage'),
                'manage' => $canManage,
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Create — form for new expense claim                                */
    /* ------------------------------------------------------------------ */

    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.expenses.manage'), 403);

        return Inertia::render('hr/expenses/create', [
            'categories' => ExpenseService::CATEGORIES,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Store — persist new expense claim                                   */
    /* ------------------------------------------------------------------ */

    public function store(StoreExpenseClaimRequest $request)
    {
        $user = $request->user();

        $validated = $request->validated();

        try {
            $claim = $this->expenseService->createClaim($user, $validated);
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect("/hr/expenses/{$claim->id}")->with('success', 'Expense claim created.');
    }

    /* ------------------------------------------------------------------ */
    /*  Show — claim detail + approval                                     */
    /* ------------------------------------------------------------------ */

    public function show(Request $request, HrExpenseClaim $expenseClaim)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.expenses.view'), 403);

        $expenseClaim->load(['user:id,name,email', 'items', 'approver:id,name']);

        return Inertia::render('hr/expenses/show', [
            'claim' => [
                'id' => $expenseClaim->id,
                'claim_number' => $expenseClaim->claim_number,
                'title' => $expenseClaim->title,
                'staff_name' => $expenseClaim->user?->name ?? 'Unknown',
                'status' => $expenseClaim->status,
                'total_amount' => (float) $expenseClaim->total_amount,
                'currency' => $expenseClaim->currency,
                'notes' => $expenseClaim->notes,
                'rejection_reason' => $expenseClaim->rejection_reason,
                'submitted_at' => $expenseClaim->submitted_at?->toDateTimeString(),
                'approved_by' => $expenseClaim->approver?->name,
                'approved_at' => $expenseClaim->approved_at?->toDateTimeString(),
                'paid_at' => $expenseClaim->paid_at?->toDateTimeString(),
                'journal_id' => $expenseClaim->journal_id,
                'gl_posted_at' => $expenseClaim->gl_posted_at?->toDateTimeString(),
                'items' => $expenseClaim->items->map(fn ($item) => [
                    'id' => $item->id,
                    'description' => $item->description,
                    'category' => $item->category,
                    'amount' => (float) $item->amount,
                    'expense_date' => $item->expense_date?->toDateString(),
                    'receipt_path' => $item->receipt_path,
                    'tax_amount' => $item->tax_amount ? (float) $item->tax_amount : null,
                    'notes' => $item->notes,
                ]),
            ],
            'can' => [
                'approve' => $user->canDo('hr.expenses.approve') && $expenseClaim->status === 'submitted',
                'manage' => $user->canDo('hr.expenses.manage'),
                // Pay only once approved AND posted to the GL (mirrors the payroll
                // pay-net gate: a claim must hit the ledger before it is disbursed).
                'pay' => $user->canDo('hr.expenses.approve')
                    && $expenseClaim->status === 'approved'
                    && $expenseClaim->gl_posted_at !== null,
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Submit — submit draft claim for approval                           */
    /* ------------------------------------------------------------------ */

    public function submit(Request $request, HrExpenseClaim $expenseClaim)
    {
        $user = $request->user();
        abort_unless($user && ($user->canDo('hr.expenses.manage') || $expenseClaim->user_id === $user->id), 403);

        try {
            $this->expenseService->submitClaim($expenseClaim);
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Expense claim submitted for approval.');
    }

    /* ------------------------------------------------------------------ */
    /*  Approve                                                            */
    /* ------------------------------------------------------------------ */

    public function approve(Request $request, HrExpenseClaim $expenseClaim)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.expenses.approve'), 403);

        try {
            $this->expenseService->approveClaim($expenseClaim, $user);
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Expense claim approved.');
    }

    /* ------------------------------------------------------------------ */
    /*  Reject                                                             */
    /* ------------------------------------------------------------------ */

    public function reject(Request $request, HrExpenseClaim $expenseClaim)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.expenses.approve'), 403);

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $this->expenseService->rejectClaim($expenseClaim, $user, $validated['rejection_reason']);
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Expense claim rejected.');
    }

    /* ------------------------------------------------------------------ */
    /*  Mark paid                                                          */
    /* ------------------------------------------------------------------ */

    public function pay(Request $request, HrExpenseClaim $expenseClaim)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.expenses.approve'), 403);

        // Only disburse a claim that has been posted to the GL (the approve flow
        // dispatches PostExpenseJournalJob; markPaid itself guards status).
        if ($expenseClaim->gl_posted_at === null) {
            return redirect()->back()->with('error', 'Expense claim must be posted to the general ledger before it can be marked paid.');
        }

        try {
            $this->expenseService->markPaid($expenseClaim);
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Expense claim marked as paid.');
    }
}
