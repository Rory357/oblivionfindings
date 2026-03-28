<?php

namespace App\Domain\Roadmap\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreQuarterlyPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fiscal_year' => ['required', 'integer', 'min:2000', 'max:3000'],
            'quarter' => ['required', 'integer', 'min:1', 'max:4'],
            'preset' => ['nullable', 'string', 'max:32'],
            'tenant_id' => ['nullable', 'integer'],
        ];
    }
}
