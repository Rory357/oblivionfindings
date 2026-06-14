<?php

namespace App\Domain\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('invoice'));
    }

    public function rules(): array
    {
        return [
            'invoice_date' => 'sometimes|required|date',
            'due_date' => 'sometimes|required|date|after_or_equal:invoice_date',
            // Mirror StoreInvoiceRequest: a client-billed invoice derives its name
            // from the client; a funder-billed one from the funding body — so
            // client_name is only required when neither is supplied.
            'client_id' => 'nullable|integer|exists:clients,id',
            'funding_body' => 'nullable|string|max:255',
            'client_name' => 'required_without_all:client_id,funding_body|nullable|string|max:255',
            'client_email' => 'nullable|email|max:255',
            'client_address' => 'nullable|string|max:2000',
            'bill_id' => 'nullable|exists:fin_bills,id',
            'currency_code' => 'nullable|string|size:3',
            'notes' => 'nullable|string|max:2000',
            'terms' => 'nullable|string|max:2000',
            'email_subject' => 'nullable|string|max:255',
            'email_body' => 'nullable|string|max:5000',
            'lines' => 'sometimes|required|array|min:1',
            'lines.*.description' => 'required|string|max:500',
            'lines.*.quantity' => 'required|numeric|min:0.01',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.tax_rate_id' => 'nullable|exists:fin_tax_rates,id',
            'lines.*.account_id' => 'nullable|exists:fin_accounts,id',
        ];
    }

    /**
     * Normalise the wizard's "default"/"none"/"" sentinel select values on each
     * line to null before validation — mirrors StoreInvoiceRequest, so the same
     * NewInvoiceDialog payload validates on both create and edit.
     */
    protected function prepareForValidation(): void
    {
        if (! is_array($this->input('lines'))) {
            return;
        }

        $this->merge([
            'lines' => array_map(function ($line) {
                if (! is_array($line)) {
                    return $line;
                }

                foreach (['tax_rate_id', 'account_id', 'billing_entry_id'] as $key) {
                    if (in_array($line[$key] ?? null, ['', 'default', 'none'], true)) {
                        $line[$key] = null;
                    }
                }

                return $line;
            }, $this->input('lines')),
        ]);
    }
}
