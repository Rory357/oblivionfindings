<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('sites.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:head_office,house,facility'],
            'phone' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'manager_name' => ['nullable', 'string', 'max:255'],
            'manager_phone' => ['nullable', 'string', 'max:60'],
            'after_hours_phone' => ['nullable', 'string', 'max:60'],
            'emergency_plan_location' => ['nullable', 'string', 'max:255'],
            'medication_storage_location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'suburb' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'postcode' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'access_instructions' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['required', 'boolean'],
            'is_high_risk' => ['boolean'],
            'is_high_needs' => ['boolean'],
            'risk_notes' => ['nullable', 'string', 'max:5000'],
            'risk_review_date' => ['nullable', 'date'],
            'primary_contact_user_id' => ['nullable', 'exists:users,id'],
        ];
    }
}
