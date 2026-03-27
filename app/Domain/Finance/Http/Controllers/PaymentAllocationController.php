<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinPaymentAllocation;
use App\Domain\Finance\Services\AccountsPayableService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PaymentAllocationController extends Controller
{
    public function __construct(
        private AccountsPayableService $accountsPayableService,
    ) {}

    public function index(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $query = FinPaymentAllocation::forOrganization($orgId)
            ->with('allocatable')
            ->orderByDesc('payment_date');

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        $allocations = $query->paginate(20)->through(fn (FinPaymentAllocation $alloc) => [
            'id' => $alloc->id,
            'type' => $alloc->type,
            'payment_date' => $alloc->payment_date->toDateString(),
            'amount' => (float) $alloc->amount,
            'allocatable_type' => class_basename($alloc->allocatable_type ?? ''),
            'allocatable_id' => $alloc->allocatable_id,
            'notes' => $alloc->notes,
            'created_at' => $alloc->created_at->toDateTimeString(),
        ]);

        return Inertia::render('finance/payment-allocations/Index', [
            'allocations' => $allocations,
            'filters' => [
                'type' => $request->input('type', ''),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|in:payable,receivable',
            'amount' => 'required|numeric|min:0.01',
            'payment_date' => 'required|date',
            'allocatable_type' => 'required|string|in:bill,invoice',
            'allocatable_id' => 'required|integer',
            'notes' => 'nullable|string|max:1000',
        ]);

        $orgId = $request->user()->organization_id;

        DB::transaction(function () use ($validated, $orgId, $request) {
            $allocatableType = $validated['allocatable_type'] === 'bill'
                ? FinBill::class
                : \App\Domain\Finance\Models\FinInvoice::class;

            $allocation = FinPaymentAllocation::create([
                'organization_id' => $orgId,
                'type' => $validated['type'],
                'payment_date' => $validated['payment_date'],
                'amount' => $validated['amount'],
                'allocatable_type' => $allocatableType,
                'allocatable_id' => $validated['allocatable_id'],
                'notes' => $validated['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            // Update the bill/invoice paid amount
            if ($validated['allocatable_type'] === 'bill') {
                $bill = FinBill::findOrFail($validated['allocatable_id']);
                $this->accountsPayableService->recordPayment($bill, (float) $validated['amount']);
            }
        });

        return back()->with('success', 'Payment allocation recorded successfully.');
    }
}
