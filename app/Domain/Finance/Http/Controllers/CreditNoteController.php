<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinCreditNote;
use App\Domain\Finance\Models\FinVendor;
use App\Domain\Finance\Services\AccountsPayableService;
use App\Domain\Finance\Http\Requests\StoreCreditNoteRequest;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
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

        $user = $request->user();
        $canManage = (bool) $user->can('create', FinCreditNote::class);

        return Inertia::render('finance/credit-notes/Index', [
            'creditNotes' => $creditNotes,
            'filters' => $request->only(['type', 'status']),
            'canManage' => $canManage,
            // Reference data for the create modal.
            'vendors' => $canManage ? $this->vendorOptions($orgId) : [],
            'clients' => $canManage ? $this->clientOptions($orgId) : [],
            'accounts' => $canManage ? $this->accountOptions($orgId) : [],
        ]);
    }

    /**
     * Stream the (filtered) credit-note list as a sanitised CSV. Mirrors the
     * index's type/status filters so "Export" respects the current view. Party
     * resolves to the client (receivable) or the vendor (payable).
     */
    public function export(Request $request)
    {
        $this->authorize('viewAny', FinCreditNote::class);

        $orgId = $request->user()->organization_id;

        $query = FinCreditNote::forOrganization($orgId)
            ->with(['vendor:id,name', 'client:id,first_name,last_name'])
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

        $rows = $query->get()->map(fn (FinCreditNote $cn) => [
            $cn->credit_note_number,
            $cn->type,
            $cn->type === 'receivable'
                ? optional($cn->client)->full_name
                : optional($cn->vendor)->name,
            optional($cn->credit_date)->format('Y-m-d'),
            number_format((float) $cn->subtotal, 2, '.', ''),
            number_format((float) $cn->gst_amount, 2, '.', ''),
            number_format((float) $cn->total_amount, 2, '.', ''),
            $cn->status,
        ]);

        return $this->streamSanitizedCsv(
            'credit-notes-'.now()->format('Y-m-d').'.csv',
            ['Credit Note #', 'Type', 'Party', 'Date', 'Subtotal', 'GST', 'Total', 'Status'],
            $rows,
        );
    }

    /** Active vendors for the credit-note modal. */
    private function vendorOptions(?int $orgId)
    {
        return FinVendor::forOrganization($orgId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /** Clients for the credit-note modal (receivable type). */
    private function clientOptions(?int $orgId)
    {
        return Client::query()
            ->when(
                $orgId && Schema::hasColumn('clients', 'organization_id'),
                fn ($query) => $query->where('organization_id', $orgId),
            )
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name'])
            ->map(fn (Client $client) => [
                'id' => $client->id,
                'name' => trim($client->first_name . ' ' . $client->last_name),
            ])
            ->values();
    }

    /** Active GL accounts (with type) for the credit-note modal's per-line account picker. */
    private function accountOptions(?int $orgId)
    {
        return FinAccount::forOrganization($orgId)
            ->active()
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type']);
    }

    public function store(StoreCreditNoteRequest $request)
    {
        $validated = $request->validated();

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
