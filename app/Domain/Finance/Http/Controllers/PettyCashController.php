<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinPettyCashFund;
use App\Domain\Finance\Models\FinPettyCashTransaction;
use App\Domain\Finance\Services\PettyCashService;
use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Inertia;

class PettyCashController extends Controller
{
    use ServesPrivateAttachments;

    /** Receipt uploads: images and PDFs only, checked against real content. */
    public const RECEIPT_EXTENSIONS = 'pdf,jpg,jpeg,png,webp,heic';

    public const RECEIPT_MAX_KILOBYTES = 10240;

    public function __construct(
        private PettyCashService $pettyCashService,
    ) {}

    public function index(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $funds = FinPettyCashFund::forOrganization($orgId)
            ->with('custodian:id,name', 'glAccount:id,code,name')
            ->orderBy('name')
            ->get()
            ->map(fn (FinPettyCashFund $fund) => [
                'id' => $fund->id,
                'name' => $fund->name,
                'float_amount' => (float) $fund->float_amount,
                'current_balance' => (float) $fund->current_balance,
                'custodian_name' => $fund->custodian->name ?? null,
                'gl_account_name' => $fund->glAccount ? $fund->glAccount->code.' - '.$fund->glAccount->name : null,
                'is_active' => $fund->is_active,
            ]);

        $canManage = (bool) $request->user()->canDo('finance.petty_cash.manage');

        return Inertia::render('finance/petty-cash/Index', [
            'funds' => $funds,
            'canManage' => $canManage,
            // Reference data for the New Fund modal (asset GL accounts + custodians).
            'accounts' => $canManage
                ? FinAccount::forOrganization($orgId)
                    ->active()
                    ->whereIn('type', ['asset'])
                    ->orderBy('code')
                    ->get(['id', 'code', 'name'])
                : [],
            'users' => $canManage
                ? User::query()
                    ->when(
                        $orgId && Schema::hasColumn('users', 'organization_id'),
                        fn ($query) => $query->where('organization_id', $orgId),
                    )
                    ->orderBy('name')
                    ->get(['id', 'name'])
                : [],
        ]);
    }

    /**
     * Stream the petty-cash fund list as a sanitised CSV. The index has no
     * filters, so this mirrors its ordering and exports every fund.
     */
    public function export(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $rows = FinPettyCashFund::forOrganization($orgId)
            ->with('custodian:id,name', 'glAccount:id,code,name')
            ->orderBy('name')
            ->get()
            ->map(fn (FinPettyCashFund $fund) => [
                $fund->name,
                $fund->custodian->name ?? null,
                number_format((float) $fund->float_amount, 2, '.', ''),
                number_format((float) $fund->current_balance, 2, '.', ''),
                $fund->glAccount ? $fund->glAccount->code.' - '.$fund->glAccount->name : null,
                $fund->is_active ? 'Yes' : 'No',
            ]);

        return $this->streamSanitizedCsv(
            'petty-cash-'.now()->format('Y-m-d').'.csv',
            ['Fund Name', 'Custodian', 'Float Amount', 'Current Balance', 'GL Account', 'Active'],
            $rows,
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'float_amount' => 'required|numeric|min:0.01',
            'gl_account_id' => 'required|exists:fin_accounts,id',
            'custodian_user_id' => 'nullable|exists:users,id',
        ]);

        $fund = $this->pettyCashService->createFund(
            $request->user()->organization_id,
            $validated,
        );

        return redirect()->route('finance.petty-cash.show', $fund)
            ->with('success', 'Petty cash fund created successfully.');
    }

    public function show(Request $request, FinPettyCashFund $fund)
    {
        $orgId = $request->user()->organization_id;

        $summary = $this->pettyCashService->getFundSummary($fund);

        $expenseAccounts = FinAccount::forOrganization($orgId)
            ->active()
            ->ofType('expense')
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        // The stored path never reaches the browser — the page gets a flag and an
        // authorised URL instead, so the private disk's layout stays private.
        $summary['transactions'] = array_map(function (array $row) use ($fund) {
            $hasReceipt = filled($row['receipt_path'] ?? null);
            unset($row['receipt_path']);
            $row['has_receipt'] = $hasReceipt;
            $row['receipt_url'] = $hasReceipt
                ? route('finance.petty-cash.receipt', ['fund' => $fund->id, 'transaction' => $row['id']])
                : null;

            return $row;
        }, $summary['transactions']);

        return Inertia::render('finance/petty-cash/Show', [
            'summary' => $summary,
            'expenseAccounts' => $expenseAccounts,
            'canManage' => (bool) $request->user()->canDo('finance.petty_cash.manage'),
        ]);
    }

    public function storeTransaction(Request $request, FinPettyCashFund $fund)
    {
        $validated = $request->validate([
            'transaction_date' => 'required|date',
            'type' => 'required|in:top_up,expense,adjustment',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:500',
            'account_id' => 'nullable|exists:fin_accounts,id',
            'receipt' => ['nullable', 'file', 'max:'.self::RECEIPT_MAX_KILOBYTES, 'mimes:'.self::RECEIPT_EXTENSIONS],
        ], [
            'receipt.mimes' => 'A receipt must be a PDF or an image (JPG, PNG, WebP or HEIC).',
            'receipt.max' => 'A receipt must be 10 MB or smaller.',
        ]);

        // The receipt lands on the private disk and only its stored path reaches
        // the transaction — the column has always existed, but nothing wrote it.
        unset($validated['receipt']);
        if ($request->hasFile('receipt')) {
            $file = $request->file('receipt');
            $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin');
            $validated['receipt_path'] = $file->storeAs(
                "finance/petty-cash/{$fund->id}/receipts",
                Str::uuid()->toString().'.'.$extension,
                self::$PRIVATE_ATTACHMENT_DISK,
            );
        }

        try {
            $this->pettyCashService->addTransaction($fund, $validated);
        } catch (\Exception $e) {
            return back()->withErrors(['transaction' => $e->getMessage()]);
        }

        return redirect()->route('finance.petty-cash.show', $fund)
            ->with('success', 'Transaction recorded successfully.');
    }

    /**
     * Stream a transaction's receipt from the private disk. The transaction is
     * resolved through the fund, so a receipt can never be pulled across funds.
     */
    public function receipt(Request $request, FinPettyCashFund $fund, FinPettyCashTransaction $transaction)
    {
        abort_unless($fund->organization_id === $request->user()->organization_id, 403);
        abort_unless((int) $transaction->petty_cash_fund_id === (int) $fund->id, 404);
        abort_unless(filled($transaction->receipt_path), 404);

        $extension = strtolower(pathinfo($transaction->receipt_path, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => null,
        };

        return $this->streamPrivateAttachment(
            self::$PRIVATE_ATTACHMENT_DISK,
            $transaction->receipt_path,
            "petty-cash-receipt-{$fund->id}-{$transaction->id}".($extension ? ".{$extension}" : ''),
            $mime,
            'inline',
        );
    }
}
