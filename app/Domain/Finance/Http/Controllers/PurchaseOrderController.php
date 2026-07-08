<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Http\Requests\StorePurchaseOrderRequest;
use App\Domain\Finance\Http\Requests\UpdatePurchaseOrderRequest;
use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinBillLine;
use App\Domain\Finance\Models\FinCostCentre;
use App\Domain\Finance\Models\FinFundingStream;
use App\Domain\Finance\Models\FinPurchaseOrder;
use App\Domain\Finance\Models\FinVendor;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PurchaseOrderController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', FinPurchaseOrder::class);

        $orgId = $request->user()->organization_id;

        $query = FinPurchaseOrder::forOrganization($orgId)
            ->with('vendor:id,name')
            ->orderBy('order_date', 'desc');

        if ($request->filled('status')) {
            $query->withStatus($request->input('status'));
        }

        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', $request->input('vendor_id'));
        }

        if ($request->filled('search')) {
            $query->where('po_number', 'like', '%'.$request->input('search').'%');
        }

        $purchaseOrders = $query->paginate(15)->withQueryString();

        $vendors = FinVendor::forOrganization($orgId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name']);

        $canManage = (bool) $request->user()?->canDo('finance.ap.manage');

        return Inertia::render('finance/purchase-orders/Index', [
            'purchaseOrders' => $purchaseOrders,
            'vendors' => $vendors,
            'filters' => [
                'status' => $request->input('status', ''),
                'vendor_id' => $request->input('vendor_id', ''),
                'search' => $request->input('search', ''),
            ],
            'canManage' => $canManage,
            // Expense accounts for the New PO modal's optional per-line account.
            'accounts' => $canManage
                ? FinAccount::forOrganization($orgId)->active()->ofType('expense')
                    ->orderBy('code')->get(['id', 'code', 'name'])
                : [],
        ]);
    }

    /**
     * Stream the (filtered) purchase-order list as a sanitised CSV. Mirrors the
     * index's status/vendor/search filters so "Export" respects the current view.
     */
    public function export(Request $request)
    {
        $this->authorize('viewAny', FinPurchaseOrder::class);

        $orgId = $request->user()->organization_id;

        $query = FinPurchaseOrder::forOrganization($orgId)
            ->with('vendor:id,name')
            ->orderBy('order_date', 'desc');

        if ($request->filled('status')) {
            $query->withStatus($request->input('status'));
        }
        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', $request->input('vendor_id'));
        }
        if ($request->filled('search')) {
            $query->where('po_number', 'like', '%'.$request->input('search').'%');
        }

        $rows = $query->get()->map(fn (FinPurchaseOrder $po) => [
            $po->po_number,
            optional($po->vendor)->name,
            optional($po->order_date)->format('Y-m-d'),
            optional($po->expected_date)->format('Y-m-d'),
            number_format((float) $po->subtotal, 2, '.', ''),
            number_format((float) $po->gst_amount, 2, '.', ''),
            number_format((float) $po->total_amount, 2, '.', ''),
            $po->status,
        ]);

        return $this->streamSanitizedCsv(
            'purchase-orders-'.now()->format('Y-m-d').'.csv',
            ['PO #', 'Vendor', 'Order Date', 'Expected Date', 'Subtotal', 'GST', 'Total', 'Status'],
            $rows,
        );
    }

    public function create(Request $request)
    {
        $this->authorize('create', FinPurchaseOrder::class);

        $orgId = $request->user()->organization_id;

        return Inertia::render('finance/purchase-orders/Create', [
            'vendors' => FinVendor::forOrganization($orgId)->active()->orderBy('name')->get(['id', 'name']),
            'accounts' => FinAccount::forOrganization($orgId)->active()->ofType('expense')->orderBy('code')->get(['id', 'code', 'name']),
            'costCentres' => FinCostCentre::forOrganization($orgId)->active()->orderBy('name')->get(['id', 'code', 'name']),
            'fundingStreams' => FinFundingStream::forOrganization($orgId)->active()->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(StorePurchaseOrderRequest $request)
    {
        $validated = $request->validated();

        $orgId = $request->user()->organization_id;
        $poNumber = FinPurchaseOrder::nextNumber($orgId);

        $po = DB::transaction(function () use ($validated, $orgId, $poNumber, $request) {
            $subtotal = 0;
            $gstAmount = 0;

            $lineData = [];
            foreach ($validated['lines'] as $line) {
                $qty = (float) $line['quantity'];
                $price = (float) $line['unit_price'];
                $gstRate = (float) ($line['gst_rate'] ?? 15);
                $lineSubtotal = round($qty * $price, 2);
                $lineGst = round($lineSubtotal * $gstRate / 100, 2);
                $lineTotal = $lineSubtotal + $lineGst;

                $subtotal += $lineSubtotal;
                $gstAmount += $lineGst;

                $lineData[] = [
                    'description' => $line['description'],
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'gst_rate' => $gstRate / 100,
                    'gst_amount' => $lineGst,
                    'line_total' => $lineTotal,
                    'account_id' => $line['account_id'] ?? null,
                ];
            }

            $po = FinPurchaseOrder::create([
                'organization_id' => $orgId,
                'po_number' => $poNumber,
                'vendor_id' => $validated['vendor_id'],
                'status' => 'draft',
                'order_date' => $validated['order_date'],
                'expected_date' => $validated['expected_date'] ?? null,
                'subtotal' => $subtotal,
                'gst_amount' => $gstAmount,
                'total_amount' => $subtotal + $gstAmount,
                'notes' => $validated['notes'] ?? null,
                'cost_centre_id' => $validated['cost_centre_id'] ?? null,
                'funding_stream_id' => $validated['funding_stream_id'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            foreach ($lineData as $ld) {
                $po->lines()->create($ld);
            }

            return $po;
        });

        return redirect()->route('finance.purchase-orders.show', $po)
            ->with('success', 'Purchase order created successfully.');
    }

    public function show(Request $request, FinPurchaseOrder $purchaseOrder)
    {
        $this->authorize('view', $purchaseOrder);

        $purchaseOrder->load([
            'vendor:id,name',
            'lines.account:id,code,name',
            'approvedBy:id,name',
            'costCentre:id,code,name',
            'fundingStream:id,code,name',
            'bills:id,purchase_order_id,bill_number,status,total_amount,bill_date',
        ]);

        return Inertia::render('finance/purchase-orders/Show', [
            'purchaseOrder' => $purchaseOrder,
        ]);
    }

    public function edit(Request $request, FinPurchaseOrder $purchaseOrder)
    {
        $this->authorize('update', $purchaseOrder);

        if ($purchaseOrder->status !== 'draft') {
            return redirect()->route('finance.purchase-orders.show', $purchaseOrder)
                ->with('error', 'Only draft purchase orders can be edited.');
        }

        $orgId = $request->user()->organization_id;

        $purchaseOrder->load('lines');

        return Inertia::render('finance/purchase-orders/Edit', [
            'purchaseOrder' => $purchaseOrder,
            'vendors' => FinVendor::forOrganization($orgId)->active()->orderBy('name')->get(['id', 'name']),
            'accounts' => FinAccount::forOrganization($orgId)->active()->ofType('expense')->orderBy('code')->get(['id', 'code', 'name']),
            'costCentres' => FinCostCentre::forOrganization($orgId)->active()->orderBy('name')->get(['id', 'code', 'name']),
            'fundingStreams' => FinFundingStream::forOrganization($orgId)->active()->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    public function update(UpdatePurchaseOrderRequest $request, FinPurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->status !== 'draft') {
            return redirect()->route('finance.purchase-orders.show', $purchaseOrder)
                ->with('error', 'Only draft purchase orders can be edited.');
        }

        $validated = $request->validated();

        DB::transaction(function () use ($validated, $purchaseOrder) {
            $purchaseOrder->lines()->delete();

            $subtotal = 0;
            $gstAmount = 0;

            foreach ($validated['lines'] as $line) {
                $qty = (float) $line['quantity'];
                $price = (float) $line['unit_price'];
                $gstRate = (float) ($line['gst_rate'] ?? 15);
                $lineSubtotal = round($qty * $price, 2);
                $lineGst = round($lineSubtotal * $gstRate / 100, 2);
                $lineTotal = $lineSubtotal + $lineGst;

                $subtotal += $lineSubtotal;
                $gstAmount += $lineGst;

                $purchaseOrder->lines()->create([
                    'description' => $line['description'],
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'gst_rate' => $gstRate / 100,
                    'gst_amount' => $lineGst,
                    'line_total' => $lineTotal,
                    'account_id' => $line['account_id'] ?? null,
                ]);
            }

            $purchaseOrder->update([
                'vendor_id' => $validated['vendor_id'],
                'order_date' => $validated['order_date'],
                'expected_date' => $validated['expected_date'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'cost_centre_id' => $validated['cost_centre_id'] ?? null,
                'funding_stream_id' => $validated['funding_stream_id'] ?? null,
                'subtotal' => $subtotal,
                'gst_amount' => $gstAmount,
                'total_amount' => $subtotal + $gstAmount,
            ]);
        });

        return redirect()->route('finance.purchase-orders.show', $purchaseOrder)
            ->with('success', 'Purchase order updated successfully.');
    }

    public function approve(Request $request, FinPurchaseOrder $purchaseOrder)
    {
        $this->authorize('approve', $purchaseOrder);

        if ($purchaseOrder->status !== 'draft') {
            return redirect()->route('finance.purchase-orders.show', $purchaseOrder)
                ->with('error', 'Only draft purchase orders can be approved.');
        }

        $purchaseOrder->update([
            'status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        return redirect()->route('finance.purchase-orders.show', $purchaseOrder)
            ->with('success', 'Purchase order approved.');
    }

    public function convertToBill(Request $request, FinPurchaseOrder $purchaseOrder)
    {
        $this->authorize('update', $purchaseOrder);

        if (! in_array($purchaseOrder->status, ['approved', 'partially_received', 'received'])) {
            return redirect()->route('finance.purchase-orders.show', $purchaseOrder)
                ->with('error', 'Only approved or received purchase orders can be converted to a bill.');
        }

        $purchaseOrder->load('lines');

        $orgId = $request->user()->organization_id;
        $billNumber = FinBill::nextNumber($orgId);

        $bill = DB::transaction(function () use ($purchaseOrder, $orgId, $billNumber, $request) {
            $bill = FinBill::create([
                'organization_id' => $orgId,
                'vendor_id' => $purchaseOrder->vendor_id,
                'purchase_order_id' => $purchaseOrder->id,
                'bill_number' => $billNumber,
                'status' => 'draft',
                'bill_date' => now()->toDateString(),
                'due_date' => now()->addDays(
                    $purchaseOrder->vendor?->payment_terms_days ?? 20
                )->toDateString(),
                'subtotal' => $purchaseOrder->subtotal,
                'gst_amount' => $purchaseOrder->gst_amount,
                'total_amount' => $purchaseOrder->total_amount,
                'amount_paid' => 0,
                'notes' => "Created from PO {$purchaseOrder->po_number}",
                'created_by' => $request->user()->id,
            ]);

            foreach ($purchaseOrder->lines as $poLine) {
                FinBillLine::create([
                    'bill_id' => $bill->id,
                    'description' => $poLine->description,
                    'quantity' => $poLine->quantity,
                    'unit_price' => $poLine->unit_price,
                    'gst_rate' => $poLine->gst_rate,
                    'gst_amount' => $poLine->gst_amount,
                    'line_total' => $poLine->line_total,
                    'account_id' => $poLine->account_id,
                    'cost_centre_id' => $purchaseOrder->cost_centre_id,
                    'funding_stream_id' => $purchaseOrder->funding_stream_id,
                ]);
            }

            return $bill;
        });

        return redirect()->route('finance.bills.show', $bill)
            ->with('success', 'Bill created from purchase order.');
    }
}
