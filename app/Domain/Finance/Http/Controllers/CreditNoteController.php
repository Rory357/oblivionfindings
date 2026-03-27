<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinCreditNote;
use App\Domain\Finance\Models\FinVendor;
use App\Domain\Finance\Services\AccountsPayableService;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CreditNoteController extends Controller
{
    public function __construct(
        private AccountsPayableService $service,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', FinCreditNote::class);

        $orgId = $request->user()->organization_id;

        $query = FinCreditNote::forOrganization($orgId)
            ->with('vendor:id,name')
            ->orderBy('credit_date', 'desc');

        if ($request->filled('type')) {
            if ($request->input('type') === 'payable') {
                $query->payable();
            } elseif ($request->input('type') === 'receivable') {
                $query->receivable();
            }
        }

        if ($request->filled('status')) {
            $query->withStatus($request->input('status'));
        }

        $creditNotes = $query->paginate(20)->withQueryString();

        return Inertia::render('finance/credit-notes/Index', [
            'creditNotes' => $creditNotes,
            'filters' => $request->only(['type', 'status']),
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('create', FinCreditNote::class);

        $orgId = $request->user()->organization_id;

        $vendors = FinVendor::forOrganization($orgId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name']);

        $clients = Client::where('organization_id', $orgId)
            ->orderBy('name')
            ->get(['id', 'name']);

        $accounts = FinAccount::forOrganization($orgId)
            ->active()
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type']);

        return Inertia::render('finance/credit-notes/Create', [
            'vendors' => $vendors,
            'clients' => $clients,
            'accounts' => $accounts,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', FinCreditNote::class);

        $validated = $request->validate([
            'type' => 'required|in:payable,receivable',
            'vendor_id' => 'nullable|required_if:type,payable|exists:fin_vendors,id',
            'client_id' => 'nullable|required_if:type,receivable|exists:clients,id',
            'credit_date' => 'required|date',
            'reason' => 'nullable|string|max:2000',
            'lines' => 'required|array|min:1',
            'lines.*.description' => 'required|string|max:500',
            'lines.*.quantity' => 'required|numeric|min:0.01',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.gst_rate' => 'nullable|numeric',
            'lines.*.account_id' => 'required|exists:fin_accounts,id',
        ]);

        $creditNote = $this->service->createCreditNote($request->user()->organization_id, $validated);

        return redirect()->route('finance.credit-notes.show', $creditNote)
            ->with('success', 'Credit note created successfully.');
    }

    public function show(Request $request, FinCreditNote $creditNote)
    {
        $this->authorize('view', $creditNote);

        $creditNote->load([
            'vendor:id,name',
            'lines.account:id,code,name',
            'approvedBy:id,name',
            'journal:id,journal_number,status,posted_at',
        ]);

        return Inertia::render('finance/credit-notes/Show', [
            'creditNote' => $creditNote,
        ]);
    }

    public function approve(Request $request, FinCreditNote $creditNote)
    {
        $this->authorize('approve', $creditNote);

        try {
            $this->service->approveCreditNote($creditNote, $request->user()->id);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['credit_note' => $e->getMessage()]);
        } catch (\Exception $e) {
            return back()->withErrors(['credit_note' => 'Failed to approve credit note: ' . $e->getMessage()]);
        }

        return redirect()->route('finance.credit-notes.show', $creditNote)
            ->with('success', 'Credit note approved and journal posted successfully.');
    }
}
