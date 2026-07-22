<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUiPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'key' => $this->route('key'),
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'key' => [
                'required',
                'string',
                'max:120',
                'regex:/^[a-z0-9][a-z0-9._-]{0,119}$/',
            ],
            'value' => ['required', 'array', 'max:100'],
        ];
    }
}
