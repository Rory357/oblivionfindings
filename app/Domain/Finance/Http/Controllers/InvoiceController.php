<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Jobs\SendInvoiceEmailJob;
use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Models\FinTaxRate;
use App\Domain\Finance\Services\InvoicePdfService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $query = FinInvoice::forOrganization($orgId)
            ->orderBy('invoice_date', 'desc');

        if ($request->filled('status')) {
            $query->ofStatus($request->input('status'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhere('client_name', 'like', "%{$search}%")
                    ->orWhere('client_email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('date_from')) {
            $query->where('invoice_date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('invoice_date', '<=', $request->input('date_to'));
        }

        $invoices = $query->paginate(20)->withQueryString();

        return Inertia::render('finance/invoices/Index', [
            'invoices' => $invoices,
            'filters' => $request->only(['status', 'search', 'date_from', 'date_to']),
        ]);
    }

    public function create(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $accounts = FinAccount::forOrganization($orgId)
            ->active()
            ->whereIn('type', ['revenue', 'income', 'asset'])
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type']);

        $taxRates = FinTaxRate::forOrganization($orgId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'rate']);

        $bills = FinBill::forOrganization($orgId)
            ->with('vendor:id,name')
            ->orderBy('bill_date', 'desc')
            ->limit(50)
            ->get(['id', 'bill_number', 'vendor_id', 'total_amount']);

        return Inertia::render('finance/invoices/Create', [
            'accounts' => $accounts,
            'taxRates' => $taxRates,
            'bills' => $bills,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'invoice_number' => 'nullable|string|max:50',
            'invoice_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:invoice_date',
            'client_name' => 'required|string|max:255',
            'client_email' => 'nullable|email|max:255',
            'client_address' => 'nullable|string|max:2000',
            'bill_id' => 'nullable|exists:fin_bills,id',
            'currency_code' => 'nullable|string|size:3',
            'notes' => 'nullable|string|max:2000',
            'terms' => 'nullable|string|max:2000',
            'email_subject' => 'nullable|string|max:255',
            'email_body' => 'nullable|string|max:5000',
            'lines' => 'required|array|min:1',
            'lines.*.description' => 'required|string|max:500',
            'lines.*.quantity' => 'required|numeric|min:0.01',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.tax_rate_id' => 'nullable|exists:fin_tax_rates,id',
            'lines.*.account_id' => 'nullable|exists:fin_accounts,id',
        ]);

        $orgId = $request->user()->organization_id;

        $invoice = DB::transaction(function () use ($validated, $orgId, $request) {
            // Auto-generate invoice number if not provided
            $invoiceNumber = $validated['invoice_number'] ?? $this->generateInvoiceNumber($orgId);

            // Calculate line totals
            $lines = [];
            $subtotal = 0;
            $taxTotal = 0;

            foreach ($validated['lines'] as $index => $lineData) {
                $qty = (float) $lineData['quantity'];
                $price = (float) $lineData['unit_price'];
                $lineSubtotal = $qty * $price;

                $taxRateId = $lineData['tax_rate_id'] ?? null;
                $taxAmount = 0;

                if ($taxRateId) {
                    $taxRate = FinTaxRate::find($taxRateId);
                    if ($taxRate) {
                        $taxAmount = $lineSubtotal * ((float) $taxRate->rate / 100);
                    }
                } else {
                    // Default 15% GST for NZ
                    $taxAmount = $lineSubtotal * 0.15;
                }

                $lineTotal = $lineSubtotal + $taxAmount;

                $lines[] = [
                    'description' => $lineData['description'],
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'tax_rate_id' => $taxRateId,
                    'tax_amount' => round($taxAmount, 2),
                    'line_total' => round($lineTotal, 2),
                    'sort_order' => $index,
                    'account_id' => $lineData['account_id'] ?? null,
                ];

                $subtotal += $lineSubtotal;
                $taxTotal += $taxAmount;
            }

            $invoice = FinInvoice::create([
                'organization_id' => $orgId,
                'invoice_number' => $invoiceNumber,
                'invoice_date' => $validated['invoice_date'],
                'due_date' => $validated['due_date'],
                'client_name' => $validated['client_name'],
                'client_email' => $validated['client_email'] ?? null,
                'client_address' => $validated['client_address'] ?? null,
                'bill_id' => $validated['bill_id'] ?? null,
                'subtotal' => round($subtotal, 2),
                'tax_amount' => round($taxTotal, 2),
                'total_amount' => round($subtotal + $taxTotal, 2),
                'currency_code' => $validated['currency_code'] ?? 'NZD',
                'status' => 'draft',
                'notes' => $validated['notes'] ?? null,
                'terms' => $validated['terms'] ?? null,
                'email_subject' => $validated['email_subject'] ?? null,
                'email_body' => $validated['email_body'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            foreach ($lines as $line) {
                $invoice->lines()->create($line);
            }

            return $invoice;
        });

        return redirect()->route('finance.invoices.show', $invoice)
            ->with('success', 'Invoice created successfully.');
    }

    public function show(Request $request, FinInvoice $invoice)
    {
        $invoice->load([
            'lines.taxRate:id,name,rate',
            'lines.account:id,code,name',
            'bill:id,bill_number',
            'createdBy:id,name',
        ]);

        return Inertia::render('finance/invoices/Show', [
            'invoice' => $invoice,
        ]);
    }

    public function edit(Request $request, FinInvoice $invoice)
    {
        if ($invoice->status !== 'draft') {
            return redirect()->route('finance.invoices.show', $invoice)
                ->with('error', 'Only draft invoices can be edited.');
        }

        $orgId = $request->user()->organization_id;

        $invoice->load('lines');

        $accounts = FinAccount::forOrganization($orgId)
            ->active()
            ->whereIn('type', ['revenue', 'income', 'asset'])
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type']);

        $taxRates = FinTaxRate::forOrganization($orgId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'rate']);

        $bills = FinBill::forOrganization($orgId)
            ->with('vendor:id,name')
            ->orderBy('bill_date', 'desc')
            ->limit(50)
            ->get(['id', 'bill_number', 'vendor_id', 'total_amount']);

        return Inertia::render('finance/invoices/Edit', [
            'invoice' => $invoice,
            'accounts' => $accounts,
            'taxRates' => $taxRates,
            'bills' => $bills,
        ]);
    }

    public function update(Request $request, FinInvoice $invoice)
    {
        if ($invoice->status !== 'draft') {
            return redirect()->route('finance.invoices.show', $invoice)
                ->with('error', 'Only draft invoices can be updated.');
        }

        $validated = $request->validate([
            'invoice_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:invoice_date',
            'client_name' => 'required|string|max:255',
            'client_email' => 'nullable|email|max:255',
            'client_address' => 'nullable|string|max:2000',
            'bill_id' => 'nullable|exists:fin_bills,id',
            'currency_code' => 'nullable|string|size:3',
            'notes' => 'nullable|string|max:2000',
            'terms' => 'nullable|string|max:2000',
            'email_subject' => 'nullable|string|max:255',
            'email_body' => 'nullable|string|max:5000',
            'lines' => 'required|array|min:1',
            'lines.*.description' => 'required|string|max:500',
            'lines.*.quantity' => 'required|numeric|min:0.01',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.tax_rate_id' => 'nullable|exists:fin_tax_rates,id',
            'lines.*.account_id' => 'nullable|exists:fin_accounts,id',
        ]);

        DB::transaction(function () use ($invoice, $validated) {
            $lines = [];
            $subtotal = 0;
            $taxTotal = 0;

            foreach ($validated['lines'] as $index => $lineData) {
                $qty = (float) $lineData['quantity'];
                $price = (float) $lineData['unit_price'];
                $lineSubtotal = $qty * $price;

                $taxRateId = $lineData['tax_rate_id'] ?? null;
                $taxAmount = 0;

                if ($taxRateId) {
                    $taxRate = FinTaxRate::find($taxRateId);
                    if ($taxRate) {
                        $taxAmount = $lineSubtotal * ((float) $taxRate->rate / 100);
                    }
                } else {
                    $taxAmount = $lineSubtotal * 0.15;
                }

                $lineTotal = $lineSubtotal + $taxAmount;

                $lines[] = [
                    'description' => $lineData['description'],
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'tax_rate_id' => $taxRateId,
                    'tax_amount' => round($taxAmount, 2),
                    'line_total' => round($lineTotal, 2),
                    'sort_order' => $index,
                    'account_id' => $lineData['account_id'] ?? null,
                ];

                $subtotal += $lineSubtotal;
                $taxTotal += $taxAmount;
            }

            $invoice->update([
                'invoice_date' => $validated['invoice_date'],
                'due_date' => $validated['due_date'],
                'client_name' => $validated['client_name'],
                'client_email' => $validated['client_email'] ?? null,
                'client_address' => $validated['client_address'] ?? null,
                'bill_id' => $validated['bill_id'] ?? null,
                'currency_code' => $validated['currency_code'] ?? 'NZD',
                'subtotal' => round($subtotal, 2),
                'tax_amount' => round($taxTotal, 2),
                'total_amount' => round($subtotal + $taxTotal, 2),
                'notes' => $validated['notes'] ?? null,
                'terms' => $validated['terms'] ?? null,
                'email_subject' => $validated['email_subject'] ?? null,
                'email_body' => $validated['email_body'] ?? null,
                'pdf_path' => null, // Clear PDF so it regenerates
            ]);

            // Replace all lines
            $invoice->lines()->delete();
            foreach ($lines as $line) {
                $invoice->lines()->create($line);
            }
        });

        return redirect()->route('finance.invoices.show', $invoice)
            ->with('success', 'Invoice updated successfully.');
    }

    public function send(Request $request, FinInvoice $invoice)
    {
        if (!$invoice->client_email) {
            return back()->withErrors(['invoice' => 'Invoice has no client email address.']);
        }

        if ($invoice->status === 'cancelled') {
            return back()->withErrors(['invoice' => 'Cannot send a cancelled invoice.']);
        }

        SendInvoiceEmailJob::dispatch($invoice->id);

        return redirect()->route('finance.invoices.show', $invoice)
            ->with('success', 'Invoice is being sent to ' . $invoice->client_email);
    }

    public function downloadPdf(Request $request, FinInvoice $invoice, InvoicePdfService $pdfService)
    {
        // Generate PDF if not exists
        if (!$invoice->pdf_path || !Storage::disk('local')->exists($invoice->pdf_path)) {
            $pdfService->generate($invoice);
            $invoice->refresh();
        }

        return Storage::disk('local')->download(
            $invoice->pdf_path,
            "Invoice-{$invoice->invoice_number}.pdf",
            ['Content-Type' => 'application/pdf']
        );
    }

    public function markPaid(Request $request, FinInvoice $invoice)
    {
        if ($invoice->status === 'cancelled') {
            return back()->withErrors(['invoice' => 'Cannot mark a cancelled invoice as paid.']);
        }

        $invoice->update([
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        return redirect()->route('finance.invoices.show', $invoice)
            ->with('success', 'Invoice marked as paid.');
    }

    private function generateInvoiceNumber(int $orgId): string
    {
        $latest = FinInvoice::forOrganization($orgId)
            ->withTrashed()
            ->orderBy('id', 'desc')
            ->value('invoice_number');

        if ($latest && preg_match('/INV-(\d+)$/', $latest, $matches)) {
            $next = (int) $matches[1] + 1;
        } else {
            $next = 1;
        }

        return 'INV-' . str_pad($next, 5, '0', STR_PAD_LEFT);
    }
}
