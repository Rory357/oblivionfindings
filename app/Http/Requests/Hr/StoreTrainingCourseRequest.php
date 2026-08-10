<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;

class StoreTrainingCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->canDo('hr.training.manage') ?? false)
            || ($this->user()?->canDo('training.manageCourses') ?? false);
    }

    public function rules(): array
    {
        return self::courseRules();
    }

    /**
     * Shared validation rules for create/update. The update request applies
     * `sometimes` on top of these so partial payloads validate.
     */
    public static function courseRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:5000'],
            'learning_outcomes' => ['nullable', 'string', 'max:5000'],
            'prerequisites' => ['nullable', 'array'],
            'prerequisites.*' => ['string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'delivery_method' => ['required', 'string', 'in:online,in_person,blended,self_paced'],
            'duration_hours' => ['required', 'numeric', 'min:0', 'max:999'],
            'provider' => ['nullable', 'string', 'max:255'],
            'provider_reference' => ['nullable', 'string', 'max:255'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'org_pays_provider' => ['sometimes', 'boolean'],
            'staff_can_claim' => ['sometimes', 'boolean'],
            'is_mandatory' => ['sometimes', 'boolean'],
            'requires_renewal' => ['sometimes', 'boolean'],
            'validity_period_months' => ['nullable', 'integer', 'min:1', 'max:600'],
            'renewal_reminder_months' => ['nullable', 'integer', 'min:0', 'max:60'],
            'requires_assessment' => ['sometimes', 'boolean'],
            'pass_mark_percentage' => ['nullable', 'required_if:requires_assessment,true,1', 'integer', 'min:0', 'max:100'],
            'cpd_points' => ['nullable', 'integer', 'min:0', 'max:999'],
            'mandatory_for_roles' => ['nullable', 'array'],
            'mandatory_for_roles.*' => ['string', 'max:120'],
            'compliance_requirement_id' => ['nullable', 'integer', 'exists:hr_compliance_requirements,id'],
            'max_participants' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
