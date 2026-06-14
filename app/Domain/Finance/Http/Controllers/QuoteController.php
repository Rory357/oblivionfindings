<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinInvoice;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\PriceBook;
use App\Models\Quote;
use App\Models\ServiceAgreement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuoteController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('finance.ar.view'), 403);

        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:draft,sent,accepted,declined,expired,converted'],
        ]);

        $quotes = Quote::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with(['client:id,first_name,last_name'])
            ->when(! empty($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return inertia('finance/quotes/Index', [
            'quotes' => $quotes,
            'filters' => $request->only(['status']),
        ]);
    }

    public function show(Request $request, $quote)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('finance.ar.view'), 403);

        $quote = Quote::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with(['client:id,first_name,last_name', 'lineItems'])
            ->findOrFail($quote);

        return inertia('finance/quotes/Show', [
            'quote' => $quote,
        ]);
    }

    public function create(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('finance.ar.manage'), 403);

        $clients = Client::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name']);

        $priceBooks = PriceBook::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->where('is_active', true)
            ->with(['items' => fn ($q) => $q->orderBy('name')])
            ->orderBy('name')
            ->get();

        return inertia('finance/quotes/Create', [
            'clients' => $clients,
            'priceBooks' => $priceBooks,
        ]);
    }

    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('finance.ar.manage'), 403);

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'title' => ['required', 'string', 'max:255'],
            'valid_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'line_items' => ['required', 'array', 'min:1'],
            'line_items.*.description' => ['required', 'string', 'max:255'],
            'line_items.*.quantity' => ['required', 'numeric', 'min:0'],
            'line_items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        $now = now();
        $count = Quote::where('organization_id', $auth->organization_id)
            ->whereYear('created_at', $now->year)
            ->whereMonth('created_at', $now->month)
            ->count();
        $quoteNumber = sprintf('QTE-%s-%03d', $now->format('Ym'), $count + 1);

        $quote = Quote::create([
            'organization_id' => $auth->organization_id,
            'client_id' => $data['client_id'],
            'quote_number' => $quoteNumber,
            'title' => $data['title'],
            'valid_until' => $data['valid_until'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => 'draft',
            'created_by' => $auth->id,
        ]);

        // Line items use the `amount` column (NOT NULL); the previous 'total' key
        // was silently dropped, so quote creation failed on the line insert.
        $subtotal = '0';
        foreach ($data['line_items'] as $item) {
            $amount = bcmul((string) $item['quantity'], (string) $item['unit_price'], 2);
            $quote->lineItems()->create([
                'organization_id' => $auth->organization_id,
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'amount' => $amount,
            ]);
            $subtotal = bcadd($subtotal, $amount, 2);
        }

        // Roll the line totals up onto the quote header (NZ GST 15%).
        $tax = bcmul($subtotal, '0.15', 2);
        $quote->update([
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'total_amount' => bcadd($subtotal, $tax, 2),
        ]);

        return redirect()->back()->with('success', 'Quote created.');
    }

    public function edit(Request $request, $quote)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('finance.ar.manage'), 403);

        $quote = Quote::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with(['lineItems'])
            ->findOrFail($quote);

        $clients = Client::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name']);

        $priceBooks = PriceBook::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->where('is_active', true)
            ->with(['items' => fn ($q) => $q->orderBy('name')])
            ->orderBy('name')
            ->get();

        return inertia('finance/quotes/Edit', [
            'quote' => $quote,
            'clients' => $clients,
            'priceBooks' => $priceBooks,
        ]);
    }

    public function update(Request $request, $quote)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('finance.ar.manage'), 403);

        $quote = Quote::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($quote);

        $data = $request->validate([
            'client_id' => ['sometimes', 'required', 'integer', 'exists:clients,id'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'valid_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:draft,sent,accepted,declined,expired'],
        ]);

        $quote->update($data);

        return redirect()->back()->with('success', 'Quote updated.');
    }

    public function send(Request $request, $quote)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('finance.ar.manage'), 403);

        $quote = Quote::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($quote);

        $quote->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Quote sent.');
    }

    public function accept(Request $request, $quote)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('finance.ar.manage'), 403);

        $quote = Quote::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($quote);

        $quote->update([
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Quote accepted.');
    }

    public function convertToAgreement(Request $request, $quote)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('finance.ar.manage'), 403);

        $quote = Quote::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with(['lineItems'])
            ->findOrFail($quote);

        $agreement = ServiceAgreement::create([
            'organization_id' => $auth->organization_id,
            'client_id' => $quote->client_id,
            'title' => $quote->title,
            'status' => 'draft',
            'created_by' => $auth->id,
        ]);

        foreach ($quote->lineItems as $item) {
            $agreement->lineItems()->create([
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'total' => $item->total,
            ]);
        }

        $quote->update([
            'status' => 'converted',
            'converted_to_agreement_id' => $agreement->id,
        ]);

        return redirect()->back()->with('success', 'Quote converted to service agreement.');
    }

    /**
     * Convert an accepted quote into a draft AR invoice (FinInvoice), copying the
     * quote's line items and applying NZ GST. Idempotent — a quote already linked
     * to an invoice returns that invoice.
     */
    public function convertToInvoice(Request $request, $quote)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('finance.ar.manage'), 403);

        $quote = Quote::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with(['lineItems', 'client'])
            ->findOrFail($quote);

        if ($quote->converted_to_invoice_id) {
            return redirect()->route('finance.invoices.show', $quote->converted_to_invoice_id)
                ->with('success', 'Quote already converted to an invoice.');
        }

        $invoice = DB::transaction(function () use ($quote, $auth) {
            $subtotal = '0';
            $taxTotal = '0';
            $lines = [];

            foreach ($quote->lineItems as $index => $item) {
                $net = (string) $item->amount;            // line subtotal (ex-GST)
                $tax = bcmul($net, '0.15', 2);            // NZ GST 15%
                $lineTotal = bcadd($net, $tax, 2);
                $subtotal = bcadd($subtotal, $net, 2);
                $taxTotal = bcadd($taxTotal, $tax, 2);

                $lines[] = [
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'tax_amount' => $tax,
                    'line_total' => $lineTotal,
                    'sort_order' => $index,
                ];
            }

            $invoice = FinInvoice::create([
                'organization_id' => $auth->organization_id,
                'client_id' => $quote->client_id,
                'invoice_number' => FinInvoice::nextNumber($auth->organization_id),
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'client_name' => $quote->client_name
                    ?: ($quote->client ? trim($quote->client->first_name.' '.$quote->client->last_name) : null),
                'client_email' => $quote->client_email,
                'subtotal' => $subtotal,
                'tax_amount' => $taxTotal,
                'total_amount' => bcadd($subtotal, $taxTotal, 2),
                'currency_code' => 'NZD',
                'status' => 'draft',
                'source' => 'quote',
                'source_type' => Quote::class,
                'source_id' => $quote->id,
                'notes' => $quote->notes,
                'terms' => $quote->terms,
                'created_by' => $auth->id,
            ]);

            foreach ($lines as $line) {
                $invoice->lines()->create($line);
            }

            $quote->update([
                'status' => 'converted',
                'converted_to_invoice_id' => $invoice->id,
            ]);

            return $invoice;
        });

        return redirect()->route('finance.invoices.show', $invoice)
            ->with('success', "Quote converted to invoice {$invoice->invoice_number}.");
    }
}
