<?php

namespace App\Domain\Finance\Http\Requests;

use App\Domain\Finance\Models\FinCreditNote;
use Illuminate\Foundation\Http\FormRequest;

class StoreCreditNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', FinCreditNote::class);
    }

    public function rules(): array
    {
        return [
            'type' => 'required|in:payable,receivable',
            'vendor_id' => 'nullable|required_if:type,payable|exists:fin_vendors,id',
            'client_id' => 'nullable|required_if:type,receivable|exists:clients,id',
            'credit_date' => 'required|date',
            'reason' => 'nullable|string|max:2000',
            'lines' => 'required|array|min:1',
            'lines.*.description' => 'required|string|max:500',
            'lines.*.quantity' => 'required|numeric|min:0.01',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.gst_rate' => 'nullable|numeric',
            'lines.*.account_id' => 'required|exists:fin_accounts,id',
        ];
    }
}
