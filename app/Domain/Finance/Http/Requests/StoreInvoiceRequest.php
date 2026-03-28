<?php

namespace App\Domain\Finance\Http\Requests;

use App\Domain\Finance\Models\FinInvoice;
use Illuminate\Foundation\Http\FormRequest;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', FinInvoice::class);
    }

    public function rules(): array
    {
        return [
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
        ];
    }
}
