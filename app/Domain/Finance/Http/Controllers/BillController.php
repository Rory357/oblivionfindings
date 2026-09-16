<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Http\Requests\StoreBillRequest;
use App\Domain\Finance\Http\Requests\UpdateBillRequest;
use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinCostCentre;
use App\Domain\Finance\Models\FinFundingStream;
use App\Domain\Finance\Models\FinPurchaseOrder;
use App\Domain\Finance\Models\FinVendor;
use App\Domain\Finance\Services\AccountsPayableService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BillController extends Controller
{
    public function __construct(
        private AccountsPayableService $service,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', FinBill::class);

        $orgId = $request->user()->organization_id;

        $query = FinBill::forOrganization($orgId)
            ->with([
                'vendor:id,name',
                // Lines power the in-place Edit modal's prefill for draft bills.
                'lines:id,bill_id,description,quantity,unit_price,gst_rate,account_id,cost_centre_id,funding_stream_id',
            ])
            ->orderBy('bill_date', 'desc');

        if ($request->filled('status')) {
            $query->withStatus($request->input('status'));
        }

        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', $request->input('vendor_id'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('bill_number', 'like', "%{$search}%")
                    ->orWhere('vendor_reference', 'like', "%{$search}%");
            });
        }

        if ($request->filled('date_from')) {
            $query->where('bill_date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('bill_date', '<=', $request->input('date_to'));
        }

        $bills = $query->paginate(20)->withQueryString();

        $vendors = FinVendor::forOrganization($orgId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name']);

        // Whole-register figures for the header meter row — never the current
        // page of results (DESIGN.md "Page-local counts labelled as totals").
        $allBills = FinBill::forOrganization($orgId)->get();
        $unpaid = $allBills->whereIn('status', ['approved', 'partially_paid']);
        $overdue = $unpaid->filter(fn ($b) => $b->due_date < now());
        $dueThisWeek = $unpaid->filter(fn ($b) => $b->due_date >= now() && $b->due_date <= now()->addDays(7));
        $awaiting = $allBills->whereIn('status', ['draft', 'awaiting_approval']);
        $summary = [
            'total_unpaid' => $unpaid->sum(fn ($b) => $b->total_amount - $b->amount_paid),
            'unpaid_count' => $unpaid->count(),
            'total_overdue' => $overdue->sum(fn ($b) => $b->total_amount - $b->amount_paid),
            'overdue_count' => $overdue->count(),
            'due_this_week' => $dueThisWeek->sum(fn ($b) => $b->total_amount - $b->amount_paid),
            'due_this_week_count' => $dueThisWeek->count(),
            'awaiting_total' => $awaiting->sum(fn ($b) => $b->total_amount),
            'awaiting_count' => $awaiting->count(),
        ];

        $canManage = (bool) $request->user()?->canDo('finance.ap.manage');

        return Inertia::render('finance/bills/Index', [
            'bills' => $bills,
            'vendors' => $vendors,
            'filters' => $request->only(['status', 'vendor_id', 'search', 'date_from', 'date_to']),
            'summary' => $summary,
            'canManage' => $canManage,
            // Expense/asset accounts for the New Bill modal (each line needs one).
            'accounts' => $canManage
                ? FinAccount::forOrganization($orgId)
                    ->active()
                    ->whereIn('type', ['expense', 'asset'])
                    ->orderBy('code')
                    ->get(['id', 'code', 'name'])
                : [],
            // Approved, still-valid governance spend approvals available to link on
            // the bill modal (for the spend-approval gate). Governance-owned; the
            // link is one-directional (a bill points at one, never creates one).
            'spendApprovals' => $canManage ? $this->linkableSpendApprovals($request) : [],
            // Reference data the bill modal needs for full field parity with the
            // retired routed Create/Edit pages.
            'purchaseOrders' => $canManage ? $this->billablePurchaseOrders($orgId) : [],
            'costCentres' => $canManage
                ? FinCostCentre::forOrganization($orgId)->active()->orderBy('name')->get(['id', 'code', 'name'])
                : [],
            'fundingStreams' => $canManage
                ? FinFundingStream::forOrganization($orgId)->active()->orderBy('name')->get(['id', 'code', 'name'])
                : [],
        ]);
    }

    /** Approved purchase orders a bill can be raised against, shaped for the modal. */
    private function billablePurchaseOrders(int $orgId)
    {
        return FinPurchaseOrder::forOrganization($orgId)
            ->withStatus('approved')
            ->orderByDesc('order_date')
            ->get(['id', 'po_number', 'vendor_id', 'total_amount']);
    }

    /**
     * Governance spend approvals a bill can be linked to: APPROVED and not past
     * their validity window. Shaped for the New Bill modal's picker. Single-tenant,
     * so not org-scoped (spend_approvals has no organization_id).
     */
    private function linkableSpendApprovals(Request $request)
    {
        if (! $request->user()->canDo('governance.spend.view')) {
            return [];
        }

        return $this->service->linkableSpendApprovals($request->user());
    }

    /**
     * Stream the (filtered) bill list as a sanitised CSV. Mirrors the index's
     * status/vendor/search/date filters so "Export" respects the current view.
     */
    public function export(Request $request)
    {
        $this->authorize('viewAny', FinBill::class);

        $orgId = $request->user()->organization_id;

        $query = FinBill::forOrganization($orgId)
            ->with('vendor:id,name')
            ->orderBy('bill_date', 'desc');

        if ($request->filled('status')) {
            $query->withStatus($request->input('status'));
        }
        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', $request->input('vendor_id'));
        }
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(fn ($q) => $q->where('bill_number', 'like', "%{$search}%")
                ->orWhere('vendor_reference', 'like', "%{$search}%"));
        }
        if ($request->filled('date_from')) {
            $query->where('bill_date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('bill_date', '<=', $request->input('date_to'));
        }

        $rows = $query->get()->map(fn (FinBill $b) => [
            $b->bill_number,
            optional($b->vendor)->name,
            optional($b->bill_date)->format('Y-m-d'),
            optional($b->due_date)->format('Y-m-d'),
            number_format((float) $b->subtotal, 2, '.', ''),
            number_format((float) $b->gst_amount, 2, '.', ''),
            number_format((float) $b->total_amount, 2, '.', ''),
            $b->status,
        ]);

        return $this->streamSanitizedCsv(
            'bills-'.now()->format('Y-m-d').'.csv',
            ['Bill #', 'Vendor', 'Bill Date', 'Due Date', 'Subtotal', 'GST', 'Total', 'Status'],
            $rows,
        );
    }

    // Create and edit are WizardShell modals on the index/show pages; the
    // retired full-page URLs redirect to the list (routes/finance.php).

    public function store(StoreBillRequest $request)
    {
        $validated = $request->validated();

        $bill = $this->service->createBill(
            $request->user()->organization_id,
            $validated,
            $request->user(),
        );

        return redirect()->route('finance.bills.show', $bill)
            ->with('success', 'Bill created successfully.');
    }

    public function show(Request $request, FinBill $bill)
    {
        $this->authorize('view', $bill);

        $bill->load([
            'vendor',
            'lines.account:id,code,name',
            'lines.costCentre:id,code,name',
            'lines.fundingStream:id,code,name',
            'approvedBy:id,name',
            'journal:id,journal_number,status,posted_at',
            'purchaseOrder:id,po_number',
            'paymentAllocations' => function ($query) {
                $query->orderBy('payment_date', 'desc');
            },
        ]);

        $orgId = $request->user()->organization_id;
        $canManage = (bool) $request->user()?->canDo('finance.ap.manage');

        return Inertia::render('finance/bills/Show', [
            'bill' => $bill,
            'canManage' => $canManage,
            // Reference data for the Edit Bill modal (draft bills only).
            'vendors' => $canManage
                ? FinVendor::forOrganization($orgId)->active()->orderBy('name')
                    ->get(['id', 'name', 'payment_terms_days', 'default_expense_account_id'])
                : [],
            'accounts' => $canManage
                ? FinAccount::forOrganization($orgId)->active()->whereIn('type', ['expense', 'asset'])
                    ->orderBy('code')->get(['id', 'code', 'name'])
                : [],
            'costCentres' => $canManage
                ? FinCostCentre::forOrganization($orgId)->active()->orderBy('name')->get(['id', 'code', 'name'])
                : [],
            'fundingStreams' => $canManage
                ? FinFundingStream::forOrganization($orgId)->active()->orderBy('name')->get(['id', 'code', 'name'])
                : [],
            'purchaseOrders' => $canManage ? $this->billablePurchaseOrders($orgId) : [],
            'spendApprovals' => $canManage ? $this->linkableSpendApprovals($request) : [],
        ]);
    }

    public function update(UpdateBillRequest $request, FinBill $bill)
    {
        if ($bill->status !== 'draft') {
            return redirect()->route('finance.bills.show', $bill)
                ->with('error', 'Only draft bills can be updated.');
        }

        $validated = $request->validated();

        try {
            $this->service->updateBill($bill, $validated, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['bill' => $e->getMessage()]);
        }

        return redirect()->route('finance.bills.show', $bill)
            ->with('success', 'Bill updated successfully.');
    }

    public function approve(Request $request, FinBill $bill)
    {
        $this->authorize('approve', $bill);

        try {
            $this->service->approveBill($bill, $request->user()->id);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['bill' => $e->getMessage()]);
        } catch (\Exception $e) {
            return back()->withErrors(['bill' => 'Failed to approve bill: '.$e->getMessage()]);
        }

        return redirect()->route('finance.bills.show', $bill)
            ->with('success', 'Bill approved and journal posted successfully.');
    }

    public function cancel(Request $request, FinBill $bill)
    {
        $this->authorize('update', $bill);

        try {
            $this->service->cancelBill($bill);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['bill' => $e->getMessage()]);
        }

        return redirect()->route('finance.bills.show', $bill)
            ->with('success', 'Bill cancelled.');
    }
}
