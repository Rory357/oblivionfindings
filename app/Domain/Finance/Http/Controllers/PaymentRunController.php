<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinPaymentRun;
use App\Domain\Finance\Services\PaymentRunService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PaymentRunController extends Controller
{
    public function __construct(
        private PaymentRunService $service,
    ) {}

    public function index(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $query = FinPaymentRun::forOrganization($orgId)
            ->with('bankAccount:id,name,bank_name')
            ->withCount('items')
            ->orderByDesc('payment_date')
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->withStatus($request->input('status'));
        }

        $paymentRuns = $query->paginate(20)->through(fn (FinPaymentRun $run) => [
            'id' => $run->id,
            'run_number' => $run->run_number,
            'payment_date' => $run->payment_date->toDateString(),
            'bank_account' => $run->bankAccount ? [
                'id' => $run->bankAccount->id,
                'name' => $run->bankAccount->name,
                'bank_name' => $run->bankAccount->bank_name,
            ] : null,
            'item_count' => $run->items_count,
            'total_amount' => (float) $run->total_amount,
            'status' => $run->status,
            'processed_at' => $run->processed_at?->toDateTimeString(),
        ]);

        return Inertia::render('finance/payment-runs/Index', [
            'paymentRuns' => $paymentRuns,
            'filters' => [
                'status' => $request->input('status', ''),
            ],
        ]);
    }

    public function create(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $bankAccounts = FinBankAccount::forOrganization($orgId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'bank_name']);

        $bills = $this->service->getApprovedUnpaidBills($orgId)
            ->map(fn ($bill) => [
                'id' => $bill->id,
                'bill_number' => $bill->bill_number,
                'bill_date' => $bill->bill_date->toDateString(),
                'due_date' => $bill->due_date->toDateString(),
                'total_amount' => (float) $bill->total_amount,
                'amount_paid' => (float) $bill->amount_paid,
                'amount_due' => $bill->getAmountDue(),
                'vendor' => $bill->vendor ? [
                    'id' => $bill->vendor->id,
                    'name' => $bill->vendor->name,
                ] : null,
            ]);

        return Inertia::render('finance/payment-runs/Create', [
            'bankAccounts' => $bankAccounts,
            'bills' => $bills,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'bank_account_id' => 'required|exists:fin_bank_accounts,id',
            'payment_date' => 'required|date',
            'bill_ids' => 'required|array|min:1',
            'bill_ids.*' => 'exists:fin_bills,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $run = $this->service->createPaymentRun(
                $request->user()->organization_id,
                $validated,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['bill_ids' => $e->getMessage()]);
        }

        return redirect()->route('finance.payment-runs.show', $run->id)
            ->with('success', 'Payment run created successfully.');
    }

    public function show(Request $request, FinPaymentRun $paymentRun)
    {
        $paymentRun->load([
            'items.vendor:id,name',
            'items.bill:id,bill_number',
            'bankAccount:id,name,bank_name',
            'journal:id,journal_number',
            'approvedBy:id,name',
            'processedBy:id,name',
        ]);

        return Inertia::render('finance/payment-runs/Show', [
            'paymentRun' => [
                'id' => $paymentRun->id,
                'run_number' => $paymentRun->run_number,
                'payment_date' => $paymentRun->payment_date->toDateString(),
                'status' => $paymentRun->status,
                'total_amount' => (float) $paymentRun->total_amount,
                'item_count' => $paymentRun->item_count,
                'notes' => $paymentRun->notes,
                'approved_at' => $paymentRun->approved_at?->toDateTimeString(),
                'processed_at' => $paymentRun->processed_at?->toDateTimeString(),
                'file_path' => $paymentRun->file_path,
                'bank_account' => $paymentRun->bankAccount ? [
                    'id' => $paymentRun->bankAccount->id,
                    'name' => $paymentRun->bankAccount->name,
                    'bank_name' => $paymentRun->bankAccount->bank_name,
                ] : null,
                'journal' => $paymentRun->journal ? [
                    'id' => $paymentRun->journal->id,
                    'journal_number' => $paymentRun->journal->journal_number,
                ] : null,
                'approved_by' => $paymentRun->approvedBy ? [
                    'id' => $paymentRun->approvedBy->id,
                    'name' => $paymentRun->approvedBy->name,
                ] : null,
                'processed_by' => $paymentRun->processedBy ? [
                    'id' => $paymentRun->processedBy->id,
                    'name' => $paymentRun->processedBy->name,
                ] : null,
                'items' => $paymentRun->items->map(fn ($item) => [
                    'id' => $item->id,
                    'vendor' => $item->vendor ? [
                        'id' => $item->vendor->id,
                        'name' => $item->vendor->name,
                    ] : null,
                    'bill' => $item->bill ? [
                        'id' => $item->bill->id,
                        'bill_number' => $item->bill->bill_number,
                    ] : null,
                    'amount' => (float) $item->amount,
                    'bank_account_number' => $item->bank_account_number
                        ? $this->maskBankAccount($item->bank_account_number)
                        : null,
                    'reference' => $item->reference,
                    'status' => $item->status,
                ]),
            ],
        ]);
    }

    public function approve(Request $request, FinPaymentRun $paymentRun)
    {
        $this->authorize('approve', $paymentRun);

        try {
            $this->service->approvePaymentRun($paymentRun, $request->user()->id);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['payment_run' => $e->getMessage()]);
        }

        return back()->with('success', 'Payment run approved successfully.');
    }

    public function process(Request $request, FinPaymentRun $paymentRun)
    {
        $this->authorize('process', $paymentRun);

        try {
            $this->service->processPaymentRun($paymentRun, $request->user()->id);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['payment_run' => $e->getMessage()]);
        } catch (\Exception $e) {
            return back()->withErrors(['payment_run' => 'An error occurred while processing the payment run. Please try again.']);
        }

        return back()->with('success', 'Payment run processed successfully.');
    }

    public function download(Request $request, FinPaymentRun $paymentRun)
    {
        if (! $paymentRun->file_path) {
            return back()->withErrors(['payment_run' => 'No bank file available for this payment run.']);
        }

        $fullPath = storage_path('app/' . $paymentRun->file_path);

        if (! file_exists($fullPath)) {
            return back()->withErrors(['payment_run' => 'Bank file not found on disk.']);
        }

        return response()->download($fullPath, $paymentRun->run_number . '.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Mask a bank account number, showing only the last 4 digits.
     */
    private function maskBankAccount(string $accountNumber): string
    {
        $length = strlen($accountNumber);
        if ($length <= 4) {
            return $accountNumber;
        }

        return str_repeat('*', $length - 4) . substr($accountNumber, -4);
    }
}
