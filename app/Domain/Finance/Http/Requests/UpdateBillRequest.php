<?php

namespace App\Domain\Finance\Http\Requests;

use App\Domain\Finance\Models\FinBill;
use Illuminate\Foundation\Http\FormRequest;

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
            'spend_approval_id' => 'nullable|exists:spend_approvals,id',
            'lines' => 'sometimes|required|array|min:1',
            'lines.*.description' => 'required|string|max:500',
            'lines.*.quantity' => 'required|numeric|min:0.01',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.gst_rate' => 'nullable|numeric',
            'lines.*.account_id' => 'required|exists:fin_accounts,id',
            'lines.*.cost_centre_id' => 'nullable|exists:fin_cost_centres,id',
            'lines.*.funding_stream_id' => 'nullable|exists:fin_funding_streams,id',
        ];
    }
}
