<?php

namespace App\Domain\Finance\Http\Requests;

use App\Domain\Finance\Services\AccountsPayableService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('bill'));
    }

    public function rules(): array
    {
        return [
            'vendor_id' => 'sometimes|required|exists:fin_vendors,id',
            'vendor_reference' => 'nullable|string|max:255',
            'bill_date' => 'sometimes|required|date',
            'due_date' => 'sometimes|required|date',
            'notes' => 'nullable|string|max:2000',
            'purchase_order_id' => 'nullable|exists:fin_purchase_orders,id',
            'spend_approval_id' => ['nullable', 'integer', 'min:1'],
            'lines' => 'sometimes|required|array|min:1',
            'lines.*.description' => 'required|string|max:500',
            'lines.*.quantity' => 'required|numeric|min:0.01',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.gst_rate' => 'nullable|numeric',
            'lines.*.tax_rate_id' => 'nullable|exists:fin_tax_rates,id',
            'lines.*.account_id' => 'required|exists:fin_accounts,id',
            'lines.*.cost_centre_id' => 'nullable|exists:fin_cost_centres,id',
            'lines.*.funding_stream_id' => 'nullable|exists:fin_funding_streams,id',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('spend_approval_id')
                || ! filled($this->input('spend_approval_id'))) {
                return;
            }

            app(AccountsPayableService::class)->assertSpendApprovalLinkVisible(
                $this->user(),
                $this->route('bill'),
                (int) $this->input('spend_approval_id'),
            );
        });
    }
}
