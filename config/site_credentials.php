<?php

/*
|--------------------------------------------------------------------------
| Site staff credential catalogue
|--------------------------------------------------------------------------
|
| Canonical list of staff credentials a site can require, surfaced by the
| Add Site modal's "Required staff credentials" picker. Selected entries are
| persisted as App\Models\SiteStaffRequirement rows on store. `category`
| (mandatory/recommended) and expiry months are editable per-selection in the
| UI; `default_expiry_months` seeds the picker (0 = no expiry).
|
| Previously this lived hard-coded in the design prototype; it now has a real
| source so the modal renders real reference data, not mock rows.
|
*/

return [
    'catalogue' => [
        ['key' => 'first_aid', 'name' => 'First Aid Certificate', 'default_expiry_months' => 24],
        ['key' => 'med_competency', 'name' => 'Medication Competency', 'default_expiry_months' => 12],
        ['key' => 'police_vet', 'name' => 'Police Vetting', 'default_expiry_months' => 36],
        ['key' => 'drivers_licence', 'name' => 'Full Driver Licence', 'default_expiry_months' => 0],
        ['key' => 'manual_handling', 'name' => 'Manual Handling', 'default_expiry_months' => 24],
        ['key' => 'cpi', 'name' => 'Crisis Prevention (CPI)', 'default_expiry_months' => 12],
    ],

    // Role-mix keys for coverage rules. Mirrors SiteComplianceController's
    // allowed role_requirements.*.key values (caregiver, driver, med_competent).
    'coverage_role_keys' => [
        ['key' => 'caregiver', 'label' => 'Support worker'],
        ['key' => 'driver', 'label' => 'Driver'],
        ['key' => 'med_competent', 'label' => 'Med-competent'],
    ],
];
