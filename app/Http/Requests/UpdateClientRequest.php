<?php

namespace App\Http\Requests;

use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('clients.update') ?? false;
    }

    public function rules(): array
    {
        return [
            // Basics. Kept nullable where the wizard handles stricter client-side
            // nudges so modal/profile-completion flows can still save partials.
            'site_id'    => ['nullable', 'integer', 'exists:sites,id'],
            'service_context_id' => ['nullable', 'integer', 'exists:service_contexts,id'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name'  => ['required', 'string', 'max:255'],
            'preferred_name' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'max:50'],
            'preferred_pronouns' => ['nullable', 'string', 'max:50'],
            'status'     => ['required', 'in:active,inactive,onboarding'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'suburb' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
            'create_client_portal_user' => ['nullable', 'boolean'],

            'funding_type' => ['nullable', 'string', 'max:100'],
            'funding_notes' => ['nullable', 'string', 'max:2000'],
            'nhi_number' => Client::nhiValidationRules($this->route('client')?->id),

            // Cultural identity.
            'ethnicity' => ['nullable', 'string', 'max:255'],
            'languages' => ['nullable', 'array'],
            'languages.*' => ['string', 'max:100'],
            'religion' => ['nullable', 'string', 'max:255'],

            // Support needs.
            'mobility_needs' => ['nullable', 'string', 'max:1000'],
            'sensory_needs' => ['nullable', 'string', 'max:2000'],
            'cognitive_needs' => ['nullable', 'string', 'max:2000'],
            'dietary_requirements' => ['nullable', 'string', 'max:2000'],
            'sleep_preferences' => ['nullable', 'string', 'max:2000'],
            'transport_needs' => ['nullable', 'array'],
            'transport_needs.*' => ['string', 'max:255'],
            'transport_notes' => ['nullable', 'string', 'max:2000'],
            'fluid_intake_min_ml' => ['nullable', 'integer', 'min:0', 'max:20000'],
            'fluid_intake_max_ml' => ['nullable', 'integer', 'min:0', 'max:20000'],
            'seizure_duration_escalation_seconds' => ['nullable', 'integer', 'min:0', 'max:36000'],

            // About me.
            'interests_hobbies' => ['nullable', 'string', 'max:5000'],
            'strengths_abilities' => ['nullable', 'string', 'max:5000'],
            'life_story' => ['nullable', 'string', 'max:10000'],
            'education_level' => ['nullable', 'string', 'max:255'],
            'employment_status' => ['nullable', 'string', 'max:255'],

            // Care setup.
            'service_start_date' => ['nullable', 'date'],
            'key_worker_id' => ['nullable', 'integer', 'exists:users,id'],
            'risk_level' => ['nullable', 'in:low,medium,high,critical'],
            'safeguarding_flag' => ['nullable', 'boolean'],
            'house_geofence_id' => ['nullable', 'integer', 'exists:asset_geofences,id'],

            // Health & medical.
            'medical' => ['nullable', 'array'],
            'medical.gp_name' => ['nullable', 'string', 'max:255'],
            'medical.gp_practice' => ['nullable', 'string', 'max:255'],
            'medical.gp_phone' => ['nullable', 'string', 'max:50'],
            'medical.hospital_preference' => ['nullable', 'string', 'max:255'],
            'medical.blood_type' => ['nullable', 'string', 'max:10'],
            'medical.organ_donor' => ['nullable', 'boolean'],
            'medical.allergies' => ['nullable', 'array'],
            'medical.allergies.*' => ['string', 'max:255'],
            'medical.disabilities' => ['nullable', 'array'],
            'medical.disabilities.*' => ['string', 'max:255'],
            'medical.medical_history' => ['nullable', 'string', 'max:10000'],
            'medical.mental_health_history' => ['nullable', 'string', 'max:10000'],
            'medical.surgical_history' => ['nullable', 'string', 'max:10000'],
            'medical.immunisation_notes' => ['nullable', 'string', 'max:5000'],
            'medical.notes' => ['nullable', 'string', 'max:10000'],

            // Diagnosed conditions.
            'conditions' => ['nullable', 'array'],
            'conditions.*.label' => ['nullable', 'string', 'max:255'],
            'conditions.*.severity' => ['nullable', 'in:Mild,Moderate,Severe'],
            'conditions.*.notes' => ['nullable', 'string', 'max:2000'],

            // Emergency contacts.
            'emergency_contacts' => ['nullable', 'array'],
            'emergency_contacts.*.name' => ['nullable', 'string', 'max:255'],
            'emergency_contacts.*.relationship' => ['nullable', 'string', 'max:255'],
            'emergency_contacts.*.phone' => ['nullable', 'string', 'max:50'],
            'emergency_contacts.*.alternate_phone' => ['nullable', 'string', 'max:50'],
            'emergency_contacts.*.email' => ['nullable', 'email', 'max:255'],
            'emergency_contacts.*.address' => ['nullable', 'string', 'max:500'],
            'emergency_contacts.*.availability' => ['nullable', 'string', 'max:255'],
            'emergency_contacts.*.preferred_method' => ['nullable', 'in:phone,text,email'],
            'emergency_contacts.*.notes' => ['nullable', 'string', 'max:2000'],
            'emergency_contacts.*.can_view_medical' => ['nullable', 'boolean'],
            'emergency_contacts.*.can_view_medications' => ['nullable', 'boolean'],
            'emergency_contacts.*.can_view_incidents' => ['nullable', 'boolean'],
            'emergency_contacts.*.can_receive_updates' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $contacts = $this->input('emergency_contacts', []);
            if (! is_array($contacts) || count($contacts) === 0) {
                return;
            }

            $isTrue = fn ($v) => $v === true || $v === '1' || $v === 1;
            $anyData = collect($contacts)->contains(function ($c) use ($isTrue) {
                $c = $c ?? [];
                foreach (['name', 'relationship', 'phone', 'alternate_phone', 'email', 'address', 'availability', 'notes'] as $f) {
                    if (filled($c[$f] ?? null)) {
                        return true;
                    }
                }
                foreach (['can_view_medical', 'can_view_medications', 'can_view_incidents'] as $f) {
                    if ($isTrue($c[$f] ?? false)) {
                        return true;
                    }
                }

                return false;
            });

            if (! $anyData) {
                return;
            }

            $primary = $contacts[0] ?? [];
            if (blank($primary['name'] ?? null)) {
                $validator->errors()->add('emergency_contacts.0.name', 'Primary contact name is required.');
            }
            if (blank($primary['phone'] ?? null)) {
                $validator->errors()->add('emergency_contacts.0.phone', 'Primary contact phone is required.');
            }
        });
    }
}
