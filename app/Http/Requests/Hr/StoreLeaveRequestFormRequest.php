<?php

namespace App\Http\Requests\Hr;

use App\Domain\Hr\Services\LeaveService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeaveRequestFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('hr.leave.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'user_id'         => ['nullable', 'integer', 'exists:users,id'],
            'leave_type'      => ['required', 'string', Rule::in(LeaveService::LEAVE_TYPES)],
            'starts_at'       => ['required', 'date', 'after_or_equal:today'],
            'ends_at'         => ['required', 'date', 'after_or_equal:starts_at'],
            'hours_requested' => ['nullable', 'numeric', 'min:0.5', 'max:999'],
            'reason'          => ['nullable', 'string', 'max:2000'],
            'supporting_doc'  => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:5120'],
        ];
    }
}
