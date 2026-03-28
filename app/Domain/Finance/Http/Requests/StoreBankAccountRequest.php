<?php

namespace App\Domain\Finance\Http\Requests;

use App\Domain\Finance\Models\FinBankAccount;
use Illuminate\Foundation\Http\FormRequest;

class StoreBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', FinBankAccount::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'bank_name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'max:50'],
            'account_type' => ['required', 'in:cheque,savings,term_deposit,credit_card'],
            'gl_account_id' => ['required', 'exists:fin_accounts,id'],
            'opening_balance' => ['required', 'numeric'],
            'is_primary' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }
}
