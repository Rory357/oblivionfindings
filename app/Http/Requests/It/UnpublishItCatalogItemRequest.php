<?php

namespace App\Http\Requests\It;

use Illuminate\Foundation\Http\FormRequest;

class UnpublishItCatalogItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canDo('it.manage');
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'expected_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
