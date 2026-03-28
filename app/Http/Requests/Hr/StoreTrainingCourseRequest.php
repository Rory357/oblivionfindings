<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;

class StoreTrainingCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('hr.training.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'title'                     => ['required', 'string', 'max:255'],
            'code'                      => ['required', 'string', 'max:50'],
            'description'               => ['nullable', 'string', 'max:5000'],
            'category'                  => ['nullable', 'string', 'max:255'],
            'delivery_method'           => ['required', 'string', 'in:online,in_person,blended,self_paced'],
            'duration_hours'            => ['required', 'numeric', 'min:0'],
            'provider'                  => ['nullable', 'string', 'max:255'],
            'cost'                      => ['nullable', 'numeric', 'min:0'],
            'is_mandatory'              => ['sometimes', 'boolean'],
            'compliance_requirement_id' => ['nullable', 'integer', 'exists:hr_compliance_requirements,id'],
            'max_participants'          => ['nullable', 'integer', 'min:1'],
            'is_active'                 => ['sometimes', 'boolean'],
        ];
    }
}
