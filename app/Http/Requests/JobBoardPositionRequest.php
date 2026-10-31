<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class JobBoardPositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user() && ($this->user()->canDo('job_board.create') || $this->user()->canDo('shifts.manageAny'));
    }

    public function rules(): array
    {
        return [
            'shift_id' => ['required', 'integer', 'min:1'],
            'request_id' => ['nullable', 'uuid'],
            'source_revision' => ['nullable', 'required_with:request_id', 'string', 'regex:/\\A[0-9a-f]{64}\\z/'],
            'required_skills' => ['nullable', 'array'],
            'required_skills.*' => ['string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
