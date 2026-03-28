<?php

namespace App\Domain\Finance\Http\Requests;

use App\Domain\Finance\Models\FinPurchaseOrder;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('purchaseOrder'));
    }

    public function rules(): array
    {
        return [
            'vendor_id' => 'sometimes|required|exists:fin_vendors,id',
            'order_date' => 'sometimes|required|date',
            'expected_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'cost_centre_id' => 'nullable|exists:fin_cost_centres,id',
            'funding_stream_id' => 'nullable|exists:fin_funding_streams,id',
            'lines' => 'sometimes|required|array|min:1',
            'lines.*.description' => 'required|string',
            'lines.*.quantity' => 'required|numeric|min:0.01',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.gst_rate' => 'nullable|numeric',
            'lines.*.account_id' => 'nullable|exists:fin_accounts,id',
        ];
    }
}
