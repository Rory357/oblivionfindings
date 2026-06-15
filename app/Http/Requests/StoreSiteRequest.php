<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('sites.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:head_office,house,facility,residential'],
            'brand_colour' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'phone' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
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
            'contacts' => ['nullable', 'array'],
            'contacts.*.id' => ['nullable', 'integer'],
            'contacts.*.type' => ['nullable', 'string', 'max:50'],
            'contacts.*.name' => ['required_with:contacts.*', 'string', 'max:255'],
            'contacts.*.role' => ['nullable', 'string', 'max:255'],
            'contacts.*.phone' => ['nullable', 'string', 'max:60'],
            'contacts.*.email' => ['nullable', 'string', 'email', 'max:255'],
            'contacts.*.is_primary' => ['nullable', 'boolean'],
            'contacts.*.notes' => ['nullable', 'string', 'max:5000'],

            'rooms' => ['nullable', 'array'],
            'rooms.*.id' => ['nullable', 'integer'],
            'rooms.*.name' => ['required_with:rooms.*', 'string', 'max:255'],
            'rooms.*.notes' => ['nullable', 'string', 'max:2000'],
            'rooms.*.is_assignable' => ['nullable', 'boolean'],

            'resources' => ['nullable', 'array'],
            'resources.*.id' => ['nullable', 'integer'],
            'resources.*.name' => ['required_with:resources.*', 'string', 'max:255'],
            'resources.*.resource_type' => ['nullable', 'string', 'max:60'],
            'resources.*.capacity' => ['nullable', 'integer', 'min:0'],

            'zones' => ['nullable', 'array'],
            'zones.*.id' => ['nullable', 'integer'],
            'zones.*.name' => ['required_with:zones.*', 'string', 'max:255'],
            'zones.*.zone_type' => ['nullable', 'string', 'max:60'],

            'assets' => ['nullable', 'array'],
            'assets.*' => ['integer', 'exists:assets,id'],

            'checklists' => ['nullable', 'array'],
            'checklists.*.template_id' => ['required_with:checklists.*', 'integer', 'exists:site_checklist_templates,id'],
            'checklists.*.enabled' => ['nullable', 'boolean'],
            'checklists.*.frequency' => ['nullable', 'string', 'max:30'],
            'checklists.*.assigned_to_user_id' => ['nullable', 'exists:users,id'],

            'documents' => ['nullable', 'array'],
            'documents.*.file' => ['required_with:documents.*', 'file', 'max:51200', 'mimes:pdf,doc,docx,xls,xlsx,csv,jpg,jpeg,png,gif,txt,rtf'],
            'documents.*.title' => ['nullable', 'string', 'max:200'],
            'documents.*.category' => ['nullable', 'string', 'max:60'],
            'documents.*.folder' => ['nullable', 'string', 'max:255'],
            'documents.*.version' => ['nullable', 'string', 'max:30'],
            'documents.*.effective_date' => ['nullable', 'date'],
            'documents.*.expiry_date' => ['nullable', 'date'],
            'documents.*.notes' => ['nullable', 'string', 'max:2000'],

            // Site capacity (column already exists on sites)
            'total_capacity' => ['nullable', 'integer', 'min:0'],

            // Rostering — coverage rules (each card fans out to one
            // SiteCoverageRequirement row per selected day on store).
            'coverage' => ['nullable', 'array'],
            'coverage.*.name' => ['required_with:coverage', 'string', 'max:255'],
            'coverage.*.coverage_type' => ['required_with:coverage', 'in:day,evening,overnight,custom'],
            'coverage.*.days' => ['required_with:coverage', 'array', 'min:1'],
            'coverage.*.days.*' => ['in:mon,tue,wed,thu,fri,sat,sun'],
            'coverage.*.starts_time' => ['required_with:coverage', 'date_format:H:i'],
            'coverage.*.ends_time' => ['required_with:coverage', 'date_format:H:i'],
            'coverage.*.minimum_staff' => ['required_with:coverage', 'integer', 'min:1', 'max:12'],
            'coverage.*.shift_type' => ['nullable', 'in:standard,sleepover,on_call,split,travel'],
            'coverage.*.allow_overstaffing' => ['nullable', 'boolean'],
            'coverage.*.service_context_id' => ['nullable', 'integer', 'exists:service_contexts,id'],
            'coverage.*.roles' => ['nullable', 'array'],
            'coverage.*.roles.caregiver' => ['nullable', 'integer', 'min:0', 'max:12'],
            'coverage.*.roles.driver' => ['nullable', 'integer', 'min:0', 'max:12'],
            'coverage.*.roles.med_competent' => ['nullable', 'integer', 'min:0', 'max:12'],

            // Rostering — required staff credentials → SiteStaffRequirement
            'credentials' => ['nullable', 'array'],
            'credentials.*.key' => ['required_with:credentials', 'string', 'max:50'],
            'credentials.*.name' => ['required_with:credentials', 'string', 'max:255'],
            'credentials.*.category' => ['required_with:credentials', 'in:mandatory,recommended'],
            'credentials.*.expiry_period_months' => ['nullable', 'integer', 'min:0', 'max:120'],

            // Geofence — circle seeded into the shared AssetGeofence at create
            'geofence' => ['nullable', 'array'],
            'geofence.mode' => ['nullable', 'in:radius,draw'],
            'geofence.radius_m' => ['nullable', 'integer', 'min:25', 'max:2000'],
            'geofence.breach_type' => ['nullable', 'in:enter,exit,both'],
            'geofence.is_active' => ['nullable', 'boolean'],

            // Property & finance (weekly_food_budget dollars → cents on store)
            'rent_amount' => ['nullable', 'numeric', 'min:0'],
            'rent_frequency' => ['nullable', 'in:weekly,fortnightly,monthly,annually'],
            'lease_start_date' => ['nullable', 'date'],
            'lease_end_date' => ['nullable', 'date', 'after_or_equal:lease_start_date'],
            'landlord_name' => ['nullable', 'string', 'max:255'],
            'landlord_contact' => ['nullable', 'string', 'max:255'],
            'weekly_food_budget' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
