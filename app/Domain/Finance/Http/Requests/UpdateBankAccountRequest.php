<?php

namespace App\Domain\Finance\Http\Requests;

use App\Domain\Finance\Models\FinBankAccount;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('bankAccount'));
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'bank_name' => ['sometimes', 'required', 'string', 'max:255'],
            'account_number' => ['sometimes', 'required', 'string', 'max:50'],
            'account_type' => ['sometimes', 'required', 'in:cheque,savings,term_deposit,credit_card'],
            'gl_account_id' => ['sometimes', 'required', 'exists:fin_accounts,id'],
            'is_primary' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }
}
