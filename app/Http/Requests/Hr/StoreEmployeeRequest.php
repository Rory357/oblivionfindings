<?php

namespace App\Http\Requests\Hr;

use App\Domain\Hr\Models\HrEmployeeProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('team')) {
            $team = $this->input('team');
            if (is_string($team) || $team === null) {
                $this->merge(['team' => HrEmployeeProfile::normalizeTeam($team)]);
            }
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->canDo('hr.employees.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name'            => ['required', 'string', 'max:255'],
            // NOT unique: an existing account (e.g. a candidate-created user) is
            // linked/updated by EmployeeIntakeService rather than rejected. The
            // controller gates silent overwrite behind `link_existing`.
            'email'           => ['required', 'email', 'max:255'],
            'preferred_name'  => ['nullable', 'string', 'max:255'],
            'role'            => ['nullable', 'string', 'exists:roles,name'],
            'position_id'     => ['nullable', 'integer', 'exists:hr_positions,id'],
            'position_title'  => ['nullable', 'string', 'max:255'],
            'employment_type' => ['nullable', 'string', Rule::in(['full_time', 'part_time', 'casual', 'fixed_term', 'contractor'])],
            'department'      => ['nullable', 'string', 'max:255'],
            'team'            => ['nullable', 'string', 'max:255'],
            'primary_site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'manager_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'start_date'      => ['nullable', 'date'],
            'work_phone'      => ['nullable', 'string', 'max:50'],

            // Step 3 — right to work / visa (all nullable so quick-add still works).
            'work_rights_status' => ['nullable', 'string', 'max:50'],
            'visa_type'          => ['nullable', 'string', 'max:100'],
            'visa_expires_at'    => ['nullable', 'date'],

            // Step 4 — emergency contacts (JSON array of {name, relationship, phone}).
            'emergency_contacts'                => ['nullable', 'array'],
            'emergency_contacts.*.name'         => ['nullable', 'string', 'max:255'],
            'emergency_contacts.*.relationship' => ['nullable', 'string', 'max:100'],
            'emergency_contacts.*.phone'        => ['nullable', 'string', 'max:50'],

            // Intake toggles.
            'start_onboarding' => ['nullable', 'boolean'],
            'send_invite'      => ['nullable', 'boolean'],
            'link_existing'    => ['nullable', 'boolean'],
        ];
    }
}
