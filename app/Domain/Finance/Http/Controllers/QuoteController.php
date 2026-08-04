<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinInvoice;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\PriceBook;
use App\Models\Quote;
use App\Models\ServiceAgreement;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuoteController extends Controller
{
    private const APPLICATION_STORAGE_CONTEXT_ID = 1;

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('finance.ar.view'), 403);

        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:draft,sent,accepted,declined,expired,converted'],
        ]);

        $baseQuery = fn () => $this->accessibleQuotes($auth);

        $quotes = $baseQuery()
            ->with(['client:id,first_name,last_name', 'creator:id,name', 'lineItems'])
            ->withCount('lineItems')
            ->when(! empty($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Quote $quote) => [
                'id' => $quote->id,
                'reference' => $quote->quote_number,
                'status' => $quote->status,
                'total_amount' => (float) $quote->total_amount,
                'valid_until' => $quote->valid_until?->toDateString(),
                'created_at' => $quote->created_at?->toDateString(),
                'items_count' => $quote->line_items_count,
                'client' => $quote->client ? [
                    'id' => $quote->client->id,
                    'first_name' => $quote->client->first_name,
                    'last_name' => $quote->client->last_name,
                ] : null,
                'creator' => $quote->creator ? ['id' => $quote->creator->id, 'name' => $quote->creator->name] : null,
                // Raw header + lines so a DRAFT row can prefill the edit modal.
                'client_id' => $quote->client_id,
                'title' => $quote->title,
                'notes' => $quote->notes,
                'lines' => $quote->lineItems->map(fn ($li) => [
                    'description' => $li->description,
                    'quantity' => $li->quantity,
                    'unit_price' => $li->unit_price,
                ])->values(),
            ]);

        $canManage = (bool) $auth->canDo('finance.ar.manage');

        $stats = [
            'total' => $baseQuery()->count(),
            'pending' => $baseQuery()->whereIn('status', ['draft', 'sent'])->count(),
            'accepted' => $baseQuery()->where('status', 'accepted')->count(),
            'converted' => $baseQuery()->where('status', 'converted')->count(),
        ];

        return inertia('finance/quotes/Index', [
            'quotes' => $quotes,
            'filters' => $request->only(['status']),
            'stats' => $stats,
            'canManage' => $canManage,
            // Reference data for the create/edit modal.
            'clients' => $canManage ? $this->clientOptions($auth) : [],
            'priceBooks' => $canManage ? $this->priceBookOptions() : [],
        ]);
    }

    /**
     * Stream the (filtered) quote list as a sanitised CSV. Honours the same
     * status filter as the index so "Export" respects the current view.
     */
    public function export(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('finance.ar.view'), 403);

        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:draft,sent,accepted,declined,expired,converted'],
        ]);

        $rows = $this->accessibleQuotes($auth)
            ->with('client:id,first_name,last_name')
            ->when(! empty($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Quote $quote) => [
                $quote->quote_number,
                $quote->client ? trim($quote->client->first_name.' '.$quote->client->last_name) : ($quote->client_name ?? ''),
                optional($quote->created_at)->format('Y-m-d'),
                optional($quote->valid_until)->format('Y-m-d'),
                number_format((float) $quote->subtotal, 2, '.', ''),
                number_format((float) $quote->tax_amount, 2, '.', ''),
                number_format((float) $quote->total_amount, 2, '.', ''),
                $quote->status,
            ]);

        return $this->streamSanitizedCsv(
            'quotes-'.now()->format('Y-m-d').'.csv',
            ['Quote #', 'Client', 'Date', 'Valid Until', 'Subtotal', 'GST', 'Total', 'Status'],
            $rows,
        );
    }

    /** Client options for the quote modal. */
    private function clientOptions(User $user)
    {
        return $this->siteAccess->applyClientScope(
            Client::query(),
            $user,
            ['reports.viewAny'],
        )
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name']);
    }

    /** Active price books with their rate items for the quote modal's quick-add. */
    private function priceBookOptions()
    {
        return PriceBook::query()
            ->where('is_active', true)
            ->with(['items' => fn ($q) => $q->orderBy('name')])
            ->orderBy('name')
            ->get()
            ->map(fn (PriceBook $pb) => [
                'id' => $pb->id,
                'name' => $pb->name,
                'items' => $pb->items->map(fn ($it) => [
                    'id' => $it->id,
                    'service_code' => $it->service_code ?? null,
                    'name' => $it->name,
                    'unit' => $it->unit,
                    'rate' => (float) $it->rate,
                ])->values(),
            ]);
    }

    public function show(Request $request, $quote)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('finance.ar.view'), 403);

        $quote = $this->accessibleQuotes($auth)
            ->with(['client:id,first_name,last_name', 'lineItems'])
            ->findOrFail($quote);

        return inertia('finance/quotes/Show', [
            'quote' => $quote,
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

        $this->siteAccess->assertCanAccessClientId(
            $auth,
            (int) $data['client_id'],
            ['reports.viewAny'],
        );

        $now = now();
        $count = Quote::withTrashed()
            ->whereYear('created_at', $now->year)
            ->whereMonth('created_at', $now->month)
            ->count();
        $quoteNumber = sprintf('QTE-%s-%03d', $now->format('Ym'), $count + 1);

        $quote = Quote::create([
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

    public function update(Request $request, $quote)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('finance.ar.manage'), 403);

        $quote = $this->accessibleQuotes($auth)->findOrFail($quote);

        $data = $request->validate([
            'client_id' => ['sometimes', 'required', 'integer', 'exists:clients,id'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'valid_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:draft,sent,accepted,declined,expired'],
        ]);

        if (array_key_exists('client_id', $data)) {
            $this->siteAccess->assertCanAccessClientId(
                $auth,
                (int) $data['client_id'],
                ['reports.viewAny'],
            );

            if (
                (int) $data['client_id'] !== (int) $quote->client_id
                && ($quote->converted_to_agreement_id || $quote->converted_to_invoice_id)
            ) {
                throw ValidationException::withMessages([
                    'client_id' => 'A converted quote cannot be reassigned to another Client.',
                ]);
            }
        }

        $quote->update($data);

        return redirect()->back()->with('success', 'Quote updated.');
    }

    public function send(Request $request, $quote)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('finance.ar.manage'), 403);

        $quote = $this->accessibleQuotes($auth)->findOrFail($quote);

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

        $quote = $this->accessibleQuotes($auth)->findOrFail($quote);

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

        $quote = $this->accessibleQuotes($auth)
            ->with(['lineItems'])
            ->findOrFail($quote);

        $agreement = ServiceAgreement::create([
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
                'budget_allocated' => $item->amount,
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

        $quote = $this->accessibleQuotes($auth)
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
                'organization_id' => self::APPLICATION_STORAGE_CONTEXT_ID,
                'client_id' => $quote->client_id,
                'invoice_number' => FinInvoice::nextNumber(self::APPLICATION_STORAGE_CONTEXT_ID),
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

    private function accessibleQuotes(User $user): Builder
    {
        return Quote::query()
            ->whereHas('client', fn (Builder $clientQuery) => $this->siteAccess->applyClientScope(
                $clientQuery,
                $user,
                ['reports.viewAny'],
            ));
    }
}
