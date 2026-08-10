<?php

namespace App\Http\Requests\Hr;

use App\Domain\Hr\Services\ExpenseService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'currency' => ['nullable', 'string', 'max:3'],
            // Manager-only: file the claim on behalf of another employee. The
            // controller resolves the target through the current Site-visible
            // staff boundary so hidden user IDs are never disclosed by validation.
            'on_behalf_user_id' => ['nullable', 'integer'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.category' => ['required', 'string', Rule::in(ExpenseService::CATEGORIES)],
            'items.*.source_type' => ['nullable', 'string', 'max:50'],
            'items.*.source_id' => ['nullable', 'integer'],
            'items.*.amount' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'items.*.expense_date' => ['required', 'date'],
            'items.*.tax_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
            'items.*.receipt' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ];
    }
}
