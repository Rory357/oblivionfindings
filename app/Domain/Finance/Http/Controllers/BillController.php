<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Http\Requests\StoreBillRequest;
use App\Domain\Finance\Http\Requests\UpdateBillRequest;
use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinCostCentre;
use App\Domain\Finance\Models\FinFundingStream;
use App\Domain\Finance\Models\FinPurchaseOrder;
use App\Domain\Finance\Models\FinTaxRate;
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
                'lines:id,bill_id,description,quantity,unit_price,gst_rate,account_id',
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

        $allBills = FinBill::forOrganization($orgId)->get();
        $summary = [
            'total_unpaid' => $allBills->whereIn('status', ['approved', 'partially_paid'])->sum(fn ($b) => $b->total_amount - $b->amount_paid),
            'total_overdue' => $allBills->whereIn('status', ['approved', 'partially_paid'])->filter(fn ($b) => $b->due_date < now())->sum(fn ($b) => $b->total_amount - $b->amount_paid),
            'due_this_week' => $allBills->whereIn('status', ['approved', 'partially_paid'])->filter(fn ($b) => $b->due_date >= now() && $b->due_date <= now()->addDays(7))->sum(fn ($b) => $b->total_amount - $b->amount_paid),
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
        ]);
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

    public function create(Request $request)
    {
        $this->authorize('create', FinBill::class);

        $orgId = $request->user()->organization_id;

        $vendors = FinVendor::forOrganization($orgId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'payment_terms_days', 'default_expense_account_id']);

        $accounts = FinAccount::forOrganization($orgId)
            ->active()
            ->whereIn('type', ['expense', 'asset'])
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type']);

        $costCentres = FinCostCentre::forOrganization($orgId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $fundingStreams = FinFundingStream::forOrganization($orgId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $taxRates = FinTaxRate::forOrganization($orgId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'rate']);

        $purchaseOrders = FinPurchaseOrder::forOrganization($orgId)
            ->withStatus('approved')
            ->with('lines.account:id,code,name', 'vendor:id,name')
            ->orderBy('order_date', 'desc')
            ->get(['id', 'po_number', 'vendor_id', 'total_amount']);

        return Inertia::render('finance/bills/Create', [
            'vendors' => $vendors,
            'accounts' => $accounts,
            'costCentres' => $costCentres,
            'fundingStreams' => $fundingStreams,
            'taxRates' => $taxRates,
            'purchaseOrders' => $purchaseOrders,
        ]);
    }

    public function store(StoreBillRequest $request)
    {
        $validated = $request->validated();

        $bill = $this->service->createBill($request->user()->organization_id, $validated);

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

        return Inertia::render('finance/bills/Show', [
            'bill' => $bill,
        ]);
    }

    public function edit(Request $request, FinBill $bill)
    {
        $this->authorize('update', $bill);

        if ($bill->status !== 'draft') {
            return redirect()->route('finance.bills.show', $bill)
                ->with('error', 'Only draft bills can be edited.');
        }

        $orgId = $request->user()->organization_id;

        $bill->load('lines');

        $vendors = FinVendor::forOrganization($orgId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'payment_terms_days', 'default_expense_account_id']);

        $accounts = FinAccount::forOrganization($orgId)
            ->active()
            ->whereIn('type', ['expense', 'asset'])
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type']);

        $costCentres = FinCostCentre::forOrganization($orgId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $fundingStreams = FinFundingStream::forOrganization($orgId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $taxRates = FinTaxRate::forOrganization($orgId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'rate']);

        $purchaseOrders = FinPurchaseOrder::forOrganization($orgId)
            ->withStatus('approved')
            ->with('lines.account:id,code,name', 'vendor:id,name')
            ->orderBy('order_date', 'desc')
            ->get(['id', 'po_number', 'vendor_id', 'total_amount']);

        return Inertia::render('finance/bills/Edit', [
            'bill' => $bill,
            'vendors' => $vendors,
            'accounts' => $accounts,
            'costCentres' => $costCentres,
            'fundingStreams' => $fundingStreams,
            'taxRates' => $taxRates,
            'purchaseOrders' => $purchaseOrders,
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
            $this->service->updateBill($bill, $validated);
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
