<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrExpenseClaim;
use App\Domain\Hr\Services\ExpenseService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ExpenseController extends Controller
{
    public function __construct(
        private readonly ExpenseService $expenseService,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Index — expense claims list                                        */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.expenses.viewAny'), 403);

        $tenantId = null;
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
                'create' => $user->canDo('hr.expenses.create'),
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
        abort_unless($user && $user->canDo('hr.expenses.create'), 403);

        return Inertia::render('hr/expenses/create', [
            'categories' => ExpenseService::CATEGORIES,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Store — persist new expense claim                                   */
    /* ------------------------------------------------------------------ */

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.expenses.create'), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'currency' => ['nullable', 'string', 'max:3'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.category' => ['required', 'string', Rule::in(ExpenseService::CATEGORIES)],
            'items.*.amount' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'items.*.expense_date' => ['required', 'date'],
            'items.*.tax_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

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
        abort_unless($user && $user->canDo('hr.expenses.viewAny'), 403);

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
                'approve' => $user->canDo('hr.expenses.manage') && $expenseClaim->status === 'submitted',
                'manage' => $user->canDo('hr.expenses.manage'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Submit — submit draft claim for approval                           */
    /* ------------------------------------------------------------------ */

    public function submit(Request $request, HrExpenseClaim $expenseClaim)
    {
        $user = $request->user();
        abort_unless($user && ($user->canDo('hr.expenses.create') || $expenseClaim->user_id === $user->id), 403);

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
        abort_unless($user && $user->canDo('hr.expenses.manage'), 403);

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
        abort_unless($user && $user->canDo('hr.expenses.manage'), 403);

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
}
