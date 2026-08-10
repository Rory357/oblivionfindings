<?php

namespace App\Http\Requests\It;

use App\Models\ItSavedTicketFilter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreItSavedTicketFilterRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
        ]);
    }

    public function authorize(): bool
    {
        return (bool) $this->user()?->canDo('it.view');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:80',
                Rule::unique(ItSavedTicketFilter::class, 'name')
                    ->where(fn ($query) => $query->where('user_id', $this->user()?->id)),
            ],
            'filters' => ['required', 'array', 'max:30'],
        ];
    }
}
