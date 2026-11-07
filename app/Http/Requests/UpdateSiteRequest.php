<?php

namespace App\Http\Requests;

use App\Http\Requests\Sites\SiteContactRules;
use App\Models\Site;
use App\Models\SiteContact;
use App\Services\Eligibility\WorkforceRequirementMapping;
use App\Support\SiteGeofenceLimits;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSiteRequest extends FormRequest
{
    /** The lists update() can replace (see the `collections` rule). */
    public const COLLECTIONS = ['contacts', 'rooms', 'resources', 'zones', 'assets', 'checklists'];

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('contacts'))) {
            $this->merge(['contacts' => SiteContactRules::withoutLinkedDetails($this->input('contacts'))]);
        }
    }

    public function authorize(): bool|Response
    {
        $user = $this->user();
        $site = $this->route('site');

        if (! $user || ! $site instanceof Site) {
            return false;
        }

        return Gate::forUser($user)->inspect('update', $site);
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
            ...SiteContactRules::rows('contacts.*'),

            // Lists the Edit site pop-up replaces. A list it sends is synced
            // in full; one named here but sent empty is cleared (a multipart
            // form drops empty arrays). A list neither sent nor named is left
            // exactly as it is, so an edit never blanks what it didn't load.
            'collections' => ['nullable', 'array'],
            'collections.*' => ['string', Rule::in(self::COLLECTIONS)],

            'rooms' => ['nullable', 'array'],
            'rooms.*.id' => ['nullable', 'integer'],
            'rooms.*.name' => ['required_with:rooms.*', 'string', 'max:255'],
            'rooms.*.notes' => ['nullable', 'string', 'max:2000'],
            'rooms.*.is_assignable' => ['nullable', 'boolean'],

            'resources' => ['nullable', 'array'],
            'resources.*.id' => ['nullable', 'integer'],
            'resources.*.name' => ['required_with:resources.*', 'string', 'max:255'],
            'resources.*.resource_type' => ['nullable', 'string', 'in:boardroom,training_room,meeting_room,other'],
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

            // New files from the pop-up's Documents step (added to the Site's
            // documents; existing files are managed on the Documents tab).
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

            // Rostering — accepted for the shared payload shape, never saved
            // here: coverage and staff requirements are edited on the Site's
            // Shift coverage and Staff requirements tabs, which keep their
            // own checks (eligibility rechecks, identity, service contexts).
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
            'credentials.*.hr_compliance_requirement_id' => app(WorkforceRequirementMapping::class)->validationRules(),
            'credentials.*.applicability_mode' => ['nullable', 'in:all_workers,minimum_staff'],
            'credentials.*.minimum_qualified_staff' => ['nullable', 'integer', 'min:1', 'max:4294967295'],

            // Geofence — a circle added when the Site has none yet. An existing
            // boundary keeps its versioned Shared boundaries workflow.
            'geofence' => ['nullable', 'array'],
            'geofence.mode' => ['nullable', 'in:radius,draw'],
            'geofence.radius_m' => SiteGeofenceLimits::radiusRules(),
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $site = $this->route('site');
            $contacts = $this->input('contacts');
            if (! $site instanceof Site || ! is_array($contacts)) {
                return;
            }

            $primaryCount = 0;
            foreach ($contacts as $index => $contact) {
                if (! is_array($contact)) {
                    continue;
                }

                if (filter_var($contact['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    $primaryCount++;
                }

                $contactId = $contact['id'] ?? null;
                if (is_numeric($contactId) && ! SiteContact::query()
                    ->where('site_id', $site->id)
                    ->whereKey((int) $contactId)
                    ->exists()) {
                    $validator->errors()->add(
                        "contacts.{$index}.id",
                        'Choose a contact that belongs to this Site.',
                    );
                }
            }

            if ($primaryCount > 1) {
                $validator->errors()->add('contacts', 'Only one Site contact can be primary.');
            }
        });
    }
}
