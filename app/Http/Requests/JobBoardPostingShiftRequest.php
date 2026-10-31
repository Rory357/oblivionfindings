<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class JobBoardPostingShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user() && ($this->user()->canDo('job_board.create') || $this->user()->canDo('shifts.manageAny'));
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'week' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:25'],
        ];
    }
}
