<?php

namespace App\Domain\Roadmap\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDecisionRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:approved,rejected,withdrawn,expired'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
