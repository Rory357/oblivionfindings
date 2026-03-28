<?php

namespace App\Domain\Finance\Http\Requests;

use App\Domain\Finance\Models\FinVendor;
use Illuminate\Foundation\Http\FormRequest;

class UpdateVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('vendor'));
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'trading_name' => ['nullable', 'string', 'max:255'],
            'vendor_type' => ['sometimes', 'required', 'in:supplier,contractor,utility,government,other'],
            'gst_number' => ['nullable', 'string', 'max:50'],
            'bank_account_number' => ['nullable', 'string'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0'],
            'default_expense_account_id' => ['nullable', 'exists:fin_accounts,id'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string'],
            'contacts' => ['nullable', 'array'],
            'contacts.*.id' => ['nullable', 'integer'],
            'contacts.*.name' => ['required', 'string', 'max:255'],
            'contacts.*.role' => ['nullable', 'string', 'max:255'],
            'contacts.*.email' => ['nullable', 'email'],
            'contacts.*.phone' => ['nullable', 'string'],
            'contacts.*.is_primary' => ['boolean'],
        ];
    }
}
