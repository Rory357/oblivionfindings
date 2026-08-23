<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinPaymentRun;
use App\Domain\Finance\Services\ExternalSettlementService;
use App\Domain\Finance\Services\PaymentRunService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PaymentRunController extends Controller
{
    public function __construct(
        private PaymentRunService $service,
        private ExternalSettlementService $externalSettlements,
    ) {}

    public function index(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $query = $this->service->scopeRunsForActor(
            FinPaymentRun::forOrganization($orgId),
            $request->user(),
        )
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

    /**
     * Stream the (filtered) payment-run list as a sanitised CSV. Mirrors the
     * index's status filter so "Export" respects the current view.
     */
    public function export(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $query = $this->service->scopeRunsForActor(
            FinPaymentRun::forOrganization($orgId),
            $request->user(),
        )
            ->with('bankAccount:id,name,bank_name')
            ->withCount('items')
            ->orderByDesc('payment_date')
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->withStatus($request->input('status'));
        }

        $rows = $query->get()->map(fn (FinPaymentRun $run) => [
            $run->run_number,
            optional($run->payment_date)->format('Y-m-d'),
            $run->bankAccount
                ? trim($run->bankAccount->name.' ('.$run->bankAccount->bank_name.')')
                : null,
            (string) $run->items_count,
            number_format((float) $run->total_amount, 2, '.', ''),
            $run->status,
        ]);

        return $this->streamSanitizedCsv(
            'payment-runs-'.now()->format('Y-m-d').'.csv',
            ['Run #', 'Payment Date', 'Bank Account', 'Item Count', 'Total', 'Status'],
            $rows,
        );
    }

    public function create(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $bankAccounts = FinBankAccount::forOrganization($orgId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'bank_name']);

        $bills = $this->service->getApprovedUnpaidBills($orgId, $request->user())
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
            // Canonical organization/Site lookups happen under lock in the
            // service so validation cannot be used as a foreign-ID oracle.
            'bank_account_id' => ['required', 'integer'],
            'payment_date' => 'required|date',
            'bill_ids' => 'required|array|min:1',
            'bill_ids.*' => ['required', 'integer'],
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $run = $this->service->createPaymentRun(
                $request->user()->organization_id,
                $request->user(),
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
        $this->service->assertCanViewRun($request->user(), $paymentRun);

        $paymentRun->load([
            'items.vendor:id,name',
            'items.bill:id,bill_number',
            'bankAccount:id,name,bank_name',
            'journal:id,journal_number',
            'approvedBy:id,name',
            'processedBy:id,name',
            'externalSettlement',
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
                'settlement' => $paymentRun->externalSettlement ? [
                    'status' => $paymentRun->externalSettlement->status,
                    'artifact_sha256' => $paymentRun->externalSettlement->artifact_sha256,
                    'exported_at' => $paymentRun->externalSettlement->exported_at?->toDateTimeString(),
                    'accepted_at' => $paymentRun->externalSettlement->accepted_at?->toDateTimeString(),
                    'acceptance_reference' => $paymentRun->externalSettlement->acceptance_reference,
                    'rejected_at' => $paymentRun->externalSettlement->rejected_at?->toDateTimeString(),
                    'rejection_reason' => $paymentRun->externalSettlement->rejection_reason,
                    'settled_at' => $paymentRun->externalSettlement->settled_at?->toDateTimeString(),
                    'reconciled_at' => $paymentRun->externalSettlement->reconciled_at?->toDateTimeString(),
                ] : null,
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

    public function approve(Request $request, int $paymentRunId)
    {
        $paymentRun = FinPaymentRun::forOrganization($request->user()->organization_id)
            ->findOrFail($paymentRunId);

        $this->authorize('approve', $paymentRun);

        try {
            $this->service->approvePaymentRun($paymentRun, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['payment_run' => $e->getMessage()]);
        }

        return back()->with('success', 'Payment run approved successfully.');
    }

    public function process(Request $request, FinPaymentRun $paymentRun)
    {
        $this->authorize('process', $paymentRun);
        $this->service->assertCanManageRun($request->user(), $paymentRun);

        try {
            $this->service->processPaymentRun($paymentRun, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['payment_run' => $e->getMessage()]);
        } catch (\Exception $e) {
            return back()->withErrors(['payment_run' => 'An error occurred while processing the payment run. Please try again.']);
        }

        return back()->with('success', 'Payment run prepared. Download and submit the bank file before recording acceptance.');
    }

    public function download(Request $request, FinPaymentRun $paymentRun)
    {
        $this->authorize('process', $paymentRun);
        $this->service->assertCanManageRun($request->user(), $paymentRun);

        if (! $paymentRun->file_path) {
            return back()->withErrors(['payment_run' => 'No bank file available for this payment run.']);
        }

        try {
            $artifact = $this->externalSettlements->exportArtifact(
                $paymentRun,
                ExternalSettlementService::PAYMENT_RUN,
                $request->user(),
            );
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['payment_run' => $exception->getMessage()]);
        }

        return response()->streamDownload(
            static function () use ($artifact): void {
                echo $artifact['contents'];
            },
            $paymentRun->run_number.'.csv',
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function accept(Request $request, FinPaymentRun $paymentRun)
    {
        $this->authorize('process', $paymentRun);
        $this->service->assertCanManageRun($request->user(), $paymentRun);
        $validated = $request->validate($this->evidenceRules());

        try {
            $this->externalSettlements->accept(
                $paymentRun,
                ExternalSettlementService::PAYMENT_RUN,
                $request->user(),
                $validated['idempotency_key'],
                $validated['reference'],
                $validated['evidence'],
            );
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['payment_run' => $exception->getMessage()]);
        }

        return back()->with('success', 'Bank acceptance evidence recorded. The run is ready to settle.');
    }

    public function reject(Request $request, FinPaymentRun $paymentRun)
    {
        $this->authorize('process', $paymentRun);
        $this->service->assertCanManageRun($request->user(), $paymentRun);
        $validated = $request->validate([
            ...$this->evidenceRules(),
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $this->externalSettlements->reject(
                $paymentRun,
                ExternalSettlementService::PAYMENT_RUN,
                $request->user(),
                $validated['idempotency_key'],
                $validated['reference'],
                $validated['reason'],
                $validated['evidence'],
            );
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['payment_run' => $exception->getMessage()]);
        }

        return back()->with('success', 'Bank rejection recorded. The bills are available for a corrected run.');
    }

    public function settle(Request $request, FinPaymentRun $paymentRun)
    {
        $this->authorize('process', $paymentRun);
        $this->service->assertCanManageRun($request->user(), $paymentRun);
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:128'],
        ]);

        try {
            $this->externalSettlements->settlePaymentRun(
                $paymentRun,
                $request->user(),
                $validated['idempotency_key'],
            );
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            return back()->withErrors(['payment_run' => $exception->getMessage()]);
        }

        return back()->with('success', 'Accepted payment run settled and posted.');
    }

    public function reconcile(Request $request, FinPaymentRun $paymentRun)
    {
        $this->authorize('process', $paymentRun);
        $this->service->assertCanManageRun($request->user(), $paymentRun);
        $validated = $request->validate([
            ...$this->evidenceRules(),
            'bank_transaction_id' => ['required', 'integer'],
        ]);

        try {
            $this->externalSettlements->reconcile(
                $paymentRun,
                ExternalSettlementService::PAYMENT_RUN,
                (int) $validated['bank_transaction_id'],
                $request->user(),
                $validated['idempotency_key'],
                $validated['reference'],
                $validated['evidence'],
            );
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['payment_run' => $exception->getMessage()]);
        }

        return back()->with('success', 'Payment run reconciled to the cleared bank transaction.');
    }

    private function evidenceRules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:128'],
            'reference' => ['required', 'string', 'max:255'],
            'evidence' => ['required', 'array', 'min:1'],
        ];
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

        return str_repeat('*', $length - 4).substr($accountNumber, -4);
    }
}
