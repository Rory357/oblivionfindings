<?php

namespace App\Domain\Finance\Http\Requests;

use App\Domain\Finance\Models\FinInvoice;
use Illuminate\Foundation\Http\FormRequest;

class StoreInvoiceRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $payload = $this->all();

        if (! isset($payload['lines'])) {
            $payload['lines'] = $payload['line_items'] ?? $payload['items'] ?? null;
        }

        if (! isset($payload['invoice_date']) && isset($payload['issue_date'])) {
            $payload['invoice_date'] = $payload['issue_date'];
        }

        if (! isset($payload['terms']) && isset($payload['payment_terms'])) {
            $payload['terms'] = $payload['payment_terms'];
        }

        if (isset($payload['lines']) && is_array($payload['lines'])) {
            $payload['lines'] = array_map(function ($line) {
                if (! is_array($line)) {
                    return $line;
                }

                foreach (['tax_rate_id', 'account_id', 'billing_entry_id'] as $key) {
                    if (in_array($line[$key] ?? null, ['', 'default', 'none'], true)) {
                        $line[$key] = null;
                    }
                }

                return $line;
            }, $payload['lines']);
        }

        $this->replace($payload);
    }

    public function authorize(): bool
    {
        return $this->user()->can('create', FinInvoice::class);
    }

    public function rules(): array
    {
        return [
            'invoice_number' => 'nullable|string|max:50',
            'client_id' => 'nullable|integer|exists:clients,id',
            'funding_body' => 'nullable|string|max:255',
            'invoice_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:invoice_date',
            'client_name' => 'required_without_all:client_id,funding_body|nullable|string|max:255',
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
            'lines.*.billing_entry_id' => 'nullable|integer|exists:billing_entries,id',
            'lines.*.service_date' => 'nullable|date',
            'lines.*.category' => 'nullable|string|max:100',
        ];
    }
}
