<?php

namespace App\Http\Controllers\Operations;

use App\Domain\Finance\Jobs\PostFinInvoiceJournalJob;
use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Services\FinInvoiceJournalService;
use App\Http\Controllers\Controller;
use App\Models\BillingEntry;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $invoices = FinInvoice::query()
            ->where('organization_id', $orgId)
            ->with(['client:id,first_name,last_name', 'createdBy:id,name'])
            ->withCount('lines as items_count')
            ->when(! empty($data['status']) && $data['status'] !== 'overdue', fn ($q) => $q->where('status', $data['status']))
            ->when(! empty($data['status']) && $data['status'] === 'overdue', fn ($q) => $q
                ->whereIn('status', ['sent', 'viewed', 'overdue'])
                ->where('due_date', '<', now()->toDateString()))
            ->when(! empty($data['client_id']), fn ($q) => $q->where('client_id', $data['client_id']))
            ->when($request->filled('q'), function ($q) use ($request) {
                $search = $request->string('q')->toString();

                $q->where(function ($query) use ($search) {
                    $query->where('invoice_number', 'like', "%{$search}%")
                        ->orWhere('client_name', 'like', "%{$search}%")
                        ->orWhere('funding_body', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('invoice_date')
            ->paginate(20)
            ->through(fn (FinInvoice $invoice) => $this->operationInvoiceIndexPayload($invoice))
            ->withQueryString();

        $stats = [
            'total' => FinInvoice::where('organization_id', $orgId)->count(),
            'draft' => FinInvoice::where('organization_id', $orgId)->where('status', 'draft')->count(),
            'sent' => FinInvoice::where('organization_id', $orgId)->whereIn('status', ['sent', 'viewed'])->count(),
            'paid' => FinInvoice::where('organization_id', $orgId)->where('status', 'paid')->count(),
            'overdue' => FinInvoice::where('organization_id', $orgId)
                ->whereIn('status', ['sent', 'viewed', 'overdue'])
                ->where('due_date', '<', now()->toDateString())
                ->count(),
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
            ->whereIn('status', ['pending', 'approved'])
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

        $payload = $request->all();
        if (! isset($payload['line_items']) && isset($payload['items'])) {
            $payload['line_items'] = $payload['items'];
        }

        $data = validator($payload, [
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'funding_body' => ['nullable', 'string', 'max:255'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'notes' => ['nullable', 'string'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'line_items' => ['required', 'array', 'min:1'],
            'line_items.*.description' => ['required', 'string', 'max:500'],
            'line_items.*.quantity' => ['required', 'numeric', 'min:0'],
            'line_items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'line_items.*.billing_entry_id' => ['nullable', 'integer', 'exists:billing_entries,id'],
            'line_items.*.service_date' => ['nullable', 'date'],
            'line_items.*.category' => ['nullable', 'string', 'max:100'],
        ])->validate();

        abort_unless(! empty($data['client_id']) || ! empty($data['funding_body']), 422, 'Either client or funding body is required.');

        $orgId = $auth->organization_id;
        $client = ! empty($data['client_id'])
            ? Client::query()
                ->when(
                    Schema::hasColumn('clients', 'organization_id'),
                    fn ($query) => $query->where('organization_id', $orgId),
                )
                ->findOrFail($data['client_id'])
            : null;

        // Generate invoice number: INV-YYYYMM-NNN
        $yearMonth = now()->format('Ym');
        $prefix = "INV-{$yearMonth}-";
        $lastInvoice = FinInvoice::withTrashed()
            ->where('organization_id', $orgId)
            ->where('invoice_number', 'like', "{$prefix}%")
            ->orderByDesc('invoice_number')
            ->first();

        $sequence = 1;
        if ($lastInvoice) {
            $lastSeq = (int) substr($lastInvoice->invoice_number, strlen($prefix));
            $sequence = $lastSeq + 1;
        }
        $invoiceNumber = $prefix.str_pad($sequence, 3, '0', STR_PAD_LEFT);

        // Calculate totals from items
        $subtotal = 0;
        $items = $data['line_items'];
        foreach ($items as $item) {
            $subtotal += $item['quantity'] * $item['unit_price'];
        }
        $taxAmount = round($subtotal * 0.15, 2);
        $totalAmount = $subtotal + $taxAmount;

        $invoice = DB::transaction(function () use ($auth, $client, $data, $invoiceNumber, $items, $orgId, $subtotal, $taxAmount, $totalAmount) {
            $invoice = FinInvoice::create([
                'organization_id' => $orgId,
                'client_id' => $client?->id,
                'funding_body' => $data['funding_body'] ?? null,
                'invoice_number' => $invoiceNumber,
                'status' => 'draft',
                'invoice_date' => $data['issue_date'],
                'due_date' => $data['due_date'] ?? $data['issue_date'],
                'client_name' => $client?->full_name ?? $data['funding_body'],
                'client_email' => $client?->email,
                'client_address' => $this->formatClientAddress($client),
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'notes' => $data['notes'] ?? null,
                'terms' => $data['payment_terms'] ?? null,
                'source' => 'operations',
                'created_by' => $auth->id,
            ]);

            foreach ($items as $index => $item) {
                $lineSubtotal = round((float) $item['quantity'] * (float) $item['unit_price'], 2);
                $lineTax = round($lineSubtotal * 0.15, 2);

                $invoice->lines()->create([
                    'billing_entry_id' => $item['billing_entry_id'] ?? null,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'tax_amount' => $lineTax,
                    'line_total' => $lineSubtotal + $lineTax,
                    'sort_order' => $index,
                    'service_date' => $item['service_date'] ?? null,
                    'category' => $item['category'] ?? null,
                ]);

                if (! empty($item['billing_entry_id'])) {
                    BillingEntry::query()
                        ->where('organization_id', $orgId)
                        ->whereKey($item['billing_entry_id'])
                        ->update(['status' => 'invoiced']);
                }
            }

            return $invoice;
        });

        return redirect()->route('operations.invoices.show', $invoice)
            ->with('success', 'Invoice created.');
    }

    public function show(Request $request, $invoice)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('invoices.viewAny'), 403);

        $invoice = FinInvoice::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with([
                'client:id,first_name,last_name',
                'createdBy:id,name',
                'lines',
            ])
            ->findOrFail($invoice);

        return inertia('operations/invoices/Show', [
            'invoice' => $this->operationInvoiceShowPayload($invoice),
        ]);
    }

    public function send(Request $request, $invoice)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('invoices.send'), 403);

        $invoice = FinInvoice::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($invoice);

        $shouldPostJournal = false;

        DB::transaction(function () use ($invoice, &$shouldPostJournal) {
            $invoice->refresh();

            if ($invoice->status === 'draft') {
                $invoice->update([
                    'status' => 'sent',
                    'sent_at' => $invoice->sent_at ?? now(),
                ]);

                $shouldPostJournal = $invoice->journal_id === null;
            }
        });

        if ($shouldPostJournal) {
            PostFinInvoiceJournalJob::dispatch($invoice->refresh());
        }

        return redirect()->back()->with('success', 'Invoice marked as sent.');
    }

    public function markPaid(Request $request, $invoice)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('invoices.update'), 403);

        $invoice = FinInvoice::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($invoice);

        $invoice->update([
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Invoice marked as paid.');
    }

    public function void(Request $request, $invoice, FinInvoiceJournalService $journalService)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('invoices.void'), 403);

        $invoice = FinInvoice::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($invoice);

        DB::transaction(function () use ($invoice, $journalService) {
            $invoice->refresh();

            if ($invoice->journal_id !== null) {
                $journalService->reverseInvoiceJournal($invoice);
                $invoice->refresh();
            }

            $invoice->update(['status' => 'cancelled']);
        });

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

    private function operationInvoiceIndexPayload(FinInvoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'reference' => $invoice->source === 'operations' ? 'Operations' : null,
            'status' => $invoice->status,
            'issue_date' => $invoice->invoice_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'paid_date' => $invoice->paid_at?->toDateString(),
            'subtotal' => (float) $invoice->subtotal,
            'tax_amount' => (float) $invoice->tax_amount,
            'total_amount' => (float) $invoice->total_amount,
            'client' => $invoice->client ? [
                'id' => $invoice->client->id,
                'first_name' => $invoice->client->first_name,
                'last_name' => $invoice->client->last_name,
            ] : null,
            'funding_body' => $invoice->funding_body,
            'items_count' => $invoice->items_count ?? $invoice->lines()->count(),
        ];
    }

    private function operationInvoiceShowPayload(FinInvoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status,
            'client_id' => $invoice->client_id,
            'funding_body' => $invoice->funding_body,
            'issue_date' => $invoice->invoice_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'payment_terms' => $invoice->terms,
            'notes' => $invoice->notes,
            'subtotal' => (float) $invoice->subtotal,
            'tax' => (float) $invoice->tax_amount,
            'total' => (float) $invoice->total_amount,
            'paid_at' => $invoice->paid_at?->toDateString(),
            'created_at' => $invoice->created_at?->toDateString(),
            'client' => $invoice->client ? [
                'id' => $invoice->client->id,
                'first_name' => $invoice->client->first_name,
                'last_name' => $invoice->client->last_name,
            ] : null,
            'line_items' => $invoice->lines->map(fn ($line) => [
                'id' => $line->id,
                'description' => $line->description,
                'quantity' => (float) $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'amount' => (float) bcsub((string) $line->line_total, (string) $line->tax_amount, 2),
            ])->values(),
        ];
    }

    private function formatClientAddress(?Client $client): ?string
    {
        if (! $client) {
            return null;
        }

        return collect([
            $client->address_line_1,
            $client->address_line_2,
            $client->suburb,
            $client->city,
            $client->postcode,
        ])->filter()->implode(', ') ?: null;
    }
}
