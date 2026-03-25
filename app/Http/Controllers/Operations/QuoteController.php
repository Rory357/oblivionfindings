<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\PriceBook;
use App\Models\Quote;
use App\Models\ServiceAgreement;
use Illuminate\Http\Request;

class QuoteController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('quotes.viewAny'), 403);

        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:draft,sent,accepted,declined,expired,converted'],
        ]);

        $quotes = Quote::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with(['client:id,first_name,last_name'])
            ->when(!empty($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return inertia('operations/quotes/Index', [
            'quotes' => $quotes,
            'filters' => $request->only(['status']),
        ]);
    }

    public function show(Request $request, $quote)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('quotes.view'), 403);

        $quote = Quote::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with(['client:id,first_name,last_name', 'lineItems'])
            ->findOrFail($quote);

        return inertia('operations/quotes/Show', [
            'quote' => $quote,
        ]);
    }

    public function create(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('quotes.create'), 403);

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

        return inertia('operations/quotes/Create', [
            'clients' => $clients,
            'priceBooks' => $priceBooks,
        ]);
    }

    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('quotes.create'), 403);

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

        foreach ($data['line_items'] as $item) {
            $quote->lineItems()->create([
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'total' => $item['quantity'] * $item['unit_price'],
            ]);
        }

        return redirect()->back()->with('success', 'Quote created.');
    }

    public function edit(Request $request, $quote)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('quotes.edit'), 403);

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

        return inertia('operations/quotes/Edit', [
            'quote' => $quote,
            'clients' => $clients,
            'priceBooks' => $priceBooks,
        ]);
    }

    public function update(Request $request, $quote)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('quotes.edit'), 403);

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
        abort_unless($auth && $auth->canDo('quotes.edit'), 403);

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
        abort_unless($auth && $auth->canDo('quotes.edit'), 403);

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
        abort_unless($auth && $auth->canDo('quotes.edit'), 403);

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
}
