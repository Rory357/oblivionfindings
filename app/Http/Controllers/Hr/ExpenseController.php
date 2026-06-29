<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Http\Requests\Hr\StoreExpenseClaimRequest;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrExpenseClaim;
use App\Domain\Hr\Models\HrExpenseItem;
use App\Domain\Hr\Services\CompensationService;
use App\Domain\Hr\Services\ExpenseService;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ExpenseController extends Controller
{
    use ResolvesHrTenant, ServesPrivateAttachments;

    public function __construct(
        private readonly ExpenseService $expenseService,
        private readonly CompensationService $compensationService,
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
            // "decided" is a UI lens, not a stored status — expand it to the set
            // of terminal states; any other value filters literally.
            ->when($status === 'decided', fn ($q) => $q->whereIn('status', ['approved', 'rejected', 'paid', 'declined']))
            ->when($status && $status !== 'decided', fn ($q) => $q->where('status', $status))
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

        return Inertia::render('hr/compensation/expenses/index', [
            'claims' => $claims,
            'filters' => [
                'status' => $status,
                'q' => $search,
            ],
            'stats' => $this->compensationService->heroStats($tenantId, $user),
            'tabCounts' => $this->compensationService->tabCounts($tenantId),
            // Surfaced for the New-claim dialog: IRD mileage rate (read-only) +
            // the category list, so the dialog renders the config-driven mileage
            // line (distance × rate) instead of hard-coding anything.
            'mileageRatePerKm' => (float) config('finance.mileage_rate_per_km'),
            'categories' => ExpenseService::CATEGORIES,
            // Managers can file on behalf of any employee in the tenant.
            'employees' => $canManage ? $this->onBehalfEmployees($tenantId) : [],
            'can' => [
                'create' => $user->canDo('hr.expenses.manage'),
                'manage' => $canManage,
                'approve' => $user->canDo('hr.expenses.approve'),
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

        // Optional prefill when arriving from a Development goal / PIP "Claim expense"
        // action — pre-populates the first line item and links it to its source.
        $prefill = null;
        if ($request->filled('description') || $request->filled('source_type')) {
            $prefill = [
                'description' => (string) $request->query('description', ''),
                'category' => in_array($request->query('category'), ExpenseService::CATEGORIES, true)
                    ? $request->query('category') : 'development',
                'amount' => $request->query('amount'),
                'source_type' => $request->query('source_type'),
                'source_id' => $request->query('source_id') !== null ? (int) $request->query('source_id') : null,
            ];
        }

        return Inertia::render('hr/compensation/expenses/create', [
            'categories' => ExpenseService::CATEGORIES,
            'mileageRatePerKm' => (float) config('finance.mileage_rate_per_km'),
            'employees' => $this->onBehalfEmployees($this->resolveHrTenantIdForUser($user)),
            'prefill' => $prefill,
        ]);
    }

    /**
     * Active employees a manager can file an expense on behalf of (user id + name).
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function onBehalfEmployees(int $tenantId): array
    {
        return HrEmployeeProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->with('user:id,name')
            ->get(['id', 'user_id'])
            ->filter(fn ($p) => $p->user !== null)
            ->map(fn ($p) => ['id' => $p->user_id, 'name' => $p->user->name])
            ->values()
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /*  Store — persist new expense claim                                   */
    /* ------------------------------------------------------------------ */

    public function store(StoreExpenseClaimRequest $request)
    {
        $user = $request->user();

        $validated = $request->validated();

        // On-behalf filing: a manager may file for another employee in the tenant.
        // Resolve the owner here (gate + same-tenant guard); ignore the field for
        // non-managers so a self-filer can never reassign ownership.
        $onBehalfOf = null;
        $onBehalfId = $validated['on_behalf_user_id'] ?? null;
        unset($validated['on_behalf_user_id']);
        if ($onBehalfId && $user->canDo('hr.expenses.manage') && (int) $onBehalfId !== (int) $user->id) {
            $tenantId = $this->resolveHrTenantIdForUser($user);
            $inTenant = HrEmployeeProfile::query()
                ->where('user_id', $onBehalfId)
                ->where('tenant_id', $tenantId)
                ->exists();
            abort_unless($inTenant, 422, 'That employee is not in your organisation.');
            $onBehalfOf = User::find($onBehalfId);
        }

        // Persist any uploaded per-item receipts to the private disk and replace the
        // raw upload with its stored path — addItem() consumes receipt_path, and the
        // UploadedFile object must never reach the model.
        foreach ($validated['items'] as $index => $item) {
            unset($validated['items'][$index]['receipt']);

            if ($request->hasFile("items.{$index}.receipt")) {
                $validated['items'][$index]['receipt_path'] = $request
                    ->file("items.{$index}.receipt")
                    ->store("hr/expense-receipts/{$user->id}", 'private');
            }
        }

        try {
            $claim = $this->expenseService->createClaim($user, $validated, $onBehalfOf);
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect("/hr/compensation/expenses/{$claim->id}")->with('success', 'Expense claim created.');
    }

    /* ------------------------------------------------------------------ */
    /*  Show — claim detail + approval                                     */
    /* ------------------------------------------------------------------ */

    public function show(Request $request, HrExpenseClaim $expenseClaim)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.expenses.view'), 403);
        $this->assertHrTenantAccess($this->resolveHrTenantIdForUser($user), $expenseClaim->tenant_id);
        // Staff without manage may only open their own claims (mirrors index/receipt).
        abort_unless($user->canDo('hr.expenses.manage') || $expenseClaim->user_id === $user->id, 403);

        $expenseClaim->load(['user:id,name,email', 'items', 'approver:id,name']);

        return Inertia::render('hr/compensation/expenses/show', [
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
    /*  Receipt — stream a stored per-item receipt (private disk)           */
    /* ------------------------------------------------------------------ */

    public function downloadReceipt(Request $request, HrExpenseClaim $expenseClaim, HrExpenseItem $item)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.expenses.view'), 403);
        $this->assertHrTenantAccess($this->resolveHrTenantIdForUser($user), $expenseClaim->tenant_id);
        // Staff without manage may only open receipts on their own claims.
        abort_unless($user->canDo('hr.expenses.manage') || $expenseClaim->user_id === $user->id, 403);
        abort_unless((int) $item->expense_claim_id === (int) $expenseClaim->id, 404);
        abort_unless($item->receipt_path, 404);

        $ext = strtolower(pathinfo($item->receipt_path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => null,
        };

        return $this->streamPrivateAttachment(
            'private',
            $item->receipt_path,
            "receipt-{$expenseClaim->claim_number}-{$item->id}".($ext ? ".{$ext}" : ''),
            $mime,
            'inline',
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Submit — submit draft claim for approval                           */
    /* ------------------------------------------------------------------ */

    public function submit(Request $request, HrExpenseClaim $expenseClaim)
    {
        $user = $request->user();
        abort_unless($user && ($user->canDo('hr.expenses.manage') || $expenseClaim->user_id === $user->id), 403);
        $this->assertHrTenantAccess($this->resolveHrTenantIdForUser($user), $expenseClaim->tenant_id);

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
        $this->assertHrTenantAccess($this->resolveHrTenantIdForUser($user), $expenseClaim->tenant_id);

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
        $this->assertHrTenantAccess($this->resolveHrTenantIdForUser($user), $expenseClaim->tenant_id);

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
        $this->assertHrTenantAccess($this->resolveHrTenantIdForUser($user), $expenseClaim->tenant_id);

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

    /* ------------------------------------------------------------------ */
    /*  Bulk approve                                                       */
    /* ------------------------------------------------------------------ */

    public function bulkApprove(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.expenses.approve'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $data = $request->validate([
            'claim_ids' => ['required', 'array', 'min:1'],
            'claim_ids.*' => ['integer'],
        ]);

        // Approve only this-tenant, still-submitted claims; skip the rest silently
        // so a stale id in the batch can't 500 the whole action.
        $claims = HrExpenseClaim::forTenant($tenantId)
            ->whereIn('id', $data['claim_ids'])
            ->where('status', 'submitted')
            ->get();

        $approved = 0;
        foreach ($claims as $claim) {
            try {
                $this->expenseService->approveClaim($claim, $user);
                $approved++;
            } catch (\LogicException) {
                // Skip a claim that raced out of the submitted state.
            }
        }

        return redirect()->back()->with(
            'success',
            $approved === 1 ? '1 claim approved.' : "{$approved} claims approved.",
        );
    }
}
