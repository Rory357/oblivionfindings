<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use Illuminate\Foundation\Http\FormRequest;

class CompleteItWorkTaskRequest extends FormRequest
{
    use ConcealsInaccessibleItWork;

    public function authorize(): bool
    {
        $this->workableTaskOrNotFound();

        return (bool) $this->user()?->canDo('it.manage');
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'completion_note' => ['nullable', 'string', 'max:5000'],
            'evidence' => ['nullable', 'array', 'max:20'],
            'evidence.*' => ['required', 'string', 'max:2000'],
        ];
    }
}
