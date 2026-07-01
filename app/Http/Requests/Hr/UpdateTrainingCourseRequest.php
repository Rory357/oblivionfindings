<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTrainingCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->canDo('hr.training.manage') ?? false)
            || ($this->user()?->canDo('training.manageCourses') ?? false);
    }

    public function rules(): array
    {
        // Mirror the store rules but make everything optional so a partial
        // edit (e.g. an inline archive toggle) validates cleanly.
        return collect(StoreTrainingCourseRequest::courseRules())
            ->map(function (array $rules) {
                $rules = array_values(array_filter($rules, fn ($r) => $r !== 'required'));
                array_unshift($rules, 'sometimes');

                return $rules;
            })
            ->all();
    }
}
