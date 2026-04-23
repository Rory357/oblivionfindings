<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\BillingEntry;
use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('invoices.viewAny'), 403);

        $orgId = $auth->organization_id;

        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:draft,sent,paid,cancelled,overdue'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
        ]);

        $invoices = Invoice::query()
            ->where('organization_id', $orgId)
            ->with(['client:id,first_name,last_name', 'creator:id,name'])
            ->when(!empty($data['status']) && $data['status'] !== 'overdue', fn ($q) => $q->where('status', $data['status']))
            ->when(!empty($data['status']) && $data['status'] === 'overdue', fn ($q) => $q->overdue())
            ->when(!empty($data['client_id']), fn ($q) => $q->where('client_id', $data['client_id']))
            ->orderByDesc('issue_date')
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'draft' => Invoice::where('organization_id', $orgId)->where('status', 'draft')->count(),
            'sent' => Invoice::where('organization_id', $orgId)->where('status', 'sent')->count(),
            'paid' => Invoice::where('organization_id', $orgId)->where('status', 'paid')->count(),
            'overdue' => Invoice::where('organization_id', $orgId)->overdue()->count(),
        ];

        $clients = $this->clientOptions($orgId);

        return inertia('operations/invoices/Index', [
            'invoices' => $invoices,
            'stats' => $stats,
            'clients' => $clients,
            'filters' => $request->only(['status', 'client_id']),
        ]);
    }

    public function create(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('invoices.create'), 403);

        $orgId = $auth->organization_id;

        $clients = $this->clientOptions($orgId);

        $billingEntries = BillingEntry::query()
            ->where('organization_id', $orgId)
            ->where('status', 'approved')
            ->with(['client:id,first_name,last_name'])
            ->orderByDesc('service_date')
            ->get();

        return inertia('operations/invoices/Create', [
            'clients' => $clients,
            'billingEntries' => $billingEntries,
        ]);
    }

    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('invoices.create'), 403);

        $data = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'funding_body' => ['nullable', 'string', 'max:255'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'notes' => ['nullable', 'string'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'items' => ['nullable', 'array'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.quantity' => ['required', 'numeric', 'min:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.billing_entry_id' => ['nullable', 'integer', 'exists:billing_entries,id'],
            'items.*.service_date' => ['nullable', 'date'],
            'items.*.category' => ['nullable', 'string', 'max:100'],
        ]);

        abort_unless(!empty($data['client_id']) || !empty($data['funding_body']), 422, 'Either client or funding body is required.');

        $orgId = $auth->organization_id;

        // Generate invoice number: INV-YYYYMM-NNN
        $yearMonth = now()->format('Ym');
        $prefix = "INV-{$yearMonth}-";
        $lastInvoice = Invoice::where('organization_id', $orgId)
            ->where('invoice_number', 'like', "{$prefix}%")
            ->orderByDesc('invoice_number')
            ->first();

        $sequence = 1;
        if ($lastInvoice) {
            $lastSeq = (int) substr($lastInvoice->invoice_number, strlen($prefix));
            $sequence = $lastSeq + 1;
        }
        $invoiceNumber = $prefix . str_pad($sequence, 3, '0', STR_PAD_LEFT);

        // Calculate totals from items
        $subtotal = 0;
        $items = $data['items'] ?? [];
        foreach ($items as $item) {
            $subtotal += $item['quantity'] * $item['unit_price'];
        }
        $taxAmount = 0; // GST calculation can be added later
        $totalAmount = $subtotal + $taxAmount;

        $invoice = Invoice::create([
            'organization_id' => $orgId,
            'client_id' => $data['client_id'] ?? null,
            'funding_body' => $data['funding_body'] ?? null,
            'invoice_number' => $invoiceNumber,
            'status' => 'draft',
            'issue_date' => $data['issue_date'],
            'due_date' => $data['due_date'] ?? null,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total_amount' => $totalAmount,
            'notes' => $data['notes'] ?? null,
            'payment_terms' => $data['payment_terms'] ?? null,
            'created_by' => $auth->id,
        ]);

        foreach ($items as $item) {
            $invoice->items()->create([
                'organization_id' => $orgId,
                'billing_entry_id' => $item['billing_entry_id'] ?? null,
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'amount' => $item['quantity'] * $item['unit_price'],
                'service_date' => $item['service_date'] ?? null,
                'category' => $item['category'] ?? null,
            ]);
        }

        return redirect()->route('operations.invoices.show', $invoice)
            ->with('success', 'Invoice created.');
    }

    public function show(Request $request, $invoice)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('invoices.view'), 403);

        $invoice = Invoice::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with([
                'client:id,first_name,last_name',
                'creator:id,name',
                'items',
            ])
            ->findOrFail($invoice);

        return inertia('operations/invoices/Show', [
            'invoice' => $invoice,
        ]);
    }

    public function send(Request $request, $invoice)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('invoices.edit'), 403);

        $invoice = Invoice::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($invoice);

        $invoice->update(['status' => 'sent']);

        return redirect()->back()->with('success', 'Invoice marked as sent.');
    }

    public function markPaid(Request $request, $invoice)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('invoices.edit'), 403);

        $invoice = Invoice::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($invoice);

        $invoice->update([
            'status' => 'paid',
            'paid_date' => now()->toDateString(),
        ]);

        return redirect()->back()->with('success', 'Invoice marked as paid.');
    }

    public function void(Request $request, $invoice)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('invoices.edit'), 403);

        $invoice = Invoice::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($invoice);

        $invoice->update(['status' => 'cancelled']);

        return redirect()->back()->with('success', 'Invoice voided.');
    }

    private function clientOptions(?int $orgId)
    {
        return Client::query()
            ->when(
                $orgId && Schema::hasColumn('clients', 'organization_id'),
                fn ($query) => $query->where('organization_id', $orgId),
            )
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name']);
    }
}
